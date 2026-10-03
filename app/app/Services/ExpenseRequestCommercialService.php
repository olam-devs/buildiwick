<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\SupplierPaymentDestination;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExpenseRequestCommercialService
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function prepare(ExpenseRequest $expenseRequest, array $data, User $actor, ?UploadedFile $evidence = null): ExpenseRequest
    {
        $newPath = null;
        $oldPath = $expenseRequest->commercial_evidence_link;

        try {
            $updated = $this->database->transaction(function () use ($expenseRequest, $data, $actor, $evidence, &$newPath): ExpenseRequest {
                $request = ExpenseRequest::query()->with(['supplierPayee', 'commercialPaymentDestination'])
                    ->whereKey($expenseRequest->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('prepareCommercial', $request);
                if ($request->status !== 'pending_approval') {
                    throw ValidationException::withMessages(['expenseRequest' => 'Commercial details are locked after a request is decided.']);
                }

                $destination = null;
                if (! empty($data['commercial_payment_destination_id'])) {
                    $destination = SupplierPaymentDestination::query()
                        ->whereKey($data['commercial_payment_destination_id'])
                        ->where('supplier_payee_id', $data['supplier_payee_id'] ?? null)
                        ->where('is_active', true)
                        ->first();
                    if (! $destination) {
                        throw ValidationException::withMessages(['commercial_payment_destination_id' => 'Select an active destination belonging to the chosen supplier/payee.']);
                    }
                    if (! empty($data['commercial_payment_method']) && $destination->payment_method !== $data['commercial_payment_method']) {
                        throw ValidationException::withMessages(['commercial_payment_method' => 'The payment method must match the selected payment destination.']);
                    }
                }

                $payeeId = $data['supplier_payee_id'] ?? null;
                if ($payeeId && ! \App\Models\SupplierPayee::query()->whereKey($payeeId)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['supplier_payee_id' => 'Select an active supplier/payee.']);
                }

                $rate = $data['commercial_unit_rate'] ?? null;
                $total = $rate === null ? null : Money::product($rate, $request->quantity ?? '1');
                if ($evidence) {
                    $newPath = $evidence->store('expense-requests/'.$request->project_id.'/commercial', 'local');
                }

                $attributes = [
                    'supplier_payee_id' => $payeeId,
                    'commercial_unit_rate' => $rate,
                    'commercial_total' => $total,
                    'commercial_payment_method' => $data['commercial_payment_method'] ?? null,
                    'commercial_payment_destination_id' => $destination?->id,
                    'commercial_payment_destination' => $destination
                        ? implode(' | ', array_filter([$destination->payment_method, $destination->account_wallet_name, $destination->account_wallet_number, $destination->bank_provider_name, $destination->branch]))
                        : ($data['commercial_payment_destination'] ?? null),
                    'commercial_notes' => $data['commercial_notes'] ?? null,
                    'commercial_prepared_by' => $actor->id,
                    'commercial_prepared_at' => now(),
                ];
                if ($evidence) {
                    $attributes['commercial_evidence_link'] = $newPath;
                }

                $request->update($attributes);

                return $request->fresh(['project', 'phase', 'supplierPayee', 'commercialPaymentDestination']);
            });

            if ($evidence && $oldPath && $oldPath !== $newPath) {
                Storage::disk('local')->delete($oldPath);
            }

            return $updated;
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }

            throw $exception;
        }
    }
}