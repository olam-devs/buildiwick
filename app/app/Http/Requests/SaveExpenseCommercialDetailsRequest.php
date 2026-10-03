<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExpenseCommercialDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('expenseRequest');

        return $request instanceof ExpenseRequest && ($this->user()?->can('prepareCommercial', $request) ?? false);
    }

    public function rules(): array
    {
        return [
            'supplier_payee_id' => ['nullable', Rule::exists('supplier_payees', 'id')->where('is_active', true)],
            'commercial_unit_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'commercial_payment_method' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'payment_method')->where('is_active', true)],
            'commercial_payment_destination_id' => ['nullable', Rule::exists('supplier_payment_destinations', 'id')->where('is_active', true)],
            'commercial_payment_destination' => ['nullable', 'string', 'max:2000'],
            'commercial_notes' => ['nullable', 'string', 'max:2000'],
            'commercial_evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $payeeId = $this->input('supplier_payee_id');
            $destinationId = $this->input('commercial_payment_destination_id');
            if ($destinationId && (! $payeeId || ! \App\Models\SupplierPaymentDestination::whereKey($destinationId)->where('supplier_payee_id', $payeeId)->exists())) {
                $validator->errors()->add('commercial_payment_destination_id', 'Select a payment destination belonging to the selected supplier/payee.');
            }
            $method = $this->input('commercial_payment_method');
            if ($destinationId && $method && \App\Models\SupplierPaymentDestination::whereKey($destinationId)->value('payment_method') !== $method) {
                $validator->errors()->add('commercial_payment_method', 'The payment method must match the selected payment destination.');
            }
        }];
    }
}