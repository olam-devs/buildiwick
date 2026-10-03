<?php

namespace App\Services;

use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExpensePaymentRecorder
{
    public function __construct(private readonly DatabaseManager $database) {}

    /** @param array{payment_date:string, payment_method:string, invoice_receipt_no?:?string, transaction_reference?:?string, variance_reason?:?string, notes?:?string} $data */
    public function record(ExpenseRequest|int $request, array $data, User $recordedBy, ?UploadedFile $evidence = null): ExpensePayment
    {
        $expenseRequestId = $request instanceof ExpenseRequest ? $request->getKey() : $request;
        $evidencePath = null;

        try {
            return $this->database->transaction(function () use ($expenseRequestId, $data, $recordedBy, $evidence, &$evidencePath): ExpensePayment {
                $payable = ExpenseRequest::query()->whereKey($expenseRequestId)->lockForUpdate()->firstOrFail();

                Gate::forUser($recordedBy)->authorize('recordPayment', $payable);
                Gate::forUser($recordedBy)->authorize('create', ExpensePayment::class);

                if ($payable->status !== 'approved') {
                    throw new AuthorizationException('Only approved expense requests can be paid.');
                }

                if ((isset($data['expense_request_id']) && (int) $data['expense_request_id'] !== (int) $payable->id)
                    || (isset($data['project_id']) && (int) $data['project_id'] !== (int) $payable->project_id)
                    || (isset($data['project_phase_id']) && (int) $data['project_phase_id'] !== (int) $payable->project_phase_id)) {
                    throw ValidationException::withMessages(['expense_request_id' => 'Payment request, project, and phase must match the locked approved request.']);
                }

                $idempotencyKey = $data['idempotency_key'] ?? null;
                if ($idempotencyKey) {
                    $existing = ExpensePayment::query()->where('idempotency_key', $idempotencyKey)->first();
                    if ($existing) {
                        if ((int) $existing->expense_request_id !== (int) $payable->id || (int) $existing->recorded_by !== (int) $recordedBy->id) {
                            throw ValidationException::withMessages(['idempotency_key' => 'This payment submission key has already been used.']);
                        }

                        return $existing;
                    }
                }

                $approvedMethod = $payable->latestApproval?->payment_method_snapshot ?? $payable->commercial_payment_method;
                if ($approvedMethod && $approvedMethod !== ($data['payment_method'] ?? null) && ! trim((string) ($data['variance_reason'] ?? ''))) {
                    throw ValidationException::withMessages(['variance_reason' => 'Explain why the actual payment method differs from the approved method.']);
                }

                $amount = Money::decimal($data['amount'] ?? '0');
                if ($amount->compareTo('0') <= 0) {
                    throw ValidationException::withMessages(['amount' => 'The payment amount must be greater than zero.']);
                }

                $paid = Money::sum($payable->payments()->lockForUpdate()->pluck('amount'));
                $outstanding = Money::difference($payable->approved_payable_amount, $paid);
                if (Money::compare($amount, $outstanding) > 0) {
                    throw ValidationException::withMessages(['amount' => 'The payment exceeds the outstanding request amount.']);
                }

                $evidencePath = $evidence?->store('expense-payments', 'local');

                return $payable->payments()->create([
                    'payment_code' => 'EXP-PAY-'.Str::ulid(),
                    'idempotency_key' => $idempotencyKey,
                    'project_id' => $payable->project_id,
                    'project_phase_id' => $payable->project_phase_id,
                    'amount' => $amount->toScale(2)->__toString(),
                    'payment_date' => $data['payment_date'],
                    'payment_method' => $data['payment_method'],
                    'transaction_reference' => $data['transaction_reference'] ?? null,
                    'variance_reason' => $data['variance_reason'] ?? null,
                    'invoice_receipt_no' => $data['invoice_receipt_no'] ?? null,
                    'evidence_link' => $evidencePath,
                    'evidence_original_name' => $evidence?->getClientOriginalName(),
                    'recorded_by' => $recordedBy->id,
                    'notes' => $data['notes'] ?? null,
                ]);
            });
        } catch (Throwable $exception) {
            if ($evidencePath) {
                Storage::disk('local')->delete($evidencePath);
            }

            throw $exception;
        }
    }
}