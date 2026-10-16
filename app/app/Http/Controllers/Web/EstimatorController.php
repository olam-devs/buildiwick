<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveEstimateEntryRequest;
use App\Models\EstimateEntry;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEditLog;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Services\EstimatorEstimateService;
use App\Services\ExcelExportService;
use App\Services\ProjectPhaseService;
use Illuminate\Http\Request;

class EstimatorController extends Controller
{
    public function index(Request $request, EstimatorEstimateService $estimates)
    {
        $user = $request->user();
        abort_unless($user->hasRole('admin') || $user->hasRole('estimator_qs'), 403);
        if ($user->hasRole('estimator_qs')) {
            $this->authorize('viewAny', ProjectEstimate::class);
        }

        $projects = Project::query()
            ->when($user->hasRole('estimator_qs'), fn ($query) => $query->whereHas('assignedUsers', fn ($assignedUsers) => $assignedUsers->whereKey($user->id)))
            ->with(['client', 'activePhase', 'estimates' => fn ($query) => $query->with('entries')->orderByDesc('version')])
            ->orderBy('name')
            ->get()
            ->map(function (Project $project) use ($estimates) {
                $estimate = $project->estimates->firstWhere('status', 'draft')
                    ?? $project->estimates->firstWhere('status', 'issued')
                    ?? $project->estimates->sortByDesc('version')->first();
                $summary = $estimate ? $estimates->projectSummary($estimate) : null;

                return (object) [
                    'project' => $project,
                    'estimate' => $estimate,
                    'summary' => $summary,
                    'status' => $estimate?->status ?? 'not_started',
                ];
            });

        return view('estimator.index', compact('projects'));
    }

    public function show(Request $request, Project $project, EstimatorEstimateService $estimates, ProjectPhaseService $phases)
    {
        $this->authorize('viewProject', [ProjectEstimate::class, $project]);

        $project->load([
            'client',
            'activePhase',
            'phases' => fn ($query) => $query->orderBy('phase_order'),
        ]);
        $workingEstimate = $project->estimates()->where('status', 'draft')->with('entries.enteredBy')->first();
        $displayEstimate = $workingEstimate ?? $project->estimates()->whereIn('status', ['issued', 'superseded'])->orderByDesc('version')->with('entries.enteredBy')->first();

        $phaseSummaries = [];
        if ($displayEstimate) {
            foreach ($project->phases as $phase) {
                $phaseSummaries[$phase->id] = $estimates->phaseSummary($displayEstimate, $phase);
            }
        }
        $projectSummary = $displayEstimate ? $estimates->projectSummary($displayEstimate) : null;
        $history = $project->estimates()->when($displayEstimate, fn ($query) => $query->where('id', '!=', $displayEstimate->id))->with('entries')->orderByDesc('version')->get();
        $history = $history->map(fn (ProjectEstimate $version) => (object) [
            'estimate' => $version,
            'summary' => $estimates->projectSummary($version),
        ]);
        $unitLabels = Lookup::where('type', 'unit')->pluck('label', 'code');
        $editLogs = ProjectEditLog::where('project_id', $project->id)->where('document_type', 'estimate')->with('editor')->latest()->get();

        $phasesLocked = $phases->isLocked($project);

        return view('estimator.show', compact('project', 'workingEstimate', 'displayEstimate', 'phaseSummaries', 'projectSummary', 'history', 'unitLabels', 'editLogs', 'phasesLocked'));
    }

    public function storePhase(Request $request, Project $project, ProjectPhaseService $phases)
    {
        $validated = $this->phaseData($request);
        $phases->create($project, $validated, $request->user());

        return back()->with('success', 'Project phase added.');
    }

    public function updatePhase(Request $request, Project $project, ProjectPhase $phase, ProjectPhaseService $phases)
    {
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
        $phases->update($phase, $this->phaseData($request), $request->user());

        return back()->with('success', 'Project phase updated.');
    }

    public function deletePhase(Request $request, Project $project, ProjectPhase $phase, ProjectPhaseService $phases)
    {
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
        $phases->delete($phase, $request->user());

        return back()->with('success', 'Draft phase and its draft entries were deleted.');
    }

    public function resetPhases(Request $request, Project $project, ProjectPhaseService $phases)
    {
        $phases->reset($project, $request->user());

        return back()->with('success', 'Draft phase structure was reset to the default template.');
    }

    public function startEstimate(Request $request, Project $project, EstimatorEstimateService $estimates)
    {
        $this->authorize('viewProject', [ProjectEstimate::class, $project]);
        $estimate = $estimates->ensureWorkingEstimate($project, $request->user());

        return redirect()->route('estimator.projects.show', $project)->with('success', 'Working estimate '.$estimate->estimate_code.' is ready.');
    }

    public function startEstimateEdit(Request $request, Project $project, ProjectEstimate $estimate, EstimatorEstimateService $estimates)
    {
        abort_unless((int) $estimate->project_id === (int) $project->id, 404);
        $validated = $request->validate(['edit_reason' => ['required', 'string', 'max:2000']]);
        $draft = $estimates->startEditing($estimate, $validated['edit_reason'], $request->user());

        return redirect()->route('estimator.projects.show', $project)->with('success', 'Estimate is open for editing as '.$draft->estimate_code.'. The issued version remains in the audit history.');
    }

