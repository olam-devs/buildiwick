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
    /** Usernames are stored lowercase and without spaces around them. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => $this->filled('username') ? mb_strtolower(trim((string) $this->input('username'))) : null,
            'email' => $this->filled('email') ? trim((string) $this->input('email')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Sign-in name: a username, an email, or both; at least one is required.
            'username' => ['nullable', 'required_without:email', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($this->route('user'))],
            'email' => ['nullable', 'required_without:username', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:50'],
            'role_id' => ['required', 'integer', 'exists:roles,id', function ($attribute, $value, $fail) {
                $name = Role::whereKey($value)->value('name');
                if ($name === 'admin' || (Features::recording() && in_array($name, ['estimator_qs', 'site_operator'], true))) {
                    $fail('Choose Director, Project Manager or Accounts.');
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
            'username.required_without' => 'Give a username or an email address (or both).',
            'email.required_without' => 'Give a username or an email address (or both).',
        ];
    }
}
