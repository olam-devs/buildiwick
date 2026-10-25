<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DirectorKpiTarget;
use App\Models\Project;
use App\Services\DirectorKpiService;
use App\Services\ProjectWarningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Director KPI targets and warning thresholds (company defaults and per-project overrides). */
class DirectorStrategyController extends Controller
{
    public function kpis(DirectorKpiService $kpis)
    {
        $this->authorize('view-director-analytics');
        $today = CarbonImmutable::today();
        $targets = DirectorKpiTarget::query()->with(['project', 'createdBy'])->orderByDesc('period_start')->orderBy('metric')->get();
        $evaluated = $kpis->evaluate($targets);
        $groups = [
            'Current' => $evaluated->filter(fn ($row) => $row['target']->period_start->lte($today) && $row['target']->period_end->gte($today)),
            'Upcoming' => $evaluated->filter(fn ($row) => $row['target']->period_start->gt($today)),
            'Past' => $evaluated->filter(fn ($row) => $row['target']->period_end->lt($today)),
        ];
        $projects = Project::query()->orderBy('name')->get(['id', 'name', 'project_code']);

        return view('director.kpis', compact('groups', 'projects'));
    }

    public function storeKpi(Request $request)
    {
        $this->authorize('manage-director-strategy');
        $validated = $request->validate([
            'metric' => ['required', Rule::in(array_keys(DirectorKpiService::METRICS))],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'period' => ['required', Rule::in(array_keys(DirectorKpiService::PERIODS))],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:from'],
            'target_value' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        [$start, $end] = DirectorKpiService::periodDates($validated['period'], $validated['from'] ?? null, $validated['to'] ?? null);
        DirectorKpiTarget::create([
            'metric' => $validated['metric'],
            'project_id' => $validated['project_id'] ?? null,
            'period_start' => $start,
            'period_end' => $end,
            'target_value' => $validated['target_value'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'KPI target saved.');
    }

    public function destroyKpi(DirectorKpiTarget $target)
    {
        $this->authorize('manage-director-strategy');
        $target->delete();

        return back()->with('success', 'KPI target removed.');
    }

    public function warningRules(ProjectWarningService $warnings)
    {
        $this->authorize('view-director-analytics');

        return view('director.warning-rules', ['rules' => $warnings->rules(), 'definitions' => ProjectWarningService::RULES]);
    }

    public function saveWarningRules(Request $request, ProjectWarningService $warnings)
    {
        return $this->persistWarningRules($request, $warnings, null);
    }

    public function saveProjectWarningRules(Request $request, Project $project, ProjectWarningService $warnings)
    {
        return $this->persistWarningRules($request, $warnings, $project);
    }

    private function persistWarningRules(Request $request, ProjectWarningService $warnings, ?Project $project)
    {
        $this->authorize('manage-director-strategy');
        $validated = $request->validate($this->ruleValidation());
        $warnings->save($project, $this->ruleValues($validated), $request->user());

        return back()->with('success', $project ? 'Warning rules saved for '.$project->name.'.' : 'Company default warning rules saved.');
    }

    public function resetProjectWarningRules(Project $project, ProjectWarningService $warnings)
    {
        $this->authorize('manage-director-strategy');
        $warnings->resetProject($project);

        return back()->with('success', $project->name.' now uses the company default warning rules.');
    }

    private function ruleValidation(): array
    {
        $rules = [];
        foreach (array_keys(ProjectWarningService::RULES) as $code) {
            $rules["rules.$code.threshold"] = ['required', 'numeric', 'min:0', 'max:99999999999999.99'];
            $rules["rules.$code.active"] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    private function ruleValues(array $validated): array
    {
        return collect($validated['rules'] ?? [])->map(fn ($rule) => [
            'threshold' => $rule['threshold'],
            'active' => (bool) ($rule['active'] ?? false),
        ])->all();
    }
}
