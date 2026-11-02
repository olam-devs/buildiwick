<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ExpenseRequest;
use App\Models\Project;
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

    /** What this person entered today, newest first. */
    private function todaysLines(Request $request)
    {
        return ExpenseRequest::query()->where('requested_by', $request->user()->id)->whereDate('created_at', today())
            ->whereIn('status', ['approved', 'rejected'])->with(['project', 'payments', 'phase'])->latest('id')->get();
    }
}
