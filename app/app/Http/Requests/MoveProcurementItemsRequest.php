<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveProcurementItemsRequest extends FormRequest
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
            'direction' => ['required', Rule::in(['to_supplier_assignment', 'to_site_review'])],
            'items' => ['nullable', 'array'],
            'items.*.item_description' => ['required_with:items', 'string', 'max:1000'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.project_phase_id' => ['required_with:items', 'integer', Rule::exists('project_phases', 'id')],
            'items.*.internal_budget_entry_id' => ['nullable', 'integer', Rule::exists('internal_budget_entries', 'id')],
            'items.*.work_area' => ['required_with:items', 'string', 'max:255'],
            'items.*.construction_category' => ['required_with:items', 'string', 'max:255'],
            'items.*.work_package_code' => ['nullable', 'string', 'max:50'],
            'items.*.work_package_name' => ['required_with:items', 'string', 'max:255'],
        ];
    }
}
