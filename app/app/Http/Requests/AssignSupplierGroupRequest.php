<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignSupplierGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $request = $this->route('expenseRequest');

        return $request instanceof ExpenseRequest && ($this->user()?->can('prepareCommercial', $request) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'distinct', Rule::exists('expense_requests', 'id')],
            'supplier_payee_id' => ['nullable', 'integer', Rule::exists('supplier_payees', 'id')->where('is_active', true), 'required_without:new_supplier_name'],
            'new_supplier_name' => ['nullable', 'string', 'max:255', 'required_without:supplier_payee_id'],
            'new_supplier_phone' => ['nullable', 'string', 'max:100'],
            'new_supplier_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
