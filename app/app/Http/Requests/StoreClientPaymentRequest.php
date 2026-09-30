<?php

namespace App\Http\Requests;

use App\Models\ClientInvoice;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('clientInvoice');

        return $invoice instanceof ClientInvoice && ($this->user()?->can('create', \App\Models\ClientPayment::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'client_invoice_id' => ['required', Rule::exists('client_invoices', 'id')],
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'client_id' => ['required', Rule::exists('clients', 'id')],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'idempotency_key' => ['required', 'uuid'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::exists('lookups', 'code')->where('type', 'payment_method')],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $existingPayment = \App\Models\ClientPayment::query()->where('idempotency_key', $this->input('idempotency_key'))->first();
            if ($existingPayment) {
                $routeInvoice = $this->route('clientInvoice');
                if (! $routeInvoice instanceof ClientInvoice
                    || (int) $this->input('client_invoice_id') !== (int) $routeInvoice->id
                    || (int) $existingPayment->client_invoice_id !== (int) $routeInvoice->id
                    || (int) $existingPayment->recorded_by !== (int) $this->user()?->id) {
                    $validator->errors()->add('idempotency_key', 'This payment submission key has already been used.');
                }

                return;
            }

            $invoice = ClientInvoice::with(['items', 'payments'])->find($this->input('client_invoice_id'));
            if (! $invoice) {
                return;
            }
            if ((int) $invoice->project_id !== (int) $this->input('project_id') || (int) $invoice->client_id !== (int) $this->input('client_id')) {
                $validator->errors()->add('client_invoice_id', 'The payment project and client must match the invoice.');
            }
            $routeInvoice = $this->route('clientInvoice');
            if ($routeInvoice instanceof ClientInvoice && (int) $routeInvoice->id !== (int) $invoice->id) {
                $validator->errors()->add('client_invoice_id', 'The payment invoice must match the invoice in the route.');
            }
            if ($invoice->status !== 'issued') {
                $validator->errors()->add('client_invoice_id', 'Only issued invoices can receive payments.');
            }
            $invoiceTotal = Money::sum($invoice->items->map(fn ($item) => $item->line_total));
            $alreadyPaid = Money::sum($invoice->payments->pluck('amount'));
            $newTotal = Money::sum([$alreadyPaid, $this->input('amount', '0')]);
            if (Money::compare($newTotal, $invoiceTotal) > 0) {
                $validator->errors()->add('amount', 'The payment cannot exceed the invoice balance.');
            }
        }];
    }
}