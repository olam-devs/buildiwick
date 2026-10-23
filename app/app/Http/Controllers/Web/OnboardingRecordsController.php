<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OnboardingImport;
use App\Models\Project;
use App\Services\OnboardingRecordsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Accounts' per-project page for bringing an ongoing project's past records into the system. */
class OnboardingRecordsController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('import-onboarding-records', $project);
        $project->load('client');
        $imports = OnboardingImport::query()->where('project_id', $project->id)->with(['importedBy', 'undoneBy'])->latest()->get();
        $hasIssuedEstimate = $project->estimates()->where('status', 'issued')->exists();
        $hasApprovedBudget = $project->internalBudgets()->where('status', 'approved')->exists();

        return view('accountant.onboarding', compact('project', 'imports', 'hasIssuedEstimate', 'hasApprovedBudget'));
    }

    public function template(Project $project, OnboardingRecordsService $records)
    {
        $this->authorize('import-onboarding-records', $project);

        return $records->template($project);
    }

    public function store(Request $request, Project $project, OnboardingRecordsService $records)
    {
        $this->authorize('import-onboarding-records', $project);
        $validated = $request->validate([
            'records_file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $import = $records->import($project, $validated['records_file'], $request->user(), $validated['notes'] ?? null);

        return redirect()->route('accountant.onboarding.index', $project)->with('success',
            $import->import_code.' imported: '.$import->expense_count.' expense(s), '.$import->invoice_count.' invoice(s), '.$import->client_payment_count.' client payment(s).');
    }

    public function download(Project $project, OnboardingImport $onboardingImport)
    {
        $this->authorize('import-onboarding-records', $project);
        abort_unless((int) $onboardingImport->project_id === (int) $project->id, 404);
        abort_unless($onboardingImport->stored_path && Storage::disk('local')->exists($onboardingImport->stored_path), 404);

        return Storage::disk('local')->download($onboardingImport->stored_path, $onboardingImport->original_filename);
    }

    public function undo(Request $request, Project $project, OnboardingImport $onboardingImport, OnboardingRecordsService $records)
    {
        $this->authorize('import-onboarding-records', $project);
        abort_unless((int) $onboardingImport->project_id === (int) $project->id, 404);
        $validated = $request->validate(['undo_reason' => ['required', 'string', 'max:2000']]);
        $records->undo($onboardingImport, $validated['undo_reason'], $request->user());

        return redirect()->route('accountant.onboarding.index', $project)->with('success', $onboardingImport->import_code.' was undone; its records were removed.');
    }
}
