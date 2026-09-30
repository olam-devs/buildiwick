<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectSetupRequest extends FormRequest
{
    use ProjectSetupRules;

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && ($this->user()?->can('update', $project) ?? false);
    }

    public function rules(): array
    {
        return $this->projectSetupRules($this->route('project'));
    }

    public function after(): array
    {
        return $this->afterProjectSetupValidation($this->route('project'));
    }
}