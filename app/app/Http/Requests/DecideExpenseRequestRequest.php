<?php

namespace App\Http\Requests;

use App\Models\ExpenseRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideExpenseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('expenseRequest');

        return $request instanceof ExpenseRequest && ($this->user()?->can($this->input('decision') === 'approve' ? 'approve' : 'reject', $request) ?? false);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['required', 'string', 'max:4000'],
        ];
    }
}