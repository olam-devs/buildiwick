<?php

namespace App\Services;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceItem;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestApproval;
use App\Models\InternalBudgetEntry;
use App\Models\Lookup;
use App\Models\OnboardingImport;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\SupplierPayee;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Brings an ongoing project's history into the system from one per-project workbook: expenses already
 * incurred/paid, client invoices already issued, and client payments already received. Rows become
 * ordinary approved requests, payments, issued invoices and receipts (so every report counts them),
 * tagged with the OnboardingImport that created them. The whole workbook is validated before anything
 * is written, and an import can be undone while nothing newer depends on its records.
 */
class OnboardingRecordsService
{
    public const EXPENSE_HEADERS = [
        'expense_date' => 'Expense Date',
        'phase' => 'Phase',
        'main_category' => 'Category',
        'subcategory' => 'Subcategory',
        'item_description' => 'Description',
        'supplier' => 'Supplier / Payee',
        'quantity' => 'Quantity',
        'unit' => 'Unit',
        'total_amount' => 'Total Cost (TSh)',
        'amount_paid' => 'Amount Paid (TSh)',
        'payment_date' => 'Payment Date',
        'payment_method' => 'Payment Method',
        'invoice_receipt_no' => 'Supplier Invoice / Receipt No.',
        'transaction_reference' => 'Payment Reference',
        'budget_item_reference' => 'Budget Item Ref.',
        'notes' => 'Notes',
    ];

    public const INVOICE_HEADERS = [
        'invoice_no' => 'Invoice No.',
        'issue_date' => 'Issue Date',
        'due_date' => 'Due Date',
        'phase' => 'Phase',
        'main_category' => 'Category',
        'item_description' => 'Description',
        'amount' => 'Amount (TSh)',
        'notes' => 'Notes',
    ];

    public const RECEIPT_HEADERS = [
        'payment_date' => 'Payment Date',
        'invoice_no' => 'Invoice No.',
        'amount' => 'Amount (TSh)',
        'payment_method' => 'Payment Method',
        'reference' => 'Reference',
        'notes' => 'Notes',
    ];

    private const SHEETS = ['Expenses' => self::EXPENSE_HEADERS, 'Client Invoices' => self::INVOICE_HEADERS, 'Client Payments' => self::RECEIPT_HEADERS];

