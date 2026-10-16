<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEditLog;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectManagerBudgetService
{
    public const CATEGORIES = [
        'materials' => 'Materials',
        'labour_subcontracts' => 'Labour & Subcontracts',
        'logistics_equipment' => 'Logistics & Equipment',
        'preliminaries_overheads' => 'Preliminaries & Site Overheads',
    ];

    public const PHASES = SiteExpenseRequestService::PHASES;

    public function __construct(private readonly DatabaseManager $database) {}

    public function ensureWorkingBudget(Project $project, User $actor, ?ProjectPhase $phase = null, ?string $category = null): InternalBudget
    {
        Gate::forUser($actor)->authorize('create', [InternalBudget::class, $project]);
        $phase ??= $project->phases()->where('status', 'active')->orderBy('phase_order')->first() ?? $project->phases()->orderBy('phase_order')->firstOrFail();
        $category ??= 'materials';
        if ((int) $phase->project_id !== (int) $project->id || ! array_key_exists($category, self::CATEGORIES)) {
            throw ValidationException::withMessages(['budget' => 'Select a valid project phase and budget type.']);
        }

        return $this->database->transaction(function () use ($project, $actor, $phase, $category): InternalBudget {
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', [InternalBudget::class, $lockedProject]);
            $scope = fn ($query) => $query->where('project_phase_id', $phase->id)->where('main_category', $category);
            $drafts = $scope($lockedProject->internalBudgets())->where('status', 'draft')->lockForUpdate()->get();
            if ($drafts->count() > 1) {
                throw ValidationException::withMessages(['budget' => 'This project has multiple working budgets; administrator review is required.']);
            }
            if ($drafts->isNotEmpty()) {
                return $drafts->first();
            }
            if ($scope($lockedProject->internalBudgets())->where('status', 'finalized')->exists()) {
                throw ValidationException::withMessages(['budget' => 'This phase budget is awaiting Director verification. It must be approved or returned before another version can be created.']);
            }
            if ($scope($lockedProject->internalBudgets())->exists()) {
                throw ValidationException::withMessages(['budget' => 'Use Edit on this phase budget and provide a justification to change the approved document.']);
            }

            $version = ((int) $scope($lockedProject->internalBudgets())->max('version')) + 1;

            return $lockedProject->internalBudgets()->create([
                'budget_code' => $this->budgetCode($lockedProject, $phase, $category, $version),
                'project_phase_id' => $phase->id,
                'main_category' => $category,
                'version' => $version,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
        });
    }

    public function startEditing(InternalBudget $source, string $reason, User $actor): InternalBudget
    {
        Gate::forUser($actor)->authorize('revise', $source);

        return $this->database->transaction(function () use ($source, $reason, $actor): InternalBudget {
            $project = Project::query()->whereKey($source->project_id)->lockForUpdate()->firstOrFail();
            $locked = InternalBudget::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('revise', $locked);
            $this->requireReason($reason);
            $scope = $project->internalBudgets()->where('project_phase_id', $locked->project_phase_id)->where('main_category', $locked->main_category);
            if ((clone $scope)->whereIn('status', ['draft', 'finalized'])->exists()) {
                throw ValidationException::withMessages(['budget' => 'Finish the existing working or Director-review budget before editing the approved budget.']);
            }

            $version = ((int) (clone $scope)->max('version')) + 1;
            $draft = $project->internalBudgets()->create([
                'budget_code' => $this->budgetCode($project, $locked->phase, $locked->main_category, $version),
                'project_phase_id' => $locked->project_phase_id,
                'main_category' => $locked->main_category,
                'version' => $version,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
            foreach ($locked->entries()->get() as $entry) {
                $draft->entries()->create($entry->only([
                    'project_phase_id', 'work_area', 'work_section', 'construction_category', 'work_package_code', 'work_package_name', 'item_reference', 'main_category', 'subcategory', 'item_description', 'supplier_fundi_client',
                    'quantity', 'unit', 'unit_cost', 'notes',
                ]) + ['entered_by' => $actor->id]);
            }
            ProjectEditLog::create([
                'project_id' => $project->id,
                'document_type' => 'internal_budget',
                'document_id' => $draft->id,
                'document_code' => $draft->budget_code,
                'from_version' => $locked->version,
                'to_version' => $draft->version,
                'action' => 'edit_started',
                'reason' => $reason,
                'edited_by' => $actor->id,
            ]);

            return $draft->load('entries');
        });
    }

    public function createEntry(InternalBudget $budget, ProjectPhase $phase, array $data, User $actor): InternalBudgetEntry
    {
        return $this->database->transaction(function () use ($budget, $phase, $data, $actor): InternalBudgetEntry {
            $locked = InternalBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', [InternalBudgetEntry::class, $locked]);
            $this->assertPhaseAndCategory($locked, $phase, $data);

            if ($locked->version > 1) {
                $this->requireReason($data['edit_reason'] ?? null);
            }
            $entry = $locked->entries()->create($this->entryData($data) + [
                'project_phase_id' => $phase->id,
                'entered_by' => $actor->id,
            ]);
            if ($locked->version > 1) {
                $this->logEntryChange($locked, $entry, 'entry_added', $data['edit_reason'], $actor, null, $this->snapshot($entry));
            }

            return $entry;
        });
    }

    public function updateEntry(InternalBudgetEntry $entry, array $data, User $actor): InternalBudgetEntry
    {
        return $this->database->transaction(function () use ($entry, $data, $actor): InternalBudgetEntry {
            InternalBudget::query()->whereKey($entry->internal_budget_id)->lockForUpdate()->firstOrFail();
            $locked = InternalBudgetEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->budget->version > 1) {
                $this->requireReason($data['edit_reason'] ?? null);
            }
            $this->assertPhaseAndCategory($locked->budget, $locked->phase, $data);
            $before = $this->snapshot($locked);
            $locked->update($this->entryData($data));
            if ($locked->budget->version > 1) {
                $this->logEntryChange($locked->budget, $locked, 'entry_updated', $data['edit_reason'], $actor, $before, $this->snapshot($locked->fresh()));
            }

            return $locked->refresh();
        });
    }

    public function deleteEntry(InternalBudgetEntry $entry, ?string $reason, User $actor): void
    {
        $this->database->transaction(function () use ($entry, $reason, $actor): void {
            InternalBudget::query()->whereKey($entry->internal_budget_id)->lockForUpdate()->firstOrFail();
            $locked = InternalBudgetEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $locked);
            if ($locked->budget->version > 1) {
                $this->requireReason($reason);
                $this->logEntryChange($locked->budget, $locked, 'entry_deleted', $reason, $actor, $this->snapshot($locked), null);
            }
            $locked->delete();
        });
    }

    public function finalize(InternalBudget $budget, User $actor): InternalBudget
    {
        return $this->database->transaction(function () use ($budget, $actor): InternalBudget {
            $project = Project::query()->whereKey($budget->project_id)->lockForUpdate()->firstOrFail();
            $locked = InternalBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('finalize', $locked);
            if ($locked->status !== 'draft' || $locked->entries()->doesntExist()) {
                throw ValidationException::withMessages(['budget' => 'This phase budget must be a working document with at least one entry before finalization.']);
            }
            $locked->update(['status' => 'finalized', 'finalized_by' => $actor->id, 'finalized_at' => now()]);

            return $locked->fresh();
        });
    }

    public function approve(InternalBudget $budget, User $actor, ?string $notes = null): InternalBudget
    {
        return $this->database->transaction(function () use ($budget, $actor, $notes): InternalBudget {
            $project = Project::query()->whereKey($budget->project_id)->lockForUpdate()->firstOrFail();
            $locked = InternalBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('approve', $locked);
            if ($locked->status !== 'finalized') {
                throw ValidationException::withMessages(['budget' => 'Only a budget awaiting Director verification can be approved.']);
            }
            $project->internalBudgets()->where('project_phase_id', $locked->project_phase_id)->where('main_category', $locked->main_category)
                ->where('status', 'approved')->where('id', '!=', $locked->id)
                ->update(['status' => 'superseded']);
            $locked->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'notes' => $notes ?: $locked->notes,
            ]);

            return $locked->fresh();
        });
    }

    public function returnForEditing(InternalBudget $budget, User $actor, string $notes): InternalBudget
    {
        return $this->database->transaction(function () use ($budget, $actor, $notes): InternalBudget {
            $locked = InternalBudget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('approve', $locked);
            if ($locked->status !== 'finalized') {
                throw ValidationException::withMessages(['budget' => 'Only a budget awaiting Director verification can be returned.']);
            }
            $locked->update([
                'status' => 'draft',
                'approved_by' => null,
                'approved_at' => null,
                'notes' => $notes,
            ]);
            ProjectEditLog::create([
                'project_id' => $locked->project_id,
                'document_type' => 'internal_budget',
                'document_id' => $locked->id,
                'document_code' => $locked->budget_code,
                'from_version' => $locked->version,
                'to_version' => $locked->version,
                'action' => 'returned_for_editing',
                'reason' => $notes,
                'edited_by' => $actor->id,
            ]);

            return $locked->fresh();
        });
    }

    public function projectBudget(Project $project): string
    {
        return Money::sum($this->approvedBudgets($project)->flatMap->entries->map(fn (InternalBudgetEntry $entry) => $entry->line_total));
    }

    public function budgetFor(Project $project, int $phaseId, string $category): string
    {
        return Money::sum($this->approvedBudgets($project)
            ->where('project_phase_id', $phaseId)->where('main_category', $category)->flatMap->entries
            ->map(fn (InternalBudgetEntry $entry) => $entry->line_total));
    }

    public function requestedAmount(Project $project, ?ProjectPhase $phase = null, ?string $category = null): string
    {
        $requests = $project->expenseRequests()->where('status', '!=', 'cancelled')->get();
        if ($phase) {
            $requests = $requests->where('project_phase_id', $phase->id);
        }
        if ($category) {
            $requests = $requests->where('main_category', $category);
        }

        return Money::sum($this->pricedRequestAmounts($requests));
    }

    public function comparison(string $budget, string $requested): array
    {
        $remaining = Money::difference($budget, $requested);
        $utilization = Money::compare($budget, '0') === 0 ? null : Money::percentage($requested, $budget);

        return [
            'budget' => $budget,
            'requested' => $requested,
            'remaining' => $remaining,
            'utilization' => $utilization,
            'over_budget' => Money::compare($requested, $budget) > 0,
            'over_budget_amount' => Money::compare($requested, $budget) > 0 ? Money::difference($requested, $budget) : '0.00',
        ];
    }

    public function projectComparison(Project $project): array
    {
        $budgetEntries = $this->currentBudgets($project)->flatMap->entries;
        $requests = $project->expenseRequests()->where('status', 'approved')->get();
        $payments = $project->expensePayments()->with('expenseRequest')->get();
        $budget = Money::sum($budgetEntries->map(fn (InternalBudgetEntry $entry) => $entry->line_total));
        $approved = Money::sum($requests->map(fn (ExpenseRequest $request) => $request->approved_payable_amount));
        $actual = Money::sum($payments->pluck('amount'));
        $categories = [];
        foreach (self::CATEGORIES as $code => $label) {
            $categoryBudget = $budgetEntries->where('main_category', $code);
            $categoryRequests = $requests->where('main_category', $code);
            $categoryPayments = $payments->filter(fn ($payment) => $payment->expenseRequest?->main_category === $code);
            $categories[$code] = ['label' => $label] + $this->costPosition($categoryBudget, $categoryRequests, $categoryPayments);
        }

        $byPhase = [];
        foreach ($project->phases->sortBy('phase_order') as $phase) {
            $phaseBudgetEntries = $budgetEntries->where('project_phase_id', $phase->id);
            $phaseRequests = $requests->where('project_phase_id', $phase->id);
            $phasePayments = $payments->where('project_phase_id', $phase->id);
            $phaseCategories = [];
            foreach (self::CATEGORIES as $code => $label) {
                $categoryBudget = $phaseBudgetEntries->where('main_category', $code);
                $categoryRequests = $phaseRequests->where('main_category', $code);
                $categoryPayments = $phasePayments->filter(fn ($payment) => $payment->expenseRequest?->main_category === $code);
                $phaseCategories[$code] = ['label' => $label] + $this->costPosition($categoryBudget, $categoryRequests, $categoryPayments);
            }
            $byPhase[$phase->id] = ['comparison' => $this->costPosition($phaseBudgetEntries, $phaseRequests, $phasePayments), 'categories' => $phaseCategories];
        }

        return ['overall' => $this->costPosition($budgetEntries, $requests, $payments), 'categories' => $categories, 'phases' => $byPhase];
    }

    private function costPosition(iterable $budgetEntries, iterable $approvedRequests, iterable $payments): array
    {
        $budgetEntries = collect($budgetEntries);
        $approvedRequests = collect($approvedRequests);
        $payments = collect($payments);
        $budget = Money::sum($budgetEntries->map(fn (InternalBudgetEntry $entry) => $entry->line_total));
        $approved = Money::sum($approvedRequests->map(fn (ExpenseRequest $request) => $request->approved_payable_amount));
        $actual = Money::sum($payments->pluck('amount'));
        $remaining = Money::difference($budget, $actual);
        $utilization = Money::compare($budget, '0') === 0 ? null : Money::percentage($actual, $budget);

        $keys = $budgetEntries->pluck('subcategory')
            ->merge($approvedRequests->pluck('subcategory'))
            ->merge($payments->map(fn ($payment) => $payment->expenseRequest?->subcategory))
            ->map(fn ($value) => $value ?: '__other')->unique()->values();
        $labels = Lookup::where('type', 'subcategory')->pluck('label', 'code');
        $subcategories = $keys->map(function (string $key) use ($budgetEntries, $approvedRequests, $payments, $labels) {
            $matches = fn ($value) => ($value ?: '__other') === $key;
            $budget = Money::sum($budgetEntries->filter(fn ($entry) => $matches($entry->subcategory))->map(fn ($entry) => $entry->line_total));
            $approved = Money::sum($approvedRequests->filter(fn ($request) => $matches($request->subcategory))->map(fn ($request) => $request->approved_payable_amount));
            $actual = Money::sum($payments->filter(fn ($payment) => $matches($payment->expenseRequest?->subcategory))->pluck('amount'));

            return [
                'key' => $key,
                'label' => $key === '__other' ? 'Other / Unclassified' : ($labels[$key] ?? ucwords(str_replace('_', ' ', $key))),
                'budget' => $budget,
                'approved' => $approved,
                'actual' => $actual,
                'remaining' => Money::difference($budget, $actual),
                'utilization' => Money::compare($budget, '0') === 0 ? null : Money::percentage($actual, $budget),
            ];
        })->values();

        return [
            'budget' => $budget,
            'approved' => $approved,
            'actual' => $actual,
            'remaining' => $remaining,
            'utilization' => $utilization,
            'over_budget' => Money::compare($actual, $budget) > 0,
            'over_budget_amount' => Money::compare($actual, $budget) > 0 ? Money::difference($actual, $budget) : '0.00',
            'commitment_over_budget' => Money::compare($approved, $budget) > 0,
            'subcategories' => $subcategories,
        ];
    }

    /** @param iterable<ExpenseRequest> $requests */
    private function pricedRequestAmounts(iterable $requests): array
    {
        $amounts = [];
        foreach ($requests as $request) {
            $amounts[] = $request->commercial_total ?? $request->total_amount ?? '0.00';
        }

        return $amounts;
    }

    public function currentBudget(Project $project): ?InternalBudget
    {
        $drafts = $project->internalBudgets()->where('status', 'draft')->with('entries')->orderByDesc('version')->get();
        if ($drafts->isNotEmpty()) {
            return $drafts->first();
        }

        return $project->internalBudgets()->whereIn('status', ['finalized', 'approved'])->with('entries')->orderByDesc('version')->first();
    }

    public function approvedBudget(Project $project): ?InternalBudget
    {
        return $project->internalBudgets()->where('status', 'approved')->with('entries')->orderByDesc('version')->first();
    }

    public function approvedBudgets(Project $project)
    {
        return $project->internalBudgets()->where('status', 'approved')->with(['entries', 'phase'])->orderBy('project_phase_id')->get();
    }

    public function currentBudgets(Project $project)
    {
        return $project->internalBudgets()->whereIn('status', ['draft', 'finalized', 'approved'])
            ->with(['entries', 'phase'])->get()
            ->groupBy(fn (InternalBudget $budget) => $budget->project_phase_id.'|'.$budget->main_category)
            ->map(fn ($versions) => $versions->sortBy(fn (InternalBudget $budget) => [
                ['draft' => 0, 'finalized' => 1, 'approved' => 2][$budget->status] ?? 3,
                -$budget->version,
            ])->first())
            ->values();
    }

    public function entryData(array $data): array
    {
        return [
            'work_area' => $data['work_area'] ?? null,
            'work_section' => $data['work_section'] ?? null,
            'construction_category' => $data['construction_category'] ?? null,
            'work_package_code' => $data['work_package_code'] ?? null,
            'work_package_name' => $data['work_package_name'] ?? null,
            'item_reference' => $data['item_reference'] ?? null,
            'main_category' => $data['main_category'],
            'subcategory' => $data['subcategory'] ?? null,
            'item_description' => $data['item_description'],
            'supplier_fundi_client' => $data['supplier_fundi_client'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'unit' => $data['unit'] ?? null,
            'unit_cost' => $data['unit_cost'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function assertPhaseAndCategory(InternalBudget $budget, ProjectPhase $phase, array $data): void
    {
        if (! $phase->exists || (int) $phase->project_id !== (int) $budget->project_id) {
            throw ValidationException::withMessages(['project_phase_id' => 'Select a phase belonging to this project.']);
        }
        if ((int) $budget->project_phase_id !== (int) $phase->id || $budget->main_category !== $data['main_category']) {
            throw ValidationException::withMessages(['main_category' => 'Each document belongs to one phase and one budget type. Add this item to the matching phase budget.']);
        }
        if (! empty($data['subcategory']) && ! Lookup::where('type', 'subcategory')->where('code', $data['subcategory'])
            ->where('parent_code', $data['main_category'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['subcategory' => 'Choose a subcategory belonging to the selected category.']);
        }
    }

    private function budgetCode(Project $project, ProjectPhase $phase, string $category, int $version): string
    {
        $categoryCode = match ($category) {
            'labour_subcontracts' => 'LAB',
            'logistics_equipment' => 'LOG',
            'preliminaries_overheads' => 'PRE',
            default => 'MAT',
        };

        return $project->project_code.'-'.strtoupper($phase->stage_code).'-'.$categoryCode.'-BUD-'.str_pad((string) $version, 3, '0', STR_PAD_LEFT);
    }

    private function requireReason(?string $reason): void
    {
        if (trim((string) $reason) === '') {
            throw ValidationException::withMessages(['edit_reason' => 'Explain why this change is needed.']);
        }
    }

    private function logEntryChange(InternalBudget $budget, InternalBudgetEntry $entry, string $action, string $reason, User $actor, ?array $before, ?array $after): void
    {
        ProjectEditLog::create([
            'project_id' => $budget->project_id,
            'document_type' => 'internal_budget',
            'document_id' => $budget->id,
            'document_code' => $budget->budget_code,
            'from_version' => $budget->version,
            'to_version' => $budget->version,
            'action' => $action,
            'item_description' => $entry->item_description,
            'reason' => $reason,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'edited_by' => $actor->id,
        ]);
    }

    private function snapshot(InternalBudgetEntry $entry): array
    {
        return $entry->only([
            'project_phase_id', 'work_area', 'work_section', 'construction_category', 'work_package_code', 'work_package_name', 'item_reference', 'main_category', 'subcategory', 'item_description', 'supplier_fundi_client',
            'quantity', 'unit', 'unit_cost', 'notes',
        ]);
    }
}
