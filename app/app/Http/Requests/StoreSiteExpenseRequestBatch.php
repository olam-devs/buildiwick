<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteExpenseRequestBatch extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Keep API and older clients compatible while the browser form now submits an item list.
        if (! $this->has('items') && $this->filled('item_description')) {
            $this->merge(['items' => [[
                'request_type' => $this->input('request_type', 'item'),
                'item_description' => $this->input('item_description'),
                'quantity' => $this->input('quantity'),
                'unit' => $this->input('unit'),
                'requested_amount' => $this->input('requested_amount'),
                'notes' => $this->input('notes'),
                'labour_agreement_id' => $this->input('labour_agreement_id'),
            ]]]);
        }
    }

    public function authorize(): bool
    {
        $project = $this->route('project');
        $isPm = $this->routeIs('pm.requests.store');
        $hasRole = $isPm
            ? $this->user()?->hasRole('project_manager')
            : ($this->user()?->hasRole('site_operator') || $this->user()?->hasRole('admin'));

        return $project instanceof Project && $hasRole
            && ($this->user()?->can('create', [ExpenseRequest::class, $project]) ?? false);
    }

    public function rules(): array
    {
        return [
            'requested_at' => ['required', 'date', 'before_or_equal:today'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.request_type' => ['required', Rule::in(array_keys(ExpenseRequest::TYPES))],
            'items.*.item_description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'items.*.unit' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'unit')->where('is_active', true)],
            'items.*.requested_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'items.*.labour_agreement_id' => [
                'nullable', 'integer',
                Rule::exists('labour_agreements', 'id')->where('project_id', $this->route('project')?->id),
            ],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            foreach ($this->input('items', []) as $index => $item) {
                if (in_array($item['request_type'] ?? null, ['salary_wage', 'cash_amount'], true)
                    && empty($item['requested_amount'])) {
                    $validator->errors()->add("items.$index.requested_amount", 'Enter the amount requested for every salary, wage or cash item.');
                }
            }
        }];
    }
}
