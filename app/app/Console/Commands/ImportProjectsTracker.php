<?php

namespace App\Console\Commands;

use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\LabourAgreement;
use App\Models\Lookup;
use App\Models\OnboardingImport;
use App\Models\Project;
use App\Models\User;
use App\Services\ExpenseLedgerService;
use App\Services\ExpensePaymentRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Imports the client's "All Projects Tracker" workbook (one sheet per project) without changing what
 * they recorded: every line keeps its Transaction ID, date, category, subcategory, description, unit,
 * quantity, prices and stage. "Expense" lines are recorded as paid (amount paid, or the line total when
 * blank); "Material log - to confirm" lines stay unpaid for Accounts to confirm. The 8 tracker stages
 * become each project's top-level phases. Labour lines naming a fundi are linked to a worker record.
 * Each project's import is an OnboardingImport, so it can be traced (and undone) like any onboarding.
 */
#[Signature('app:import-tracker {file : Path to the .xlsx workbook} {--fresh : Delete ALL existing projects (demo and test data) first} {--dry-run : Read and report only; save nothing} {--as= : Email of the Director the import is recorded under}')]
#[Description('Import every project sheet of the Buildiwick All Projects Tracker workbook into recording mode.')]
class ImportProjectsTracker extends Command
{
    private const SKIP_SHEETS = ['Consolidated', 'Project Template', 'Lists & Settings'];

    private const STAGES = ['Preliminaries', 'Substructure (Foundation)', 'Superstructure', 'Walling', 'Roofing', 'Finishing', 'External Works', 'Other / General'];

    private const CATEGORIES = [
        'materials' => 'materials',
        'labour & subcontracts' => 'labour_subcontracts',
        'logistics & equipment' => 'logistics_equipment',
        'preliminaries & site overheads' => 'preliminaries_overheads',
    ];

    private const STATUSES = ['active' => 'active', 'completed' => 'completed', 'near closure' => 'near_closure', 'overheads' => 'overheads', 'planning' => 'planning', 'on hold' => 'on_hold'];

    /** @var array<string, string> */
    private array $subcategories = [];

    /** @var array<string, string> */
    private array $units = [];

    private array $notes = [];

