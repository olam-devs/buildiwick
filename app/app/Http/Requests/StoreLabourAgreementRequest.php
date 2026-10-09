<?php

namespace App\Http\Requests;

use App\Models\LabourAgreement;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabourAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        if (! $project instanceof Project) {
            $project = Project::find($this->input('project_id'));
        }

        return $project instanceof Project
            && ($this->user()?->can('create', [LabourAgreement::class, $project]) ?? false);
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
