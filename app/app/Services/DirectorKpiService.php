<?php

namespace App\Services;

use App\Models\DirectorKpiTarget;
use App\Support\Money;
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

    public function __construct(private readonly DirectorAnalyticsService $analytics) {}

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
            $definition = self::METRICS[$target->metric] ?? null;
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
            $report = $this->report($start, $measuredTo);
            $actual = $this->actual($target->metric, $report, $target->project_id);
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
