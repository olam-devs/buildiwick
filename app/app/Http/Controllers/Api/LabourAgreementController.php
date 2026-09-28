<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLabourAgreementRequest;
use App\Models\LabourAgreement;
use App\Models\Project;
use Illuminate\Http\Request;

class LabourAgreementController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', LabourAgreement::class);

        $user = $request->user();
        $query = LabourAgreement::query()->with('project:id,name,project_code');

        if ($user->hasRole('project_manager')) {
            $query->whereIn('project_id', $user->assignedProjects()->pluck('projects.id'));
        }

        return $query->get()->map(fn (LabourAgreement $a) => [
            'id' => $a->id,
            'agreement_code' => $a->agreement_code,
            'project' => $a->project->name,
            'fundi_subcontractor' => $a->fundi_subcontractor,
            'scope_of_work' => $a->scope_of_work,
            'agreed_value' => (float) $a->agreed_value,
            'paid_to_date' => $a->paidToDate(),
            'outstanding' => $a->outstanding(),
            'status' => $a->status,
        ]);
    }

    /** "New agreement" mode in the entry form — the terms only, no money moving yet. */
    public function store(StoreLabourAgreementRequest $request)
    {
        $data = $request->validated();
        $project = Project::findOrFail($data['project_id']);
        $this->authorize('create', [LabourAgreement::class, $project]);

        $agreement = LabourAgreement::create($data + [
            'agreement_code' => $this->nextAgreementCode(),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return response()->json($agreement->fresh(), 201);
    }

    private function nextAgreementCode(): string
    {
        $n = LabourAgreement::count() + 1;

        return 'AGR-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
