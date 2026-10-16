<?php

namespace App\Http\Requests;

use App\Models\SupplierRequestGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideSupplierGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $group = $this->route('supplierRequestGroup');

        return $group instanceof SupplierRequestGroup
            && ($group->items()->first() && ($this->user()?->can('prepareCommercial', $group->items()->first()) ?? false));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'returned'])],
            'notes' => ['nullable', 'string', 'max:2000', 'required_if:decision,returned'],
        ];
    }
}
