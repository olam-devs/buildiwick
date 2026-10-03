<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // A controller must authorize Estimator/QS access to the project.
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', Rule::exists('projects', 'id')],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'version' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['draft', 'issued', 'superseded'])],
            'prepared_at' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:prepared_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}