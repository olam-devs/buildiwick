<?php

namespace App\Http\Requests;

use App\Models\InternalBudget;
use App\Models\ProjectPhase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInternalBudgetEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // A controller must authorize budget ownership/role before persistence.
    }

    public function rules(): array
    {
        return [
            'internal_budget_id' => ['required', Rule::exists('internal_budgets', 'id')],
            'project_phase_id' => ['required', Rule::exists('project_phases', 'id')],
            'main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')],
            'subcategory' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $this->input('main_category'))],
            'item_description' => ['required', 'string', 'max:1000'],
            'supplier_fundi_client' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'unit')],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $budgetProjectId = InternalBudget::whereKey($this->input('internal_budget_id'))->value('project_id');
            $phaseProjectId = ProjectPhase::whereKey($this->input('project_phase_id'))->value('project_id');
            if ($budgetProjectId && $phaseProjectId && (int) $budgetProjectId !== (int) $phaseProjectId) {
                $validator->errors()->add('project_phase_id', 'The phase must belong to the budget project.');
            }
        }];
    }
}