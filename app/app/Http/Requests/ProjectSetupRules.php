<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Role;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ProjectSetupRules
{
    protected function projectSetupRules(?Project $project = null): array
    {
        $estimatorRole = Role::where('name', 'estimator_qs')->value('id');
        $managerRole = Role::where('name', 'project_manager')->value('id');
        $operatorRole = Role::where('name', 'site_operator')->value('id');
        $stages = ['preliminaries', 'substructure', 'superstructure', 'walling', 'roofing', 'finishing', 'external_works'];

        $rules = [
            'project_code' => [
                'required', 'string', 'max:100',
                Rule::unique('projects', 'project_code')->ignore($project?->id),
                ...($project ? [Rule::in([$project->project_code])] : []),
            ],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', 'project_status'), Rule::in(['active', 'planning', 'on_hold', 'completed'])],
            'planned_start_date' => ['required', 'date'],
            'expected_completion_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'client_mode' => ['required', Rule::in(['existing', 'new'])],
            'client_id' => [Rule::requiredIf($this->input('client_mode') === 'existing'), 'nullable', Rule::exists('clients', 'id')->where('is_active', true)],
            'new_client.name' => [Rule::requiredIf($this->input('client_mode') === 'new'), 'nullable', 'string', 'max:255'],
            'new_client.phone' => ['nullable', 'string', 'max:50'],
            'new_client.email' => ['nullable', 'email', 'max:255'],
            'new_client.address' => ['nullable', 'string', 'max:2000'],
            'new_client.notes' => ['nullable', 'string', 'max:2000'],
            'contract_reference' => ['nullable', 'string', 'max:255'],
            'contract_signed' => ['required', 'boolean'],
            'contract_signed_at' => ['nullable', 'date', Rule::requiredIf((bool) $this->boolean('contract_signed'))],
            'contract_document' => [
                Rule::requiredIf((bool) $this->boolean('contract_signed') && ! ($project?->contractDocuments()->exists())),
                'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:20480',
            ],
            'estimator_id' => ['required', 'distinct', Rule::exists('users', 'id')->where('role_id', $estimatorRole)->where('is_active', true)],
            'project_manager_id' => ['required', 'distinct', Rule::exists('users', 'id')->where('role_id', $managerRole)->where('is_active', true)],
            'site_operator_id' => ['required', 'distinct', Rule::exists('users', 'id')->where('role_id', $operatorRole)->where('is_active', true)],
        ];

        foreach ($stages as $stage) {
            $rules["phases.$stage.planned_start_date"] = ['required', 'date'];
            $rules["phases.$stage.planned_completion_date"] = ['required', 'date', "after_or_equal:phases.$stage.planned_start_date"];
        }

        return $rules;
    }

    protected function afterProjectSetupValidation(?Project $project = null): array
    {
        return [function (Validator $validator) use ($project): void {
            if ($this->input('client_mode') === 'existing' && $this->filled('new_client.name')) {
                $validator->errors()->add('new_client.name', 'Choose either an existing client or a new client, not both.');
            }
            if ($this->input('client_mode') === 'new' && $this->filled('client_id')) {
                $validator->errors()->add('client_id', 'Choose either an existing client or a new client, not both.');
            }
            $assignedIds = array_filter([
                $this->input('estimator_id'),
                $this->input('project_manager_id'),
                $this->input('site_operator_id'),
            ]);
            if (count($assignedIds) !== count(array_unique($assignedIds))) {
                $validator->errors()->add('site_operator_id', 'Assign a different user to each project team role.');
            }

            if (! $this->boolean('contract_signed') && $this->file('contract_document')) {
                $validator->errors()->add('contract_document', 'A contract document can only be uploaded when the contract is marked signed.');
            }
            if ($project?->contractDocuments()->exists() && ! $this->boolean('contract_signed')) {
                $validator->errors()->add('contract_signed', 'A project with a stored signed contract cannot be marked unsigned.');
            }

            if ($project && $project->invoices()->exists()) {
                $submittedClientId = $this->input('client_mode') === 'existing' ? (int) $this->input('client_id') : 0;
                if ($submittedClientId !== (int) $project->client_id) {
                    $validator->errors()->add('client_id', 'The client cannot be changed after invoices have been created.');
                }
            }
        }];
    }
}