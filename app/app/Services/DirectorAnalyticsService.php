<?php

namespace App\Services;

use App\Models\ClientInvoice;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Read-only organization and project projections over the existing workflow records. */
class DirectorAnalyticsService
{
    private ?Collection $phaseLabels = null;

    private const PHASES = [
        'preliminaries', 'substructure', 'superstructure', 'walling',
        'roofing', 'finishing', 'external_works',
    ];

    public function portfolio(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $projects = $this->loadProjects()->map(fn (Project $project) => $this->projectRow($project, $from, $to));

        return $this->report($projects, $from, $to);
    }

    public function project(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $loaded = $this->loadProjects($project->id)->first();
        abort_unless($loaded, 404);

        return $this->projectRow($loaded, $from, $to);
    }

    public function projectSnapshot(Project $project, CarbonImmutable $asOf): array
    {
        $loaded = $this->loadProjects($project->id)->first();
        abort_unless($loaded, 404);

        $cutoff = $asOf->endOfDay();
        $labels = $this->phaseLabels ??= Lookup::ofType('construction_stage')->pluck('label', 'code');
        $estimate = $loaded->estimates
            ->where('status', 'issued')
            ->filter(fn ($item) => CarbonImmutable::parse($item->issued_at ?? $item->created_at)->lte($cutoff))
            ->sortByDesc('version')->first();
        $budgetRecord = $loaded->internalBudgets
            ->whereIn('status', ['finalized', 'approved'])
            ->filter(fn ($item) => CarbonImmutable::parse($item->finalized_at ?? $item->approved_at ?? $item->created_at)->lte($cutoff))
            ->sortByDesc('version')->first();
        $approvedRequests = $loaded->expenseRequests->where('status', 'approved')->filter(function (ExpenseRequest $request) use ($cutoff) {
            $approvedAt = $request->latestPmApproval?->decided_at ?? $request->submitted_at ?? $request->requested_at ?? $request->created_at;

            return $approvedAt && CarbonImmutable::parse($approvedAt)->lte($cutoff);
        });
        $expensePayments = $loaded->expensePayments->filter(fn ($payment) => CarbonImmutable::parse($payment->payment_date)->lte($cutoff));
        $clientPayments = $loaded->clientPayments->filter(fn ($payment) => CarbonImmutable::parse($payment->payment_date)->lte($cutoff));
        $issuedInvoices = $loaded->invoices->where('status', 'issued')
            ->filter(fn (ClientInvoice $invoice) => $invoice->issued_at && CarbonImmutable::parse($invoice->issued_at)->lte($cutoff));

        $invoiced = Money::sum($issuedInvoices->map(fn (ClientInvoice $invoice) => $invoice->total_amount));
        $received = Money::sum($clientPayments->pluck('amount'));
        $expenses = Money::sum($expensePayments->pluck('amount'));
        $commitments = Money::sum($approvedRequests->map(fn (ExpenseRequest $request) => $request->approved_payable_amount));
        $budget = $budgetRecord?->total_amount ?? '0.00';
        $phaseStates = $loaded->phases->sortBy('phase_order')->map(function ($phase) use ($cutoff) {
            $started = $phase->actual_start_date ?? $phase->started_at;
            $completed = $phase->actual_completion_date ?? $phase->completed_at;
            $status = $completed && CarbonImmutable::parse($completed)->lte($cutoff)
                ? 'completed'
                : (($started && CarbonImmutable::parse($started)->lte($cutoff)) ? 'active' : 'not_started');

            return [
                'label' => $phase->stage_label,
                'status' => $status,
            ];
        })->values();

        $receivables = Money::difference($invoiced, $received);

        return [
            'as_of' => $asOf,
            'current_phase' => $phaseStates->firstWhere('status', 'active')['label'] ?? null,
            'completed_phases' => $phaseStates->where('status', 'completed')->count(),
            'phase_count' => $phaseStates->count(),
            'estimated_client_value' => $estimate?->total_amount ?? '0.00',
            'budget' => $budget,
            'approved_commitments' => $commitments,
            'actual_expenses' => $expenses,
            'invoiced' => $invoiced,
            'received' => $received,
            'receivables' => Money::compare($receivables, '0') < 0 ? '0.00' : $receivables,
            'cash_balance' => Money::difference($received, $expenses),
            'phase_states' => $phaseStates,
        ];
    }

