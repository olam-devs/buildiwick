<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\Access;
use App\Support\Features;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAdminUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    /**
     * Usernames are only for Data Entry accounts (stored lowercase); every other role signs in with
     * an email, so a username sent for them is dropped.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => $this->isDataEntry() && $this->filled('username') ? mb_strtolower(trim((string) $this->input('username'))) : null,
            'email' => $this->filled('email') ? trim((string) $this->input('email')) : null,
        ]);
    }

    private function isDataEntry(): bool
    {
        return Role::whereKey($this->input('role_id'))->value('name') === 'data_entry';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Data Entry signs in with a username (email optional); other roles sign in with their email.
            'username' => ['nullable', Rule::requiredIf(fn () => $this->isDataEntry()), 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($this->route('user'))],
            'email' => ['nullable', Rule::requiredIf(fn () => ! $this->isDataEntry()), 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:50'],
            'role_id' => ['required', 'integer', 'exists:roles,id', function ($attribute, $value, $fail) {
                $name = Role::whereKey($value)->value('name');
                if ($name === 'admin' || (Features::recording() && in_array($name, ['estimator_qs', 'site_operator'], true))) {
                    $fail('Choose Director, Project Manager, Accounts or Data Entry.');
                }
            }],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'is_active' => ['required', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(array_keys(Access::PERMISSIONS))],
            'all_projects' => ['nullable', 'boolean'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.regex' => 'Use small letters, numbers, dots, dashes or underscores (no spaces), e.g. rehema.m',
            'username.required' => 'Data Entry accounts sign in with a username.',
            'email.required' => 'This role signs in with an email address.',
        ];
    }
}
