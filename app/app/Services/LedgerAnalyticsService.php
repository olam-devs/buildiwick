<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use App\Support\PhaseTree;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Recording-mode figures, computed the way the client's tracker does: spend ("recorded" in these
 * arrays) is what has been confirmed as paid, so it matches the tracker's totals; lines still "to
 * confirm" (e.g. deliveries from the procurement log) are reported separately as to_confirm and never
 * count as spend until confirmed. Budgets are the Director's amounts per phase × category. Phase
 * figures roll up the tree (a phase includes its subphases).
 *
 * Each line's amounts are converted once to integer cents (exact fixed-point) and every total is
 * summed in cents, so dashboards over thousands of lines stay fast without losing precision.
 */
class LedgerAnalyticsService
{
    public const CATEGORIES = [
        'materials' => 'Materials',
        'labour_subcontracts' => 'Labour & Subcontracts',
        'logistics_equipment' => 'Logistics & Equipment',
        'preliminaries_overheads' => 'Preliminaries & Site Overheads',
    ];

    public const CATEGORY_COLORS = [
        'materials' => '#2490EF',
        'labour_subcontracts' => '#2FA36E',
        'logistics_equipment' => '#F5A623',
        'preliminaries_overheads' => '#8E6CEF',
    ];

    /** @var array<int, Collection> lines per project id, loaded once per request */
    private array $lineCache = [];

    /** @var array<int, array<string, int>> budget cents per project id */
    private array $budgetCache = [];

    /** Projects the user may open in the ledger. */
    public function projectsFor(User $user, bool $withOverheads = false): Collection
    {
        return Project::query()
            ->when(! ($withOverheads && $user->hasRole('admin')), fn ($query) => $query->where('is_company_overheads', false))
            ->when(! $user->seesAllProjects(), fn ($query) => $query->whereHas('assignedUsers', fn ($users) => $users->whereKey($user->id)))
            ->orderBy('name')->get();
    }

    /** @return Collection<int, ExpenseRequest> recorded lines (approved requests) with payments */
    public function lines(Project $project): Collection
    {
        return $this->lineCache[$project->id] ??= self::normalize($project->expenseRequests()->where('status', 'approved')->with(['payments', 'requestedBy'])
            ->orderBy('requested_at')->orderBy('id')->get());
    }

    /**
     * Lines from the older approval workflow can carry their price only as the approved amount; the
     * ledger always reads a line's amount from total_amount, so fill it in memory (never saved).
     * Also stores each line's amounts in cents (ledger_total_c / ledger_paid_c / ledger_open_c).
     */
    public static function normalize(Collection $lines): Collection
    {
        return $lines->each(function (ExpenseRequest $line): void {
            if ($line->total_amount === null || $line->total_amount === '') {
                $line->setAttribute('total_amount', $line->approved_payable_amount);
            }
            $total = self::cents($line->total_amount);
            $paid = 0;
            foreach ($line->payments as $payment) {
                $paid += self::cents($payment->amount);
            }
            $line->setAttribute('ledger_total_c', $total);
            $line->setAttribute('ledger_paid_c', $paid);
            // Under TSh 1 left open is tracker rounding (e.g. 11,828,000.01 paid as 11,828,000): treat as settled.
            $open = $total > 0 ? max(0, $total - $paid) : 0;
            $open = $open < 100 ? 0 : $open;
            $line->setAttribute('ledger_open_c', $open);
            $line->setAttribute('ledger_status', $open === 0 ? ($paid !== 0 || $total <= 0 ? 'paid' : 'unpaid') : ($paid > 0 ? 'part_paid' : 'unpaid'));
        });
    }

    /** @return array<string, string> "phaseId|category" => budget amount (own node only) */
    public function budgets(Project $project): array
    {
        return array_map(fn (int $cents) => self::money($cents), $this->budgetCents($project));
    }

