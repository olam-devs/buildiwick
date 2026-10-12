<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExpenseRequestBatchCommercial extends FormRequest
{
    public function authorize(): bool
    {
        $expenseRequest = $this->route('expenseRequest');

        return $expenseRequest instanceof ExpenseRequest
            && ($this->user()?->can('prepareCommercial', $expenseRequest) ?? false);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.internal_budget_entry_id' => ['nullable', 'integer', 'exists:internal_budget_entries,id'],
            'items.*.supplier_payee_id' => ['nullable', Rule::exists('supplier_payees', 'id')->where('is_active', true)],
            'items.*.commercial_unit_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'items.*.commercial_payment_method' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'payment_method')->where('is_active', true)],
            'items.*.commercial_payment_destination_id' => ['nullable', Rule::exists('supplier_payment_destinations', 'id')->where('is_active', true)],
            'items.*.commercial_notes' => ['nullable', 'string', 'max:2000'],
            'items.*.commercial_evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
        ];
    }
}
