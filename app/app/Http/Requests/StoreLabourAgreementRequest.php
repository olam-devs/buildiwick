<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabourAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // real check: $this->authorize('create', [LabourAgreement::class, $project]) in the controller
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'fundi_subcontractor' => ['required', 'string', 'max:255'],
            'scope_of_work' => ['nullable', 'string', 'max:1000'],
            'labour_type' => [
                'nullable', 'string',
                Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', 'labour_subcontracts'),
            ],
            'agreement_date' => ['required', 'date'],
            'agreed_value' => ['required', 'numeric', 'min:0.01'],
            'start_date' => ['nullable', 'date'],
            'expected_completion' => ['nullable', 'date', 'after_or_equal:start_date'],
            'contact' => ['nullable', 'string', 'max:100'],
        ];
    }
}