    public function receivables(?int $projectId = null): Collection
    {
        return $this->loadProjects()->when($projectId, fn (Collection $projects) => $projects->where('id', $projectId))
            ->flatMap(function (Project $project) {
                return $project->invoices->where('status', 'issued')->map(function (ClientInvoice $invoice) use ($project) {
                    $total = $invoice->total_amount;
                    $received = $invoice->paid_to_date;
                    $outstanding = $invoice->outstanding_amount;
                    if (Money::compare($outstanding, '0') <= 0) {
                        return null;
                    }

                    $due = $invoice->due_at ? CarbonImmutable::parse($invoice->due_at) : null;
                    $overdue = $invoice->overdue;

                    return [
                        'invoice' => $invoice,
                        'project' => $project,
                        'total' => $total,
                        'received' => $received,
                        'outstanding' => $outstanding,
                        'overdue' => $overdue,
                        'age_days' => $due && $overdue ? $due->diffInDays(today()) : 0,
                    ];
                })->filter();
            })->values();
    }

    public function payables(?int $projectId = null): Collection
    {
        return $this->loadProjects()->when($projectId, fn (Collection $projects) => $projects->where('id', $projectId))
            ->flatMap(function (Project $project) {
                return $project->expenseRequests->where('status', 'approved')->map(function (ExpenseRequest $request) use ($project) {
                    $approved = $request->approved_payable_amount;
                    $paid = $request->paid_to_date;
                    $outstanding = $request->approved_outstanding_amount;
                    if (Money::compare($outstanding, '0') <= 0) {
                        return null;
                    }

                    return ['request' => $request, 'project' => $project, 'approved' => $approved, 'paid' => $paid, 'outstanding' => $outstanding];
                })->filter();
            })->values();
    }

    private function report(Collection $projects, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $receivables = $projects->flatMap(fn ($row) => $row['receivable_rows']);
        $payables = $projects->flatMap(fn ($row) => $row['payable_rows']);
        $summary = [
            'active_projects' => $projects->filter(fn ($row) => $row['project']->status === 'active')->count(),
            'estimated_client_value' => Money::sum($projects->pluck('estimated_client_value')),
            'invoiced_period' => Money::sum($projects->pluck('invoiced_period')),
            'received_period' => Money::sum($projects->pluck('received_period')),
            'expenses_period' => Money::sum($projects->pluck('expenses_period')),
            'cash_balance' => Money::sum($projects->pluck('cash_balance')),
            'receivables' => Money::sum($receivables->pluck('outstanding')),
            'payables' => Money::sum($payables->pluck('outstanding')),
            'overdue_invoice_count' => $receivables->where('overdue', true)->count(),
            'overdue_receivables' => Money::sum($receivables->where('overdue', true)->pluck('outstanding')),
            'invoiced_current' => Money::sum($projects->pluck('invoiced_current')),
            'received_current' => Money::sum($projects->pluck('received_current')),
            'expenses_current' => Money::sum($projects->pluck('actual_expenses')),
        ];

        $categoryCharts = collect(ProjectManagerBudgetService::CATEGORIES)->map(function (string $label, string $code) use ($projects) {
            return ['key' => $code, 'label' => $label, 'actual' => Money::sum($projects->map(fn ($row) => $row['category_analytics'][$code]['actual'] ?? '0.00'))];
        })->values();
        $phaseCharts = $projects->flatMap(fn ($row) => $row['phase_analytics'])->groupBy('stage_code')->map(function ($rows, $stage) {
            return ['key' => $stage, 'label' => $rows->first()['label'], 'actual' => Money::sum($rows->pluck('actual'))];
        })->values();

        return [
            'projects' => $projects->values(),
            'summary' => $summary,
            'categories' => $categoryCharts,
            'phases' => $phaseCharts,
            'project_chart' => $projects->map(fn ($row) => [
                'name' => $row['project']->name,
                'budget' => $row['budget'],
                'approved' => $row['approved_commitments'],
                'actual' => $row['actual_expenses'],
                'invoiced' => $row['invoiced_current'],
                'received' => $row['received_current'],
            ])->values(),
            'trend' => $this->cashTrend($projects, $from, $to),
            'from' => $from,
            'to' => $to,
        ];
    }

