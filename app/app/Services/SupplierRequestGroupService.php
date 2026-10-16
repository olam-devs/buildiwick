<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\InternalBudgetEntry;
use App\Models\SupplierPayee;
use App\Models\SupplierRequestGroup;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierRequestGroupService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ExpenseRequestDecisionService $decisions,
    ) {}

    public function move(ExpenseRequest $anchor, array $data, User $actor): int
    {
        return $this->database->transaction(function () use ($anchor, $data, $actor): int {
            $items = $this->selectedBatchItems($anchor, $data['item_ids'], $actor);
            $target = $data['direction'] === 'to_supplier_assignment' ? 'supplier_assignment' : 'site_review';

            foreach ($items as $item) {
                if ($target === 'supplier_assignment' && $item->procurement_stage !== 'site_review') {
                    throw ValidationException::withMessages(['item_ids' => 'Only Step 1 items can move to supplier assignment.']);
                }
                if ($target === 'site_review' && $item->procurement_stage !== 'supplier_assignment') {
                    throw ValidationException::withMessages(['item_ids' => 'Only ungrouped Step 2 items can return to Step 1.']);
                }

                $edits = $data['items'][$item->id] ?? [];
                if ($target === 'supplier_assignment') {
                    $budgetEntry = $this->budgetEntry($item, $edits['internal_budget_entry_id'] ?? $item->internal_budget_entry_id);
                    $edits = [
                        ...$edits,
                        'internal_budget_entry_id' => $budgetEntry->id,
                        'project_phase_id' => $budgetEntry->project_phase_id,
                        'main_category' => $budgetEntry->main_category,
                        'subcategory' => $budgetEntry->subcategory,
                        'work_area' => $budgetEntry->work_area,
                        'construction_category' => $budgetEntry->construction_category,
                        'work_package_code' => $budgetEntry->work_package_code,
                        'work_package_name' => $budgetEntry->work_package_name,
                        'is_new_item' => false,
                    ];

                    $labels = [
                        'item_description' => 'description', 'quantity' => 'quantity', 'unit' => 'unit',
                        'internal_budget_entry_id' => 'budget allocation', 'project_phase_id' => 'phase',
                        'main_category' => 'budget category', 'subcategory' => 'budget subcategory',
                        'work_area' => 'work area', 'construction_category' => 'construction category',
                        'work_package_code' => 'work package code', 'work_package_name' => 'work package',
                    ];
                    $changed = collect($labels)->filter(function (string $label, string $field) use ($item, $edits): bool {
                        $before = $item->getAttribute($field);
                        $after = array_key_exists($field, $edits) ? $edits[$field] : $before;

                        return (string) ($before ?? '') !== (string) ($after ?? '');
                    })->values();
                    $edits['pm_edited'] = $changed->isNotEmpty();
                    $edits['pm_edit_summary'] = $changed->isNotEmpty()
                        ? 'Changed: '.$changed->join(', ')
                        : null;
                }
                $item->update([
                    ...$edits,
                    'procurement_stage' => $target,
                    'verified_by' => $target === 'supplier_assignment' ? $actor->id : null,
                    'verified_at' => $target === 'supplier_assignment' ? now() : null,
                ]);
            }

            return $items->count();
        });
    }

    public function assign(ExpenseRequest $anchor, array $data, User $actor): SupplierRequestGroup
    {
        return $this->database->transaction(function () use ($anchor, $data, $actor): SupplierRequestGroup {
            $items = $this->selectedBatchItems($anchor, $data['item_ids'], $actor);
            if ($items->contains(fn (ExpenseRequest $item) => $item->procurement_stage !== 'supplier_assignment' || $item->supplier_request_group_id)) {
                throw ValidationException::withMessages(['item_ids' => 'Select only ungrouped items currently in Step 2.']);
            }

            $supplier = ! empty($data['supplier_payee_id'])
                ? SupplierPayee::query()->where('is_active', true)->findOrFail($data['supplier_payee_id'])
                : SupplierPayee::create([
                    'name' => $data['new_supplier_name'], 'type' => 'supplier', 'phone' => $data['new_supplier_phone'] ?? null,
                    'email' => $data['new_supplier_email'] ?? null, 'is_active' => true,
                ]);

            $group = SupplierRequestGroup::create([
                'group_code' => 'SG-TMP-'.Str::ulid(), 'project_id' => $anchor->project_id,
                'request_batch_code' => $anchor->request_batch_code ?: $anchor->request_code,
                'supplier_payee_id' => $supplier->id, 'status' => 'draft', 'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            $group->update(['group_code' => 'SG-'.str_pad((string) $group->id, 6, '0', STR_PAD_LEFT)]);
            ExpenseRequest::query()->whereIn('id', $items->pluck('id'))->update([
                'supplier_request_group_id' => $group->id, 'supplier_payee_id' => $supplier->id,
                'procurement_stage' => 'supplier_pricing',
            ]);

            return $group->fresh(['supplier', 'items']);
        });
    }

    public function price(SupplierRequestGroup $group, array $data, User $actor, ?UploadedFile $evidence): SupplierRequestGroup
    {
        $storedPath = null;
        try {
            return $this->database->transaction(function () use ($group, $data, $actor, $evidence, &$storedPath): SupplierRequestGroup {
                $locked = SupplierRequestGroup::query()->with(['items', 'supplier'])->lockForUpdate()->findOrFail($group->id);
                if ($locked->status !== 'draft') {
                    throw ValidationException::withMessages(['items' => 'An approved supplier group can no longer be repriced.']);
                }
                $destinationId = $data['payment_destination_id'] ?? null;
                if (! $destinationId && ! empty($data['new_account_wallet_number'])) {
                    $destinationId = $locked->supplier->paymentDestinations()->create([
                        'payment_method' => $data['payment_method'],
                        'account_wallet_name' => $data['new_account_wallet_name'] ?? $locked->supplier->name,
                        'account_wallet_number' => $data['new_account_wallet_number'],
                        'bank_provider_name' => $data['new_bank_provider_name'] ?? null,
                        'branch' => $data['new_branch'] ?? null,
                        'is_active' => true,
                    ])->id;
                }
                foreach ($locked->items as $item) {
                    Gate::forUser($actor)->authorize('prepareCommercial', $item);
                    $rate = $data['items'][$item->id]['commercial_unit_rate'] ?? null;
                    if ($rate === null) {
                        throw ValidationException::withMessages(['items' => 'Enter a unit price for every item in this supplier group.']);
                    }
                    $item->update([
                        'commercial_unit_rate' => $rate,
                        'commercial_total' => Money::product($rate, $item->quantity ?? '1'),
                        'commercial_payment_method' => $data['payment_method'],
                        'commercial_payment_destination_id' => $destinationId,
                        'commercial_notes' => $data['notes'] ?? null,
                        'commercial_prepared_by' => $actor->id, 'commercial_prepared_at' => now(),
                    ]);
                }
                if ($destinationId && ! $locked->supplier->paymentDestinations()->whereKey($destinationId)->exists()) {
                    throw ValidationException::withMessages(['payment_destination_id' => 'Select a payment destination belonging to this supplier.']);
                }
                $storedPath = $evidence?->store('supplier-request-groups/'.$locked->project_id, 'local');
                $oldPath = $locked->evidence_link;
                $locked->update([
                    'payment_method' => $data['payment_method'], 'payment_destination_id' => $destinationId,
                    'notes' => $data['notes'] ?? null,
                    ...($evidence ? ['evidence_link' => $storedPath, 'evidence_original_name' => $evidence->getClientOriginalName()] : []),
                ]);
                if ($evidence && $oldPath && $oldPath !== $storedPath) {
                    Storage::disk('local')->delete($oldPath);
                }

                return $locked->fresh(['supplier', 'paymentDestination', 'items']);
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    public function returnToAssignment(SupplierRequestGroup $group, User $actor): void
    {
        $this->database->transaction(function () use ($group, $actor): void {
            $locked = SupplierRequestGroup::query()->with('items')->lockForUpdate()->findOrFail($group->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['group' => 'Approved groups cannot return to Step 2.']);
            }
            foreach ($locked->items as $item) {
                Gate::forUser($actor)->authorize('prepareCommercial', $item);
                $item->update(['supplier_request_group_id' => null, 'supplier_payee_id' => null, 'procurement_stage' => 'supplier_assignment', 'commercial_unit_rate' => null, 'commercial_total' => null, 'commercial_payment_method' => null, 'commercial_payment_destination_id' => null]);
            }
            $locked->delete();
        });
    }

    public function decide(SupplierRequestGroup $group, array $data, User $actor): SupplierRequestGroup
    {
        if ($data['decision'] === 'returned') {
            $this->returnToAssignment($group, $actor);

            return $group;
        }

        return $this->database->transaction(function () use ($group, $data, $actor): SupplierRequestGroup {
            $locked = SupplierRequestGroup::query()->with('items')->lockForUpdate()->findOrFail($group->id);
            if ($locked->status !== 'draft' || $locked->items->isEmpty()) {
                throw ValidationException::withMessages(['decision' => 'This supplier group is not available for approval.']);
            }
            foreach ($locked->items as $item) {
                $this->decisions->decide($item, 'approve', $data['notes'] ?: 'Supplier group '.$locked->group_code.' approved.', $actor);
                $item->update(['procurement_stage' => 'approved']);
            }
            $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_notes' => $data['notes'] ?? null]);

            return $locked->fresh(['supplier', 'items']);
        });
    }

    private function selectedBatchItems(ExpenseRequest $anchor, array $ids, User $actor): Collection
    {
        $batchCode = $anchor->request_batch_code ?: $anchor->request_code;
        $items = ExpenseRequest::query()->whereIn('id', $ids)->where('project_id', $anchor->project_id)
            ->where(fn ($query) => $query->where('request_batch_code', $batchCode)->orWhere('request_code', $batchCode))
            ->lockForUpdate()->get();
        if ($items->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['item_ids' => 'One or more selected items do not belong to this request.']);
        }
        foreach ($items as $item) {
            Gate::forUser($actor)->authorize('prepareCommercial', $item);
        }

        return $items;
    }

    private function budgetEntry(ExpenseRequest $item, mixed $entryId): InternalBudgetEntry
    {
        $entry = $entryId ? InternalBudgetEntry::query()->whereKey($entryId)
            ->whereHas('budget', fn ($query) => $query->where('project_id', $item->project_id)->where('status', 'approved'))->first() : null;
        if (! $entry) {
            throw ValidationException::withMessages(['items' => 'Each verified item must be linked to an exact line in the approved internal budget.']);
        }

        return $entry;
    }
}
