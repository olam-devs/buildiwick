<?php

namespace App\Services;

use App\Models\DirectorKpiTarget;
use App\Models\Project;
use App\Support\Features;
use App\Support\Money;
use App\Support\PhaseTree;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Director KPI targets measured against figures the system already records. Actuals come from
 * DirectorAnalyticsService (payment events, issued invoices, budgets), never from a separate store.
 */
class DirectorKpiService
{
    /**
     * basis "period": summed over the target's dates; "current": today's position.
     * direction "at_least": higher is better; "at_most": the target is a ceiling.
     */
    public const METRICS = [
        'client_receipts' => ['label' => 'Client money received', 'unit' => 'money', 'direction' => 'at_least', 'basis' => 'period'],
        'invoiced' => ['label' => 'Invoiced to clients', 'unit' => 'money', 'direction' => 'at_least', 'basis' => 'period'],
        'net_cash_flow' => ['label' => 'Net cash flow (received − paid out)', 'unit' => 'money', 'direction' => 'at_least', 'basis' => 'period'],
        'expenses_paid' => ['label' => 'Money paid out (spending ceiling)', 'unit' => 'money', 'direction' => 'at_most', 'basis' => 'period'],
        'overdue_receivables' => ['label' => 'Overdue client invoices (ceiling)', 'unit' => 'money', 'direction' => 'at_most', 'basis' => 'current'],
        'budget_used_pct' => ['label' => 'Budget used, % paid of budget (ceiling)', 'unit' => 'percent', 'direction' => 'at_most', 'basis' => 'current'],
    ];

    /**
     * Recording mode measures only spending (no client billing). Every target can be narrowed to one
     * project, one phase (with its subphases) and one main category.
     */
    public const RECORDING_METRICS = [
        'spend' => ['label' => 'Recorded spend (ceiling)', 'unit' => 'money', 'direction' => 'at_most', 'basis' => 'period'],
        'paid_out' => ['label' => 'Money paid out (ceiling)', 'unit' => 'money', 'direction' => 'at_most', 'basis' => 'period'],
        'to_confirm' => ['label' => 'Amount still to confirm (ceiling)', 'unit' => 'money', 'direction' => 'at_most', 'basis' => 'current'],
        'budget_used_pct' => ['label' => 'Budget used, % of budget (ceiling)', 'unit' => 'percent', 'direction' => 'at_most', 'basis' => 'current'],
    ];

    /** @return array<string, array<string, string>> the measures for the current rollout mode */
    public static function definitions(): array
    {
        return Features::recording() ? self::RECORDING_METRICS : self::METRICS;
    }

    public const PERIODS = [
        'this_month' => 'This month',
        'next_month' => 'Next month',
        'this_quarter' => 'This quarter',
        'next_quarter' => 'Next quarter',
        'this_year' => 'This year',
        'next_year' => 'Next year',
        'custom' => 'Custom dates',
    ];

    public const STATUS_LABELS = [
        'met' => 'Met', 'on_track' => 'On track', 'behind' => 'Behind', 'missed' => 'Missed',
        'within' => 'Within limit', 'over' => 'Over limit', 'upcoming' => 'Not started',
    ];

    /** @var array<string, array> */
    private array $reports = [];

