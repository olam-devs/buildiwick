<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Services\AccountantReportingService;
use App\Services\DirectorAnalyticsService;
use App\Services\ExpenseRequestDecisionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DirectorController extends Controller
{
    public function requests(Request $request, ExpenseRequestDecisionService $decisions)
    {
        $this->authorize('view-director-analytics');
        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'request_type' => ['nullable', 'in:'.implode(',', array_keys(ExpenseRequest::TYPES))],
            'category' => ['nullable', 'in:materials,labour_subcontracts,logistics_equipment,preliminaries_overheads'],
        ]);
        $query = ExpenseRequest::query()
            ->with(['project', 'phase', 'labourAgreement', 'requestedBy', 'supplierPayee', 'commercialPaymentDestination', 'latestPmApproval.decidedBy'])
            ->where('status', 'pending_director_approval')
            ->when($request->integer('project_id'), fn ($builder, int $projectId) => $builder->where('project_id', $projectId))
            ->when($filters['request_type'] ?? null, fn ($builder, string $type) => $builder->where('request_type', $type))
            ->when($filters['category'] ?? null, fn ($builder, string $category) => $builder->where('main_category', $category))
            ->orderBy('submitted_at');
        $requests = $query->get();
        $impacts = $requests->mapWithKeys(fn (ExpenseRequest $item) => [$item->id => $decisions->impact($item)]);

        return view('director.requests', [
            'requests' => $requests,
            'impacts' => $impacts,
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function decideRequest(Request $request, ExpenseRequest $expenseRequest, ExpenseRequestDecisionService $decisions)
    {
        $this->authorize('view-director-analytics');
        abort_unless($request->user()->hasRole('director'), 403);
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'notes' => ['required', 'string', 'max:4000'],
        ]);
        $decisions->decideAsDirector($expenseRequest, $validated['decision'], $validated['notes'], $request->user());
        $message = $validated['decision'] === 'approve'
            ? 'Director approval recorded. The request is now available to Accounts for payment.'
            : 'Director rejection recorded. The request was not sent to Accounts.';

        return redirect()->route('director.requests.index')->with('success', $message);
    }

    public function index(Request $request, DirectorAnalyticsService $analytics)
    {
        $this->authorize('view-director-analytics');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,active,planning,on_hold,completed'],
        ]);
        $range = $this->dateRange($request);
        $report = $analytics->portfolio($range['from'], $range['to']);
        $projects = $report['projects']
            ->when(($filters['search'] ?? null), function ($rows, string $search) {
                $needle = mb_strtolower($search);

                return $rows->filter(fn ($row) => str_contains(mb_strtolower(implode(' ', [
                    $row['project']->name,
                    $row['project']->project_code,
                    $row['project']->client?->name,
                ])), $needle));
            })
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($rows) => $rows->where('project.status', $filters['status']))
            ->values();
        $actionCounts = [
            'over_budget' => $report['projects']->where('over_budget', true)->count(),
            'overdue_invoices' => $report['summary']['overdue_invoice_count'],
            'overdue_receivables' => $report['summary']['overdue_receivables'],
        ];

        return view('director.index', compact('projects', 'actionCounts', 'range', 'filters'));
    }

    public function project(Request $request, Project $project, DirectorAnalyticsService $analytics)
    {
        $this->authorize('view-director-analytics');
        $range = $this->dateRange($request);
        $row = $analytics->project($project, $range['from'], $range['to']);

        return view('director.project', compact('row', 'range'));
    }

    public function receivables(Request $request, DirectorAnalyticsService $analytics)
    {
        $this->authorize('view-director-analytics');
        $request->validate(['project_id' => ['nullable', 'integer', 'exists:projects,id']]);

        return view('director.receivables', [
            'receivables' => $analytics->receivables($request->integer('project_id') ?: null),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function payables(Request $request, DirectorAnalyticsService $analytics)
    {
        $this->authorize('view-director-analytics');
        $request->validate(['project_id' => ['nullable', 'integer', 'exists:projects,id']]);

        return view('director.payables', [
            'payables' => $analytics->payables($request->integer('project_id') ?: null),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function transactions(Request $request, AccountantReportingService $reporting)
    {
        $this->authorize('view-director-analytics');
        $range = $this->dateRange($request);
        $events = $reporting->transactions(null, ['from' => $range['from'], 'to' => $range['to']]);

        return view('director.transactions', compact('events', 'range'));
    }

    public function exportPortfolio(Request $request, DirectorAnalyticsService $analytics): StreamedResponse
    {
        $this->authorize('view-director-analytics');
        $range = $this->dateRange($request);
        $report = $analytics->portfolio($range['from'], $range['to']);
        $rows = $report['projects']->map(fn ($row) => [
            $row['project']->project_code, $row['project']->name, $row['project']->client?->name,
            $row['project']->status, $row['current_phase'], $row['estimated_client_value'], $row['budget'],
            $row['approved_commitments'], $row['actual_expenses'], $row['invoiced_current'],
            $row['received_current'], $row['receivables_current'], $row['payables_current'], $row['cash_balance'],
        ])->all();

        return $this->csv('director-project-portfolio.csv', [
            'Project Code', 'Project', 'Client', 'Project Status', 'Current Phase', 'Estimated Client Value',
            'Internal Budget', 'Approved Commitments', 'Actual Expenses Paid', 'Invoiced', 'Client Payments Received',
            'Outstanding Receivables', 'Outstanding Approved Payables', 'Recorded Cash Balance',
        ], $rows);
    }

    public function exportSummary(Request $request, DirectorAnalyticsService $analytics): StreamedResponse
    {
        $this->authorize('view-director-analytics');
        $range = $this->dateRange($request);
        $summary = $analytics->portfolio($range['from'], $range['to'])['summary'];

        return $this->csv('director-financial-summary.csv', ['Metric', 'Amount / Count'], [
            ['Active Projects', $summary['active_projects']],
            ['Estimated Client Value (current)', $summary['estimated_client_value']],
            ['Invoiced During Period', $summary['invoiced_period']],
            ['Client Payments Received During Period', $summary['received_period']],
            ['Expense Payments During Period', $summary['expenses_period']],
            ['Current Recorded Cash Balance (not profit)', $summary['cash_balance']],
            ['Current Outstanding Receivables', $summary['receivables']],
            ['Current Outstanding Approved Payables', $summary['payables']],
            ['Overdue Invoice Count', $summary['overdue_invoice_count']],
            ['Overdue Receivables', $summary['overdue_receivables']],
        ]);
    }

    public function exportTransactions(Request $request, AccountantReportingService $reporting): StreamedResponse
    {
        $this->authorize('view-director-analytics');
        $range = $this->dateRange($request);
        $events = $reporting->transactions(null, ['from' => $range['from'], 'to' => $range['to']]);
        $rows = $events->map(fn ($event) => [
            $event['date']->toDateString(), $event['reference'], $event['source']->project?->name,
            $event['type'], $event['description'], $event['phase'] ?? '', $event['category'] ?? '',
            $event['in'], $event['out'], $event['balance'], $event['recorded_by'],
        ])->all();

        return $this->csv('director-transaction-register.csv', [
            'Date', 'Reference', 'Project', 'Type', 'Description', 'Phase', 'Category',
            'Money In', 'Money Out', 'Running Balance', 'Recorded By',
        ], $rows);
    }

    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:this_month,last_3_months,this_year,custom'],
            'from' => ['required_if:range,custom', 'nullable', 'date'],
            'to' => ['required_if:range,custom', 'nullable', 'date', 'after_or_equal:from'],
        ]);
        $preset = $validated['range'] ?? 'this_year';
        $today = CarbonImmutable::today();

        [$from, $to] = match ($preset) {
            'this_month' => [$today->startOfMonth(), $today],
            'last_3_months' => [$today->startOfMonth()->subMonths(2), $today],
            'custom' => [CarbonImmutable::parse($validated['from']), CarbonImmutable::parse($validated['to'])],
            default => [$today->startOfYear(), $today],
        };

        return ['name' => $preset, 'from' => $from, 'to' => $to];
    }

    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
