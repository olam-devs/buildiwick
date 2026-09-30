<?php

namespace App\Http\Requests;

use App\Models\ProjectEstimate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstimateEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // A controller must authorize estimate ownership/role before persistence.
    }

    public function rules(): array
    {
        return [
            'project_estimate_id' => ['required', Rule::exists('project_estimates', 'id')],
            'project_phase_id' => ['required', Rule::exists('project_phases', 'id')],
            'main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')],
            'subcategory' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $this->input('main_category'))],
            'item_description' => ['required', 'string', 'max:1000'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'unit')],
            'base_unit_cost' => ['required', 'numeric', 'min:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $estimateProjectId = ProjectEstimate::whereKey($this->input('project_estimate_id'))->value('project_id');
            $phaseProjectId = \App\Models\ProjectPhase::whereKey($this->input('project_phase_id'))->value('project_id');
            if ($estimateProjectId && $phaseProjectId && (int) $estimateProjectId !== (int) $phaseProjectId) {
                $validator->errors()->add('project_phase_id', 'The phase must belong to the estimate project.');
            }
        }];
    }
}