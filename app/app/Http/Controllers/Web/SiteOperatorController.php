<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteExpenseRequestBatch;
use App\Http\Requests\UpdateSiteExpenseRequest;
use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteExpenseRequestService;
use App\Support\CostEntryOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteOperatorController extends Controller
{
    public function index(Request $request, SiteExpenseRequestService $requests)
    {
        $user = $request->user();
        $this->authorizeWorkspaceRole($user);
        $query = Project::query()->with(['client', 'activePhase', 'phases']);
        if ($user->hasRole('site_operator')) {
            $query->whereIn('id', $user->assignedProjects()->select('projects.id'));
        }

        $projects = $query->orderBy('name')->get()->map(function (Project $project) use ($requests) {
            $activePhases = $project->phases->where('status', 'active')->sortBy('phase_order');
            $configuration = $activePhases->count();

            return (object) [
                'project' => $project,
                'summary' => $requests->projectSummary($project),
                'phase_configuration' => $configuration,
                'phase_label' => $configuration === 1 ? $activePhases->first()->stage_label : ($configuration === 0 ? 'Phase not configured' : 'Multiple active phases'),
            ];
        });

        return view('site.index', compact('projects'));
    }

    public function project(Request $request, Project $project, SiteExpenseRequestService $requests)
    {
        $this->authorizeWorkspaceRole($request->user());
        $this->authorize('view', $project);
        $project->load(['client', 'phases']);
        $summary = $requests->projectSummary($project);
        $expenseRequests = $project->expenseRequests()->with(['phase', 'requestedBy', 'approvals.decidedBy', 'payments'])
            ->orderByDesc('submitted_at')->orderByDesc('id')->get();
        $activeCount = $project->phases()->where('status', 'active')->count();
        $currentPhase = $activeCount === 1
            ? $project->phases->first(fn ($phase) => $phase->status === 'active')
            : null;

        return view('site.project', [
            'project' => $project,
            'summary' => $summary,
            'showFinancialDetails' => ! $request->user()->hasRole('site_operator'),
            'expenseRequests' => $expenseRequests,
            'activeCount' => $activeCount,
            'currentPhase' => $currentPhase,
            'categoryLabels' => SiteExpenseRequestService::CATEGORIES,
            'subcategoryLabels' => Lookup::where('type', 'subcategory')->pluck('label', 'code'),
            'unitLabels' => Lookup::where('type', 'unit')->pluck('label', 'code'),
        ]);
    }

    public function requests(Request $request)
    {
        $this->authorizeWorkspaceRole($request->user());
        $this->authorize('viewAny', ExpenseRequest::class);
        $user = $request->user();
        $query = ExpenseRequest::with(['project', 'phase', 'payments'])->orderByDesc('submitted_at');
        if ($user->hasRole('site_operator')) {
            $query->whereIn('project_id', $user->assignedProjects()->select('projects.id'));
        }

        return view('site.requests', ['requests' => $query->get()]);
    }

    public function create(Request $request, Project $project)
    {
        $this->authorizeWorkspaceRole($request->user());
        $this->authorize('create', [ExpenseRequest::class, $project]);
        $activePhases = $project->phases()->where('status', 'active')->orderBy('phase_order')->get();

        return view('site.request-form', [
            'project' => $project,
            'phase' => $activePhases->count() === 1 ? $activePhases->first() : null,
            'phaseConfiguration' => $activePhases->count(),
            'expenseRequest' => null,
            'lookups' => $this->lookups(),
            'labourAgreements' => $project->labourAgreements()->whereIn('status', ['draft', 'active'])->orderBy('fundi_subcontractor')->get(),
        ]);
    }

    public function store(StoreSiteExpenseRequestBatch $request, Project $project, SiteExpenseRequestService $service)
    {
        $created = $service->createBatch($project, $request->validated(), $request->user(), $request->file('evidence'));
        $first = $created->firstOrFail();

        return redirect()->route('site.requests.show', [$project, $first])
            ->with('success', 'Site request '.$first->request_batch_code.' with '.$created->count().' item(s) was submitted to the Project Manager.');
    }

    public function show(Request $request, Project $project, ExpenseRequest $expenseRequest)
    {
        $this->authorizeWorkspaceRole($request->user());
        abort_unless((int) $expenseRequest->project_id === (int) $project->id, 404);
        $this->authorize('view', $expenseRequest);
        $expenseRequest->load(['project', 'phase', 'labourAgreement', 'requestedBy', 'cancelledBy', 'approvals.decidedBy', 'payments.recordedBy']);
        $batchItems = ExpenseRequest::query()
            ->where('request_batch_code', $expenseRequest->request_batch_code ?: $expenseRequest->request_code)
            ->with(['phase', 'approvals.decidedBy', 'payments.recordedBy'])
            ->orderBy('request_line_number')->get();
        $canEdit = $request->user()->can('update', $expenseRequest);

        return view('site.request-show', [
            'project' => $project,
            'expenseRequest' => $expenseRequest,
            'batchItems' => $batchItems,
            'canEdit' => $canEdit,
            'showFinancialDetails' => ! $request->user()->hasRole('site_operator'),
            'categoryLabels' => SiteExpenseRequestService::CATEGORIES,
            'subcategoryLabels' => Lookup::where('type', 'subcategory')->pluck('label', 'code'),
            'unitLabels' => Lookup::where('type', 'unit')->pluck('label', 'code'),
        ]);
    }

    public function edit(Request $request, Project $project, ExpenseRequest $expenseRequest)
    {
        abort_unless($request->user()->hasRole('site_operator'), 403);
        abort_unless((int) $expenseRequest->project_id === (int) $project->id, 404);
        $this->authorize('update', $expenseRequest);

        return view('site.request-form', [
            'project' => $project,
            'phase' => $expenseRequest->phase,
            'phaseConfiguration' => 1,
            'expenseRequest' => $expenseRequest,
            'lookups' => $this->lookups(),
            'labourAgreements' => $project->labourAgreements()->whereIn('status', ['draft', 'active'])->orderBy('fundi_subcontractor')->get(),
        ]);
    }

    public function update(UpdateSiteExpenseRequest $request, Project $project, ExpenseRequest $expenseRequest, SiteExpenseRequestService $service)
    {
        abort_unless($request->user()->hasRole('site_operator'), 403);
        abort_unless((int) $expenseRequest->project_id === (int) $project->id, 404);
        $service->update($expenseRequest, $request->validated(), $request->user(), $request->file('evidence'));

        return redirect()->route('site.requests.show', [$project, $expenseRequest])->with('success', 'Pending expense request updated.');
    }

    public function cancel(Request $request, Project $project, ExpenseRequest $expenseRequest, SiteExpenseRequestService $service)
    {
        abort_unless($request->user()->hasRole('site_operator'), 403);
        abort_unless((int) $expenseRequest->project_id === (int) $project->id, 404);
        $service->cancel($expenseRequest, $request->user());

        return redirect()->route('site.requests.show', [$project, $expenseRequest])->with('success', 'Expense request cancelled. Its history has been retained.');
    }

    public function evidence(Request $request, Project $project, ExpenseRequest $expenseRequest): StreamedResponse
    {
        $this->authorizeWorkspaceRole($request->user());
        abort_unless((int) $expenseRequest->project_id === (int) $project->id, 404);
        $this->authorize('view', $expenseRequest);
        abort_unless($expenseRequest->evidence_link && Storage::disk('local')->exists($expenseRequest->evidence_link), 404);

        return Storage::disk('local')->download($expenseRequest->evidence_link, $expenseRequest->evidence_original_name ?? basename($expenseRequest->evidence_link));
    }

    private function lookups(): array
    {
        return [
            'categories' => Lookup::ofType('main_category')->get(),
            'subcategories' => Lookup::ofType('subcategory')->get(),
            'units' => Lookup::ofType('unit')->get(),
            'unitRecommendations' => CostEntryOptions::unitRecommendations(),
        ];
    }

    private function authorizeWorkspaceRole(User $user): void
    {
        abort_unless($user->hasRole('site_operator') || $user->hasRole('admin'), 403);
    }
}
