<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientInvoiceRequest;
use App\Http\Requests\StoreClientPaymentRequest;
use App\Http\Requests\StoreExpensePaymentRequest;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceItem;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Services\AccountantReportingService;
use App\Services\ClientInvoiceService;
use App\Services\ClientPaymentRecorder;
use App\Services\ExcelExportService;
use App\Services\ExpensePaymentRecorder;
use App\Services\ProjectManagerBudgetService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountantController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeWorkspace($request);
        $approved = $this->approvedQuery()->get();
        $approvedBatches = $this->requestBatches($approved);
        $awaiting = $approvedBatches->filter(fn ($batch) => Money::compare($batch->outstanding, '0') > 0);
        $paid = Money::sum($approved->flatMap(fn (ExpenseRequest $item) => $item->payments->pluck('amount')));
        $projects = Project::with(['client', 'activePhase', 'expenseRequests' => fn ($query) => $query->where('status', 'approved')->with('payments'), 'invoices.items', 'invoices.payments', 'expensePayments'])->orderBy('name')->get()->map(function (Project $project) {
            $requests = $project->expenseRequests->where('status', 'approved');
            $invoices = $project->invoices->where('status', 'issued');
            $actual = Money::sum($project->expensePayments->pluck('amount'));
            $received = Money::sum($project->invoices->flatMap(fn ($invoice) => $invoice->payments->pluck('amount')));

            return (object) ['project' => $project, 'request_count' => $requests->groupBy(fn ($item) => $item->request_batch_code ?: $item->request_code)->count(), 'expense_event_count' => $project->expensePayments->count(), 'actual' => $actual, 'invoiced' => Money::sum($invoices->map(fn ($invoice) => $invoice->total_amount)), 'received' => $received, 'payables' => Money::sum($requests->map(fn ($item) => $item->approved_outstanding_amount)), 'receivables' => Money::sum($invoices->map(fn ($invoice) => $invoice->outstanding_amount)), 'cash' => Money::difference($received, $actual)];
        });

        $allProjects = $projects;
        $dashboardReceived = Money::sum($allProjects->pluck('received'));
        $dashboardExpenses = Money::sum($allProjects->pluck('actual'));
        $dashboardReceivables = Money::sum($allProjects->pluck('receivables'));
        $dashboardPayables = Money::sum($allProjects->pluck('payables'));
        $overdueInvoices = $allProjects->sum(fn ($row) => $row->project->invoices->filter(fn (ClientInvoice $invoice) => $invoice->overdue)->count());

        return view('accountant.index', [
            'metrics' => [
                'active_projects' => $allProjects->filter(fn ($row) => $row->project->status === 'active')->count(),
                'client_received' => $dashboardReceived,
                'actual_expenses' => $dashboardExpenses,
                'cash_balance' => Money::difference($dashboardReceived, $dashboardExpenses),
                'receivables' => $dashboardReceivables,
                'payables' => $dashboardPayables,
                'approved_awaiting' => $awaiting->count(),
                'overdue_invoices' => $overdueInvoices,
                'part_paid' => $awaiting->filter(fn ($batch) => Money::compare($batch->paid, '0') > 0)->count(),
                'paid' => $approvedBatches->filter(fn ($batch) => $batch->payment_status === 'paid')->count(),
                'awaiting_amount' => Money::sum($awaiting->pluck('outstanding')),
                'payments_made' => $paid,
            ],
            'recentPayments' => ExpensePayment::with(['expenseRequest.project', 'recordedBy'])->latest()->limit(10)->get(), 'projects' => $projects,
        ]);
    }

    public function requests(Request $request)
    {
        $this->authorizeWorkspace($request);
        $query = $this->approvedQuery();
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }
        $batches = $this->requestBatches($query->get())->filter(function ($batch) use ($request): bool {
            return match ($request->input('filter', 'awaiting')) {
                'paid' => $batch->payment_status === 'paid',
                'part_paid' => $batch->payment_status === 'part_paid',
                'all' => true,
                default => $batch->payment_status !== 'paid',
            };
        });

        return view('accountant.requests', ['batches' => $batches, 'projects' => Project::orderBy('name')->get(), 'filter' => $request->input('filter', 'awaiting')]);
    }

    public function show(Request $request, ExpenseRequest $expenseRequest)
    {
        $this->authorizeWorkspace($request);
        $this->authorize('view', $expenseRequest);
        abort_unless($expenseRequest->status === 'approved', 404);
        $expenseRequest->load(['project', 'phase', 'internalBudgetEntry', 'supplierPayee.paymentDestinations', 'latestPmApproval.decidedBy', 'payments.recordedBy']);
        $batchCode = $expenseRequest->request_batch_code;
        $batchItems = $this->approvedQuery()
            ->where('project_id', $expenseRequest->project_id)
            ->when($expenseRequest->supplier_request_group_id,
                fn ($query) => $query->where('supplier_request_group_id', $expenseRequest->supplier_request_group_id),
                fn ($query) => $query->where(function ($nested) use ($batchCode, $expenseRequest) {
                    $batchCode ? $nested->where('request_batch_code', $batchCode) : $nested->whereKey($expenseRequest->id);
                }))
            ->orderBy('request_line_number')->get();
        $supplierGroups = $batchItems->groupBy(fn (ExpenseRequest $item) => $item->supplier_request_group_id ?: ($item->supplier_payee_id ?: ($item->latestPmApproval?->supplier_name_snapshot ?: 'unassigned')));

        return view('accountant.show', [
            'expenseRequest' => $expenseRequest,
            'batchItems' => $batchItems,
            'supplierGroups' => $supplierGroups,
            'batchTotals' => $this->financialTotals($batchItems),
            'paymentMethods' => Lookup::ofType('payment_method')->get(),
        ]);
    }

    public function record(StoreExpensePaymentRequest $request, ExpenseRequest $expenseRequest, ExpensePaymentRecorder $recorder)
    {
        $this->authorizeWorkspace($request);
        $this->authorize('recordPayment', $expenseRequest);
        abort_unless($expenseRequest->status === 'approved', 404);
        $recorder->record($expenseRequest, $request->validated(), $request->user(), $request->file('evidence'));

        return redirect()->route('accountant.requests.show', $expenseRequest)->with('success', 'Payment recorded as an actual ExpensePayment event.');
    }

    public function evidence(Request $request, ExpensePayment $expensePayment): StreamedResponse
    {
        $this->authorizeWorkspace($request);
        $this->authorize('view', $expensePayment);
        abort_unless($expensePayment->evidence_link && Storage::disk('local')->exists($expensePayment->evidence_link), 404);

        return Storage::disk('local')->download($expensePayment->evidence_link, $expensePayment->evidence_original_name ?? basename($expensePayment->evidence_link));
    }

    public function siteEvidence(Request $request, ExpenseRequest $expenseRequest): StreamedResponse
    {
        $this->authorizeWorkspace($request);
        $this->authorize('view', $expenseRequest);
        abort_unless($expenseRequest->evidence_link && Storage::disk('local')->exists($expenseRequest->evidence_link), 404);

        return Storage::disk('local')->download($expenseRequest->evidence_link, $expenseRequest->evidence_original_name ?? basename($expenseRequest->evidence_link));
    }

    public function project(Request $request, Project $project)
    {
        $this->authorizeWorkspace($request);
        $approved = $project->expenseRequests()->where('status', 'approved')->with(['payments', 'phase', 'latestApproval'])->get();
        $invoices = $project->invoices()->with(['items', 'payments'])->orderByDesc('created_at')->get();
        $estimate = $project->estimates()->where('status', 'issued')->with('entries')->orderByDesc('version')->first();
        $byPhase = $approved->groupBy('project_phase_id')->map(fn ($group) => $this->financialTotals($group));
        $byCategory = $approved->groupBy('main_category')->map(fn ($group) => $this->financialTotals($group));
        $invoiceTotal = Money::sum($invoices->where('status', 'issued')->map(fn ($invoice) => $invoice->total_amount));
        $received = Money::sum($invoices->flatMap(fn ($invoice) => $invoice->payments->pluck('amount')));
        $paid = Money::sum($project->expensePayments()->pluck('amount'));
        $approvedCommitments = Money::sum($approved->map(fn ($item) => $item->approved_payable_amount));
        $budget = app(ProjectManagerBudgetService::class)->projectBudget($project);
        $approvedBatches = $this->requestBatches($approved);
        $requestMetrics = ['approved' => $approvedBatches->count(), 'awaiting' => $approvedBatches->filter(fn ($batch) => Money::compare($batch->outstanding, '0') > 0)->count(), 'part_paid' => $approvedBatches->filter(fn ($batch) => $batch->payment_status === 'part_paid')->count(), 'paid' => $approvedBatches->filter(fn ($batch) => $batch->payment_status === 'paid')->count()];

        return view('accountant.project', compact('project', 'byPhase', 'byCategory', 'invoices', 'estimate', 'invoiceTotal', 'received', 'paid', 'approvedCommitments', 'budget', 'requestMetrics'));
    }

    public function invoices(Request $request)
    {
        $this->authorizeWorkspace($request);

        return view('accountant.invoices', ['invoices' => ClientInvoice::with(['project', 'client', 'items', 'payments'])
            ->when($request->integer('project_id'), fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->orderByDesc('created_at')->get()]);
    }

    public function createInvoice(Request $request, Project $project)
    {
        $this->authorizeWorkspace($request);
        $estimate = $request->boolean('from_estimate') ? $project->estimates()->where('status', 'issued')->with(['entries.phase'])->orderByDesc('version')->first() : null;
        if ($estimate) {
            $claimed = ClientInvoiceItem::query()->whereIn('estimate_entry_id', $estimate->entries->pluck('id'))
                ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'void'))
                ->selectRaw('estimate_entry_id, SUM(claim_amount) as claimed')->groupBy('estimate_entry_id')->pluck('claimed', 'estimate_entry_id');
            $estimate->entries->each(function ($entry) use ($claimed): void {
                $entry->setAttribute('claimed_amount', $claimed[$entry->id] ?? '0.00');
                $remaining = Money::difference($entry->line_total, $entry->claimed_amount);
                $entry->setAttribute('remaining_amount', Money::compare($remaining, '0') < 0 ? '0.00' : $remaining);
            });
        }

        return view('accountant.invoice-form', ['project' => $project->load(['client', 'phases']), 'estimate' => $estimate]);
    }

    public function storeInvoice(StoreClientInvoiceRequest $request, Project $project, ClientInvoiceService $invoices)
    {
        $this->authorizeWorkspace($request);
        $invoices->createDraft($project, $request->validated(), $request->user());

        return redirect()->route('accountant.projects.show', $project)->with('success', 'Draft invoice created.');
    }

    public function invoice(Request $request, ClientInvoice $clientInvoice)
    {
        $this->authorizeWorkspace($request);
        $clientInvoice->load(['project', 'client', 'items.phase', 'payments.recordedBy', 'issuedBy']);

        return view('accountant.invoice', ['clientInvoice' => $clientInvoice, 'paymentIdempotencyKey' => (string) Str::uuid()]);
    }

    public function exportInvoice(Request $request, ClientInvoice $clientInvoice, ExcelExportService $excel)
    {
        $this->authorizeWorkspace($request);
        $clientInvoice->load(['project', 'client', 'items.phase', 'issuedBy']);
        $rows = $clientInvoice->items->map(fn ($item) => [
            $item->phase?->stage_label, $item->item_description, $item->quantity ?? 1,
            $item->unit ?? 'LS', $item->unit_price, $item->line_total,
        ]);
        $rows->push(['', 'INVOICE TOTAL', '', '', '', $clientInvoice->total_amount]);

        return $excel->download($clientInvoice->invoice_code, 'Client Invoice - '.$clientInvoice->invoice_code,
            ['Phase', 'Description', 'Quantity', 'Unit', 'Unit Price (TSh)', 'Line Total (TSh)'],
            $rows, [
                'Project: '.$clientInvoice->project->name,
                'Client: '.$clientInvoice->client->name,
                'Reference: '.($clientInvoice->reference ?: 'Not set'),
                'Status: '.ucwords(str_replace('_', ' ', $clientInvoice->status)),
                'Invoice date: '.($clientInvoice->issued_at?->format('d M Y') ?? 'Draft'),
                'Due: '.($clientInvoice->due_at?->format('d M Y') ?? 'Not set'),
                'Received: TSh '.Money::format($clientInvoice->paid_to_date),
                'Outstanding: TSh '.Money::format($clientInvoice->outstanding_amount),
            ], [4, 5]);
    }

    public function issueInvoice(Request $request, ClientInvoice $clientInvoice, ClientInvoiceService $invoices)
    {
        $this->authorizeWorkspace($request);
        $invoices->issue($clientInvoice, $request->user());

        return back()->with('success', 'Invoice issued.');
    }

    public function voidInvoice(Request $request, ClientInvoice $clientInvoice, ClientInvoiceService $invoices)
    {
        $this->authorizeWorkspace($request);
        $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $invoices->void($clientInvoice, $request->input('reason'), $request->user());

        return back()->with('success', 'Invoice voided.');
    }

    public function clientPayment(StoreClientPaymentRequest $request, ClientInvoice $clientInvoice, ClientPaymentRecorder $payments)
    {
        $this->authorizeWorkspace($request);
        $payments->record($clientInvoice, $request->validated(), $request->user(), $request->file('evidence'));

        return back()->with('success', 'Client payment recorded.');
    }

    public function clientPaymentEvidence(Request $request, ClientPayment $clientPayment): StreamedResponse
    {
        $this->authorizeWorkspace($request);
        abort_unless($clientPayment->evidence_link && Storage::disk('local')->exists($clientPayment->evidence_link), 404);

        return Storage::disk('local')->download($clientPayment->evidence_link, $clientPayment->evidence_original_name ?? basename($clientPayment->evidence_link));
    }

    public function projects(Request $request)
    {
        $this->authorizeWorkspace($request);
        $projects = Project::withCount(['expenseRequests as approved_request_count' => fn ($query) => $query->where('status', 'approved')])->orderBy('name')->get();

        return view('accountant.projects', compact('projects'));
    }

    public function transactions(Request $request, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);
        $project = null;
        $events = $reporting->transactions(null, $request->only(['from', 'to', 'direction', 'phase', 'category']));
        $exportUrl = route('accountant.transactions.export');

        return view('accountant.transactions', compact('events', 'project', 'exportUrl'));
    }

    public function projectTransactions(Request $request, Project $project, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);
        $events = $reporting->transactions($project, $request->only(['from', 'to', 'direction', 'phase', 'category']));
        $exportUrl = route('accountant.projects.transactions.export', ['project' => $project->id]);

        return view('accountant.transactions', compact('events', 'project', 'exportUrl'));
    }

    public function receivables(Request $request, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);

        return view('accountant.receivables', ['invoices' => $reporting->receivables($request->only(['project_id'])), 'projects' => Project::orderBy('name')->get()]);
    }

    public function payables(Request $request, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);

        return view('accountant.payables', ['requests' => $reporting->payables($request->only(['project_id', 'category'])), 'projects' => Project::orderBy('name')->get()]);
    }

    public function statement(Request $request, Project $project, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);
        $statement = $reporting->statement($project, ['from' => $request->date('from'), 'to' => $request->date('to')]);

        return view('accountant.statement', compact('project', 'statement'));
    }

    public function exportTransactions(Request $request, AccountantReportingService $reporting, ExcelExportService $excel)
    {
        $this->authorizeWorkspace($request);

        return $excel->download('transactions', 'All Project Transactions', $this->transactionHeaders(), $this->transactionRows($reporting->transactions(null, $request->only(['from', 'to', 'direction', 'phase', 'category']))), [], [7, 8, 9], [0]);
    }

    public function exportProjectTransactions(Request $request, Project $project, AccountantReportingService $reporting, ExcelExportService $excel)
    {
        $this->authorizeWorkspace($request);

        return $excel->download($project->project_code.'-transactions', $project->name.' - Transaction Register', $this->transactionHeaders(), $this->transactionRows($reporting->transactions($project, $request->only(['from', 'to', 'direction', 'phase', 'category']))), [$project->project_code], [7, 8, 9], [0]);
    }

    public function exportReceivables(Request $request, AccountantReportingService $reporting, ExcelExportService $excel)
    {
        $this->authorizeWorkspace($request);

        return $excel->download('receivables', 'Receivables Register', ['Invoice', 'Project', 'Client', 'Invoice Date', 'Due Date', 'Invoice Total', 'Received', 'Outstanding', 'Age', 'Status'], $reporting->receivables($request->only(['project_id']))->map(fn ($invoice) => [$invoice->invoice_code, $invoice->project->name, $invoice->client->name, $invoice->issued_at, $invoice->due_at, $invoice->total_amount, $invoice->paid_to_date, $invoice->outstanding_amount, $invoice->due_at?->diffInDays(today()), $invoice->overdue ? 'Overdue' : 'Current']), [], [5, 6, 7], [3, 4]);
    }

    public function exportPayables(Request $request, AccountantReportingService $reporting, ExcelExportService $excel)
    {
        $this->authorizeWorkspace($request);

        return $excel->download('approved-payables', 'Approved Payables Register', ['Request', 'Project', 'Supplier', 'Approved At', 'Approved Amount', 'Paid', 'Outstanding', 'Payment Status', 'Age Since Approval'], $reporting->payables($request->only(['project_id', 'category']))->map(fn ($item) => [$item->request_code, $item->project->name, $item->supplierPayee?->name ?? $item->latestApproval?->supplier_name_snapshot, $item->latestApproval?->decided_at, $item->approved_payable_amount, $item->paid_to_date, $item->approved_outstanding_amount, $item->payment_status, $item->latestApproval?->decided_at?->diffInDays(today())]), [], [4, 5, 6], [3]);
    }

    private function transactionHeaders(): array
    {
        return ['Date', 'Reference', 'Project', 'Type', 'Description', 'Phase', 'Category', 'Money In', 'Money Out', 'Running Balance', 'Recorded By'];
    }

    private function transactionRows($events): array
    {
        return $events->map(fn ($event) => [$event['date']->toDateString(), $event['reference'], $event['source']->project?->name, $event['type'], $event['description'], $event['phase'] ?? '', $event['category'] ?? '', $event['in'], $event['out'], $event['balance'], $event['recorded_by']])->all();
    }

    private function approvedQuery()
    {
        return ExpenseRequest::query()->where('status', 'approved')->with(['project', 'phase', 'internalBudgetEntry', 'supplierRequestGroup', 'supplierPayee.paymentDestinations', 'latestPmApproval.decidedBy', 'payments.recordedBy']);
    }

    private function financialTotals($requests): array
    {
        return [
            'approved' => Money::sum($requests->map(fn (ExpenseRequest $item) => $item->approved_payable_amount)),
            'paid' => Money::sum($requests->map(fn (ExpenseRequest $item) => $item->paid_to_date)),
            'outstanding' => Money::sum($requests->map(fn (ExpenseRequest $item) => $item->approved_outstanding_amount)),
        ];
    }

    private function requestBatches($requests)
    {
        return collect($requests)->groupBy(fn (ExpenseRequest $item) => $item->supplier_request_group_id ? 'supplier-group-'.$item->supplier_request_group_id : ($item->request_batch_code ?: $item->request_code))
            ->map(function ($items, string $code) {
                $totals = $this->financialTotals($items);
                $paid = $totals['paid'];
                $outstanding = $totals['outstanding'];

                return (object) [
                    'code' => $items->first()->supplierRequestGroup?->group_code ?? $code,
                    'anchor' => $items->sortBy('request_line_number')->first(),
                    'items' => $items->sortBy('request_line_number')->values(),
                    'item_count' => $items->count(),
                    'supplier_count' => $items->map(fn (ExpenseRequest $item) => $item->supplier_payee_id ?: $item->latestPmApproval?->supplier_name_snapshot ?: 'unassigned')->unique()->count(),
                    'approved' => $totals['approved'],
                    'paid' => $paid,
                    'outstanding' => $outstanding,
                    'payment_status' => Money::compare($outstanding, '0') === 0 ? 'paid' : (Money::compare($paid, '0') > 0 ? 'part_paid' : 'unpaid'),
                ];
            })->values();
    }

    private function authorizeWorkspace(Request $request): void
    {
        abort_unless($request->user()->hasRole('accountant') || $request->user()->hasRole('accounts') || $request->user()->hasRole('admin'), 403);
    }
}
