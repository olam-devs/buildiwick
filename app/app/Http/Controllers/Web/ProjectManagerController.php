<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideExpenseRequestRequest;
use App\Http\Requests\SaveExpenseCommercialDetailsRequest;
use App\Http\Requests\SaveInternalBudgetEntryRequest;
use App\Http\Requests\StoreLabourAgreementRequest;
use App\Http\Requests\StoreSiteExpenseRequest;
use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\LabourAgreement;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\SupplierPayee;
use App\Models\SupplierPaymentDestination;
use App\Services\ExpenseRequestCommercialService;
use App\Services\ExpenseRequestDecisionService;
use App\Services\ProjectManagerBudgetService;
use App\Services\SiteExpenseRequestService;
use App\Support\CostEntryOptions;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectManagerController extends Controller
{
    public function requests(Request $request)
    {
        abort_unless($request->user()->hasRole('project_manager') || $request->user()->hasRole('admin'), 403);
        $filters = $request->validate([
            'filter' => ['nullable', 'in:all,awaiting_pricing,ready,awaiting_director,approved,rejected'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'phase_id' => ['nullable', 'integer', 'exists:project_phases,id'],
            'category' => ['nullable', 'in:materials,labour_subcontracts,logistics_equipment,preliminaries_overheads'],
        ]);
        $query = ExpenseRequest::with(['project', 'phase', 'requestedBy', 'supplierPayee', 'labourAgreement'])
            ->where('status', '!=', 'cancelled')->orderByDesc('submitted_at');
        if ($request->user()->hasRole('project_manager')) {
            $query->whereIn('project_id', $request->user()->assignedProjects()->select('projects.id'));
        }
        $counts = [
            'all' => (clone $query)->count(),
            'awaiting_pricing' => (clone $query)->where('status', 'pending_approval')->whereNull('commercial_total')->count(),
            'ready' => (clone $query)->where('status', 'pending_approval')->whereNotNull('commercial_total')->count(),
            'awaiting_director' => (clone $query)->where('status', 'pending_director_approval')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
        ];
        $query->when($request->integer('project_id'), fn ($builder, int $projectId) => $builder->where('project_id', $projectId))
            ->when($request->integer('phase_id'), fn ($builder, int $phaseId) => $builder->where('project_phase_id', $phaseId))
            ->when($filters['category'] ?? null, fn ($builder, string $category) => $builder->where('main_category', $category));
        match ($filters['filter'] ?? 'all') {
            'awaiting_pricing' => $query->where('status', 'pending_approval')->whereNull('commercial_total'),
            'ready' => $query->where('status', 'pending_approval')->whereNotNull('commercial_total'),
            'awaiting_director' => $query->where('status', 'pending_director_approval'),
            'approved' => $query->where('status', 'approved'),
            'rejected' => $query->where('status', 'rejected'),
            default => null,
        };

        $projectQuery = Project::query()->orderBy('name');
        if ($request->user()->hasRole('project_manager')) {
            $projectQuery->whereIn('id', $request->user()->assignedProjects()->select('projects.id'));
        }
        $projects = $projectQuery->get(['id', 'name']);
        $phases = ProjectPhase::query()->whereIn('project_id', $projects->pluck('id'))->orderBy('phase_order')->get();

        return view('pm.requests', [
            'requests' => $query->get(),
            'labels' => ProjectManagerBudgetService::CATEGORIES,
            'filter' => $filters['filter'] ?? 'all',
            'filters' => $filters,
            'counts' => $counts,
            'projects' => $projects,
            'phases' => $phases,
        ]);
    }

    public function createRequest(Request $request, Project $project)
    {
        abort_unless($request->user()->hasRole('project_manager'), 403);
        $this->authorize('create', [ExpenseRequest::class, $project]);
        $activePhases = $project->phases()->where('status', 'active')
            ->whereIn('stage_code', SiteExpenseRequestService::PHASES)->orderBy('phase_order')->get();

        return view('site.request-form', [
            'project' => $project,
            'phase' => $activePhases->count() === 1 ? $activePhases->first() : null,
            'phaseConfiguration' => $activePhases->count(),
            'expenseRequest' => null,
            'lookups' => $this->lookups(),
            'formAudience' => 'project_manager',
            'labourAgreements' => $project->labourAgreements()->whereIn('status', ['draft', 'active'])->orderBy('fundi_subcontractor')->get(),
        ]);
    }

    public function storeRequest(StoreSiteExpenseRequest $request, Project $project, SiteExpenseRequestService $service)
    {
        abort_unless($request->user()->hasRole('project_manager'), 403);
        $created = $service->create($project, $request->validated(), $request->user(), $request->file('evidence'));

        return redirect()->route('pm.requests.commercial.edit', $created)
            ->with('success', 'Request '.$created->request_code.' created. Complete the commercial details, then submit it to the Director.');
    }

    public function labourAgreements(Request $request)
    {
        abort_unless($request->user()->hasRole('project_manager') || $request->user()->hasRole('admin'), 403);
        $projectQuery = Project::query()->orderBy('name');
        if ($request->user()->hasRole('project_manager')) {
            $projectQuery->whereIn('id', $request->user()->assignedProjects()->select('projects.id'));
        }
        $projects = $projectQuery->get(['id', 'name', 'project_code']);
        $agreements = LabourAgreement::query()
            ->with(['project', 'expenseRequests.payments'])
            ->whereIn('project_id', $projects->pluck('id'))
            ->orderByDesc('agreement_date')->get();

        return view('pm.labour.index', compact('projects', 'agreements'));
    }

    public function createLabourAgreement(Request $request, Project $project)
    {
        abort_unless($request->user()->hasRole('project_manager') || $request->user()->hasRole('admin'), 403);
        $this->authorize('create', [LabourAgreement::class, $project]);

        return view('pm.labour.form', [
            'project' => $project,
            'labourTypes' => Lookup::where('type', 'subcategory')->where('parent_code', 'labour_subcontracts')->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function storeLabourAgreement(StoreLabourAgreementRequest $request, Project $project)
    {
        abort_unless($request->user()->hasRole('project_manager') || $request->user()->hasRole('admin'), 403);
        $this->authorize('create', [LabourAgreement::class, $project]);
        abort_unless((int) $request->validated()['project_id'] === (int) $project->id, 404);
        $agreement = LabourAgreement::create($request->validated() + [
            'agreement_code' => 'AGR-TMP-'.Str::ulid(),
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);
        $agreement->update(['agreement_code' => 'AGR-'.str_pad((string) $agreement->id, 6, '0', STR_PAD_LEFT)]);

        return redirect()->route('pm.labour.index')->with('success', 'Labour/subcontract agreement '.$agreement->agreement_code.' created. Requests can now be linked to it.');
    }

    public function editCommercial(Request $request, ExpenseRequest $expenseRequest, ExpenseRequestDecisionService $decisions)
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->hasRole('project_manager'), 403);
        abort_unless($request->user()->can('view', $expenseRequest), 403);

        return view('pm.commercial-form', [
            'expenseRequest' => $expenseRequest->load(['project', 'phase', 'labourAgreement', 'supplierPayee', 'commercialPaymentDestination', 'latestPmApproval.decidedBy', 'latestDirectorApproval.decidedBy']),
            'suppliers' => SupplierPayee::where('is_active', true)->orderBy('name')->get(),
            'destinations' => SupplierPaymentDestination::where('is_active', true)->orderBy('supplier_payee_id')->get(),
            'paymentMethods' => Lookup::ofType('payment_method')->get(),
            'impact' => $decisions->impact($expenseRequest),
        ]);
    }

    public function updateCommercial(SaveExpenseCommercialDetailsRequest $request, ExpenseRequest $expenseRequest, ExpenseRequestCommercialService $commercial)
    {
        $commercial->prepare($expenseRequest, $request->validated(), $request->user(), $request->file('commercial_evidence'));

        return redirect()->route('pm.requests.commercial.edit', $expenseRequest)->with('success', 'Commercial details saved.');
    }

    public function decide(DecideExpenseRequestRequest $request, ExpenseRequest $expenseRequest, ExpenseRequestDecisionService $decisions)
    {
        $decisions->decide($expenseRequest, $request->validated()['decision'], $request->validated()['notes'], $request->user());

        $message = $request->validated()['decision'] === 'approve'
            ? 'PM approval recorded and the request was submitted to the Director. It is not yet available to Accounts.'
            : 'PM rejection recorded. No payment was created.';

        return redirect()->route('pm.requests.commercial.edit', $expenseRequest)->with('success', $message);
    }

    public function commercialEvidence(Request $request, ExpenseRequest $expenseRequest): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('admin') || $user->hasRole('director') || $user->hasRole('accounts')
            || ($user->hasRole('project_manager') && $user->can('view', $expenseRequest)), 403);
        abort_unless($expenseRequest->commercial_evidence_link && Storage::disk('local')->exists($expenseRequest->commercial_evidence_link), 404);

        return Storage::disk('local')->download($expenseRequest->commercial_evidence_link, basename($expenseRequest->commercial_evidence_link));
    }

    public function index(Request $request, ProjectManagerBudgetService $budgets)
    {
        $user = $request->user();
        abort_unless($user->hasRole('admin') || $user->hasRole('project_manager'), 403);
        if ($user->hasRole('project_manager')) {
            $this->authorize('viewAny', InternalBudget::class);
        }

        $projects = Project::query()
            ->when($user->hasRole('project_manager'), fn ($query) => $query->whereIn('id', $user->assignedProjects()->select('projects.id')))
            ->with(['client', 'activePhase', 'phases', 'internalBudgets' => fn ($query) => $query->orderByDesc('version'), 'expenseRequests'])
            ->orderBy('name')->get()->map(function (Project $project) use ($budgets) {
                $current = $this->currentBudget($project);
                $requests = $project->expenseRequests->where('status', '!=', 'cancelled');
                $approvedCommitments = Money::sum($requests->where('status', 'approved')->map(fn (ExpenseRequest $request) => $request->commercial_total ?? $request->approved_amount ?? $request->total_amount ?? '0.00'));
                $comparison = $budgets->comparison($budgets->projectBudget($project), $approvedCommitments);
                $pending = $requests->where('status', 'pending_approval');

                return (object) [
                    'project' => $project,
                    'budget' => $current,
                    'comparison' => $comparison,
                    'pending_count' => $pending->count(),
                    'pending_amount' => Money::sum($pending->filter(fn (ExpenseRequest $request) => $request->commercial_total !== null)->map(fn (ExpenseRequest $request) => $request->commercial_total)),
                    'commercialized_count' => $requests->whereNotNull('commercial_total')->count(),
                    'awaiting_commercial_count' => $pending->whereNull('commercial_total')->count(),
                    'ready_count' => $pending->whereNotNull('commercial_total')->count(),
                    'awaiting_director_count' => $requests->where('status', 'pending_director_approval')->count(),
                    'approved_awaiting_accounts' => $requests->where('status', 'approved')->filter(fn (ExpenseRequest $request) => Money::compare($request->approved_outstanding_amount, '0') > 0)->count(),
                ];
            });

        return view('pm.index', compact('projects'));
    }

    public function show(Request $request, Project $project, ProjectManagerBudgetService $budgets)
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->hasRole('project_manager'), 403);
        $this->authorize('viewProject', [InternalBudget::class, $project]);
        $project->load(['client', 'activePhase', 'phases' => fn ($query) => $query->whereIn('stage_code', ProjectManagerBudgetService::PHASES)->orderBy('phase_order')]);
        $working = $project->internalBudgets()->where('status', 'draft')->with('entries.enteredBy')->first();
        $current = $working ?? $project->internalBudgets()->whereIn('status', ['finalized', 'approved'])->orderByDesc('version')->with('entries.enteredBy')->first();
        $comparison = $budgets->projectComparison($project);
        $siteRequests = $project->expenseRequests()->where('status', '!=', 'cancelled')->with('phase')->orderByDesc('submitted_at')->get();
        $entryGroups = $current ? $current->entries->groupBy('project_phase_id') : collect();
        $history = $project->internalBudgets()->when($current, fn ($query) => $query->where('id', '!=', $current->id))->with('entries')->orderByDesc('version')->get()
            ->map(fn (InternalBudget $budget) => (object) ['budget' => $budget, 'total' => $budget->total_amount]);

        return view('pm.show', [
            'project' => $project,
            'workingBudget' => $working,
            'currentBudget' => $current,
            'comparison' => $comparison,
            'siteRequests' => $siteRequests,
            'entryGroups' => $entryGroups,
            'history' => $history,
            'labels' => ProjectManagerBudgetService::CATEGORIES,
            'categoryLabels' => ProjectManagerBudgetService::CATEGORIES,
            'subcategoryLabels' => Lookup::where('type', 'subcategory')->pluck('label', 'code'),
            'unitLabels' => Lookup::where('type', 'unit')->pluck('label', 'code'),
        ]);
    }

    public function startBudget(Request $request, Project $project, ProjectManagerBudgetService $budgets)
    {
        $this->authorize('create', [InternalBudget::class, $project]);
        $budget = $budgets->ensureWorkingBudget($project, $request->user());

        return redirect()->route('pm.projects.show', $project)->with('success', 'Working internal budget '.$budget->budget_code.' is ready.');
    }

    public function createEntry(Request $request, Project $project, ProjectPhase $phase, ProjectManagerBudgetService $budgets)
    {
        $this->authorize('viewProject', [InternalBudget::class, $project]);
        abort_unless((int) $phase->project_id === (int) $project->id && in_array($phase->stage_code, ProjectManagerBudgetService::PHASES, true), 404);
        $budget = $budgets->ensureWorkingBudget($project, $request->user());
        $this->authorize('create', [InternalBudgetEntry::class, $budget]);

        return view('pm.entry-form', ['project' => $project, 'phase' => $phase, 'budget' => $budget, 'entry' => null, 'lookups' => $this->lookups()]);
    }

    public function storeEntry(SaveInternalBudgetEntryRequest $request, Project $project, ProjectPhase $phase, ProjectManagerBudgetService $budgets)
    {
        $this->authorize('viewProject', [InternalBudget::class, $project]);
        abort_unless((int) $phase->project_id === (int) $project->id && in_array($phase->stage_code, ProjectManagerBudgetService::PHASES, true), 404);
        $budget = $budgets->ensureWorkingBudget($project, $request->user());
        $budgets->createEntry($budget, $phase, $request->validated(), $request->user());

        return redirect()->route('pm.projects.show', $project)->with('success', 'Internal budget entry added.');
    }

    public function editEntry(Request $request, Project $project, ProjectPhase $phase, InternalBudgetEntry $entry)
    {
        abort_unless((int) $phase->project_id === (int) $project->id && (int) $entry->project_phase_id === (int) $phase->id, 404);
        $this->authorize('update', $entry);

        return view('pm.entry-form', ['project' => $project, 'phase' => $phase, 'budget' => $entry->budget, 'entry' => $entry, 'lookups' => $this->lookups()]);
    }

    public function updateEntry(SaveInternalBudgetEntryRequest $request, Project $project, ProjectPhase $phase, InternalBudgetEntry $entry, ProjectManagerBudgetService $budgets)
    {
        abort_unless((int) $phase->project_id === (int) $project->id && (int) $entry->project_phase_id === (int) $phase->id, 404);
        $budgets->updateEntry($entry, $request->validated(), $request->user());

        return redirect()->route('pm.projects.show', $project)->with('success', 'Internal budget entry updated.');
    }

    public function deleteEntry(Request $request, Project $project, ProjectPhase $phase, InternalBudgetEntry $entry, ProjectManagerBudgetService $budgets)
    {
        abort_unless((int) $phase->project_id === (int) $project->id && (int) $entry->project_phase_id === (int) $phase->id, 404);
        $budgets->deleteEntry($entry, $request->user());

        return redirect()->route('pm.projects.show', $project)->with('success', 'Draft budget entry deleted.');
    }

    public function finalize(Request $request, Project $project, InternalBudget $budget, ProjectManagerBudgetService $budgets)
    {
        abort_unless((int) $budget->project_id === (int) $project->id, 404);
        $budgets->finalize($budget, $request->user());

        return redirect()->route('pm.projects.show', $project)->with('success', 'Internal budget finalized as a planning baseline.');
    }

    private function currentBudget(Project $project): ?InternalBudget
    {
        return $project->internalBudgets->firstWhere('status', 'draft')
            ?? $project->internalBudgets->firstWhere('status', 'finalized')
            ?? $project->internalBudgets->firstWhere('status', 'approved');
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
}
