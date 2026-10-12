<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\SupplierPayee;
use App\Models\SupplierPaymentDestination;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExpenseRequestBatchCommercialService
{
    public function __construct(private readonly DatabaseManager $database) {}

    /** @return Collection<int, ExpenseRequest> */
    public function prepare(ExpenseRequest $anchor, array $items, array $files, User $actor): Collection
    {
        $newPaths = [];
        $oldPaths = [];

        try {
            $updated = $this->database->transaction(function () use ($anchor, $items, $files, $actor, &$newPaths, &$oldPaths): Collection {
                $batchCode = $anchor->request_batch_code ?: $anchor->request_code;
                $requests = ExpenseRequest::query()->where('request_batch_code', $batchCode)
                    ->lockForUpdate()->get()->keyBy('id');
                if ($requests->isEmpty() || collect(array_keys($items))->diff($requests->keys()->map(fn ($id) => (string) $id))->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => 'One or more submitted items do not belong to this Site request.']);
                }

                $currentBudget = InternalBudget::query()->where('project_id', $anchor->project_id)
                    ->whereIn('status', ['draft', 'finalized', 'approved'])
                    ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
                    ->orderByDesc('version')->first();

                foreach ($items as $id => $data) {
                    $request = $requests->get((int) $id);
                    Gate::forUser($actor)->authorize('prepareCommercial', $request);
                    if ($request->status !== 'pending_approval' || $request->approvals()->exists() || $request->payments()->exists()) {
                        throw ValidationException::withMessages(["items.$id" => 'This item is locked because it has already been decided.']);
                    }

                    $entry = null;
                    if (! empty($data['internal_budget_entry_id'])) {
                        $entry = $currentBudget?->entries()->whereKey($data['internal_budget_entry_id'])->first();
                        if (! $entry instanceof InternalBudgetEntry) {
                            throw ValidationException::withMessages(["items.$id.internal_budget_entry_id" => 'Select a line from this project’s current internal budget.']);
                        }
                    }

                    $supplier = null;
                    if (! empty($data['supplier_payee_id'])) {
                        $supplier = SupplierPayee::query()->whereKey($data['supplier_payee_id'])->where('is_active', true)->first();
                        if (! $supplier) {
                            throw ValidationException::withMessages(["items.$id.supplier_payee_id" => 'Select an active supplier.']);
                        }
                    }

                    $destination = null;
                    if (! empty($data['commercial_payment_destination_id'])) {
                        $destination = SupplierPaymentDestination::query()
                            ->whereKey($data['commercial_payment_destination_id'])
                            ->where('supplier_payee_id', $supplier?->id)->where('is_active', true)->first();
                        if (! $destination) {
                            throw ValidationException::withMessages(["items.$id.commercial_payment_destination_id" => 'Select a payment destination belonging to the selected supplier.']);
                        }
                        if (! empty($data['commercial_payment_method']) && $destination->payment_method !== $data['commercial_payment_method']) {
                            throw ValidationException::withMessages(["items.$id.commercial_payment_method" => 'Payment method must match the selected destination.']);
                        }
                    }

                    $rate = $data['commercial_unit_rate'] ?? null;
                    $upload = $files[$id]['commercial_evidence'] ?? null;
                    $newPath = $upload?->store('expense-requests/'.$request->project_id.'/commercial', 'local');
                    if ($newPath) {
                        $newPaths[] = $newPath;
                        if ($request->commercial_evidence_link) {
                            $oldPaths[] = $request->commercial_evidence_link;
                        }
                    }

                    $request->update([
                        'project_phase_id' => $entry?->project_phase_id ?? $request->project_phase_id,
                        'main_category' => $entry?->main_category ?? $request->main_category,
                        'subcategory' => $entry?->subcategory ?? $request->subcategory,
                        'internal_budget_entry_id' => $entry?->id,
                        'supplier_payee_id' => $supplier?->id,
                        'commercial_unit_rate' => $rate,
                        'commercial_total' => $rate === null ? null : Money::product($rate, $request->quantity ?? '1'),
                        'commercial_payment_method' => $data['commercial_payment_method'] ?? null,
                        'commercial_payment_destination_id' => $destination?->id,
                        'commercial_payment_destination' => $destination ? implode(' | ', array_filter([
                            $destination->payment_method, $destination->account_wallet_name,
                            $destination->account_wallet_number, $destination->bank_provider_name, $destination->branch,
                        ])) : null,
                        'commercial_notes' => $data['commercial_notes'] ?? null,
                        'commercial_prepared_by' => $actor->id,
                        'commercial_prepared_at' => now(),
                        ...($newPath ? ['commercial_evidence_link' => $newPath] : []),
                    ]);
                }

                return ExpenseRequest::query()->where('request_batch_code', $batchCode)
                    ->with(['supplierPayee', 'internalBudgetEntry'])->orderBy('request_line_number')->get();
            });

            Storage::disk('local')->delete(array_unique($oldPaths));

            return $updated;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($newPaths);
            throw $exception;
        }
    }
}
