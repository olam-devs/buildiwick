<?php

namespace App\Http\Requests;

use App\Models\ProjectPhase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // A controller must authorize Site Operator/PM project access before persistence.
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'project_phase_id' => ['required', Rule::exists('project_phases', 'id')->where('project_id', $this->input('project_id'))],
            'main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')],
            'subcategory' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $this->input('main_category'))],
            'item_description' => ['required', 'string', 'max:1000'],
            'supplier_fundi_client' => ['nullable', 'string', 'max:255'],
            'invoice_receipt_no' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'unit')],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'requested_at' => ['required', 'date', 'before_or_equal:today'],
            'evidence' => ['nullable', 'file', 'image', 'max:8192'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}