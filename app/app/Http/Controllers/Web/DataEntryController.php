<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ExpenseDraft;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\LedgerAnalyticsService;
use Illuminate\Http\Request;

/**
 * The Data Entry screen: pick a project, enter expenses on one form, and see (and, the same day,
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
     * "Entered Today" outside a project: the projects entered on today, each with its lines and total; choose one to see them.
     * With a single project it opens that project's list straight away.
     */
    public function today(Request $request, LedgerAnalyticsService $analytics)
    {
        $user = $request->user();
        $lines = $this->todaysLines($request);
        $projects = $analytics->projectsFor($user, $user->hasRole('admin'))->filter(fn (Project $project) => $user->can('record-expense', $project))
            ->concat($lines->pluck('project')->filter())->unique('id')->values();
        if ($projects->count() === 1) {
            return redirect()->route('entry.today.project', $projects->first());
        }
        $byProject = $lines->groupBy('project_id');

        return view('entry.today-pick', [
            // Only the projects something was entered on today.
            'projects' => $projects->filter(fn (Project $project) => $byProject->has($project->id))->sortBy(fn (Project $project) => mb_strtolower((string) $project->name))->values(),
            'byProject' => $byProject,
            'lines' => $lines,
        ]);
    }

    /** "Entered Today" inside a project: only that project's lines, and the sidebar stays in the project. */
    public function todayForProject(Request $request, Project $project)
    {
        $user = $request->user();
        abort_unless($user->can('record-expense', $project) || $user->can('view-ledger', $project), 403);

        return view('entry.today', ['today' => $this->todaysLines($request)->where('project_id', $project->id)->values(), 'project' => $project]);
    }

    /** Number of lines this person entered today, on one project or on all (sidebar badge). */
    public static function todayCount(User $user, ?Project $project = null): int
    {
        return ExpenseRequest::query()->where('requested_by', $user->id)->whereDate('created_at', today())->whereIn('status', ['approved', 'rejected'])
            ->when($project, fn ($query) => $query->where('project_id', $project->id))->count();
    }

    /** What this person entered today, newest first. */
    private function todaysLines(Request $request)
    {
        return ExpenseRequest::query()->where('requested_by', $request->user()->id)->whereDate('created_at', today())
            ->whereIn('status', ['approved', 'rejected'])->with(['project', 'payments', 'phase'])->latest('id')->get();
    }
}