    /** Everything the project Overview needs. */
    public function project(Project $project, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $phases = $project->phases()->get();
        $tree = new PhaseTree($phases);
        $all = $this->lines($project);
        $lines = $all->filter(fn (ExpenseRequest $line) => $this->within($line, $from, $to))->values();
        $budgets = $this->budgetCents($project);

        // Headline spend is what is confirmed (paid), as in the tracker's totals; the rest awaits confirmation.
        $recorded = $this->sum($lines, 'ledger_paid_c');
        $paid = $recorded;
        $budget = array_sum($budgets);
        $open = $lines->filter(fn ($line) => $line->ledger_open_c > 0);

        $byCategory = [];
        foreach (self::CATEGORIES as $code => $label) {
            $group = $lines->where('main_category', $code);
            $categoryBudget = 0;
            foreach ($budgets as $key => $amount) {
                if (str_ends_with($key, '|'.$code)) {
                    $categoryBudget += $amount;
                }
            }
            $amount = $this->sum($group, 'ledger_paid_c');
            $byCategory[$code] = [
                'label' => $label, 'color' => self::CATEGORY_COLORS[$code], 'recorded' => self::money($amount),
                'paid' => self::money($this->sum($group, 'ledger_paid_c')), 'budget' => self::money($categoryBudget),
                'to_confirm' => self::money($this->sum($group, 'ledger_open_c')), 'to_confirm_count' => $group->filter(fn ($line) => $line->ledger_open_c > 0)->count(),
                'count' => $group->count(), 'share' => self::pct($amount, $recorded) ?? '0.00', 'used' => self::pct($amount, $categoryBudget),
            ];
        }

        return [
            'project' => $project,
            'tree' => $tree,
            'totals' => [
                'recorded' => self::money($recorded), 'paid' => self::money($paid), 'to_confirm' => self::money($this->sum($lines, 'ledger_open_c')),
                'to_confirm_count' => $open->count(), 'lines' => $lines->count(), 'budget' => self::money($budget),
                'used' => self::pct($recorded, $budget),
                'last_entry' => $all->max('requested_at'),
            ],
            'categories' => $byCategory,
            'phases' => $this->phaseRows($tree, $lines, $budgets),
            'monthly' => $this->monthly($lines, $from, $to),
            'top_items' => $this->topItems($lines),
            'top_by_category' => collect(self::CATEGORIES)->mapWithKeys(fn ($label, $code) => [$code => $this->topItems($lines->where('main_category', $code))])->all(),
            'recent' => $all->sortByDesc(fn ($line) => [$line->requested_at?->format('Y-m-d'), $line->id])->take(8)->values(),
            'lines' => $all,
        ];
    }

    /**
     * Phase tree rows with roll-up totals per category and against the phase budget.
     *
     * @param  array<string, int|string>  $budgets  "phaseId|category" => amount (cents or decimal string)
     * @return Collection<int, array<string, mixed>>
     */
    public function phaseRows(PhaseTree $tree, Collection $lines, array $budgets): Collection
    {
        $budgets = array_map(fn ($amount) => is_int($amount) ? $amount : self::cents($amount), $budgets);
        self::normalize($lines->filter(fn ($line) => $line->ledger_total_c === null));
        $own = [];
        foreach ($lines as $line) {
            $key = $line->project_phase_id.'|'.$line->main_category;
            $own[$key]['total'] = ($own[$key]['total'] ?? 0) + $line->ledger_paid_c;
            $own[$key]['paid'] = ($own[$key]['paid'] ?? 0) + $line->ledger_paid_c;
            $own[$key]['count'] = ($own[$key]['count'] ?? 0) + 1;
        }

        return $tree->flatten()->map(function (array $node) use ($tree, $own, $budgets) {
            $ids = $tree->withDescendants($node['phase']->id);
            $categories = [];
            $recorded = $paid = $count = $budget = 0;
            foreach (array_keys(self::CATEGORIES) as $code) {
                $categoryRecorded = $categoryBudget = 0;
                foreach ($ids as $id) {
                    $key = $id.'|'.$code;
                    $categoryRecorded += $own[$key]['total'] ?? 0;
                    $paid += $own[$key]['paid'] ?? 0;
                    $count += $own[$key]['count'] ?? 0;
                    $categoryBudget += $budgets[$key] ?? 0;
                }
                $ownBudget = $budgets[$node['phase']->id.'|'.$code] ?? null;
                $categories[$code] = ['recorded' => self::money($categoryRecorded), 'budget' => self::money($categoryBudget),
                    'own_budget' => $ownBudget === null ? null : self::money($ownBudget)];
                $recorded += $categoryRecorded;
                $budget += $categoryBudget;
            }

            return $node + [
                'ids' => $ids, 'recorded' => self::money($recorded), 'paid' => self::money($paid), 'budget' => self::money($budget),
                'used' => self::pct($recorded, $budget), 'count' => $count, 'categories' => $categories,
            ];
        });
    }