    public function __construct(
        private readonly DirectorAnalyticsService $analytics,
        private readonly LedgerAnalyticsService $ledger,
    ) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function periodDates(string $period, ?string $from = null, ?string $to = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return match ($period) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            'next_month' => [$today->addMonthNoOverflow()->startOfMonth(), $today->addMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$today->startOfQuarter(), $today->endOfQuarter()->startOfDay()],
            'next_quarter' => [$today->addQuarterNoOverflow()->startOfQuarter(), $today->addQuarterNoOverflow()->endOfQuarter()->startOfDay()],
            'next_year' => [$today->addYear()->startOfYear(), $today->addYear()->endOfYear()->startOfDay()],
            'custom' => [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->startOfDay()],
            default => [$today->startOfYear(), $today->endOfYear()->startOfDay()],
        };
    }

    /**
     * Adds actual, progress and status to each target.
     *
     * @param  Collection<int, DirectorKpiTarget>  $targets
     * @return Collection<int, array<string, mixed>>
     */
    public function evaluate(Collection $targets, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();

        return $targets->map(function (DirectorKpiTarget $target) use ($today) {
            $definition = self::definitions()[$target->metric] ?? null;
            $start = CarbonImmutable::parse($target->period_start);
            $end = CarbonImmutable::parse($target->period_end);
            $base = ['target' => $target, 'definition' => $definition, 'actual' => null, 'progress' => null, 'status' => 'upcoming'];
            if (! $definition) {
                return $base;
            }
            if ($definition['basis'] === 'period' && $start->isAfter($today)) {
                return $base;
            }

            $measuredTo = $end->isAfter($today) ? $today : $end;
            $actual = Features::recording()
                ? $this->ledgerActual($target, $start, $measuredTo)
                : $this->actual($target->metric, $this->report($start, $measuredTo), $target->project_id);
            if ($actual === null) {
                return $base;
            }
            $targetValue = (string) $target->target_value;
            $progress = Money::compare($targetValue, '0') === 0 ? null : Money::percentage($actual, $targetValue);
            $ended = $end->lt($today);

            if ($definition['direction'] === 'at_least') {
                if (Money::compare($actual, $targetValue) >= 0) {
                    $status = 'met';
                } elseif ($ended) {
                    $status = 'missed';
                } else {
                    $elapsed = max(1, $start->diffInDays($today) + 1) / max(1, $start->diffInDays($end) + 1);
                    $expected = number_format((float) $targetValue * min(1, $elapsed), 2, '.', '');
                    $status = Money::compare($actual, $expected) >= 0 ? 'on_track' : 'behind';
                }
            } else {
                $status = Money::compare($actual, $targetValue) <= 0 ? ($ended ? 'met' : 'within') : 'over';
            }

            return ['target' => $target, 'definition' => $definition, 'actual' => $actual, 'progress' => $progress, 'status' => $status];
        });
    }

    private function actual(string $metric, array $report, ?int $projectId): ?string
    {
        if ($projectId) {
            $row = $report['projects']->first(fn (array $row) => (int) $row['project']->id === $projectId);
            if (! $row) {
                return null;
            }

            return match ($metric) {
                'client_receipts' => $row['received_period'],
                'invoiced' => $row['invoiced_period'],
                'expenses_paid' => $row['expenses_period'],
                'net_cash_flow' => Money::difference($row['received_period'], $row['expenses_period']),
                'overdue_receivables' => Money::sum($row['receivable_rows']->where('overdue', true)->pluck('outstanding')),
                'budget_used_pct' => $row['actual_utilization'],
                default => null,
            };
        }

        $summary = $report['summary'];

        return match ($metric) {
            'client_receipts' => $summary['received_period'],
            'invoiced' => $summary['invoiced_period'],
            'expenses_paid' => $summary['expenses_period'],
            'net_cash_flow' => Money::difference($summary['received_period'], $summary['expenses_period']),
            'overdue_receivables' => $summary['overdue_receivables'],
            'budget_used_pct' => $this->companyBudgetUsed($report['projects']),
            default => null,
        };
    }

    /** Recording-mode actual for a target's project / phase / category scope. */
    private function ledgerActual(DirectorKpiTarget $target, CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        $projects = $target->project_id ? Project::query()->whereKey($target->project_id)->get() : Project::query()->where('is_company_overheads', false)->get();
        $spend = '0.00';
        $paid = '0.00';
        $outstanding = '0.00';
        $budget = '0.00';
        $allSpend = '0.00';
        foreach ($projects as $project) {
            $ids = null;
            if ($target->project_phase_id) {
                $ids = (new PhaseTree($project->phases()->get()))->withDescendants((int) $target->project_phase_id);
            }
            $lines = $this->ledger->lines($project)
                ->when($ids, fn ($rows) => $rows->whereIn('project_phase_id', $ids))
                ->when($target->main_category, fn ($rows) => $rows->where('main_category', $target->main_category));
            $period = $lines->filter(fn ($line) => $line->requested_at && $line->requested_at->between($from->startOfDay(), $to->endOfDay()));
            // Spend is confirmed (paid) spend, as on every dashboard; "to confirm" lines count only once confirmed.
            $spend = Money::sum([$spend, LedgerAnalyticsService::money((int) $period->sum('ledger_paid_c'))]);
            $paid = Money::sum([$paid, ...$period->flatMap(fn ($line) => $line->payments->filter(fn ($payment) => $payment->payment_date->between($from->startOfDay(), $to->endOfDay()))->pluck('amount'))]);
            $outstanding = Money::sum([$outstanding, LedgerAnalyticsService::money((int) $lines->sum('ledger_open_c'))]);
            $allSpend = Money::sum([$allSpend, LedgerAnalyticsService::money((int) $lines->sum('ledger_paid_c'))]);
            foreach ($this->ledger->budgets($project) as $key => $amount) {
                [$phaseId, $category] = explode('|', $key);
                if ((! $ids || in_array((int) $phaseId, $ids, true)) && (! $target->main_category || $target->main_category === $category)) {
                    $budget = Money::sum([$budget, $amount]);
                }
            }
        }

        return match ($target->metric) {
            'spend' => $spend,
            'paid_out' => $paid,
            'to_confirm' => $outstanding,
            'budget_used_pct' => Money::compare($budget, '0') === 0 ? null : Money::percentage($allSpend, $budget),
            default => null,
        };
    }

    private function companyBudgetUsed(Collection $rows): ?string
    {
        $budgeted = $rows->filter(fn (array $row) => ! $row['no_budget']);
        $budget = Money::sum($budgeted->pluck('budget'));

        return Money::compare($budget, '0') === 0 ? null : Money::percentage(Money::sum($budgeted->pluck('actual_expenses')), $budget);
    }

    private function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->reports[$from->toDateString().'|'.$to->toDateString()] ??= $this->analytics->portfolio($from, $to);
    }
}
