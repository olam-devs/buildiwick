<?php

namespace App\Services;

use App\Models\ClientInvoice;
use App\Models\ClientPayment;
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

class ClientPaymentRecorder
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function record(ClientInvoice|int $invoice, array $data, User $actor, ?UploadedFile $evidence = null): ClientPayment
    {
        $invoiceId = $invoice instanceof ClientInvoice ? $invoice->id : $invoice;
        $path = null;
        try {
            return $this->database->transaction(function () use ($invoiceId, $data, $actor, $evidence, &$path): ClientPayment {
                $locked = ClientInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('create', ClientPayment::class);
                if ($locked->status !== 'issued') {
                    throw new AuthorizationException('Only issued client invoices can receive payments.');
                }
                if ((isset($data['client_invoice_id']) && (int) $data['client_invoice_id'] !== (int) $locked->id)
                    || (isset($data['project_id']) && (int) $data['project_id'] !== (int) $locked->project_id)
                    || (isset($data['client_id']) && (int) $data['client_id'] !== (int) $locked->client_id)) {
                    throw ValidationException::withMessages(['client_invoice_id' => 'Payment invoice, project, and client must match the locked invoice.']);
                }
                $idempotencyKey = $data['idempotency_key'] ?? null;
                if ($idempotencyKey) {
                    $existing = ClientPayment::query()->where('idempotency_key', $idempotencyKey)->first();
                    if ($existing) {
                        if ((int) $existing->client_invoice_id !== (int) $locked->id || (int) $existing->recorded_by !== (int) $actor->id) {
                            throw ValidationException::withMessages(['idempotency_key' => 'This payment submission key has already been used.']);
                        }

                        return $existing;
                    }
                }
                $amount = Money::decimal($data['amount'] ?? '0');
                $items = $locked->items()->lockForUpdate()->get();
                $total = Money::sum($items->map(fn ($item) => $item->line_total));
                $paid = Money::sum($locked->payments()->lockForUpdate()->pluck('amount'));
                if ($amount->compareTo('0') <= 0 || Money::compare($amount, Money::difference($total, $paid)) > 0) {
                    throw ValidationException::withMessages(['amount' => 'Payment must be positive and no greater than the invoice outstanding balance.']);
                }
                $path = $evidence?->store('client-payments/'.$locked->project_id, 'local');
                return $locked->payments()->create([
                    'payment_code' => 'RCP-'.Str::ulid(), 'project_id' => $locked->project_id, 'client_id' => $locked->client_id,
                    'idempotency_key' => $idempotencyKey,
                    'amount' => $amount->__toString(), 'payment_date' => $data['payment_date'], 'payment_method' => $data['payment_method'],
                    'transaction_reference' => $data['transaction_reference'] ?? null, 'reference' => $data['reference'] ?? null,
                    'evidence_link' => $path, 'evidence_original_name' => $evidence?->getClientOriginalName(),
                    'recorded_by' => $actor->id, 'notes' => $data['notes'] ?? null,
                ]);
            });
        } catch (Throwable $exception) {
            if ($path) Storage::disk('local')->delete($path);
            throw $exception;
        }
    }
}
