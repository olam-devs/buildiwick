<?php

namespace App\Http\Requests;

use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInternalBudgetEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $entry = $this->route('entry');
        if ($entry instanceof InternalBudgetEntry) {
            return $user?->can('update', $entry) ?? false;
        }
        $budget = $this->route('budget');
        if ($budget instanceof InternalBudget) {
            return $user?->can('create', [InternalBudgetEntry::class, $budget]) ?? false;
        }
        $project = $this->route('project');

        return $project instanceof Project && ($user?->can('create', [InternalBudget::class, $project]) ?? false);
    }

    public function rules(): array
    {
        $category = $this->input('main_category');

        return [
            'work_area' => ['nullable', 'string', 'max:150'],
            'work_section' => ['nullable', 'string', 'max:255'],
            'item_reference' => ['nullable', 'string', 'max:100'],
            'main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')->where('is_active', true)],
            'subcategory' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $category)->where('is_active', true)],
            'item_description' => ['required', 'string', 'max:1000'],
            'supplier_fundi_client' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'unit' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'unit')->where('is_active', true)],
            'unit_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'edit_reason' => [($this->route('entry') instanceof InternalBudgetEntry && $this->route('entry')->budget?->version > 1) ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }
}
