<?php

namespace App\Http\Requests;

use App\Models\ProjectPhase;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('create', [\App\Models\ClientInvoice::class, $project]) ?? false);
    }

    public function rules(): array
    {
        $rules = [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'client_id' => ['required', Rule::exists('clients', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:255'],
            'project_estimate_id' => ['nullable', Rule::exists('project_estimates', 'id')],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.project_phase_id' => ['required', Rule::exists('project_phases', 'id')->where('project_id', $this->input('project_id'))],
            'items.*.main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')],
            'items.*.item_description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', Rule::exists('lookups', 'code')->where('type', 'unit')],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];

        foreach ($this->input('items', []) as $index => $item) {
            $rules["items.$index.subcategory"] = [
                'nullable',
                'string',
                Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $item['main_category'] ?? null),
            ];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($validator): void {
            $estimateId = $this->input('project_estimate_id');
            if ($estimateId) {
                $estimate = \App\Models\ProjectEstimate::find($estimateId);
                if ($estimate && ((int) $estimate->project_id !== (int) $this->input('project_id') || ($estimate->client_id && (int) $estimate->client_id !== (int) $this->input('client_id')))) {
                    $validator->errors()->add('project_estimate_id', 'The estimate must belong to the invoice project and client.');
                }
            }
            foreach ($this->input('items', []) as $index => $item) {
                $phaseProjectId = ProjectPhase::whereKey($item['project_phase_id'] ?? null)->value('project_id');
                if ($phaseProjectId && (int) $phaseProjectId !== (int) $this->input('project_id')) {
                    $validator->errors()->add("items.$index.project_phase_id", 'The phase must belong to the invoice project.');
                }
            }
        }];
    }
}