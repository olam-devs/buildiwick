<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteExpenseRequest extends FormRequest
{
    use SiteExpenseRequestRules;

    public function authorize(): bool
    {
        $request = $this->route('expenseRequest');

        return $request instanceof ExpenseRequest && ($this->user()?->can('update', $request) ?? false);
    }

    public function rules(): array
    {
        return $this->expenseRequestRules();
    }
}