    private function projectRow(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $labels = $this->phaseLabels ??= Lookup::ofType('construction_stage')->pluck('label', 'code');
        $estimate = $project->estimates->where('status', 'issued')->sortByDesc('version')->first();
        $estimateEntries = $estimate?->entries ?? collect();
        $estimateValue = $estimate?->total_amount ?? '0.00';

        $draftBudgets = $project->internalBudgets->where('status', 'draft')->sortByDesc('version');
        if ($draftBudgets->count() > 1) {
            throw ValidationException::withMessages(['budget' => 'This project has multiple working budgets; administrator review is required.']);
        }
        $budgetRecord = $draftBudgets->first() ?? $project->internalBudgets
            ->whereIn('status', ['finalized', 'approved'])->sortByDesc('version')->first();
        $budgetEntries = $budgetRecord?->entries ?? collect();
        $budget = $budgetRecord?->total_amount ?? '0.00';

        $approvedRequests = $project->expenseRequests->where('status', 'approved');
        $approvedCommitments = Money::sum($approvedRequests->map(fn (ExpenseRequest $request) => $request->approved_payable_amount));
        $actualExpenses = Money::sum($project->expensePayments->pluck('amount'));
        $clientReceived = Money::sum($project->clientPayments->pluck('amount'));

        $issuedInvoices = $project->invoices->where('status', 'issued');
        $receivableRows = $issuedInvoices->map(function (ClientInvoice $invoice) use ($project) {
            $total = $invoice->total_amount;
            $received = $invoice->paid_to_date;
            $outstanding = $invoice->outstanding_amount;
            if (Money::compare($outstanding, '0') <= 0) {
                return null;
            }

            return ['invoice' => $invoice, 'project' => $project, 'total' => $total, 'received' => $received, 'outstanding' => $outstanding, 'overdue' => $invoice->overdue];
        })->filter()->values();
        $payableRows = $approvedRequests->map(function (ExpenseRequest $request) use ($project) {
            $approved = $request->approved_payable_amount;
            $paid = $request->paid_to_date;
            $outstanding = $request->approved_outstanding_amount;
            if (Money::compare($outstanding, '0') <= 0) {
                return null;
            }

            return ['request' => $request, 'project' => $project, 'approved' => $approved, 'paid' => $paid, 'outstanding' => $outstanding];
        })->filter()->values();

        $periodInvoices = $issuedInvoices->filter(fn (ClientInvoice $invoice) => $this->within($invoice->issued_at, $from, $to));
        $receivedPeriod = $project->clientPayments->filter(fn ($payment) => $this->within($payment->payment_date, $from, $to));
        $expensePeriod = $project->expensePayments->filter(fn (ExpensePayment $payment) => $this->within($payment->payment_date, $from, $to));
        $invoicedCurrent = Money::sum($issuedInvoices->map(fn (ClientInvoice $invoice) => $invoice->total_amount));

        $phaseAnalytics = [];
        foreach ($project->phases->sortBy('phase_order') as $phase) {
            $phaseEstimateEntries = $estimateEntries->where('project_phase_id', $phase->id);
            $phaseBudgetEntries = $budgetEntries->where('project_phase_id', $phase->id);
            $phaseRequests = $approvedRequests->where('project_phase_id', $phase->id);
            $phasePayments = $project->expensePayments->where('project_phase_id', $phase->id);
            $phaseBudget = Money::sum($phaseBudgetEntries->map(fn ($entry) => $entry->line_total));
            $phaseCommitments = Money::sum($phaseRequests->map(fn (ExpenseRequest $request) => $request->approved_payable_amount));
            $phaseActual = Money::sum($phasePayments->pluck('amount'));
            $phaseAnalytics[] = [
                'id' => $phase->id, 'stage_code' => $phase->stage_code,
                'label' => $phase->stage_label,
                'status' => $phase->status,
                'estimator_base' => Money::sum($phaseEstimateEntries->map(fn ($entry) => $entry->base_total)),
                'estimator_client' => Money::sum($phaseEstimateEntries->map(fn ($entry) => $entry->line_total)),
                'budget' => $phaseBudget,
                'approved' => $phaseCommitments, 'actual' => $phaseActual,
                'remaining' => Money::difference($phaseBudget, $phaseActual),
                'has_budget_data' => $phaseBudgetEntries->isNotEmpty(),
                'has_commitment_data' => $phaseRequests->isNotEmpty(),
                'has_actual_data' => $phasePayments->isNotEmpty(),
            ];
        }

        $categoryAnalytics = [];
        $subcategoryLabels = Lookup::where('type', 'subcategory')->pluck('label', 'code');
        foreach (ProjectManagerBudgetService::CATEGORIES as $code => $label) {
            $categoryEstimateEntries = $estimateEntries->where('main_category', $code);
            $categoryBudgetEntries = $budgetEntries->where('main_category', $code);
            $categoryRequests = $approvedRequests->where('main_category', $code);
            $categoryPayments = $project->expensePayments->filter(fn (ExpensePayment $payment) => $payment->expenseRequest?->main_category === $code);
            $categoryBudget = Money::sum($categoryBudgetEntries->map(fn ($entry) => $entry->line_total));
            $categoryCommitments = Money::sum($categoryRequests->map(fn (ExpenseRequest $request) => $request->approved_payable_amount));
            $categoryActual = Money::sum($categoryPayments->pluck('amount'));
            $subcategoryKeys = $categoryEstimateEntries->pluck('subcategory')
                ->merge($categoryBudgetEntries->pluck('subcategory'))
                ->merge($categoryRequests->pluck('subcategory'))
                ->merge($categoryPayments->map(fn (ExpensePayment $payment) => $payment->expenseRequest?->subcategory))
                ->map(fn ($value) => $value ?: '__other')->unique()->values();
            $subcategories = $subcategoryKeys->map(function (string $key) use ($categoryEstimateEntries, $categoryBudgetEntries, $categoryRequests, $categoryPayments, $subcategoryLabels) {
                $matches = fn ($value) => ($value ?: '__other') === $key;
                $estimateGroup = $categoryEstimateEntries->filter(fn ($entry) => $matches($entry->subcategory));
                $budgetGroup = $categoryBudgetEntries->filter(fn ($entry) => $matches($entry->subcategory));
                $requestGroup = $categoryRequests->filter(fn ($request) => $matches($request->subcategory));
                $paymentGroup = $categoryPayments->filter(fn (ExpensePayment $payment) => $matches($payment->expenseRequest?->subcategory));
                $internal = Money::sum($budgetGroup->map(fn ($entry) => $entry->line_total));
                $actual = Money::sum($paymentGroup->pluck('amount'));

                return [
                    'key' => $key,
                    'label' => $key === '__other' ? 'Other / Unclassified' : ($subcategoryLabels[$key] ?? ucwords(str_replace('_', ' ', $key))),
                    'estimator_base' => Money::sum($estimateGroup->map(fn ($entry) => $entry->base_total)),
                    'estimator_client' => Money::sum($estimateGroup->map(fn ($entry) => $entry->line_total)),
                    'budget' => $internal,
                    'approved' => Money::sum($requestGroup->map(fn (ExpenseRequest $request) => $request->approved_payable_amount)),
                    'actual' => $actual,
                    'remaining' => Money::difference($internal, $actual),
                    'utilization' => Money::compare($internal, '0') === 0 ? null : Money::percentage($actual, $internal),
                ];
            })->values();
            $categoryAnalytics[$code] = [
                'label' => $label,
                'estimator_base' => Money::sum($categoryEstimateEntries->map(fn ($entry) => $entry->base_total)),
                'estimator_client' => Money::sum($categoryEstimateEntries->map(fn ($entry) => $entry->line_total)),
                'budget' => $categoryBudget,
                'approved' => $categoryCommitments, 'actual' => $categoryActual,
                'remaining' => Money::difference($categoryBudget, $categoryActual),
                'utilization' => Money::compare($categoryBudget, '0') === 0 ? null : Money::percentage($categoryActual, $categoryBudget),
                'has_budget_data' => $categoryBudgetEntries->isNotEmpty(),
                'subcategories' => $subcategories,
            ];
        }

        $standardPhases = $project->phases;
        $completedPhases = $standardPhases->where('status', 'completed')->count();
        $activePhase = $project->activePhase;
        $budgetUtilization = Money::compare($budget, '0') === 0 ? null : Money::percentage($actualExpenses, $budget);
        $commitmentUtilization = Money::compare($budget, '0') === 0 ? null : Money::percentage($approvedCommitments, $budget);
        $phaseHistory = $standardPhases->sortBy('phase_order')->map(function ($phase) {
            $started = $phase->actual_start_date ?? $phase->started_at;
            $completed = $phase->actual_completion_date ?? $phase->completed_at;

            return [
                'label' => $phase->stage_label,
                'status' => $phase->status,
                'started_at' => $started,
                'completed_at' => $completed,
                'duration_days' => $started && $completed
                    ? CarbonImmutable::parse($started)->diffInDays(CarbonImmutable::parse($completed)) + 1
                    : null,
            ];
        })->values();

        return [
            'project' => $project,
            'current_phase' => $activePhase ? ($labels[$activePhase->stage_code] ?? $activePhase->stage_code) : null,
            'completed_phases' => $completedPhases,
            'phase_count' => $standardPhases->count(),
            'estimated_client_value' => $estimateValue,
            'budget' => $budget,
            'approved_commitments' => $approvedCommitments,
            'actual_expenses' => $actualExpenses,
            'invoiced_current' => $invoicedCurrent,
            'received_current' => $clientReceived,
            'receivables_current' => Money::sum($receivableRows->pluck('outstanding')),
            'payables_current' => Money::sum($payableRows->pluck('outstanding')),
            'cash_balance' => Money::difference($clientReceived, $actualExpenses),
            'budget_remaining_actual' => Money::difference($budget, $actualExpenses),
            'commitment_headroom' => Money::difference($budget, $approvedCommitments),
            'actual_utilization' => $budgetUtilization,
            'committed_utilization' => $commitmentUtilization,
            'no_budget' => Money::compare($budget, '0') === 0,
            'over_budget' => Money::compare($budget, '0') > 0 && Money::compare($approvedCommitments, $budget) > 0,
            'invoiced_period' => Money::sum($periodInvoices->map(fn (ClientInvoice $invoice) => $invoice->total_amount)),
            'received_period' => Money::sum($receivedPeriod->pluck('amount')),
            'expenses_period' => Money::sum($expensePeriod->pluck('amount')),
            'category_analytics' => $categoryAnalytics,
            'phase_analytics' => $phaseAnalytics,
            'phase_history' => $phaseHistory,
            'receivable_rows' => $receivableRows,
            'payable_rows' => $payableRows,
        ];
    }