    private const MAX_REPORTED_ERRORS = 30;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ExpensePaymentRecorder $expensePayments,
        private readonly ClientPaymentRecorder $clientPayments,
    ) {}

    public function template(Project $project): BinaryFileResponse
    {
        $project->loadMissing(['phases' => fn ($query) => $query->orderBy('phase_order')]);
        $lists = $this->lists($project);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator('Buildiwick Group')->setTitle('Opening Records - '.$project->name);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle('Instructions');
        $lines = [
            'Opening Records - '.$project->name.' ('.$project->project_code.')',
            '',
            'Use this workbook to bring records that already happened on this project into Buildiwick.',
            'Fill only the sheets you need. Header rows must stay as they are; one record per row.',
            'Expenses: every cost already incurred. Amount Paid can be less than Total Cost; the balance becomes an approved payable for Accounts.',
            'Client Invoices: invoices already issued to the client. Several rows with the same Invoice No. become lines of one invoice.',
            'Client Payments: money already received. Invoice No. must match an invoice in this workbook or one already in the system.',
            'Dates: use real Excel dates or day/month/year (for example 15/03/2026). Amounts are in TSh.',
            'Phase, Category, Unit and Payment Method must match the "Lists" sheet. Budget Item Ref. is optional and links a cost to an approved budget line.',
            'Nothing is saved if any row has a problem; every problem is listed with its sheet and row number.',
        ];
        foreach ($lines as $index => $line) {
            $instructions->setCellValue('A'.($index + 1), $line);
        }
        $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $instructions->getColumnDimension('A')->setWidth(130);

        foreach (self::SHEETS as $title => $headers) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            foreach (array_values($headers) as $index => $header) {
                $sheet->setCellValue([$index + 1, 1], $header);
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth(max(14, strlen($header) + 4));
            }
            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27333D']],
            ]);
            $sheet->freezePane('A2');
        }

        $listSheet = $spreadsheet->createSheet();
        $listSheet->setTitle('Lists');
        $columns = [
            'Phases' => $project->phases->map(fn (ProjectPhase $phase) => $phase->stage_label)->values(),
            'Categories' => $lists['categories']->values(),
            'Subcategories' => $lists['subcategories']->pluck('label')->values(),
            'Units' => $lists['units']->values(),
            'Payment Methods' => $lists['methods']->values(),
            'Budget Item Refs' => $lists['budgetEntries']->keys()->values(),
        ];
        $column = 1;
        foreach ($columns as $header => $values) {
            $listSheet->setCellValue([$column, 1], $header);
            foreach ($values as $index => $value) {
                $listSheet->setCellValueExplicit([$column, $index + 2], (string) $value, DataType::TYPE_STRING);
            }
            $listSheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
            $column++;
        }
        $listSheet->getStyle('A1:F1')->getFont()->setBold(true);

        $range = fn (string $column, Collection $values) => '=Lists!$'.$column.'$2:$'.$column.'$'.max(2, $values->count() + 1);
        $expenses = $spreadsheet->getSheetByName('Expenses');
        $this->dropdown($expenses, 'B', $range('A', $columns['Phases']));
        $this->dropdown($expenses, 'C', $range('B', $columns['Categories']));
        $this->dropdown($expenses, 'D', $range('C', $columns['Subcategories']));
        $this->dropdown($expenses, 'H', $range('D', $columns['Units']));
        $this->dropdown($expenses, 'L', $range('E', $columns['Payment Methods']));
        $this->dropdown($expenses, 'O', $range('F', $columns['Budget Item Refs']));
        $this->dropdown($spreadsheet->getSheetByName('Client Invoices'), 'D', $range('A', $columns['Phases']));
        $this->dropdown($spreadsheet->getSheetByName('Client Invoices'), 'E', $range('B', $columns['Categories']));
        $this->dropdown($spreadsheet->getSheetByName('Client Payments'), 'D', $range('E', $columns['Payment Methods']));
        foreach (['Expenses' => ['A', 'K'], 'Client Invoices' => ['B', 'C'], 'Client Payments' => ['A']] as $title => $dateColumns) {
            foreach ($dateColumns as $dateColumn) {
                $spreadsheet->getSheetByName($title)->getStyle("{$dateColumn}2:{$dateColumn}2000")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }
        }
        $spreadsheet->setActiveSheetIndex(1);

        $filename = Str::of($project->project_code.'-opening-records')->replaceMatches('/[^A-Za-z0-9._-]/', '-')->finish('.xlsx')->toString();
        $path = tempnam(sys_get_temp_dir(), 'buildiwick-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** @throws ValidationException */
    public function import(Project $project, UploadedFile $file, User $actor, ?string $notes = null): OnboardingImport
    {
        Gate::forUser($actor)->authorize('import-onboarding-records', $project);
        $parsed = $this->parse($project, $file);
        $storedPath = $file->store('onboarding-imports/'.$project->id, 'local');

        try {
            return $this->database->transaction(function () use ($project, $parsed, $actor, $notes, $file, $storedPath): OnboardingImport {
                Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
                $import = OnboardingImport::create([
                    'import_code' => 'ONB-TMP-'.Str::ulid(),
                    'project_id' => $project->id,
                    'imported_by' => $actor->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => $storedPath,
                    'notes' => $notes,
                ]);
                $import->update(['import_code' => 'ONB-'.str_pad((string) $import->id, 5, '0', STR_PAD_LEFT)]);

                $expenseTotal = '0.00';
                $paidTotal = '0.00';
                foreach ($parsed['expenses'] as $row) {
                    $this->createExpense($project, $import, $row, $actor);
                    $expenseTotal = Money::sum([$expenseTotal, $row['total_amount']]);
                    $paidTotal = Money::sum([$paidTotal, $row['amount_paid']]);
                }

                $invoiceTotal = '0.00';
                $invoices = [];
                foreach ($parsed['invoices'] as $invoiceNo => $invoice) {
                    $invoices[$invoiceNo] = $this->createInvoice($project, $import, $invoiceNo, $invoice, $actor);
                    $invoiceTotal = Money::sum([$invoiceTotal, ...array_column($invoice['lines'], 'amount')]);
                }

                $receiptTotal = '0.00';
                foreach ($parsed['receipts'] as $row) {
                    $invoiceId = ($invoices[$row['invoice_key']] ?? null)?->id ?? $row['existing_invoice_id'];
                    $payment = $this->clientPayments->record($invoiceId, [
                        'amount' => $row['amount'],
                        'payment_date' => $row['payment_date'],
                        'payment_method' => $row['payment_method'],
                        'reference' => $row['reference'],
                        'notes' => $this->historicalNote($row['notes']),
                    ], $actor);
                    $payment->update(['onboarding_import_id' => $import->id]);
                    $receiptTotal = Money::sum([$receiptTotal, $row['amount']]);
                }

                $import->update([
                    'expense_count' => count($parsed['expenses']),
                    'invoice_count' => count($parsed['invoices']),
                    'client_payment_count' => count($parsed['receipts']),
                    'expense_total' => $expenseTotal,
                    'expense_paid_total' => $paidTotal,
                    'invoice_total' => $invoiceTotal,
                    'client_payment_total' => $receiptTotal,
                ]);

                return $import->fresh();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);
            throw $exception;
        }
    }

    /** Removes everything an import created, provided nothing recorded since depends on it. */
    public function undo(OnboardingImport $import, string $reason, User $actor): OnboardingImport
    {
        Gate::forUser($actor)->authorize('import-onboarding-records', $import->project);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['undo_reason' => 'Explain why this import is being undone.']);
        }

        return $this->database->transaction(function () use ($import, $reason, $actor): OnboardingImport {
            $locked = OnboardingImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->undone_at) {
                throw ValidationException::withMessages(['import' => 'This import has already been undone.']);
            }
            $requestIds = $locked->expenseRequests()->pluck('id');
            $invoiceIds = $locked->clientInvoices()->pluck('id');
            $laterExpensePayments = ExpensePayment::query()->whereIn('expense_request_id', $requestIds)
                ->where(fn ($query) => $query->whereNull('onboarding_import_id')->orWhere('onboarding_import_id', '!=', $locked->id))->exists();
            $laterClientPayments = ClientPayment::query()->whereIn('client_invoice_id', $invoiceIds)
                ->where(fn ($query) => $query->whereNull('onboarding_import_id')->orWhere('onboarding_import_id', '!=', $locked->id))->exists();
            if ($laterExpensePayments || $laterClientPayments) {
                throw ValidationException::withMessages(['import' => 'Payments have been recorded against these imported records since the import, so it can no longer be undone.']);
            }

            $locked->clientPayments()->delete();
            $locked->expensePayments()->delete();
            ClientInvoiceItem::query()->whereIn('client_invoice_id', $invoiceIds)->delete();
            $locked->clientInvoices()->delete();
            ExpenseRequestApproval::query()->whereIn('expense_request_id', $requestIds)->delete();
            $locked->expenseRequests()->delete();
            $locked->update(['undone_at' => now(), 'undone_by' => $actor->id, 'undo_reason' => $reason]);

            return $locked->fresh();
        });
    }

    /**
     * @return array{expenses: array<int, array<string, mixed>>, invoices: array<string, array<string, mixed>>, receipts: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function parse(Project $project, UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::createReader('Xlsx')->load($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['records_file' => 'The file could not be read as an Excel (.xlsx) workbook.']);
        }

        $project->loadMissing('phases');
        $lists = $this->lists($project);
        $errors = [];
        $sheets = [];
        foreach (self::SHEETS as $title => $headers) {
            $sheet = $spreadsheet->getSheetByName($title);
            $sheets[$title] = $sheet ? $this->rows($sheet, $headers, $title, $errors) : [];
        }
        $spreadsheet->disconnectWorksheets();

        $expenses = [];
        foreach ($sheets['Expenses'] as $rowNumber => $raw) {
            $row = $this->resolveExpense($raw, $project, $lists, $rowErrors);
            if (! $this->collect($errors, 'Expenses', $rowNumber, $rowErrors)) {
                $expenses[] = $row;
            }
        }

        $invoices = [];
        $existingReferences = ClientInvoice::query()->where('project_id', $project->id)->whereNotNull('reference')->pluck('reference')
            ->map(fn ($reference) => $this->normalise((string) $reference))->all();
        foreach ($sheets['Client Invoices'] as $rowNumber => $raw) {
            $line = $this->resolveInvoiceLine($raw, $project, $lists, $rowErrors);
            $key = $this->normalise((string) $raw['invoice_no']);
            if ($key !== '' && in_array($key, $existingReferences, true)) {
                $rowErrors[] = 'Invoice No. "'.$raw['invoice_no'].'" is already recorded for this project.';
            }
            if ($key !== '' && isset($invoices[$key]) && ($invoices[$key]['issue_date'] !== $line['issue_date'])) {
                $rowErrors[] = 'Rows for Invoice No. "'.$raw['invoice_no'].'" must share the same Issue Date.';
            }
            if ($this->collect($errors, 'Client Invoices', $rowNumber, $rowErrors)) {
                continue;
            }
            $invoices[$key] ??= ['invoice_no' => trim((string) $raw['invoice_no']), 'issue_date' => $line['issue_date'], 'due_date' => $line['due_date'], 'lines' => []];
            $invoices[$key]['due_date'] ??= $line['due_date'];
            $invoices[$key]['lines'][] = $line;
        }
        if (($invoices || $sheets['Client Payments']) && ! $project->client_id) {
            $errors[] = 'Client Invoices: this project has no client linked. Link the client in Project Setup before importing invoices or client payments.';
        }

        $receipts = [];
        $receivedPerInvoice = [];
        foreach ($sheets['Client Payments'] as $rowNumber => $raw) {
            $row = $this->resolveReceipt($raw, $project, $lists, $invoices, $rowErrors);
            if (! $rowErrors) {
                $invoiceTotal = $row['existing_invoice_id']
                    ? ClientInvoice::with('items', 'payments')->find($row['existing_invoice_id'])->outstanding_amount
                    : Money::sum(array_column($invoices[$row['invoice_key']]['lines'], 'amount'));
                $received = Money::sum([$receivedPerInvoice[$row['invoice_key']] ?? '0.00', $row['amount']]);
                if (Money::compare($received, $invoiceTotal) > 0) {
                    $rowErrors[] = 'Payments for Invoice No. "'.$raw['invoice_no'].'" add up to more than the invoice amount still owed.';
                }
                $receivedPerInvoice[$row['invoice_key']] = $received;
            }
            if (! $this->collect($errors, 'Client Payments', $rowNumber, $rowErrors)) {
                $receipts[] = $row;
            }
        }

        if ($errors) {
            $shown = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);
            if (count($errors) > self::MAX_REPORTED_ERRORS) {
                $shown[] = (count($errors) - self::MAX_REPORTED_ERRORS).' more problem(s) not shown.';
            }
            throw ValidationException::withMessages(['records_file' => $shown]);
        }
        if (! $expenses && ! $invoices && ! $receipts) {
            throw ValidationException::withMessages(['records_file' => 'The workbook has no records on the Expenses, Client Invoices or Client Payments sheets.']);
        }

        return compact('expenses', 'invoices', 'receipts');
    }

    private function createExpense(Project $project, OnboardingImport $import, array $row, User $actor): ExpenseRequest
    {
        $supplier = null;
        if ($row['supplier']) {
            $supplier = SupplierPayee::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($row['supplier'])])->first()
                ?? SupplierPayee::create(['name' => $row['supplier'], 'type' => $row['main_category'] === 'labour_subcontracts' ? 'fundi' : 'supplier', 'is_active' => true,
                    'notes' => 'Created from onboarding import '.$import->import_code.'.']);
        }
        $request = ExpenseRequest::create([
            'request_code' => 'REQ-TMP-'.Str::ulid(),
            'project_id' => $project->id,
            'project_phase_id' => $row['project_phase_id'],
            'internal_budget_entry_id' => $row['internal_budget_entry_id'],
            'request_type' => $row['main_category'] === 'labour_subcontracts' ? 'labour' : 'item',
            'procurement_stage' => 'approved',
            'main_category' => $row['main_category'],
            'subcategory' => $row['subcategory'],
            'item_description' => $row['item_description'],
            'supplier_fundi_client' => $row['supplier'],
            'supplier_payee_id' => $supplier?->id,
            'invoice_receipt_no' => $row['invoice_receipt_no'],
            'quantity' => $row['quantity'],
            'unit' => $row['unit'],
            'unit_cost' => $row['unit_cost'],
            'total_amount' => $row['total_amount'],
            'commercial_unit_rate' => $row['unit_cost'],
            'commercial_total' => $row['total_amount'],
            'commercial_payment_method' => $row['payment_method'],
            'commercial_prepared_by' => $actor->id,
            'commercial_prepared_at' => now(),
            'approved_amount' => $row['total_amount'],
            'requested_at' => $row['expense_date'],
            'submitted_at' => $row['expense_date'],
            'status' => 'approved',
            'requested_by' => $actor->id,
            'notes' => $this->historicalNote($row['notes']),
            'onboarding_import_id' => $import->id,
        ]);
        $request->update(['request_code' => 'REQ-'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT)]);
        $request->approvals()->create([
            'approval_stage' => 'onboarding_import',
            'decision' => 'approved',
            'approved_amount' => $row['total_amount'],
            'approved_unit_rate' => $row['unit_cost'],
            'approved_total' => $row['total_amount'],
            'supplier_payee_id' => $supplier?->id,
            'supplier_name_snapshot' => $supplier?->name ?? $row['supplier'],
            'supplier_type_snapshot' => $supplier?->type,
            'payment_method_snapshot' => $row['payment_method'],
            'decided_by' => $actor->id,
            // Payable aging runs from approval, so the historical commitment date is used.
            'decided_at' => CarbonImmutable::parse($row['expense_date'])->startOfDay(),
            'notes' => 'Historical record entered at onboarding ('.$import->import_code.').',
        ]);

        if (Money::compare($row['amount_paid'], '0') > 0) {
            $payment = $this->expensePayments->record($request->id, [
                'amount' => $row['amount_paid'],
                'payment_date' => $row['payment_date'],
                'payment_method' => $row['payment_method'],
                'invoice_receipt_no' => $row['invoice_receipt_no'],
                'transaction_reference' => $row['transaction_reference'],
                'notes' => 'Historical payment entered at onboarding ('.$import->import_code.').',
            ], $actor);
            $payment->update(['onboarding_import_id' => $import->id]);
        }

        return $request;
    }

    private function createInvoice(Project $project, OnboardingImport $import, string $key, array $invoice, User $actor): ClientInvoice
    {
        $estimateId = $project->estimates()->where('status', 'issued')->orderByDesc('version')->value('id');
        $record = ClientInvoice::create([
            'invoice_code' => 'INV-TMP-'.Str::ulid(),
            'reference' => $invoice['invoice_no'],
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'project_estimate_id' => $estimateId,
            'status' => 'issued',
            'issued_at' => $invoice['issue_date'],
            'due_at' => $invoice['due_date'],
            'created_by' => $actor->id,
            'issued_by' => $actor->id,
            'notes' => 'Historical invoice entered at onboarding ('.$import->import_code.').',
            'onboarding_import_id' => $import->id,
        ]);
        $record->update(['invoice_code' => 'INV-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT)]);
        foreach ($invoice['lines'] as $line) {
            $record->items()->create([
                'project_phase_id' => $line['project_phase_id'],
                'main_category' => $line['main_category'],
                'item_description' => $line['item_description'],
                'quantity' => '1.00',
                'unit' => 'ls',
                'unit_price' => $line['amount'],
                'contract_amount' => $line['amount'],
                'claim_amount' => $line['amount'],
                'notes' => $line['notes'],
            ]);
        }

        return $record;
    }

    /** @param  array<int, string>|null  $errors */
    private function resolveExpense(array $raw, Project $project, array $lists, ?array &$errors): array
    {
        $errors = [];
        $expenseDate = $this->date($raw['expense_date'], 'Expense Date', true, $errors);
        $phase = $this->phase($project->phases, $raw['phase'], $errors);
        $category = $this->code($lists['categories'], $raw['main_category'], 'Category', true, $errors);
        $subcategory = null;
        if ($this->filled($raw['subcategory']) && $category) {
            $subcategory = $this->code($lists['subcategories']->where('parent_code', $category)->pluck('label', 'code'), $raw['subcategory'], 'Subcategory', false, $errors, 'does not belong to the selected category');
        }
        $unit = $this->filled($raw['unit']) ? $this->code($lists['units'], $raw['unit'], 'Unit', false, $errors) : null;
        $description = $this->text($raw['item_description']);
        if (! $description) {
            $errors[] = 'Description is required.';
        }
        $total = $this->money($raw['total_amount'], 'Total Cost', true, $errors);
        if ($total !== null && Money::compare($total, '0') <= 0) {
            $errors[] = 'Total Cost must be greater than zero.';
        }
        $paid = $this->money($raw['amount_paid'], 'Amount Paid', true, $errors);
        if ($paid !== null && $total !== null && Money::compare($paid, $total) > 0) {
            $errors[] = 'Amount Paid cannot be more than Total Cost.';
        }
        $quantity = $this->filled($raw['quantity']) ? $this->money($raw['quantity'], 'Quantity', false, $errors) : null;
        $paymentDate = null;
        $method = null;
        if ($paid !== null && Money::compare($paid, '0') > 0) {
            $paymentDate = $this->filled($raw['payment_date']) ? $this->date($raw['payment_date'], 'Payment Date', true, $errors) : $expenseDate;
            $method = $this->code($lists['methods'], $raw['payment_method'], 'Payment Method', true, $errors);
        } elseif ($this->filled($raw['payment_method'])) {
            $method = $this->code($lists['methods'], $raw['payment_method'], 'Payment Method', false, $errors);
        }
        $budgetEntryId = null;
        if ($this->filled($raw['budget_item_reference'])) {
            $budgetEntryId = $lists['budgetEntries'][trim((string) $raw['budget_item_reference'])] ?? null;
            if (! $budgetEntryId) {
                $errors[] = 'Budget Item Ref. "'.$raw['budget_item_reference'].'" is not a line in this project\'s approved budget.';
            }
        }
        foreach (['item_description' => 1000, 'supplier' => 255, 'invoice_receipt_no' => 255, 'transaction_reference' => 255, 'notes' => 2000] as $field => $max) {
            if (mb_strlen((string) $raw[$field]) > $max) {
                $errors[] = self::EXPENSE_HEADERS[$field].' is longer than '.$max.' characters.';
            }
        }

        return [
            'expense_date' => $expenseDate,
            'project_phase_id' => $phase?->id,
            'main_category' => $category,
            'subcategory' => $subcategory,
            'item_description' => $description,
            'supplier' => $this->text($raw['supplier']),
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_cost' => $quantity && $total && Money::compare($quantity, '0') > 0 ? number_format(round((float) $total / (float) $quantity, 2), 2, '.', '') : null,
            'total_amount' => $total,
            'amount_paid' => $paid ?? '0.00',
            'payment_date' => $paymentDate,
            'payment_method' => $method,
            'invoice_receipt_no' => $this->text($raw['invoice_receipt_no']),
            'transaction_reference' => $this->text($raw['transaction_reference']),
            'internal_budget_entry_id' => $budgetEntryId,
            'notes' => $this->text($raw['notes']),
        ];
    }

    /** @param  array<int, string>|null  $errors */
    private function resolveInvoiceLine(array $raw, Project $project, array $lists, ?array &$errors): array
    {
        $errors = [];
        if (! $this->filled($raw['invoice_no'])) {
            $errors[] = 'Invoice No. is required.';
        }
        $issueDate = $this->date($raw['issue_date'], 'Issue Date', true, $errors);
        $dueDate = $this->filled($raw['due_date']) ? $this->date($raw['due_date'], 'Due Date', false, $errors, allowFuture: true) : null;
        if ($issueDate && $dueDate && $dueDate < $issueDate) {
            $errors[] = 'Due Date cannot be before Issue Date.';
        }
        $phase = $this->phase($project->phases, $raw['phase'], $errors);
        $category = $this->code($lists['categories'], $raw['main_category'], 'Category', true, $errors);
        $description = $this->text($raw['item_description']);
        if (! $description) {
            $errors[] = 'Description is required.';
        }
        $amount = $this->money($raw['amount'], 'Amount', true, $errors);
        if ($amount !== null && Money::compare($amount, '0') <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }

        return ['issue_date' => $issueDate, 'due_date' => $dueDate, 'project_phase_id' => $phase?->id, 'main_category' => $category, 'item_description' => $description, 'amount' => $amount, 'notes' => $this->text($raw['notes'])];
    }

    /** @param  array<int, string>|null  $errors */
    private function resolveReceipt(array $raw, Project $project, array $lists, array $invoices, ?array &$errors): array
    {
        $errors = [];
        $paymentDate = $this->date($raw['payment_date'], 'Payment Date', true, $errors);
        $amount = $this->money($raw['amount'], 'Amount', true, $errors);
        if ($amount !== null && Money::compare($amount, '0') <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }
        $method = $this->code($lists['methods'], $raw['payment_method'], 'Payment Method', true, $errors);
        $key = $this->normalise((string) $raw['invoice_no']);
        $existingId = null;
        if ($key === '') {
            $errors[] = 'Invoice No. is required.';
        } elseif (! isset($invoices[$key])) {
            $existing = ClientInvoice::query()->where('project_id', $project->id)->where('status', 'issued')
                ->get(['id', 'reference', 'invoice_code'])
                ->first(fn (ClientInvoice $invoice) => in_array($key, [$this->normalise((string) $invoice->reference), $this->normalise($invoice->invoice_code)], true));
            $existingId = $existing?->id;
            if (! $existingId) {
                $errors[] = 'Invoice No. "'.$raw['invoice_no'].'" is not on the Client Invoices sheet or an issued invoice of this project.';
            }
        }
        if ($paymentDate && isset($invoices[$key]) && $invoices[$key]['issue_date'] && $paymentDate < $invoices[$key]['issue_date']) {
            $errors[] = 'Payment Date is before the invoice Issue Date.';
        }

        return ['payment_date' => $paymentDate, 'invoice_key' => $key, 'existing_invoice_id' => $existingId, 'amount' => $amount, 'payment_method' => $method,
            'reference' => $this->text($raw['reference']), 'notes' => $this->text($raw['notes'])];
    }

    /** @return array{categories: Collection, subcategories: Collection, units: Collection, methods: Collection, budgetEntries: Collection} */
    private function lists(Project $project): array
    {
        $budgetEntries = InternalBudgetEntry::query()
            ->whereHas('budget', fn ($query) => $query->where('project_id', $project->id)->where('status', 'approved'))
            ->whereNotNull('item_reference')
            ->orderBy('item_reference')
            ->get(['id', 'item_reference'])
            ->mapWithKeys(fn (InternalBudgetEntry $entry) => [trim((string) $entry->item_reference) => $entry->id]);

        return [
            'categories' => Lookup::ofType('main_category')->get()->pluck('label', 'code'),
            'subcategories' => Lookup::ofType('subcategory')->get(['code', 'label', 'parent_code']),
            'units' => Lookup::ofType('unit')->get()->pluck('label', 'code'),
            'methods' => Lookup::ofType('payment_method')->get()->pluck('label', 'code'),
            'budgetEntries' => $budgetEntries,
        ];
    }

    /**
     * Reads data rows keyed by their spreadsheet row number. Header cells are matched by text, so
     * column order does not matter; missing required columns are reported once per sheet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(Worksheet $sheet, array $headers, string $title, array &$errors): array
    {
        $aliases = [];
        foreach ($headers as $field => $header) {
            $aliases[$this->normalise($header)] = $field;
        }
        $columns = [];
        $highestColumn = min(40, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        for ($column = 1; $column <= $highestColumn; $column++) {
            $key = $this->normalise((string) $sheet->getCell([$column, 1])->getValue());
            if (isset($aliases[$key])) {
                $columns[$aliases[$key]] = $column;
            }
        }
        $missing = array_diff_key($headers, $columns);
        if ($missing && $sheet->getHighestDataRow() > 1) {
            $errors[] = $title.': missing column(s) '.implode(', ', $missing).'. Keep the header row from the downloaded template.';

            return [];
        }

        $rows = [];
        for ($rowNumber = 2; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
            $raw = [];
            foreach (array_keys($headers) as $field) {
                $value = isset($columns[$field]) ? $sheet->getCell([$columns[$field], $rowNumber])->getCalculatedValue() : null;
                $raw[$field] = is_string($value) ? trim($value) : $value;
            }
            if (collect($raw)->every(fn ($value) => ! $this->filled($value))) {
                continue;
            }
            $rows[$rowNumber] = $raw;
        }

        return $rows;
    }

    /** Adds row errors to the list; returns true when the row had any. */
    private function collect(array &$errors, string $sheet, int $rowNumber, array $rowErrors): bool
    {
        foreach ($rowErrors as $message) {
            $errors[] = $sheet.' row '.$rowNumber.': '.$message;
        }

        return $rowErrors !== [];
    }

    private function dropdown(Worksheet $sheet, string $column, string $formula): void
    {
        $validation = $sheet->getCell($column.'2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_WARNING)
            ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)
            ->setErrorTitle('Not in list')->setError('Choose a value from the Lists sheet.')->setFormula1($formula);
        $sheet->setDataValidation("{$column}2:{$column}2000", $validation);
    }

    private function phase(Collection $phases, mixed $value, array &$errors): ?ProjectPhase
    {
        if (! $this->filled($value)) {
            $errors[] = 'Phase is required.';

            return null;
        }
        $phase = is_numeric($value)
            ? $phases->firstWhere('phase_order', (int) $value)
            : $phases->first(fn (ProjectPhase $phase) => in_array($this->normalise((string) $value), [
                $this->normalise((string) $phase->stage_label), $this->normalise((string) $phase->phase_name), $this->normalise((string) $phase->stage_code),
            ], true));
        if (! $phase) {
            $errors[] = 'Phase "'.$value.'" is not one of this project\'s phases.';
        }

        return $phase;
    }

    private function code(Collection $labelsByCode, mixed $value, string $label, bool $required, array &$errors, string $problem = 'is not recognised'): ?string
    {
        if (! $this->filled($value)) {
            if ($required) {
                $errors[] = $label.' is required.';
            }

            return null;
        }
        $needle = $this->normalise((string) $value);
        foreach ($labelsByCode as $code => $text) {
            if ($this->normalise((string) $code) === $needle || $this->normalise((string) $text) === $needle) {
                return (string) $code;
            }
        }
        $errors[] = $label.' "'.$value.'" '.$problem.'.';

        return null;
    }

    private function date(mixed $value, string $label, bool $required, array &$errors, bool $allowFuture = false): ?string
    {
        if (! $this->filled($value)) {
            if ($required) {
                $errors[] = $label.' is required.';
            }

            return null;
        }
        $date = null;
        if (is_numeric($value)) {
            $date = CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
        } else {
            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'd M Y', 'j M Y', 'd F Y'] as $format) {
                try {
                    $candidate = CarbonImmutable::createFromFormat('!'.$format, (string) $value);
                } catch (Throwable) {
                    $candidate = false;
                }
                if ($candidate && $candidate->format($format) === (string) $value) {
                    $date = $candidate;
                    break;
                }
            }
        }
        if (! $date) {
            $errors[] = $label.' "'.$value.'" is not a date. Use day/month/year, for example 15/03/2026.';

            return null;
        }
        if (! $allowFuture && $date->isAfter(today())) {
            $errors[] = $label.' cannot be in the future.';

            return null;
        }

        return $date->toDateString();
    }

    private function money(mixed $value, string $label, bool $required, array &$errors): ?string
    {
        if (! $this->filled($value)) {
            if ($required) {
                $errors[] = $label.' is required.';
            }

            return null;
        }
        $clean = is_string($value) ? str_replace([',', ' ', 'TSh', 'tsh'], '', $value) : $value;
        if (! is_numeric($clean) || (float) $clean < 0 || (float) $clean > 999999999999.99) {
            $errors[] = $label.' "'.$value.'" is not a valid amount.';

            return null;
        }

        return number_format(round((float) $clean, 2), 2, '.', '');
    }

    private function historicalNote(?string $notes): string
    {
        return trim('Historical record entered at onboarding. '.($notes ?? ''));
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function text(mixed $value): ?string
    {
        return $this->filled($value) ? trim((string) $value) : null;
    }

    private function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9²³]/u', '', mb_strtolower(trim($value)));
    }
}
