<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Real check happens in the controller via $this->authorize('create', [Transaction::class, $project])
        // — the policy needs the resolved Project model, which isn't available until after validation passes.
        return true;
    }

    public function rules(): array
    {
        $mainCategory = $this->input('main_category');

        return [
            'client_uuid' => ['nullable', 'uuid'],
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')],
            'subcategory' => [
                'nullable', 'string',
                Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $mainCategory),
            ],
            'item_description' => ['required', 'string', 'max:500'],
            'supplier_fundi_client' => ['nullable', 'string', 'max:255'],
            'invoice_receipt_no' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'unit')],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'work_area_stage' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'construction_stage')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'evidence' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:8192'], // 8MB, matches a phone camera photo

            // Only meaningful when main_category = labour_subcontracts: this is an
            // instalment against an existing agreement, never a naked labour cost.
            'agreement_id' => [
                Rule::requiredIf($mainCategory === 'labour_subcontracts'),
                'nullable',
                Rule::exists('labour_agreements', 'id')->where('project_id', $this->input('project_id')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'agreement_id.required' => 'A labour or subcontract payment must reference an existing agreement — record the agreement first.',
        ];
    }
}