    /** Company dashboard: one row per project plus company-wide splits. */
    public function portfolio(Collection $projects, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $months = $this->emptyMonths($from, $to);
        $categoryTotals = array_fill_keys(array_keys(self::CATEGORIES), 0);
        $rows = $projects->map(function (Project $project) use ($from, $to, &$months, &$categoryTotals) {
            $lines = $this->lines($project);
            $budget = array_sum($this->budgetCents($project));
            $recordedAll = $this->sum($lines, 'ledger_paid_c');
            $recorded = $paid = $open = $openCount = $count = 0;
            $byCategory = array_fill_keys(array_keys(self::CATEGORIES), 0);
            foreach ($lines as $line) {
                if (! $this->within($line, $from, $to)) {
                    continue;
                }
                $count++;
                $recorded += $line->ledger_paid_c;
                $paid += $line->ledger_paid_c;
                $open += $line->ledger_open_c;
                $openCount += $line->ledger_open_c > 0 ? 1 : 0;
                if (isset($byCategory[$line->main_category])) {
                    $byCategory[$line->main_category] += $line->ledger_paid_c;
                    $categoryTotals[$line->main_category] += $line->ledger_paid_c;
                }
                $month = $line->requested_at?->format('Y-m');
                if (isset($months[$month])) {
                    $months[$month]['total'] += $line->ledger_paid_c;
                    if (isset($months[$month]['categories'][$line->main_category])) {
                        $months[$month]['categories'][$line->main_category] += $line->ledger_paid_c;
                    }
                }
            }

            return [
                'project' => $project, 'recorded' => self::money($recorded), 'recorded_all' => self::money($recordedAll),
                'paid' => self::money($paid), 'to_confirm' => self::money($open), 'to_confirm_count' => $openCount, 'lines' => $count,
                'budget' => self::money($budget), 'used' => self::pct($recordedAll, $budget), 'last_entry' => $lines->max('requested_at'),
                'by_category' => array_map(fn ($cents) => self::money($cents), $byCategory),
                '_recorded_c' => $recorded, '_paid_c' => $paid, '_open_c' => $open, '_budget_c' => $budget,
            ];
        });
        $recorded = $rows->sum('_recorded_c');
        $categories = collect(self::CATEGORIES)->map(fn ($label, $code) => ['label' => $label, 'color' => self::CATEGORY_COLORS[$code],
            'recorded' => self::money($categoryTotals[$code]), 'share' => self::pct($categoryTotals[$code], $recorded) ?? '0.00'])->all();

        return [
            'rows' => $rows->map(fn ($row) => collect($row)->except(['_recorded_c', '_paid_c', '_open_c', '_budget_c'])->all())->values(),
            'totals' => [
                'recorded' => self::money($recorded), 'paid' => self::money($rows->sum('_paid_c')), 'to_confirm' => self::money($rows->sum('_open_c')),
                'to_confirm_count' => $rows->sum('to_confirm_count'), 'lines' => $rows->sum('lines'), 'budget' => self::money($rows->sum('_budget_c')),
                'active_projects' => $rows->filter(fn ($row) => $row['project']->status === 'active')->count(),
            ],
            'categories' => $categories,
            'monthly' => $this->monthsToMoney($months),
        ];
    }

    /** @return array<int, array{key: string, label: string, total: string, categories: array<string, string>}> */
    public function monthly(Collection $lines, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $months = $this->emptyMonths($from, $to);
        foreach ($lines as $line) {
            $key = $line->requested_at?->format('Y-m');
            if (! isset($months[$key])) {
                continue;
            }
            $amount = $line->ledger_paid_c ?? 0;
            $months[$key]['total'] += $amount;
            if (isset($months[$key]['categories'][$line->main_category])) {
                $months[$key]['categories'][$line->main_category] += $amount;
            }
        }

        return $this->monthsToMoney($months);
    }

