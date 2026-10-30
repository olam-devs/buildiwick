<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DirectorKpiTarget;
use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\ProjectEditLog;
use App\Models\ProjectEstimate;
use App\Services\AccountantReportingService;
use App\Services\DirectorAnalyticsService;
use App\Services\DirectorKpiService;
use App\Services\ExcelExportService;
use App\Services\LedgerAnalyticsService;
use App\Services\ProjectManagerBudgetService;
use App\Services\ProjectWarningService;
use App\Support\Features;
use App\Support\Money;
use App\Support\ReviewPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DirectorController extends Controller
{
    public function requests(Request $request)
    {
        $this->authorize('view-director-analytics');
        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'request_type' => ['nullable', 'in:'.implode(',', array_keys(ExpenseRequest::TYPES))],
            'category' => ['nullable', 'in:materials,labour_subcontracts,logistics_equipment,preliminaries_overheads'],
            'status' => ['nullable', 'in:all,pending_approval,approved,rejected'],
        ]);
        $query = ExpenseRequest::query()
            ->with(['project', 'phase', 'labourAgreement', 'internalBudgetEntry', 'requestedBy', 'supplierPayee', 'commercialPaymentDestination', 'latestPmApproval.decidedBy'])
            ->where('status', '!=', 'cancelled')
            ->when($request->integer('project_id'), fn ($builder, int $projectId) => $builder->where('project_id', $projectId))
            ->when($filters['request_type'] ?? null, fn ($builder, string $type) => $builder->where('request_type', $type))
            ->when($filters['category'] ?? null, fn ($builder, string $category) => $builder->where('main_category', $category))
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($builder) => $builder->where('status', $filters['status']))
            ->orderByDesc('submitted_at');
        $requestBatches = $query->get()
            ->groupBy(fn (ExpenseRequest $item) => $item->request_batch_code ?: $item->request_code)
            ->map(function ($items, string $code) {
                $items = $items->sortBy('request_line_number')->values();

                return (object) [
                    'code' => $code,
                    'anchor' => $items->first(),
                    'items' => $items,
                    'item_count' => $items->count(),
                    'supplier_groups' => $items->groupBy(fn (ExpenseRequest $item) => $item->supplier_request_group_id ?: 'unassigned'),
                    'total' => Money::sum($items->map(fn (ExpenseRequest $item) => $item->commercial_total ?? '0')),
                    'statuses' => $items->pluck('status')->unique()->values(),
                ];
            })->values();

        return view('director.requests', [
            'requestBatches' => $requestBatches,
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function index(Request $request, DirectorAnalyticsService $analytics, ProjectWarningService $warnings, DirectorKpiService $kpis)
    {
        $this->authorize('view-director-analytics');
        if (Features::recording()) {
            return $this->recordingDashboard($request, app(LedgerAnalyticsService::class), $warnings, $kpis);
        }
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,active,planning,on_hold,completed'],
        ]);
        $range = $this->dateRange($request);
        $report = $analytics->portfolio($range['from'], $range['to']);
        $previousRange = ReviewPeriod::previous($range);
        $previousSummary = $analytics->portfolio($previousRange['from'], $previousRange['to'])['summary'];
        $periodSummary = $report['summary'];
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
            'estimates_awaiting' => ProjectEstimate::query()->where('status', 'submitted')->count(),
            'projects_with_warnings' => 0,
            'setup_incomplete' => Project::query()->with('assignedUsers.role')->get()->filter(function (Project $project): bool {
                $roles = $project->assignedUsers->map(fn ($user) => $user->role?->name)->filter();

                return ! $project->client_id
                    || ! $roles->contains('estimator_qs')
                    || ! $roles->contains('project_manager')
                    || ! $roles->contains('site_operator');
            })->count(),
        ];

        $projectWarnings = $report['projects']->mapWithKeys(fn (array $row) => [$row['project']->id => $warnings->evaluate($row)]);
        $actionCounts['projects_with_warnings'] = $projectWarnings->filter()->count();
        $today = CarbonImmutable::today();
        $currentKpis = $kpis->evaluate(DirectorKpiTarget::query()->whereNull('project_id')
            ->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)
            ->orderBy('metric')->limit(8)->get());

        return view('director.index', compact('projects', 'actionCounts', 'range', 'filters', 'previousRange', 'previousSummary', 'periodSummary', 'projectWarnings', 'currentKpis'));
    }

    public function project(Request $request, Project $project, DirectorAnalyticsService $analytics, ProjectWarningService $warnings)
    {
        $this->authorize('view-director-analytics');
        if (Features::recording()) {
            return redirect()->route('ledger.overview', $project);
        }
        $snapshotInput = $request->validate(['as_of' => ['nullable', 'date', 'before_or_equal:today']]);
        $range = $this->dateRange($request);
        $row = $analytics->project($project, $range['from'], $range['to']);
        $previousRange = ReviewPeriod::previous($range);
        $previousRow = $analytics->project($project, $previousRange['from'], $previousRange['to']);
        $projectWarnings = $warnings->evaluate($row);
        $asOf = isset($snapshotInput['as_of']) ? CarbonImmutable::parse($snapshotInput['as_of']) : null;
        $snapshot = $asOf ? $analytics->projectSnapshot($project, $asOf) : null;

        $pendingBudgets = $project->internalBudgets()->where('status', 'finalized')->with(['entries', 'createdBy', 'finalizedBy'])->orderByDesc('version')->get();
        $projectEstimate = $project->estimates()->whereIn('status', ['issued', 'superseded'])->with(['entries.phase', 'preparedBy', 'issuedBy'])->orderByDesc('version')->first()
            ?? $project->estimates()->with(['entries.phase', 'preparedBy', 'issuedBy'])->orderByDesc('version')->first();
        $openEstimate = $project->estimates()->whereIn('status', ['draft', 'submitted'])->with('submittedBy')->first();
        $projectBudgets = $project->internalBudgets()->whereIn('status', ['approved', 'finalized', 'draft'])
            ->with(['phase', 'entries.phase', 'createdBy', 'approvedBy'])->orderByDesc('version')->get()
            ->groupBy(fn (InternalBudget $budget) => $budget->project_phase_id.'|'.$budget->main_category)
            ->map(fn ($versions) => $versions->sortBy(fn ($budget) => ['approved' => 0, 'finalized' => 1, 'draft' => 2][$budget->status] ?? 3)->first())
            ->sortBy(fn ($budget) => [$budget->phase?->phase_order, array_search($budget->main_category, array_keys(ProjectManagerBudgetService::CATEGORIES), true)]);
        $editLogs = ProjectEditLog::where('project_id', $project->id)->with('editor')->latest()->get();
        $selectedEstimatePhaseId = $request->integer('estimate_phase') ?: null;
        if ($selectedEstimatePhaseId && ! $project->phases->contains('id', $selectedEstimatePhaseId)) {
            abort(404);
        }

        return view('director.project', compact('row', 'range', 'asOf', 'snapshot', 'pendingBudgets', 'projectEstimate', 'openEstimate', 'previousRange', 'previousRow', 'projectWarnings', 'projectBudgets', 'editLogs', 'selectedEstimatePhaseId'));
    }

    public function budget(Project $project, InternalBudget $budget)
    {
        $this->authorize('view-director-analytics');
        abort_unless((int) $budget->project_id === (int) $project->id, 404);
        $budget->load(['phase', 'entries.phase', 'createdBy', 'approvedBy']);

        return view('director.budget', [
            'project' => $project,
            'budget' => $budget,
            'categoryLabel' => ProjectManagerBudgetService::CATEGORIES[$budget->main_category] ?? $budget->main_category,
        ]);
    }

    public function decideBudget(Request $request, Project $project, InternalBudget $budget, ProjectManagerBudgetService $budgets)
    {
        abort_unless((int) $budget->project_id === (int) $project->id, 404);
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,return'],
            'notes' => ['nullable', 'string', 'max:2000', 'required_if:decision,return'],
        ]);
        if ($validated['decision'] === 'approve') {
            $budgets->approve($budget, $request->user(), $validated['notes'] ?? null);
            $message = 'Internal budget verified and activated as the approved baseline.';
        } else {
            $budgets->returnForEditing($budget, $request->user(), $validated['notes']);
            $message = 'Internal budget returned to the Project Manager for editing.';
        }

        return back()->with('success', $message);
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

    public function exportPortfolio(Request $request, DirectorAnalyticsService $analytics, ExcelExportService $excel)
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

        return $excel->download('director-project-portfolio', 'Director Project Portfolio', [
            'Project Code', 'Project', 'Client', 'Project Status', 'Current Phase', 'Estimated Client Value',
            'Internal Budget', 'Approved Commitments', 'Actual Expenses Paid', 'Invoiced', 'Client Payments Received',
            'Outstanding Receivables', 'Outstanding Approved Payables', 'Recorded Cash Balance',
        ], $rows, [$range['from']->format('d M Y').' to '.$range['to']->format('d M Y')], [5, 6, 7, 8, 9, 10, 11, 12, 13]);
    }

    public function exportSummary(Request $request, DirectorAnalyticsService $analytics, ExcelExportService $excel)
    {
        $this->authorize('view-director-analytics');
        $range = $this->dateRange($request);
        $summary = $analytics->portfolio($range['from'], $range['to'])['summary'];

        return $excel->download('director-financial-summary', 'Director Financial Summary', ['Metric', 'Amount / Count'], [
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
        ], [$range['from']->format('d M Y').' to '.$range['to']->format('d M Y')], [1]);
    }

    public function exportTransactions(Request $request, AccountantReportingService $reporting, ExcelExportService $excel)
    {
        $this->authorize('view-director-analytics');
        $range = $this->dateRange($request);
        $events = $reporting->transactions(null, ['from' => $range['from'], 'to' => $range['to']]);
        $rows = $events->map(fn ($event) => [
            $event['date']->toDateString(), $event['reference'], $event['source']->project?->name,
            $event['type'], $event['description'], $event['phase'] ?? '', $event['category'] ?? '',
            $event['in'], $event['out'], $event['balance'], $event['recorded_by'],
        ])->all();

        return $excel->download('director-transaction-register', 'Director Transaction Register', [
            'Date', 'Reference', 'Project', 'Type', 'Description', 'Phase', 'Category',
            'Money In', 'Money Out', 'Running Balance', 'Recorded By',
        ], $rows, [$range['from']->format('d M Y').' to '.$range['to']->format('d M Y')], [7, 8, 9], [0]);
    }

    /** Recording-mode company dashboard: spending only, compared with the previous period. */
    private function recordingDashboard(Request $request, LedgerAnalyticsService $ledger, ProjectWarningService $warnings, DirectorKpiService $kpis)
    {
        $range = $this->dateRange($request);
        $previousRange = ReviewPeriod::previous($range);
        $projects = Project::query()->orderBy('name')->get();
        $portfolio = $ledger->portfolio($projects, $range['from'], $range['to']);
        $previous = $ledger->portfolio($projects, $previousRange['from'], $previousRange['to'])['totals'];
        $attention = $projects->map(fn (Project $project) => ['project' => $project, 'warnings' => $warnings->evaluateLedger($ledger->project($project))])
            ->filter(fn ($row) => $row['warnings'])->values();
        $today = CarbonImmutable::today();
        $companyKpis = $kpis->evaluate(DirectorKpiTarget::query()->whereNull('project_id')
            ->whereDate('period_start', '<=', $today)->whereDate('period_end', '>=', $today)->orderBy('metric')->get());

        return view('director.recording-dashboard', compact('portfolio', 'previous', 'range', 'previousRange', 'attention', 'companyKpis'));
    }

    private function dateRange(Request $request): array
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:'.implode(',', array_keys(ReviewPeriod::PRESETS))],
            'from' => ['required_if:range,custom', 'nullable', 'date'],
            'to' => ['required_if:range,custom', 'nullable', 'date', 'after_or_equal:from'],
        ]);

        return ReviewPeriod::resolve($validated['range'] ?? 'this_year', $validated['from'] ?? null, $validated['to'] ?? null);
    }
}
