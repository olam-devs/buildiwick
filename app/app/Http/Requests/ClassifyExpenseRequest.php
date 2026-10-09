<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassifyExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expenseRequest = $this->route('expenseRequest');

        return $expenseRequest instanceof ExpenseRequest
            && ($this->user()?->can('prepareCommercial', $expenseRequest) ?? false);
    }

    public function rules(): array
    {
        $category = $this->input('main_category');

        return [
            'project_phase_id' => ['required', 'integer', 'exists:project_phases,id'],
            'main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')->where('is_active', true)],
            'subcategory' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $category)->where('is_active', true)],
            'internal_budget_entry_id' => ['nullable', 'integer', 'exists:internal_budget_entries,id'],
        ];
    }
}
