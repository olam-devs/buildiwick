<?php

namespace App\Http\Requests;

use App\Models\EstimateEntry;
use App\Models\Project;
use App\Models\ProjectEstimate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEstimateEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $entry = $this->route('entry');
        if ($entry instanceof EstimateEntry) {
            return $user?->can('update', $entry) ?? false;
        }

        $project = $this->route('project');

        return $project instanceof Project
            && ($user?->can('viewProject', [ProjectEstimate::class, $project]) ?? false)
            && ($user?->can('create', [ProjectEstimate::class, $project]) ?? false);
    }

    public function rules(): array
    {
        $mainCategory = $this->input('main_category');

        return [
            'work_area' => ['nullable', 'string', 'max:150'],
            'work_section' => ['nullable', 'string', 'max:255'],
            'item_reference' => ['nullable', 'string', 'max:100'],
            'main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')->where('is_active', true)],
            'subcategory' => [
                'nullable', 'string',
                Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $mainCategory)->where('is_active', true),
            ],
            'item_description' => ['required', 'string', 'max:1000'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'unit' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'unit')->where('is_active', true)],
            'base_unit_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'markup_percent' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'edit_reason' => [($this->route('entry') instanceof EstimateEntry && $this->route('entry')->estimate?->version > 1) ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }
}