    public function handle(ExpensePaymentRecorder $payments): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }
        $actor = $this->option('as')
            ? User::where('email', $this->option('as'))->first()
            : User::whereHas('role', fn ($query) => $query->where('name', 'director'))->orderBy('id')->first();
        if (! $actor) {
            $this->error('No Director account found to record the import under (use --as=email).');

            return self::FAILURE;
        }

        $this->subcategories = Lookup::where('type', 'subcategory')->get()->mapWithKeys(fn ($row) => [$this->key($row->label) => $row->code])->all();
        $this->units = Lookup::where('type', 'unit')->get()->flatMap(fn ($row) => [$this->key($row->label) => $row->code, $this->key($row->code) => $row->code])->all();

        $this->info('Reading '.basename($path).' …');
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $projects = [];
        foreach ($book->getAllSheets() as $sheet) {
            if (in_array($sheet->getTitle(), self::SKIP_SHEETS, true)) {
                continue;
            }
            $parsed = $this->parseSheet($sheet);
            if ($parsed) {
                $projects[] = $parsed;
            }
        }
        $book->disconnectWorksheets();

        $rows = collect($projects)->map(fn ($p) => [$p['code'], Str::limit($p['name'], 40), $p['status'], count($p['lines']), Money::format($p['recorded']), Money::format($p['paid']), $p['unpaid']]);
        $this->table(['Project ID', 'Name', 'Status', 'Lines', 'Recorded', 'Paid', 'To confirm'], $rows->all());
        $this->line('Total: '.collect($projects)->sum(fn ($p) => count($p['lines'])).' lines · recorded TSh '.Money::format(Money::sum(array_column($projects, 'recorded'))).' · paid TSh '.Money::format(Money::sum(array_column($projects, 'paid'))));
        foreach (array_slice($this->notes, 0, 40) as $note) {
            $this->warn('  '.$note);
        }
        if (count($this->notes) > 40) {
            $this->warn('  … '.(count($this->notes) - 40).' more notes.');
        }
        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was saved.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $count = Project::count();
            $this->warn('Deleting '.$count.' existing project(s) and all their records …');
            DB::transaction(function (): void {
                foreach (Project::query()->get() as $project) {
                    DB::table('client_payments')->where('project_id', $project->id)->delete();
                    DB::table('client_invoice_items')->whereIn('client_invoice_id', DB::table('client_invoices')->where('project_id', $project->id)->pluck('id'))->delete();
                    DB::table('client_invoices')->where('project_id', $project->id)->delete();
                    $project->delete();
                }
            });
        }

        $bar = $this->output->createProgressBar(collect($projects)->sum(fn ($p) => count($p['lines'])));
        foreach ($projects as $data) {
            DB::transaction(function () use ($data, $actor, $payments, $bar, $path): void {
                $this->importProject($data, $actor, $payments, $bar, basename($path));
            });
        }
        $bar->finish();
        $this->newLine();
        $this->info('Imported '.count($projects).' projects.');

        return self::SUCCESS;
    }

    private function importProject(array $data, User $actor, ExpensePaymentRecorder $payments, $bar, string $filename): void
    {
        $project = Project::where('project_code', $data['code'])->first();
        if ($project && $project->expenseRequests()->exists()) {
            $this->warn($data['code'].' already has expenses; skipped to avoid duplicates.');
            $bar->advance(count($data['lines']));

            return;
        }
        $project ??= Project::create(['project_code' => $data['code'], 'name' => $data['name'], 'status' => $data['status'], 'location' => $data['location'], 'created_by' => $actor->id,
            'notes' => 'Imported from '.$filename.' (sheet "'.$data['sheet'].'").']);

        $phases = [];
        foreach (self::STAGES as $index => $stage) {
            $phases[$this->key($stage)] = $project->phases()->firstOrCreate(['stage_code' => Str::slug($stage, '_')], [
                'phase_name' => $stage, 'phase_order' => $index + 1, 'status' => $index === 0 ? 'active' : 'not_started', 'created_by' => $actor->id,
            ]);
        }
        $import = OnboardingImport::create(['import_code' => 'ONB-TMP-'.Str::ulid(), 'project_id' => $project->id, 'imported_by' => $actor->id,
            'original_filename' => $filename, 'notes' => 'Tracker sheet "'.$data['sheet'].'"']);
        $import->update(['import_code' => 'ONB-'.str_pad((string) $import->id, 5, '0', STR_PAD_LEFT)]);

        $workers = [];
        $seen = [];
        foreach ($data['lines'] as $line) {
            $phase = $phases[$this->key($line['stage'])] ?? $phases[$this->key('Other / General')];
            $code = $line['id'] ?: ExpenseLedgerService::codePrefix($project).'-IMP-'.str_pad((string) (count($seen) + 1), 4, '0', STR_PAD_LEFT);
            while (isset($seen[$code]) || ExpenseRequest::where('request_code', $code)->exists()) {
                $code .= '-B';
            }
            $seen[$code] = true;
            $workerId = null;
            if ($line['category'] === 'labour_subcontracts' && $line['supplier']) {
                $workerId = $workers[$this->key($line['supplier'])] ??= LabourAgreement::create([
                    'agreement_code' => 'WRK-'.strtoupper(Str::random(8)), 'project_id' => $project->id, 'fundi_subcontractor' => $line['supplier'],
                    'labour_type' => $line['subcategory'] === 'subcontractor' ? 'subcontractor' : 'fundi_labour', 'agreement_date' => $line['date'],
                    'agreed_value' => 0, 'status' => 'active', 'created_by' => $actor->id,
                ])->id;
            }
            $request = ExpenseRequest::create([
                'request_code' => $code,
                'project_id' => $project->id,
                'project_phase_id' => $phase->id,
                'labour_agreement_id' => $workerId,
                'request_type' => $line['category'] === 'labour_subcontracts' ? 'labour' : 'item',
                'procurement_stage' => 'approved',
                'main_category' => $line['category'],
                'subcategory' => $line['subcategory'],
                'item_description' => $line['description'],
                'supplier_fundi_client' => $line['supplier'],
                'invoice_receipt_no' => $line['invoice'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_cost' => $line['unit_cost'],
                'total_amount' => $line['total'],
                'commercial_unit_rate' => $line['unit_cost'],
                'commercial_total' => $line['total'],
                'commercial_payment_method' => $line['paid'] ? 'other' : null,
                'approved_amount' => $line['total'],
                'requested_at' => $line['date'],
                'submitted_at' => now(),
                'status' => 'approved',
                'requested_by' => $actor->id,
                'notes' => $line['evidence'] ? 'Evidence: '.$line['evidence'] : null,
                'onboarding_import_id' => $import->id,
            ]);
            $request->approvals()->create([
                'approval_stage' => 'onboarding_import', 'decision' => 'approved', 'approved_amount' => $line['total'], 'approved_unit_rate' => $line['unit_cost'],
                'approved_total' => $line['total'], 'payment_method_snapshot' => $line['paid'] ? 'other' : null, 'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::parse($line['date'])->startOfDay(), 'notes' => 'Imported from the project tracker as recorded.',
            ]);
            if ($line['paid'] && Money::compare($line['paid'], '0') > 0) {
                $payment = $payments->record($request->id, [
                    'amount' => $line['paid'], 'payment_date' => $line['date'], 'payment_method' => 'other',
                    'invoice_receipt_no' => $line['invoice'], 'notes' => 'Imported from the project tracker (payment method not recorded there).',
                ], $actor);
                $payment->update(['onboarding_import_id' => $import->id]);
            } elseif ($line['paid'] && Money::compare($line['paid'], '0') < 0) {
                // Tracker "difference / discount" lines carry negative paid amounts; the recorder only takes
                // positive payments, so the adjustment is stored as recorded to keep the tracker's paid total.
                ExpensePayment::create([
                    'payment_code' => 'EXP-ADJ-'.Str::ulid(), 'expense_request_id' => $request->id, 'project_id' => $project->id,
                    'project_phase_id' => $phase->id, 'amount' => $line['paid'], 'payment_date' => $line['date'], 'payment_method' => 'other',
                    'invoice_receipt_no' => $line['invoice'], 'recorded_by' => $actor->id, 'onboarding_import_id' => $import->id,
                    'notes' => 'Imported tracker adjustment (rounding/discount), negative as recorded.',
                ]);
            }
            $bar->advance();
        }
        $import->update([
            'expense_count' => count($data['lines']), 'expense_total' => $data['recorded'], 'expense_paid_total' => $data['paid'],
        ]);
    }

    private function parseSheet(Worksheet $sheet): ?array
    {
        $title = $sheet->getTitle();
        $code = trim((string) $sheet->getCell('B2')->getValue());
        if ($code === '' || ! str_starts_with($code, 'BWG-')) {
            $this->notes[] = $title.': no Project ID in B2, sheet skipped.';

            return null;
        }
        $statusText = $this->key((string) $sheet->getCell('F2')->getValue());
        $data = [
            'sheet' => $title, 'code' => $code, 'name' => trim((string) $sheet->getCell('D2')->getValue()) ?: $title,
            'status' => self::STATUSES[str_replace('_', ' ', $statusText)] ?? 'active',
            'location' => trim((string) $sheet->getCell('H2')->getValue()) ?: null,
            'lines' => [], 'recorded' => '0.00', 'paid' => '0.00', 'unpaid' => 0,
        ];
        $lastDate = null;
        for ($row = 6; $row <= $sheet->getHighestDataRow(); $row++) {
            $id = trim((string) $sheet->getCell("A$row")->getValue());
            if ($id === 'LABOUR & SUBCONTRACT AGREEMENTS' || $id === 'SPEND BY STAGE (TSh)') {
                break;
            }
            if ($id === 'Transaction ID') {
                continue; // repeated header rows inside the register
            }
            $description = trim((string) $sheet->getCell("F$row")->getValue());
            $categoryText = trim((string) $sheet->getCell("D$row")->getValue());
            if ($description === '' && $categoryText === '') {
                continue;
            }
            $category = self::CATEGORIES[$this->key($categoryText)] ?? null;
            if (! $category) {
                $this->notes[] = "$title row $row: unknown category \"$categoryText\", recorded as Materials.";
                $category = 'materials';
            }
            $date = $this->date($sheet->getCell("B$row")->getValue());
            if (! $date) {
                $date = $lastDate ?? now()->toDateString();
                $this->notes[] = "$title row $row ($id): no date, used $date.";
            }
            $lastDate = $date;
            $quantity = $this->number($sheet->getCell("I$row")->getValue());
            $unitCost = $this->number($sheet->getCell("K$row")->getValue());
            $paidCell = $this->number($sheet->getCell("M$row")->getValue());
            $total = ($unitCost !== null) ? Money::product($unitCost, $quantity ?? '1') : ($paidCell ?? '0.00');
            $type = $this->key((string) $sheet->getCell("C$row")->getValue());
            $isExpense = $type === 'expense' || $this->key((string) $sheet->getCell("N$row")->getValue()) === 'paid';
            $paid = $isExpense ? ($paidCell ?? $total) : null;
            if ($paid !== null && Money::compare($paid, $total) > 0) {
                $this->notes[] = "$title row $row ($id): amount paid ".Money::format($paid).' is more than the line total '.Money::format($total).'; the total was raised to match.';
                $total = $paid;
            }
            $unitText = trim((string) $sheet->getCell("J$row")->getValue());
            $subText = trim((string) $sheet->getCell("E$row")->getValue());
            $data['lines'][] = [
                'id' => $id, 'date' => $date, 'category' => $category,
                'subcategory' => $subText === '' ? null : ($this->subcategories[$this->key($subText)] ?? $subText),
                'description' => Str::limit($description !== '' ? $description : $subText, 1000, ''),
                'supplier' => trim((string) $sheet->getCell("G$row")->getValue()) ?: null,
                'invoice' => trim((string) $sheet->getCell("H$row")->getValue()) ?: null,
                'quantity' => $quantity, 'unit' => $unitText === '' ? null : ($this->units[$this->key($unitText)] ?? Str::limit($unitText, 30, '')),
                'unit_cost' => $unitCost, 'total' => $total, 'paid' => $paid,
                'stage' => trim((string) $sheet->getCell("P$row")->getValue()) ?: 'Other / General',
                'evidence' => trim((string) $sheet->getCell("Q$row")->getValue()) ?: null,
            ];
            $data['recorded'] = Money::sum([$data['recorded'], $total]);
            $data['paid'] = Money::sum([$data['paid'], $paid ?? '0']);
            $data['unpaid'] += $paid === null ? 1 : 0;
        }

        return $data;
    }

    private function date(mixed $value): ?string
    {
        if (is_numeric($value)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return CarbonImmutable::createFromFormat('!d/m/Y', trim($value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function number(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = is_string($value) ? str_replace([',', ' '], '', $value) : $value;

        return is_numeric($clean) ? number_format(round((float) $clean, 2), 2, '.', '') : null;
    }

    private function key(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
