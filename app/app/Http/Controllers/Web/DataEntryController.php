<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ExpenseDraft;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\LedgerAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The Data Entry screen: pick a project, enter expenses on one form, and see (and, until confirmed,
 * correct) what you entered today. Nothing else of the system is shown; entries wait for Accounts.
 */
class DataEntryController extends Controller
{
    public function index(Request $request, LedgerAnalyticsService $analytics)
    {
        $user = $request->user();
        $projects = $analytics->projectsFor($user)->filter(fn (Project $project) => $user->can('record-expense', $project))->values();
        abort_if($projects->isEmpty() && ! $user->hasRole('admin') && $user->role?->name !== 'data_entry', 403);
        if ($projects->count() === 1 && $user->role?->name === 'data_entry') {
            return redirect()->route('entry.form', $projects->first());
        }

        return view('entry.index', [
            'projects' => $projects,
            'drafts' => ExpenseDraft::query()->where('user_id', $user->id)->pluck('item_count', 'project_id'),
            'today' => $this->todaysLines($request),
        ]);
    }

    public function form(Request $request, Project $project, LedgerController $ledger)
    {
        $this->authorize('record-expense', $project);

        $user = $request->user();

        return $ledger->create($request, $project)->with([
            'entryMode' => true,
            'canChangeProject' => app(LedgerAnalyticsService::class)->projectsFor($user)->filter(fn (Project $p) => $user->can('record-expense', $p))->count() > 1,
            'today' => $this->todaysLines($request)->where('project_id', $project->id)->values(),
        ]);
    }

    /**
     * "My Entries" outside a project: the projects entered on in the chosen period (today by default), each with its
     * lines; choose one to see them. With a single project it opens that project's list straight away.
     */
    public function today(Request $request, LedgerAnalyticsService $analytics)
    {
        $user = $request->user();
        $period = self::period($request);
        $lines = $this->linesIn($request, $period);
        $projects = $analytics->projectsFor($user, $user->hasRole('admin'))->filter(fn (Project $project) => $user->can('record-expense', $project))
            ->concat($lines->pluck('project')->filter())->unique('id')->values();
        if ($projects->count() === 1) {
            return redirect()->route('entry.today.project', [$projects->first()] + $period['query']);
        }
        $byProject = $lines->groupBy('project_id');

        return view('entry.today-pick', [
            // Only the projects something was entered on today.
            'projects' => $projects->filter(fn (Project $project) => $byProject->has($project->id))->sortBy(fn (Project $project) => mb_strtolower((string) $project->name))->values(),
            'byProject' => $byProject,
            'lines' => $lines,
            'period' => $period,
        ]);
    }

    /** "My Entries" inside a project: only that project's lines in the chosen period, and the sidebar stays in the project. */
    public function todayForProject(Request $request, Project $project)
    {
        $user = $request->user();
        abort_unless($user->can('record-expense', $project) || $user->can('view-ledger', $project), 403);
        $period = self::period($request);

        return view('entry.today', ['today' => $this->linesIn($request, $period, $project), 'project' => $project, 'period' => $period]);
    }

    /**
     * The period chosen on My Entries: today (default), yesterday, this week, this month, the last 12 months, or picked
     * dates (at most a year, never in the future). Dates are when the lines were entered, or with `by=expense` the expense
     * date on the line. `q` searches item, supplier, receipt no. and line code within the period.
     *
     * @return array{key: string, from: CarbonImmutable, to: CarbonImmutable, label: string, query: array<string, string>, by: string, q: string, keep: array<string, string>}
     */
    public static function period(Request $request): array
    {
        $today = CarbonImmutable::today();
        $key = (string) $request->query('period', 'today');
        [$from, $to] = match ($key) {
            'yesterday' => [$today->subDay(), $today->subDay()],
            'week' => [$today->startOfWeek(), $today],
            'month' => [$today->startOfMonth(), $today],
            'year' => [$today->subYear(), $today],
            'custom' => [self::date($request->query('from')) ?? $today, self::date($request->query('to')) ?? $today],
            default => [$today, $today],
        };
        if (! in_array($key, ['yesterday', 'week', 'month', 'year', 'custom'], true)) {
            $key = 'today';
        }
        $to = $to->min($today);
        $from = $from->min($to)->max($to->subYear());
        $label = match ($key) {
            'today' => 'Today, '.$today->format('d M Y'),
            'yesterday' => 'Yesterday, '.$from->format('d M Y'),
            'week' => 'This week, '.$from->format('d M').' – '.$to->format('d M Y'),
            'month' => 'This month, '.$from->format('d M').' – '.$to->format('d M Y'),
            'year' => 'Last 12 months, '.$from->format('d M Y').' – '.$to->format('d M Y'),
            default => $from->equalTo($to) ? $from->format('l, d M Y') : $from->format('d M Y').' – '.$to->format('d M Y'),
        };
        $query = match ($key) {
            'today' => [],
            'custom' => ['period' => 'custom', 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            default => ['period' => $key],
        };
        $by = $request->query('by') === 'expense' ? 'expense' : 'entered';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        // Kept when another period is picked: the date basis and the search.
        $keep = array_filter(['by' => $by === 'expense' ? 'expense' : null, 'q' => $search !== '' ? $search : null]);
        $label .= $by === 'expense' ? ' · by expense date' : ' · by date entered';

        return ['key' => $key, 'from' => $from, 'to' => $to, 'label' => $label, 'query' => $query + $keep, 'by' => $by, 'q' => $search, 'keep' => $keep];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Number of lines this person entered today, on one project or on all (sidebar badge). */
    public static function todayCount(User $user, ?Project $project = null): int
    {
        return ExpenseRequest::query()->where('requested_by', $user->id)->whereDate('created_at', today())->whereIn('status', ['approved', 'rejected'])
            ->when($project, fn ($query) => $query->where('project_id', $project->id))->count();
    }

    /** What this person entered in a period (by date entered or expense date, optionally searched, on one project), newest first. */
    private function linesIn(Request $request, array $period, ?Project $project = null)
    {
        $byExpense = ($period['by'] ?? 'entered') === 'expense';
        $search = $period['q'] ?? '';

        return ExpenseRequest::query()->where('requested_by', $request->user()->id)
            ->when($byExpense,
                fn ($query) => $query->whereDate('requested_at', '>=', $period['from']->toDateString())->whereDate('requested_at', '<=', $period['to']->toDateString()),
                fn ($query) => $query->whereBetween('created_at', [$period['from']->startOfDay(), $period['to']->endOfDay()]))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                foreach (['item_description', 'supplier_fundi_client', 'invoice_receipt_no', 'request_code'] as $column) {
                    $query->orWhere($column, 'like', $like);
                }
            }))
            ->when($project, fn ($query) => $query->where('project_id', $project->id))
            ->whereIn('status', ['approved', 'rejected'])->with(['project', 'payments', 'phase'])
            ->when($byExpense, fn ($query) => $query->orderByDesc('requested_at'))->latest('id')->get();
    }

    /** What this person entered today, newest first. */
    private function todaysLines(Request $request)
    {
        return ExpenseRequest::query()->where('requested_by', $request->user()->id)->whereDate('created_at', today())
            ->whereIn('status', ['approved', 'rejected'])->with(['project', 'payments', 'phase'])->latest('id')->get();
    }
}
