<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectSetupRequest extends FormRequest
{
    use ProjectSetupRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
    }

    public function rules(): array
    {
        return $this->projectSetupRules();
    }

    public function after(): array
    {
        return $this->afterProjectSetupValidation();
    }
}