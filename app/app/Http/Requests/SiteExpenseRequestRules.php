<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use Illuminate\Validation\Rule;

trait SiteExpenseRequestRules
{
    protected function expenseRequestRules(): array
    {
        $mainCategory = $this->input('main_category');

        return [
            'requested_at' => ['required', 'date', 'before_or_equal:today'],
            'request_type' => ['required', Rule::in(array_keys(ExpenseRequest::TYPES))],
            'main_category' => ['required', Rule::exists('lookups', 'code')->where('type', 'main_category')->where('is_active', true)],
            'subcategory' => [
                'nullable', 'string',
                Rule::exists('lookups', 'code')->where('type', 'subcategory')->where('parent_code', $mainCategory)->where('is_active', true),
            ],
            'item_description' => ['required', 'string', 'max:1000'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'unit' => ['nullable', Rule::exists('lookups', 'code')->where('type', 'unit')->where('is_active', true)],
            'requested_amount' => ['nullable', 'required_if:request_type,salary_wage,cash_amount', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
