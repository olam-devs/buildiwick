<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectPhaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // A controller must authorize project access before persistence.
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'stage_code' => ['required', Rule::exists('lookups', 'code')->where('type', 'construction_stage'), Rule::in([
                'preliminaries', 'substructure', 'superstructure', 'walling', 'roofing', 'finishing', 'external_works',
            ])],
            'phase_order' => ['required', 'integer', 'between:1,7'],
            'status' => ['sometimes', Rule::in(['not_started', 'active', 'completed', 'on_hold'])],
            'planned_start_date' => ['nullable', 'date'],
            'planned_completion_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'actual_start_date' => ['nullable', 'date'],
            'actual_completion_date' => ['nullable', 'date', 'after_or_equal:actual_start_date'],
            'physical_progress_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}