    /** @return Collection<int, array{name: string, total: string, count: int}> */
    /**
     * Lines that may be entered twice, with why: (a) the same date, amount and description as another
     * line, or (b) a line still awaiting confirmation with the same amount as a paid line of the same
     * project within 14 days (a procurement-log delivery and its payment). These are leads to check,
     * not proof: two real purchases can share a price.
     *
     * @return array<int, string> line id => note
     */
    public function duplicateNotes(Project $project): array
    {
        $lines = $this->lines($project)->filter(fn ($line) => $line->requested_at !== null && $line->ledger_total_c > 0);
        $notes = [];
        $same = $lines->groupBy(fn ($line) => $line->requested_at->toDateString().'|'.$line->ledger_total_c.'|'.mb_strtolower(trim((string) $line->item_description)));
        foreach ($same as $group) {
            if ($group->count() < 2) {
                continue;
            }
            foreach ($group as $line) {
                $others = $group->where('id', '!=', $line->id)->pluck('request_code')->join(', ');
                $notes[$line->id] = 'Same date, amount and description as '.$others;
            }
        }
        $paid = $lines->filter(fn ($line) => $line->ledger_open_c <= 0)->groupBy('ledger_total_c');
        foreach ($lines->filter(fn ($line) => $line->ledger_open_c > 0) as $line) {
            $match = collect($paid[$line->ledger_total_c] ?? [])->first(fn ($candidate) => abs($candidate->requested_at->diffInDays($line->requested_at)) <= 14);
            if ($match) {
                $notes[$line->id] ??= 'Same amount as paid line '.$match->request_code.' ('.$match->requested_at->format('d M').': '.Str::limit((string) $match->item_description, 40).')';
            }
        }

        return $notes;
    }

    public function topItems(Collection $lines, int $limit = 8): Collection
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = mb_strtolower(trim((string) $line->item_description));
            $groups[$key] ??= ['name' => $line->item_description, 'total' => 0, 'count' => 0];
            $groups[$key]['total'] += $line->ledger_paid_c ?? 0;
            $groups[$key]['count']++;
        }

        return collect($groups)->filter(fn ($row) => $row['total'] > 0)->sortByDesc('total')->take($limit)->map(fn ($row) => ['name' => $row['name'], 'total' => self::money($row['total']), 'count' => $row['count']])->values();
    }

    public static function cents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    public static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Percentage of $part in $whole with two decimals, or null when there is nothing to compare against. */
    public static function pct(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : number_format(round($part / $whole * 100, 2), 2, '.', '');
    }

    /** @return array<string, int> */
    private function budgetCents(Project $project): array
    {
        if (isset($this->budgetCache[$project->id])) {
            return $this->budgetCache[$project->id];
        }
        $amounts = [];
        $budgets = InternalBudget::query()->where('project_id', $project->id)->where('status', 'approved')->with('entries')->get();
        foreach ($budgets as $budget) {
            if (! $budget->project_phase_id || ! $budget->main_category) {
                continue;
            }
            $key = $budget->project_phase_id.'|'.$budget->main_category;
            foreach ($budget->entries as $entry) {
                $amounts[$key] = ($amounts[$key] ?? 0) + self::cents(Money::product($entry->unit_cost, $entry->quantity ?? '1'));
            }
        }

        return $this->budgetCache[$project->id] = $amounts;
    }

    private function sum(Collection $lines, string $field): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += (int) $line->{$field};
        }

        return $total;
    }

    private function emptyMonths(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $end = ($to ?? CarbonImmutable::today())->startOfMonth();
        $start = $from ? $from->startOfMonth() : $end->subMonths(11);
        if ($start->diffInMonths($end) > 23) {
            $start = $end->subMonths(23);
        }
        $months = [];
        for ($cursor = $start; $cursor->lte($end); $cursor = $cursor->addMonth()) {
            $months[$cursor->format('Y-m')] = ['key' => $cursor->format('Y-m'), 'label' => $cursor->format('M y'), 'total' => 0,
                'categories' => array_fill_keys(array_keys(self::CATEGORIES), 0)];
        }

        return $months;
    }

    private function monthsToMoney(array $months): array
    {
        return array_values(array_map(fn ($month) => ['key' => $month['key'], 'label' => $month['label'], 'total' => self::money($month['total']),
            'categories' => array_map(fn ($cents) => self::money($cents), $month['categories'])], $months));
    }

    private function within(ExpenseRequest $line, ?CarbonImmutable $from, ?CarbonImmutable $to): bool
    {
        if (! $from && ! $to) {
            return true;
        }
        $day = $line->requested_at?->format('Y-m-d');
        if (! $day) {
            return false;
        }

        return (! $from || $day >= $from->format('Y-m-d')) && (! $to || $day <= $to->format('Y-m-d'));
    }
}
