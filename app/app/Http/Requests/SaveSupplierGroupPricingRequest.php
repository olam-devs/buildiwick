<?php

namespace App\Http\Requests;

use App\Models\SupplierRequestGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierGroupPricingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $group = $this->route('supplierRequestGroup');

        return $group instanceof SupplierRequestGroup
            && ($group->items()->first() && ($this->user()?->can('prepareCommercial', $group->items()->first()) ?? false));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.commercial_unit_rate' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'payment_method' => ['required', Rule::exists('lookups', 'code')->where('type', 'payment_method')->where('is_active', true)],
            'payment_destination_id' => ['nullable', 'integer', Rule::exists('supplier_payment_destinations', 'id')],
            'new_account_wallet_name' => ['nullable', 'string', 'max:255'],
            'new_account_wallet_number' => ['nullable', 'string', 'max:255'],
            'new_bank_provider_name' => ['nullable', 'string', 'max:255'],
            'new_branch' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
        ];
    }
}
