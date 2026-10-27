<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DirectorKpiTarget;
use App\Models\Project;
use App\Services\DirectorAnalyticsService;
use App\Services\DirectorKpiService;
use App\Services\ProjectWarningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Director KPI targets and warning thresholds. Company-wide targets and default rules live on
 * pages outside any project; each project's targets, warnings and limits live inside its workspace.
 */
class DirectorStrategyController extends Controller
{
    public function kpis(DirectorKpiService $kpis)
    {
        $this->authorize('view-director-analytics');
        $groups = $this->grouped($kpis, DirectorKpiTarget::query()->whereNull('project_id')->with('createdBy')
            ->orderByDesc('period_start')->orderBy('metric')->get());

        return view('director.kpis', compact('groups'));
    }

    public function storeKpi(Request $request)
    {
        return $this->createTarget($request, null);
    }

    public function warningRules(ProjectWarningService $warnings)
    {
        $this->authorize('view-director-analytics');

        return view('director.warning-rules', ['rules' => $warnings->rules()]);
    }

    public function saveWarningRules(Request $request, ProjectWarningService $warnings)
    {
        return $this->persistWarningRules($request, $warnings, null);
    }

    /** One project's warnings, KPI targets and warning limits, inside its workspace. */
    public function project(Project $project, DirectorAnalyticsService $analytics, ProjectWarningService $warnings, DirectorKpiService $kpis)
    {
        $this->authorize('view-director-analytics');
        $today = CarbonImmutable::today();
        $row = $analytics->project($project, $today->startOfYear(), $today);
        $projectWarnings = $warnings->evaluate($row);
        $rules = $warnings->rules($project->id);
        $groups = $this->grouped($kpis, DirectorKpiTarget::query()->where('project_id', $project->id)->with('createdBy')
            ->orderByDesc('period_start')->orderBy('metric')->get());

        return view('director.project-warnings', compact('project', 'projectWarnings', 'rules', 'groups'));
    }

    public function storeProjectKpi(Request $request, Project $project)
    {
        return $this->createTarget($request, $project);
    }

    public function saveProjectWarningRules(Request $request, Project $project, ProjectWarningService $warnings)
    {
        return $this->persistWarningRules($request, $warnings, $project);
    }

    public function resetProjectWarningRules(Project $project, ProjectWarningService $warnings)
    {
        $this->authorize('manage-director-strategy');
        $warnings->resetProject($project);

        return back()->with('success', $project->name.' now uses the company default warning rules.');
    }

    public function destroyKpi(DirectorKpiTarget $target)
    {
        $this->authorize('manage-director-strategy');
        $target->delete();

        return back()->with('success', 'KPI target removed.');
    }

    private function createTarget(Request $request, ?Project $project)
    {
        $this->authorize('manage-director-strategy');
        $validated = $request->validate([
            'metric' => ['required', Rule::in(array_keys(DirectorKpiService::METRICS))],
            'period' => ['required', Rule::in(array_keys(DirectorKpiService::PERIODS))],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:from'],
            'target_value' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        [$start, $end] = DirectorKpiService::periodDates($validated['period'], $validated['from'] ?? null, $validated['to'] ?? null);
        DirectorKpiTarget::create([
            'metric' => $validated['metric'],
            'project_id' => $project?->id,
            'period_start' => $start,
            'period_end' => $end,
            'target_value' => $validated['target_value'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', $project ? 'KPI target saved for '.$project->name.'.' : 'Company KPI target saved.');
    }

    private function persistWarningRules(Request $request, ProjectWarningService $warnings, ?Project $project)
    {
        $this->authorize('manage-director-strategy');
        $rules = [];
        foreach (array_keys(ProjectWarningService::RULES) as $code) {
            $rules["rules.$code.threshold"] = ['required', 'numeric', 'min:0', 'max:99999999999999.99'];
            $rules["rules.$code.active"] = ['nullable', 'boolean'];
        }
        $validated = $request->validate($rules);
        $values = collect($validated['rules'] ?? [])->map(fn ($rule) => [
            'threshold' => $rule['threshold'],
            'active' => (bool) ($rule['active'] ?? false),
        ])->all();
        $warnings->save($project, $values, $request->user());

        return back()->with('success', $project ? 'Warning limits saved for '.$project->name.'.' : 'Company default warning rules saved.');
    }

    /** @return array<string, Collection> */
    private function grouped(DirectorKpiService $kpis, Collection $targets): array
    {
        $today = CarbonImmutable::today();
        $evaluated = $kpis->evaluate($targets);

        return [
            'Current' => $evaluated->filter(fn ($row) => $row['target']->period_start->lte($today) && $row['target']->period_end->gte($today)),
            'Upcoming' => $evaluated->filter(fn ($row) => $row['target']->period_start->gt($today)),
            'Past' => $evaluated->filter(fn ($row) => $row['target']->period_end->lt($today)),
        ];
    }
}
