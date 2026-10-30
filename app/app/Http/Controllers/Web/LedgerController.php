<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ExpenseRequest;
use App\Models\LabourAgreement;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEditLog;
use App\Models\ProjectPhase;
use App\Services\ExpenseLedgerService;
use App\Services\LedgerAnalyticsService;
use App\Services\LedgerWorkbookService;
use App\Services\PhaseBudgetService;
use App\Services\PhaseStructureService;
use App\Support\Money;
use App\Support\PhaseTree;
use App\Support\ReviewPeriod;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Recording-mode project workspace shared by Director, PM and Accounts: projects list, overview
 * analytics, the Excel-style expenditure register, recording/confirming lines, phases & budget, and
 * the workers register. What each role may change is decided by gates, not by separate pages.
 */
class LedgerController extends Controller
{
    public function index(Request $request, LedgerAnalyticsService $analytics)
    {
        $user = $request->user();
        abort_unless($user->hasRole('admin') || in_array($user->role?->name, ['project_manager', 'accounts', 'accountant'], true), 403);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:30']]);
        $projects = $analytics->projectsFor($user)
            ->when($filters['search'] ?? null, fn ($rows, $search) => $rows->filter(fn ($project) => str_contains(mb_strtolower($project->name.' '.$project->project_code.' '.$project->location), mb_strtolower($search))))
            ->when(($filters['status'] ?? '') !== '', fn ($rows) => $rows->where('status', $filters['status']));
        if (! $user->can('view-analytics')) {
            return view('ledger.index-team', ['rows' => $this->teamRows($projects->values()), 'filters' => $filters]);
        }
        $portfolio = $analytics->portfolio($projects->values());

        return view('ledger.index', compact('portfolio', 'filters'));
    }

    /**
     * Projects list for staff without "See totals & analytics": only what waits for confirmation on
     * each project, from two light aggregate queries (no project totals).
     *
     * @return Collection<int, array{project: Project, to_confirm: string, to_confirm_count: int, last_entry: ?string}>
     */
    private function teamRows(Collection $projects): Collection
    {
        $ids = $projects->pluck('id');
        $open = ExpenseRequest::query()->whereIn('project_id', $ids)->where('status', 'approved')->where('total_amount', '>', 0)->doesntHave('payments')
            ->selectRaw('project_id, count(*) as lines, sum(total_amount) as amount')->groupBy('project_id')->get()->keyBy('project_id');
        $last = ExpenseRequest::query()->whereIn('project_id', $ids)->where('status', 'approved')
            ->selectRaw('project_id, max(requested_at) as last_entry')->groupBy('project_id')->pluck('last_entry', 'project_id');

        return $projects->map(fn (Project $project) => [
            'project' => $project,
            'to_confirm' => number_format((float) ($open[$project->id]->amount ?? 0), 2, '.', ''),
            'to_confirm_count' => (int) ($open[$project->id]->lines ?? 0),
            'last_entry' => $last[$project->id] ?? null,
        ]);
    }

    public function toConfirm(Request $request, LedgerAnalyticsService $analytics)
    {
        if (! $request->user()->can('confirm-payments')) {
            return redirect()->route('ledger.index');
        }
        $projects = $analytics->projectsFor($request->user());
        $lines = ExpenseRequest::query()->whereIn('project_id', $projects->pluck('id'))->where('status', 'approved')->with('payments')
            ->get(['id', 'project_id', 'total_amount', 'approved_amount', 'commercial_total', 'status', 'requested_at']);
        $lines = LedgerAnalyticsService::normalize($lines)->filter(fn ($line) => $line->ledger_open_c > 0);
        $rows = $lines->groupBy('project_id')->map(fn ($group, $projectId) => [
            'project' => $projects->firstWhere('id', $projectId),
            'count' => $group->count(),
            'amount' => LedgerAnalyticsService::money((int) $group->sum('ledger_open_c')),
            'oldest' => $group->min('requested_at'),
        ])->sortByDesc(fn ($row) => (int) round((float) $row['amount'] * 100))->values();

        return view('ledger.to-confirm', ['rows' => $rows, 'total' => LedgerAnalyticsService::money((int) $lines->sum('ledger_open_c')), 'count' => $lines->count()]);
    }

