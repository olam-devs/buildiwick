<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequestDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // A controller must authorize an assigned Project Manager/Admin.
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}