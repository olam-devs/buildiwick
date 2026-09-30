<?php

namespace App\Http\Requests;

use App\Models\SupplierPayee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePaymentDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplierPayee');

        return $supplier instanceof SupplierPayee && ($this->user()?->can('update', $supplier) ?? false);
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::exists('lookups', 'code')->where('type', 'payment_method')->where('is_active', true)],
            'account_wallet_name' => ['nullable', 'string', 'max:255'],
            'account_wallet_number' => ['nullable', 'string', 'max:255'],
            'bank_provider_name' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}