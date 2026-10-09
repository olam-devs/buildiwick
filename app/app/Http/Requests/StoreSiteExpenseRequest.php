<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreSiteExpenseRequest extends FormRequest
{
    use SiteExpenseRequestRules;

    public function authorize(): bool
    {
        $project = $this->route('project');
        $usesPmRoute = $this->routeIs('pm.requests.store');
        $hasCorrectRole = $usesPmRoute
            ? $this->user()?->hasRole('project_manager')
            : ($this->user()?->hasRole('site_operator') || $this->user()?->hasRole('admin'));

        return $project instanceof Project && $hasCorrectRole
            && ($this->user()?->can('create', [ExpenseRequest::class, $project]) ?? false);
    }

    public function rules(): array
    {
        return $this->expenseRequestRules();
    }
}