    public function overview(Request $request, Project $project, LedgerAnalyticsService $analytics)
    {
        $this->authorize('view-ledger', $project);
        if (! $request->user()->can('view-analytics', $project)) {
            return redirect()->route('ledger.expenses', $project);
        }
        $input = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(ReviewPeriod::PRESETS))],
            'from' => ['nullable', 'required_if:range,custom', 'date'],
            'to' => ['nullable', 'required_if:range,custom', 'date', 'after_or_equal:from'],
        ]);
        $range = ! empty($input['range']) ? ReviewPeriod::resolve($input['range'], $input['from'] ?? null, $input['to'] ?? null) : null;
        $report = $analytics->project($project, $range['from'] ?? null, $range['to'] ?? null);

        return view('ledger.overview', compact('project', 'report', 'range'));
    }

    public function expenses(Request $request, Project $project, LedgerAnalyticsService $analytics)
    {
        $this->authorize('view-ledger', $project);
        [$lines, $filters, $tree] = $this->filteredLines($request, $project, $analytics);
        $totals = [
            'recorded' => Money::sum($lines->pluck('total_amount')),
            'paid' => Money::sum($lines->map(fn ($line) => $line->paid_to_date)),
            'count' => $lines->count(),
        ];
        $totals['to_confirm'] = Money::difference($totals['recorded'], $totals['paid']);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paged = new LengthAwarePaginator($lines->slice(($page - 1) * 100, 100)->values(), $lines->count(), 100, $page, ['path' => $request->url(), 'query' => $request->query()]);
        // The per-phase summary is only shown to users who may see totals; skip the work for everyone else.
        $phaseRows = $request->user()->can('view-analytics', $project)
            ? $analytics->phaseRows($tree, $lines, $analytics->budgets($project))->filter(fn ($row) => $row['depth'] === 1 || $row['count'] > 0)
            : collect();

        return view('ledger.expenses', [
            'project' => $project, 'lines' => $paged, 'totals' => $totals, 'filters' => $filters, 'tree' => $tree,
            'phaseRows' => $phaseRows, 'subcategories' => Lookup::ofType('subcategory')->pluck('label', 'code'),
            'units' => ExpenseLedgerService::units(), 'methods' => Lookup::ofType('payment_method')->pluck('label', 'code'),
            'workers' => LabourAgreement::query()->where('project_id', $project->id)->orderBy('fundi_subcontractor')->pluck('fundi_subcontractor', 'id'),
        ]);
    }

    public function export(Request $request, Project $project, LedgerAnalyticsService $analytics, LedgerWorkbookService $workbooks)
    {
        $this->authorize('export-ledger', $project);
        [$lines, $filters, $tree] = $this->filteredLines($request, $project, $analytics);
        $notes = array_values(array_filter([
            ! empty($filters['category']) ? LedgerAnalyticsService::CATEGORIES[$filters['category']] : null,
            ! empty($filters['phase']) ? 'Phase: '.$tree->path((int) $filters['phase']) : null,
            ! empty($filters['status']) ? 'Status: '.str_replace('_', ' ', $filters['status']) : null,
            ! empty($filters['from']) || ! empty($filters['to']) ? 'Dates: '.($filters['from'] ?? '…').' to '.($filters['to'] ?? '…') : null,
            ! empty($filters['q']) ? 'Search: '.$filters['q'] : null,
        ]));

        return $workbooks->project($project, $lines, $tree, $request->user()->can('view-analytics', $project), $notes, $request->user());
    }

    /** Every project the user may see, in one workbook (Director, or anyone with totals + Excel access). */
    public function exportAll(Request $request, LedgerAnalyticsService $analytics, LedgerWorkbookService $workbooks)
    {
        $user = $request->user();
        abort_unless($user->can('view-analytics') && $user->permits('export'), 403);
        $input = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(ReviewPeriod::PRESETS))],
            'from' => ['nullable', 'required_if:range,custom', 'date'],
            'to' => ['nullable', 'required_if:range,custom', 'date', 'after_or_equal:from'],
        ]);
        $range = ! empty($input['range']) ? ReviewPeriod::resolve($input['range'], $input['from'] ?? null, $input['to'] ?? null) : null;
        // Every line of every project in one workbook needs more than the host's default 128 MB; only this download gets it.
        ini_set('memory_limit', '512M');
        set_time_limit(180);

        return $workbooks->portfolio($analytics->projectsFor($user), $range, $user);
    }

    public function create(Request $request, Project $project)
    {
        $this->authorize('record-expense', $project);

        return view('ledger.expense-batch', $this->formData($project));
    }

    public function store(Request $request, Project $project, ExpenseLedgerService $ledger)
    {
        $this->authorize('record-expense', $project);
        if ($request->has('rows')) {
            return $this->storeRows($request, $project, $ledger);
        }
        $line = $ledger->record($project, $this->validated($request, $project), $request->user());
        $next = $request->input('after') === 'another' ? route('ledger.expenses.create', $project) : route('ledger.expenses', $project);

        return redirect($next)->with('success', $line->request_code.' recorded: '.$line->item_description.' · TSh '.Money::format($line->total_amount).'.');
    }

    /** Several lines in one go: a header (date, category, phase, status) and one table row per item. */
    private function storeRows(Request $request, Project $project, ExpenseLedgerService $ledger)
    {
        $rows = collect($request->input('rows', []))
            ->filter(fn ($row) => is_array($row) && collect($row)->except(['main_category', 'project_phase_id'])->filter(fn ($v) => $v !== null && trim((string) $v) !== '')->isNotEmpty())
            ->values()->all();
        $request->merge(['rows' => $rows]);
        if ($request->user()->hasRole('admin')) {
            // The Director's entries are confirmed as they are recorded.
            $request->merge(['status' => 'paid', 'payment_method' => $request->input('payment_method') ?: 'cash',
                'payment_date' => $request->input('payment_date') ?: $request->input('expense_date')]);
        }
        $phaseRule = Rule::exists('project_phases', 'id')->where('project_id', $project->id);
        $header = $request->validate([
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(['to_confirm', 'paid'])],
            'payment_date' => ['nullable', 'required_if:status,paid', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', 'required_if:status,paid', Rule::exists('lookups', 'code')->where('type', 'payment_method')],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.main_category' => ['required', Rule::in(array_keys(LedgerAnalyticsService::CATEGORIES))],
            'rows.*.project_phase_id' => ['required', 'integer', $phaseRule],
            'rows.*.item_description' => ['required', 'string', 'max:255'],
            'rows.*.subcategory' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'subcategory')],
            'rows.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'rows.*.unit' => ['nullable', 'string', 'max:30'],
            'rows.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'rows.*.total_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'rows.*.invoice_receipt_no' => ['nullable', 'string', 'max:255'],
            'rows.*.labour_agreement_id' => ['nullable', 'integer', Rule::exists('labour_agreements', 'id')->where('project_id', $project->id)],
            'rows.*.details' => ['nullable', 'string', 'max:2000'],
        ], [
            'rows.required' => 'Add at least one item.',
            'rows.*.item_description.required' => 'Row :position needs an item name.',
            'rows.*.project_phase_id.required' => 'Row :position needs a phase.',
            'rows.*.main_category.required' => 'Row :position needs a category.',
        ]);
        $lines = DB::transaction(fn () => collect($header['rows'])->map(fn (array $row) => $ledger->record($project, $row + [
            'expense_date' => $header['expense_date'], 'status' => $header['status'], 'payment_date' => $header['payment_date'] ?? null,
            'payment_method' => $header['payment_method'] ?? null, 'transaction_reference' => $header['transaction_reference'] ?? null,
        ], $request->user())));
        $total = LedgerAnalyticsService::money((int) $lines->sum(fn ($line) => LedgerAnalyticsService::cents($line->total_amount)));
        $next = $request->input('after') === 'another' ? route('ledger.expenses.create', $project) : route('ledger.expenses', $project);

        return redirect($next)->with('success', $lines->count().' line(s) recorded ('.$lines->first()->request_code.($lines->count() > 1 ? ' – '.$lines->last()->request_code : '').') · TSh '.Money::format($total).'.');
    }

    public function edit(Project $project, ExpenseRequest $line)
    {
        $this->owned($project, $line);
        $this->authorize('edit-ledger-line', $line);
        abort_if($line->payments()->exists() && ! request()->user()->hasRole('admin'), 403, 'This line already has a payment recorded.');

        return view('ledger.expense-form', $this->formData($project) + ['line' => $line]);
    }

    public function update(Request $request, Project $project, ExpenseRequest $line, ExpenseLedgerService $ledger)
    {
        $this->owned($project, $line);
        $line = $ledger->update($line, $this->validated($request, $project, editing: true), $request->user());

        return redirect()->route('ledger.expenses', $project)->with('success', $line->request_code.' updated.');
    }

    public function destroy(Request $request, Project $project, ExpenseRequest $line, ExpenseLedgerService $ledger)
    {
        $this->owned($project, $line);
        $ledger->delete($line, $request->user());

        return back()->with('success', $line->request_code.' deleted.');
    }

    public function deny(Request $request, Project $project, ExpenseRequest $line, ExpenseLedgerService $ledger)
    {
        $this->owned($project, $line);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $ledger->deny($line, $validated['reason'], $request->user());

        return back()->with('success', $line->request_code.' denied. It no longer counts in any total.');
    }

    public function restore(Request $request, Project $project, ExpenseRequest $line, ExpenseLedgerService $ledger)
    {
        $this->owned($project, $line);
        $ledger->restore($line, $request->user());

        return back()->with('success', $line->request_code.' restored.');
    }

    public function confirm(Request $request, Project $project, ExpenseRequest $line, ExpenseLedgerService $ledger)
    {
        $this->owned($project, $line);
        $validated = $request->validate([
            'total_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::exists('lookups', 'code')->where('type', 'payment_method')],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
        ]);
        $line = $ledger->confirm($line, $validated, $request->user());

        return back()->with('success', $line->request_code.' confirmed: TSh '.Money::format($validated['amount']).' paid.');
    }

    public function structure(Request $request, Project $project, LedgerAnalyticsService $analytics)
    {
        $this->authorize('view-budget', $project);
        $tree = new PhaseTree($project->phases()->get());
        $rows = $analytics->phaseRows($tree, $analytics->lines($project), $analytics->budgets($project));
        $log = ProjectEditLog::query()->where('project_id', $project->id)->where('document_type', 'budget')->with('editor')->latest()->limit(15)->get();

        return view('ledger.structure', compact('project', 'tree', 'rows', 'log'));
    }

    public function storePhase(Request $request, Project $project, PhaseStructureService $phases)
    {
        $validated = $request->validate(['phase_name' => ['required', 'string', 'max:150'], 'parent_id' => ['nullable', 'integer']]);
        $phase = $phases->create($project, $validated['phase_name'], $validated['parent_id'] ?? null, $request->user());

        return back()->with('success', '"'.$phase->stage_label.'" added.');
    }

    public function renamePhase(Request $request, Project $project, ProjectPhase $phase, PhaseStructureService $phases)
    {
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
        $validated = $request->validate(['phase_name' => ['required', 'string', 'max:150']]);
        $phases->rename($phase, $validated['phase_name'], $request->user());

        return back()->with('success', 'Renamed to "'.$validated['phase_name'].'".');
    }

    public function movePhase(Request $request, Project $project, ProjectPhase $phase, PhaseStructureService $phases)
    {
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
        $phases->move($phase, $request->input('direction') === 'up' ? 'up' : 'down', $request->user());

        return back();
    }

    public function destroyPhase(Request $request, Project $project, ProjectPhase $phase, PhaseStructureService $phases)
    {
        abort_unless((int) $phase->project_id === (int) $project->id, 404);
        $phases->delete($phase, $request->user());

        return back()->with('success', '"'.$phase->stage_label.'" deleted.');
    }

    public function saveBudget(Request $request, Project $project, PhaseBudgetService $budgets)
    {
        $validated = $request->validate(['budget' => ['array'], 'budget.*' => ['array'], 'budget.*.*' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99']]);
        $changed = $budgets->save($project, $validated['budget'] ?? [], $request->user());

        return back()->with('success', $changed ? $changed.' budget amount(s) saved.' : 'No budget amounts changed.');
    }

    public function workers(Request $request, Project $project)
    {
        $this->authorize('view-workers', $project);
        $workers = LabourAgreement::query()->where('project_id', $project->id)->withCount('expenseRequests')->orderBy('fundi_subcontractor')->get()
            ->map(function (LabourAgreement $worker) {
                $lines = LedgerAnalyticsService::normalize($worker->expenseRequests()->where('status', 'approved')->with('payments')->get());
                $recorded = Money::sum($lines->pluck('total_amount'));
                $paid = Money::sum($lines->map(fn ($line) => $line->paid_to_date));
                $contract = (string) $worker->agreed_value;

                return ['worker' => $worker, 'recorded' => $recorded, 'paid' => $paid, 'contract' => $contract,
                    'balance' => Money::compare($contract, '0') > 0 ? Money::difference($contract, $recorded) : null,
                    'progress' => Money::compare($contract, '0') > 0 ? Money::percentage($recorded, $contract) : null,
                    'lines' => $lines->count()];
            });

        return view('ledger.workers', ['project' => $project, 'workers' => $workers, 'labourTypes' => Lookup::query()->where('type', 'subcategory')->where('parent_code', 'labour_subcontracts')->pluck('label', 'code')]);
    }

    public function storeWorker(Request $request, Project $project)
    {
        $this->authorizeWorkers($request, $project);
        $data = $this->workerData($request);
        $worker = LabourAgreement::create($data + [
            'agreement_code' => 'WRK-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'project_id' => $project->id,
            'agreement_date' => $data['start_date'] ?? now()->toDateString(),
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', $worker->fundi_subcontractor.' registered.');
    }

    public function updateWorker(Request $request, Project $project, LabourAgreement $worker)
    {
        abort_unless((int) $worker->project_id === (int) $project->id, 404);
        $this->authorizeWorkers($request, $project);
        $worker->update($this->workerData($request) + ['status' => $request->input('status') === 'completed' ? 'completed' : 'active']);

        return back()->with('success', $worker->fundi_subcontractor.' updated.');
    }

    /** @return array{0: Collection<int, ExpenseRequest>, 1: array<string, mixed>, 2: PhaseTree} */
    private function filteredLines(Request $request, Project $project, LedgerAnalyticsService $analytics): array
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(array_keys(LedgerAnalyticsService::CATEGORIES))],
            'phase' => ['nullable', 'integer'],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['paid', 'to_confirm', 'part_paid', 'denied'])],
            'worker' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount'])],
        ]);
        $tree = new PhaseTree($project->phases()->get());
        $phaseIds = ! empty($filters['phase']) ? $tree->withDescendants((int) $filters['phase']) : null;
        $denied = ($filters['status'] ?? null) === 'denied';
        $lines = $project->expenseRequests()->where('status', $denied ? 'rejected' : 'approved')->with(['payments', 'labourAgreement', 'requestedBy', 'latestApproval.decidedBy'])
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('main_category', $category))
            ->when($phaseIds, fn ($query) => $query->whereIn('project_phase_id', $phaseIds))
            ->when($filters['subcategory'] ?? null, fn ($query, $subcategory) => $query->where('subcategory', $subcategory))
            ->when($filters['worker'] ?? null, fn ($query, $worker) => $query->where('labour_agreement_id', $worker))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('requested_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('requested_at', '<=', $to))
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->where(fn ($inner) => $inner->where('item_description', 'like', '%'.$search.'%')
                ->orWhere('request_code', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%')->orWhere('invoice_receipt_no', 'like', '%'.$search.'%')))
            ->get()
            ->pipe(fn ($rows) => LedgerAnalyticsService::normalize($rows))
            ->when(! $denied && ($filters['status'] ?? null), fn ($rows) => $rows->filter(fn ($line) => $line->ledger_status === match ($filters['status']) {
                'paid' => 'paid', 'part_paid' => 'part_paid', default => 'unpaid',
            }));
        $lines = match ($filters['sort'] ?? 'newest') {
            'oldest' => $lines->sortBy(fn ($line) => [$line->requested_at?->format('Y-m-d'), $line->id]),
            'amount' => $lines->sortByDesc(fn ($line) => (float) $line->total_amount),
            default => $lines->sortByDesc(fn ($line) => [$line->requested_at?->format('Y-m-d'), $line->id]),
        };

        return [LedgerAnalyticsService::normalize($lines->values()), $filters, $tree];
    }

    private function statusLabel(ExpenseRequest $line): string
    {
        return ['paid' => 'Paid', 'part_paid' => 'Part paid', 'unpaid' => 'To confirm'][$line->payment_status] ?? 'To confirm';
    }

    private function formData(Project $project): array
    {
        $tree = new PhaseTree($project->phases()->get());
        $suggestions = Lookup::ofType('material_item')->orderBy('label')->get(['label', 'parent_code'])->groupBy('parent_code')->map(fn ($items) => $items->pluck('label')->values());

        return [
            'project' => $project,
            'phases' => $tree->flatten()->map(fn ($node) => $node + ['leaf' => $tree->isLeaf($node['phase']->id)]),
            'subcategories' => Lookup::ofType('subcategory')->get(['code', 'label', 'parent_code']),
            'units' => Lookup::ofType('unit')->get(['code', 'label']),
            'methods' => Lookup::ofType('payment_method')->pluck('label', 'code'),
            'workers' => LabourAgreement::query()->where('project_id', $project->id)->where('status', '!=', 'cancelled')->orderBy('fundi_subcontractor')->get(),
            'suggestions' => $suggestions,
            'canMarkPaid' => ExpenseLedgerService::canMarkPaid(request()->user()),
        ];
    }

    private function validated(Request $request, Project $project, bool $editing = false): array
    {
        return $request->validate([
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'main_category' => ['required', Rule::in(array_keys(LedgerAnalyticsService::CATEGORIES))],
            'project_phase_id' => ['required', 'integer', Rule::exists('project_phases', 'id')->where('project_id', $project->id)],
            'subcategory' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'subcategory')],
            'item_description' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'unit' => ['nullable', 'string', 'max:30'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'total_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'invoice_receipt_no' => ['nullable', 'string', 'max:255'],
            'labour_agreement_id' => ['nullable', 'integer', Rule::exists('labour_agreements', 'id')->where('project_id', $project->id)],
            'status' => [$editing ? 'nullable' : 'required', Rule::in(['to_confirm', 'paid'])],
            'payment_date' => ['nullable', 'required_if:status,paid', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', 'required_if:status,paid', Rule::exists('lookups', 'code')->where('type', 'payment_method')],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function workerData(Request $request): array
    {
        $validated = $request->validate([
            'fundi_subcontractor' => ['required', 'string', 'max:150'],
            'labour_type' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'subcategory')],
            'scope_of_work' => ['nullable', 'string', 'max:1000'],
            'contact' => ['nullable', 'string', 'max:100'],
            'agreed_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'start_date' => ['nullable', 'date'],
            'expected_completion' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $validated['agreed_value'] = $validated['agreed_value'] ?? 0;

        return $validated;
    }

    private function authorizeWorkers(Request $request, Project $project): void
    {
        $this->authorize('manage-workers', $project);
    }

    private function owned(Project $project, ExpenseRequest $line): void
    {
        abort_unless((int) $line->project_id === (int) $project->id, 404);
        $this->authorize('view-ledger', $project);
    }
}
