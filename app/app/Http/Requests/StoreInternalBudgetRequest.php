<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInternalBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // A controller must authorize the PM/Admin for the project.
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'version' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['draft', 'approved', 'superseded'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}