    public function createEntry(Request $request, Project $project, ProjectPhase $phase, EstimatorEstimateService $estimates)
    {
        $this->authorize('viewProject', [ProjectEstimate::class, $project]);
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
        $estimate = $estimates->ensureWorkingEstimate($project, $request->user());
        $this->authorize('create', [EstimateEntry::class, $estimate]);

        return view('estimator.entry-form', [
            'project' => $project,
            'phase' => $phase,
            'estimate' => $estimate,
            'entry' => null,
            'lookups' => $this->lookupData(),
        ]);
    }

    public function storeEntry(SaveEstimateEntryRequest $request, Project $project, ProjectPhase $phase, EstimatorEstimateService $estimates)
    {
        $this->authorize('viewProject', [ProjectEstimate::class, $project]);
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
        $estimate = $estimates->ensureWorkingEstimate($project, $request->user());
        $estimates->createEntry($estimate, $phase, $request->validated(), $request->user());

        return redirect()->route('estimator.projects.show', $project)->with('success', 'Estimate entry added to '.$phase->stage_label.'.');
    }

    public function editEntry(Request $request, Project $project, ProjectPhase $phase, EstimateEntry $entry)
    {
        abort_unless((int) $phase->project_id === (int) $project->id && (int) $entry->project_phase_id === (int) $phase->id, 404);
        $this->authorize('update', $entry);

        return view('estimator.entry-form', [
            'project' => $project,
            'phase' => $phase,
            'estimate' => $entry->estimate,
            'entry' => $entry,
            'lookups' => $this->lookupData(),
        ]);
    }

    public function updateEntry(SaveEstimateEntryRequest $request, Project $project, ProjectPhase $phase, EstimateEntry $entry, EstimatorEstimateService $estimates)
    {
        abort_unless((int) $phase->project_id === (int) $project->id && (int) $entry->project_phase_id === (int) $phase->id, 404);
        $estimates->updateEntry($entry, $request->validated(), $request->user());

        return redirect()->route('estimator.projects.show', $project)->with('success', 'Estimate entry updated.');
    }

    public function deleteEntry(Request $request, Project $project, ProjectPhase $phase, EstimateEntry $entry, EstimatorEstimateService $estimates)
    {
        abort_unless((int) $phase->project_id === (int) $project->id && (int) $entry->project_phase_id === (int) $phase->id, 404);
        $this->authorize('delete', $entry);
        $validated = $request->validate(['edit_reason' => [$entry->estimate->version > 1 ? 'required' : 'nullable', 'string', 'max:2000']]);
        $estimates->deleteEntry($entry, $validated['edit_reason'] ?? null, $request->user());

        return redirect()->route('estimator.projects.show', $project)->with('success', 'Estimate entry deleted.');
    }

    public function finalize(Request $request, Project $project, ProjectEstimate $estimate, EstimatorEstimateService $estimates)
    {
        abort_unless((int) $estimate->project_id === (int) $project->id, 404);
        $estimates->finalize($estimate, $request->user());

        return redirect()->route('estimator.projects.show', $project)->with('success', 'Estimate issued and locked for invoicing.');
    }

    public function export(Request $request, Project $project, ProjectEstimate $estimate, ExcelExportService $excel)
    {
        abort_unless((int) $estimate->project_id === (int) $project->id, 404);
        $this->authorize('view', $estimate);
        $estimate->load(['entries.phase', 'client', 'preparedBy', 'issuedBy']);
        $rows = $estimate->entries->sortBy(fn ($entry) => [$entry->phase?->phase_order, $entry->id])->map(fn ($entry) => [
            $entry->phase?->stage_label, $entry->work_section ?: 'General work', $entry->item_reference,
            $entry->item_description, $entry->quantity ?? 1, $entry->unit ?? 'LS',
            $entry->client_unit_cost, $entry->line_total, $entry->notes,
        ]);
        $rows->push(['', '', '', 'PROJECT TOTAL', '', '', '', $estimate->total_amount, '']);

        return $excel->download($estimate->estimate_code, 'Project Estimate - '.$project->name,
            ['Phase', 'Subphase / Section', 'Item Ref.', 'Description', 'Quantity', 'Unit', 'Rate', 'Amount', 'Notes'],
            $rows, [$estimate->estimate_code, 'Client: '.($estimate->client?->name ?? 'Not linked'), 'Location: '.($project->location ?? 'Not set'), 'Version '.$estimate->version, 'Status: '.ucfirst($estimate->status)], [6, 7]);
    }

    private function lookupData(): array
    {
        return [
            'units' => Lookup::ofType('unit')->get(),
        ];
    }

    private function phaseData(Request $request): array
    {
        return $request->validate([
            'phase_name' => ['required', 'string', 'max:150'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_completion_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
        ]);
    }
}