    private function loadProjects(?int $projectId = null): Collection
    {
        return Project::query()->with([
            'client', 'activePhase', 'phases', 'estimates.entries', 'internalBudgets.entries',
            'expenseRequests.phase', 'expenseRequests.payments', 'expenseRequests.latestPmApproval', 'expensePayments.expenseRequest', 'clientPayments',
            'invoices.client', 'invoices.items', 'invoices.payments',
        ])->when($projectId, fn ($query) => $query->whereKey($projectId))->orderBy('name')->get();
    }

    private function cashTrend(Collection $projects, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $months = [];
        $cursor = $from->startOfMonth();
        $last = $to->startOfMonth();
        while ($cursor->lte($last)) {
            $months[$cursor->format('Y-m')] = ['month' => $cursor->format('M Y'), 'in' => '0.00', 'out' => '0.00'];
            $cursor = $cursor->addMonth();
        }

        foreach ($projects as $row) {
            foreach ($row['project']->clientPayments->filter(fn ($payment) => $this->within($payment->payment_date, $from, $to)) as $payment) {
                $month = CarbonImmutable::parse($payment->payment_date)->format('Y-m');
                $months[$month]['in'] = Money::sum([$months[$month]['in'], $payment->amount]);
            }
            foreach ($row['project']->expensePayments->filter(fn ($payment) => $this->within($payment->payment_date, $from, $to)) as $payment) {
                $month = CarbonImmutable::parse($payment->payment_date)->format('Y-m');
                $months[$month]['out'] = Money::sum([$months[$month]['out'], $payment->amount]);
            }
        }

        return collect($months)->map(function (array $month) {
            $month['net'] = Money::difference($month['in'], $month['out']);

            return $month;
        })->values();
    }

    private function within(mixed $date, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if (! $date) {
            return false;
        }
        $date = CarbonImmutable::parse($date);

        return $date->gte($from->startOfDay()) && $date->lte($to->endOfDay());
    }
}
