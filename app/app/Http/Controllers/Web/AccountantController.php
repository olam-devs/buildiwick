<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientInvoiceRequest;
use App\Http\Requests\StoreClientPaymentRequest;
use App\Http\Requests\StoreExpensePaymentRequest;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Services\AccountantReportingService;
use App\Services\ClientInvoiceService;
use App\Services\ClientPaymentRecorder;
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
        $awaiting = $approved->filter(fn (ExpenseRequest $item) => Money::compare($item->approved_outstanding_amount, '0') > 0);
        $paid = Money::sum($approved->flatMap(fn (ExpenseRequest $item) => $item->payments->pluck('amount')));
        $projects = Project::with(['client', 'activePhase', 'expenseRequests.payments', 'invoices.items', 'invoices.payments', 'expensePayments'])->orderBy('name')->get()->map(function (Project $project) {
            $requests = $project->expenseRequests->where('status', '!=', 'cancelled');
            $invoices = $project->invoices->where('status', 'issued');
            $actual = Money::sum($project->expensePayments->pluck('amount'));
            $received = Money::sum($project->invoices->flatMap(fn ($invoice) => $invoice->payments->pluck('amount')));

            return (object) ['project' => $project, 'request_count' => $requests->count(), 'expense_event_count' => $project->expensePayments->count(), 'actual' => $actual, 'invoiced' => Money::sum($invoices->map(fn ($invoice) => $invoice->total_amount)), 'received' => $received, 'payables' => Money::sum($requests->where('status', 'approved')->map(fn ($item) => $item->approved_outstanding_amount)), 'receivables' => Money::sum($invoices->map(fn ($invoice) => $invoice->outstanding_amount)), 'cash' => Money::difference($received, $actual)];
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
                'part_paid' => $awaiting->filter(fn (ExpenseRequest $item) => Money::compare($item->paid_to_date, '0') > 0)->count(),
                'paid' => $approved->filter(fn (ExpenseRequest $item) => $item->payment_status === 'paid')->count(),
                'awaiting_amount' => Money::sum($awaiting->map(fn (ExpenseRequest $item) => $item->approved_outstanding_amount)),
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
        $requests = $query->get()->filter(function (ExpenseRequest $item) use ($request): bool {
            return match ($request->input('filter', 'awaiting')) {
                'paid' => $item->payment_status === 'paid',
                'part_paid' => $item->payment_status === 'part_paid',
                'all' => true,
                default => $item->payment_status !== 'paid',
            };
        });

        return view('accountant.requests', ['requests' => $requests, 'projects' => Project::orderBy('name')->get(), 'filter' => $request->input('filter', 'awaiting')]);
    }

    public function show(Request $request, ExpenseRequest $expenseRequest)
    {
        $this->authorizeWorkspace($request);
        $this->authorize('view', $expenseRequest);
        abort_unless($expenseRequest->status === 'approved', 404);
        $expenseRequest->load(['project', 'phase', 'requestedBy', 'supplierPayee', 'latestPmApproval.decidedBy', 'latestDirectorApproval.decidedBy', 'payments.recordedBy']);

        return view('accountant.show', [
            'expenseRequest' => $expenseRequest,
            'paymentMethods' => Lookup::ofType('payment_method')->get(),
            'paymentIdempotencyKey' => (string) Str::uuid(),
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
        $requests = $project->expenseRequests()->with(['payments', 'phase', 'latestApproval'])->get();
        $approved = $requests->where('status', 'approved');
        $invoices = $project->invoices()->with(['items', 'payments'])->orderByDesc('created_at')->get();
        $estimate = $project->estimates()->where('status', 'issued')->with('entries')->orderByDesc('version')->first();
        $byPhase = $approved->groupBy('project_phase_id')->map(fn ($group) => $this->financialTotals($group));
        $byCategory = $approved->groupBy('main_category')->map(fn ($group) => $this->financialTotals($group));
        $invoiceTotal = Money::sum($invoices->where('status', 'issued')->map(fn ($invoice) => $invoice->total_amount));
        $received = Money::sum($invoices->flatMap(fn ($invoice) => $invoice->payments->pluck('amount')));
        $paid = Money::sum($project->expensePayments()->pluck('amount'));
        $approvedCommitments = Money::sum($approved->map(fn ($item) => $item->approved_payable_amount));
        $budget = app(ProjectManagerBudgetService::class)->projectBudget($project);
        $requestMetrics = ['total' => $requests->where('status', '!=', 'cancelled')->count(), 'pending' => $requests->where('status', 'pending_approval')->count(), 'approved' => $approved->count(), 'rejected' => $requests->where('status', 'rejected')->count(), 'awaiting' => $approved->filter(fn ($item) => Money::compare($item->approved_outstanding_amount, '0') > 0)->count(), 'part_paid' => $approved->filter(fn ($item) => $item->payment_status === 'part_paid')->count(), 'paid' => $approved->filter(fn ($item) => $item->payment_status === 'paid')->count()];

        return view('accountant.project', compact('project', 'byPhase', 'byCategory', 'invoices', 'estimate', 'invoiceTotal', 'received', 'paid', 'approvedCommitments', 'budget', 'requestMetrics'));
    }

    public function invoices(Request $request)
    {
        $this->authorizeWorkspace($request);

        return view('accountant.invoices', ['invoices' => ClientInvoice::with(['project', 'client', 'items', 'payments'])->orderByDesc('created_at')->get()]);
    }

    public function createInvoice(Request $request, Project $project)
    {
        $this->authorizeWorkspace($request);
        $estimate = $request->boolean('from_estimate') ? $project->estimates()->where('status', 'issued')->with('entries')->orderByDesc('version')->first() : null;

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

    public function exportTransactions(Request $request, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);

        return $this->csv('transactions.csv', $this->transactionHeaders(), $this->transactionRows($reporting->transactions(null, $request->only(['from', 'to', 'direction', 'phase', 'category']))));
    }

    public function exportProjectTransactions(Request $request, Project $project, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);

        return $this->csv('project-transactions.csv', $this->transactionHeaders(), $this->transactionRows($reporting->transactions($project, $request->only(['from', 'to', 'direction', 'phase', 'category']))));
    }

    public function exportReceivables(Request $request, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);

        return $this->csv('receivables.csv', ['Invoice', 'Project', 'Client', 'Invoice Date', 'Due Date', 'Invoice Total', 'Received', 'Outstanding', 'Age', 'Status'], $reporting->receivables($request->only(['project_id']))->map(fn ($invoice) => [$invoice->invoice_code, $invoice->project->name, $invoice->client->name, $invoice->issued_at?->toDateString(), $invoice->due_at?->toDateString(), $invoice->total_amount, $invoice->paid_to_date, $invoice->outstanding_amount, $invoice->due_at?->diffInDays(today()), $invoice->overdue ? 'Overdue' : 'Current']));
    }

    public function exportPayables(Request $request, AccountantReportingService $reporting)
    {
        $this->authorizeWorkspace($request);

        return $this->csv('approved-payables.csv', ['Request', 'Project', 'Supplier', 'Approved At', 'Approved Amount', 'Paid', 'Outstanding', 'Payment Status', 'Age Since Approval'], $reporting->payables($request->only(['project_id', 'category']))->map(fn ($item) => [$item->request_code, $item->project->name, $item->supplierPayee?->name ?? $item->latestApproval?->supplier_name_snapshot, $item->latestApproval?->decided_at?->toDateString(), $item->approved_payable_amount, $item->paid_to_date, $item->approved_outstanding_amount, $item->payment_status, $item->latestApproval?->decided_at?->diffInDays(today())]));
    }

    private function csv(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            } fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
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
        return ExpenseRequest::query()->where('status', 'approved')->with(['project', 'phase', 'requestedBy', 'supplierPayee', 'latestPmApproval.decidedBy', 'latestDirectorApproval.decidedBy', 'payments']);
    }

    private function financialTotals($requests): array
    {
        return [
            'approved' => Money::sum($requests->map(fn (ExpenseRequest $item) => $item->approved_payable_amount)),
            'paid' => Money::sum($requests->map(fn (ExpenseRequest $item) => $item->paid_to_date)),
            'outstanding' => Money::sum($requests->map(fn (ExpenseRequest $item) => $item->approved_outstanding_amount)),
        ];
    }

    private function authorizeWorkspace(Request $request): void
    {
        abort_unless($request->user()->hasRole('accountant') || $request->user()->hasRole('accounts') || $request->user()->hasRole('admin'), 403);
    }
}
