<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassifyExpenseRequest;
use App\Http\Requests\DecideExpenseRequestRequest;
use App\Http\Requests\SaveExpenseCommercialDetailsRequest;
use App\Http\Requests\SaveExpenseRequestBatchCommercial;
use App\Http\Requests\SaveInternalBudgetEntryRequest;
use App\Http\Requests\StoreLabourAgreementRequest;
use App\Http\Requests\StoreSiteExpenseRequestBatch;
use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\LabourAgreement;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEditLog;
use App\Models\ProjectPhase;
use App\Models\SupplierPayee;
use App\Models\SupplierPaymentDestination;
use App\Services\ExcelExportService;
use App\Services\ExpenseRequestBatchCommercialService;
use App\Services\ExpenseRequestClassificationService;
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
            'filter' => ['nullable', 'in:all,awaiting_pricing,ready,approved,rejected'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'phase_id' => ['nullable', 'integer', 'exists:project_phases,id'],
            'category' => ['nullable', 'in:materials,labour_subcontracts,logistics_equipment,preliminaries_overheads'],
        ]);
        $query = ExpenseRequest::with(['project', 'phase', 'requestedBy', 'supplierPayee', 'labourAgreement'])
            ->where('status', '!=', 'cancelled')->orderByDesc('submitted_at');
        if ($request->user()->hasRole('project_manager')) {
            $query->whereIn('project_id', $request->user()->assignedProjects()->select('projects.id'));
        }
        $allBatches = $this->requestBatches((clone $query)->get());
        $counts = ['all' => $allBatches->count()];
        foreach (['awaiting_pricing', 'ready', 'approved', 'rejected'] as $state) {
            $counts[$state] = $allBatches->where('state', $state)->count();
        }
        $query->when($request->integer('project_id'), fn ($builder, int $projectId) => $builder->where('project_id', $projectId))
            ->when($request->integer('phase_id'), fn ($builder, int $phaseId) => $builder->where('project_phase_id', $phaseId))
            ->when($filters['category'] ?? null, fn ($builder, string $category) => $builder->where('main_category', $category));
        $batches = $this->requestBatches($query->get());
        if (($filters['filter'] ?? 'all') !== 'all') {
            $batches = $batches->where('state', $filters['filter'])->values();
        }

        $projectQuery = Project::query()->orderBy('name');
        if ($request->user()->hasRole('project_manager')) {
            $projectQuery->whereIn('id', $request->user()->assignedProjects()->select('projects.id'));
        }
        $projects = $projectQuery->get(['id', 'name']);
        $phases = ProjectPhase::query()->whereIn('project_id', $projects->pluck('id'))->orderBy('phase_order')->get();

        return view('pm.requests', [
            'batches' => $batches,
            'labels' => ProjectManagerBudgetService::CATEGORIES,
            'filter' => $filters['filter'] ?? 'all',
            'filters' => $filters,
            'counts' => $counts,
            'projects' => $projects,
            'phases' => $phases,
        ]);
    }

    public function exportRequests(Request $request, ExcelExportService $excel)
    {
        abort_unless($request->user()->hasRole('project_manager') || $request->user()->hasRole('admin'), 403);
        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'category' => ['nullable', 'in:materials,labour_subcontracts,logistics_equipment,preliminaries_overheads'],
        ]);
        $requests = ExpenseRequest::with(['project', 'phase', 'requestedBy', 'supplierPayee', 'internalBudgetEntry'])
            ->where('status', '!=', 'cancelled')
            ->when($request->user()->hasRole('project_manager'), fn ($query) => $query->whereIn('project_id', $request->user()->assignedProjects()->select('projects.id')))
            ->when($request->integer('project_id'), fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('main_category', $category))
            ->orderBy('project_id')->orderByDesc('submitted_at')->get();
        $rows = $requests->map(fn (ExpenseRequest $item) => [
            $item->request_batch_code ?: $item->request_code, $item->request_code, $item->project->project_code,
            $item->project->name, $item->phase?->stage_label, ExpenseRequest::TYPES[$item->request_type] ?? $item->request_type,
            ProjectManagerBudgetService::CATEGORIES[$item->main_category] ?? $item->main_category, $item->subcategory,
            $item->item_description, $item->quantity, $item->unit, $item->requestedBy?->name, $item->submitted_at,
            $item->supplierPayee?->name, $item->internalBudgetEntry?->item_description, $item->commercial_unit_rate,
            $item->commercial_total, ucwords(str_replace('_', ' ', $item->status)), $item->notes,
        ]);
        $title = ($filters['category'] ?? null) === 'labour_subcontracts' ? 'Labour & Subcontract Requests' : 'Project Requests';

        return $excel->download(strtolower(str_replace(' ', '-', $title)), $title,
            ['Batch', 'Request', 'Project Code', 'Project', 'Phase', 'Type', 'Category', 'Subcategory', 'Description', 'Quantity', 'Unit', 'Submitted By', 'Submitted At', 'Supplier / Payee', 'Internal Budget Line', 'PM Unit Price', 'PM Total', 'Status', 'Site Notes'],
            $rows, [], [15, 16], [12]);
    }

    public function createRequest(Request $request, Project $project)
    {
        abort_unless($request->user()->hasRole('project_manager'), 403);
        $this->authorize('create', [ExpenseRequest::class, $project]);
        $phases = $project->phases()->orderBy('phase_order')->get();
        $activePhases = $phases->where('status', 'active');
        $budgetEntries = InternalBudgetEntry::query()
            ->with('phase')
            ->whereHas('budget', fn ($query) => $query->where('project_id', $project->id)->where('status', 'approved'))
            ->where('main_category', 'materials')
            ->orderBy('item_description')->get();

        return view('site.request-form', [
            'project' => $project,
            'phase' => $activePhases->count() === 1 ? $activePhases->first() : null,
            'phases' => $phases,
            'phaseConfiguration' => $activePhases->count(),
            'budgetEntries' => $budgetEntries,
            'expenseRequest' => null,
            'lookups' => $this->lookups(),
            'formAudience' => 'project_manager',
            'labourAgreements' => $project->labourAgreements()->whereIn('status', ['draft', 'active'])->orderBy('fundi_subcontractor')->get(),
        ]);
    }

    public function storeRequest(StoreSiteExpenseRequestBatch $request, Project $project, SiteExpenseRequestService $service)
    {
        abort_unless($request->user()->hasRole('project_manager'), 403);
        $created = $service->createBatch($project, $request->validated(), $request->user(), $request->file('evidence'));
        $first = $created->firstOrFail();

        return redirect()->route('pm.procurement.show', $first)
            ->with('success', 'Request '.$first->request_batch_code.' created. Verify its items in Step 1.');
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
            ->when($request->integer('project_id'), fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->orderByDesc('agreement_date')->get();

        return view('pm.labour.index', compact('projects', 'agreements'));
    }

    public function exportLabourAgreements(Request $request, ExcelExportService $excel)
    {
        abort_unless($request->user()->hasRole('project_manager') || $request->user()->hasRole('admin'), 403);
        $projectIds = $request->user()->hasRole('project_manager')
            ? $request->user()->assignedProjects()->pluck('projects.id')
            : Project::pluck('id');
        $agreements = LabourAgreement::with(['project', 'expenseRequests.payments'])
            ->whereIn('project_id', $projectIds)
            ->when($request->integer('project_id'), fn ($query, int $projectId) => $query->where('project_id', $projectId))
            ->orderBy('project_id')->orderBy('agreement_code')->get();
        $rows = $agreements->map(function (LabourAgreement $agreement) {
            $approved = Money::sum($agreement->expenseRequests->where('status', 'approved')->map(fn ($item) => $item->approved_payable_amount));
            $paid = (string) $agreement->paidToDate();

            return [$agreement->agreement_code, $agreement->project->project_code, $agreement->project->name,
                $agreement->fundi_subcontractor, $agreement->labour_type, $agreement->scope_of_work,
                $agreement->agreement_date, $agreement->start_date, $agreement->expected_completion,
                $agreement->agreed_value, $approved, $paid, Money::difference($agreement->agreed_value, $paid), $agreement->status];
        });

        return $excel->download('labour-and-subcontract-agreements', 'Labour & Subcontract Agreements',
            ['Agreement', 'Project Code', 'Project', 'Worker / Fundi / Subcontractor', 'Type', 'Scope', 'Agreement Date', 'Start Date', 'Expected Completion', 'Agreed Value', 'PM Approved Requests', 'Actual Paid', 'Outstanding', 'Status'],
            $rows, [], [9, 10, 11, 12], [6, 7, 8]);
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

        $expenseRequest->load(['project.phases', 'phase', 'labourAgreement', 'internalBudgetEntry', 'supplierPayee', 'commercialPaymentDestination', 'latestPmApproval.decidedBy']);
        $batchItems = ExpenseRequest::query()
            ->where('request_batch_code', $expenseRequest->request_batch_code ?: $expenseRequest->request_code)
            ->with(['phase', 'labourAgreement', 'internalBudgetEntry', 'supplierPayee', 'commercialPaymentDestination', 'latestPmApproval.decidedBy'])
            ->orderBy('request_line_number')->get();
        foreach ($batchItems as $item) {
            abort_unless($request->user()->can('view', $item), 403);
        }
        $currentBudget = $expenseRequest->project->internalBudgets()->where('status', 'approved')
            ->orderByDesc('version')->first();

        return view('pm.commercial-form', [
            'expenseRequest' => $expenseRequest,
            'batchItems' => $batchItems,
            'suppliers' => SupplierPayee::where('is_active', true)->orderBy('name')->get(),
            'destinations' => SupplierPaymentDestination::where('is_active', true)->orderBy('supplier_payee_id')->get(),
            'paymentMethods' => Lookup::ofType('payment_method')->get(),
            'impacts' => $batchItems->mapWithKeys(fn (ExpenseRequest $item) => [$item->id => $decisions->impact($item)]),
            'classification' => $this->lookups(),
            'budgetEntries' => $currentBudget?->entries()->with('phase')->orderBy('project_phase_id')->orderBy('id')->get() ?? collect(),
        ]);
    }

    public function updateBatchCommercial(SaveExpenseRequestBatchCommercial $request, ExpenseRequest $expenseRequest, ExpenseRequestBatchCommercialService $commercial)
    {
        $commercial->prepare(
            $expenseRequest,
            $request->validated()['items'],
            $request->file('items', []),
            $request->user(),
        );

        return redirect()->route('pm.requests.commercial.edit', $expenseRequest)
            ->with('success', 'Supplier, prices, documents and internal-budget allocations were saved for the request.');
    }

    public function decideBatch(DecideExpenseRequestRequest $request, ExpenseRequest $expenseRequest, ExpenseRequestDecisionService $decisions)
    {
        $decisions->decideBatch($expenseRequest, $request->validated()['decision'], $request->validated()['notes'], $request->user());
        $message = $request->validated()['decision'] === 'approve'
            ? 'PM approval recorded. Every item is now available to Accounts for payment.'
            : 'The complete Site request was rejected. No payment was created.';

        return redirect()->route('pm.requests.commercial.edit', $expenseRequest)->with('success', $message);
    }

    public function classifyRequest(ClassifyExpenseRequest $request, ExpenseRequest $expenseRequest, ExpenseRequestClassificationService $classification)
    {
        $classification->classify($expenseRequest, $request->validated(), $request->user());

        return redirect()->route('pm.requests.commercial.edit', $expenseRequest)
            ->with('success', 'The request was mapped to the selected phase and internal-budget classification.');
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
            ? 'PM approval recorded. The request is now available to Accounts for payment.'
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
                    'approved_awaiting_accounts' => $requests->where('status', 'approved')->filter(fn (ExpenseRequest $request) => Money::compare($request->approved_outstanding_amount, '0') > 0)->count(),
                ];
            });

        return view('pm.index', compact('projects'));
    }

    public function show(Request $request, Project $project, ProjectManagerBudgetService $budgets)
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->hasRole('project_manager'), 403);
        $this->authorize('viewProject', [InternalBudget::class, $project]);
        $project->load(['client', 'activePhase', 'phases' => fn ($query) => $query->orderBy('phase_order')]);
        $working = $project->internalBudgets()->where('status', 'draft')->with('entries.enteredBy')->first();
        $current = $working ?? $project->internalBudgets()->whereIn('status', ['finalized', 'approved'])->orderByDesc('version')->with('entries.enteredBy')->first();
        $comparison = $budgets->projectComparison($project);
        $siteRequests = $project->expenseRequests()->where('status', '!=', 'cancelled')->with('phase')->orderByDesc('submitted_at')->get();
        $entryGroups = $current ? $current->entries->groupBy('project_phase_id') : collect();
        $history = $project->internalBudgets()->when($current, fn ($query) => $query->where('id', '!=', $current->id))->with('entries')->orderByDesc('version')->get()
            ->map(fn (InternalBudget $budget) => (object) ['budget' => $budget, 'total' => $budget->total_amount]);
        $editLogs = ProjectEditLog::where('project_id', $project->id)->where('document_type', 'internal_budget')->with('editor')->latest()->get();

        return view('pm.show', [
            'project' => $project,
            'workingBudget' => $working,
            'currentBudget' => $current,
            'comparison' => $comparison,
            'siteRequests' => $siteRequests,
            'entryGroups' => $entryGroups,
            'history' => $history,
            'editLogs' => $editLogs,
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

    public function startBudgetEdit(Request $request, Project $project, InternalBudget $budget, ProjectManagerBudgetService $budgets)
    {
        abort_unless((int) $budget->project_id === (int) $project->id, 404);
        $validated = $request->validate(['edit_reason' => ['required', 'string', 'max:2000']]);
        $draft = $budgets->startEditing($budget, $validated['edit_reason'], $request->user());

        return redirect()->route('pm.projects.show', $project)->with('success', 'Internal budget is open for editing as '.$draft->budget_code.'. The approved version remains in the audit history.');
    }

    public function createEntry(Request $request, Project $project, ProjectPhase $phase, ProjectManagerBudgetService $budgets)
    {
        $this->authorize('viewProject', [InternalBudget::class, $project]);
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
        $budget = $budgets->ensureWorkingBudget($project, $request->user());
        $this->authorize('create', [InternalBudgetEntry::class, $budget]);

        return view('pm.entry-form', ['project' => $project, 'phase' => $phase, 'budget' => $budget, 'entry' => null, 'lookups' => $this->lookups()]);
    }

    public function storeEntry(SaveInternalBudgetEntryRequest $request, Project $project, ProjectPhase $phase, ProjectManagerBudgetService $budgets)
    {
        $this->authorize('viewProject', [InternalBudget::class, $project]);
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
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
        $this->authorize('delete', $entry);
        $validated = $request->validate(['edit_reason' => [$entry->budget->version > 1 ? 'required' : 'nullable', 'string', 'max:2000']]);
        $budgets->deleteEntry($entry, $validated['edit_reason'] ?? null, $request->user());

        return redirect()->route('pm.projects.show', $project)->with('success', 'Draft budget entry deleted.');
    }

    public function finalize(Request $request, Project $project, InternalBudget $budget, ProjectManagerBudgetService $budgets)
    {
        abort_unless((int) $budget->project_id === (int) $project->id, 404);
        $budgets->finalize($budget, $request->user());

        return redirect()->route('pm.projects.show', $project)->with('success', 'Internal budget submitted for Director verification. It is locked until approved or returned.');
    }

    public function exportBudget(Request $request, Project $project, InternalBudget $budget, ExcelExportService $excel)
    {
        abort_unless((int) $budget->project_id === (int) $project->id, 404);
        $this->authorize('view', $budget);
        $budget->load(['entries.phase', 'createdBy', 'approvedBy']);
        $rows = $budget->entries->sortBy(fn ($entry) => [$entry->phase?->phase_order, $entry->id])->map(fn ($entry) => [
            $entry->work_area ?: 'General', $entry->phase?->stage_label, $entry->work_section ?: 'General work', $entry->item_reference,
            ProjectManagerBudgetService::CATEGORIES[$entry->main_category] ?? $entry->main_category,
            $entry->subcategory, $entry->item_description, $entry->supplier_fundi_client,
            $entry->quantity ?? 1, $entry->unit ?? 'LS', $entry->unit_cost, $entry->line_total, $entry->notes,
        ]);
        $rows->push(['', '', '', '', '', '', 'BUDGET TOTAL', '', '', '', '', $budget->total_amount, '']);

        return $excel->download($budget->budget_code, 'Internal Budget - '.$project->name,
            ['Work Area / Building', 'Phase', 'Work Section', 'Item Ref.', 'Category', 'Subcategory', 'Description', 'Expected Supplier / Fundi', 'Quantity', 'Unit', 'Unit Cost (TSh)', 'Budget Total (TSh)', 'Notes'],
            $rows, [$budget->budget_code, 'Project: '.$project->name, 'Location: '.($project->location ?? 'Not set'), 'Version '.$budget->version, 'Status: '.ucwords(str_replace('_', ' ', $budget->status)), 'Director review: '.($budget->approvedBy?->name ?? ($budget->status === 'approved' ? 'Grandfathered baseline' : 'Awaiting verification'))], [10, 11]);
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

    private function requestBatches($requests)
    {
        return collect($requests)->groupBy(fn (ExpenseRequest $item) => $item->request_batch_code ?: $item->request_code)
            ->map(function ($items, string $code) {
                $items = $items->sortBy('request_line_number')->values();
                $state = $items->every(fn ($item) => $item->status === 'approved') ? 'approved'
                    : ($items->every(fn ($item) => $item->status === 'rejected') ? 'rejected'
                        : ($items->contains(fn ($item) => $item->commercial_total === null) ? 'awaiting_pricing' : 'ready'));

                return (object) [
                    'code' => $code,
                    'anchor' => $items->first(),
                    'items' => $items,
                    'item_count' => $items->count(),
                    'supplier_count' => $items->pluck('supplier_payee_id')->filter()->unique()->count(),
                    'total' => Money::sum($items->map(fn ($item) => $item->commercial_total ?? '0')),
                    'state' => $state,
                ];
            })->values();
    }
}
