<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreSiteExpenseRequest extends FormRequest
{
    use SiteExpenseRequestRules;

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('create', [\App\Models\ExpenseRequest::class, $project]) ?? false);
    }

    public function rules(): array
    {
        return $this->expenseRequestRules();
    }
}