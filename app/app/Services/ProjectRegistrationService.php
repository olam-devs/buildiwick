<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProjectRegistrationService
{
    private const PHASES = [
        'preliminaries', 'substructure', 'superstructure', 'walling',
        'roofing', 'finishing', 'external_works',
    ];

    public function create(array $data, User $actor): Project
    {
        Gate::forUser($actor)->authorize('create', Project::class);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($data, $actor, &$storedPath): Project {
                $client = $this->resolveClient($data);
                $project = Project::create($this->projectAttributes($data, $client, $actor));

                $this->createPhases($project, $data, $actor);
                $this->syncTeam($project, $data);

                if (($data['contract_document'] ?? null) instanceof UploadedFile) {
                    $storedPath = $this->storeContract($project, $data['contract_document'], $actor, $data);
                }

                return $project->fresh(['client', 'phases', 'assignedUsers.role', 'documents']);
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            if ($exception instanceof QueryException && str_contains($exception->getMessage(), 'projects.project_code')) {
                throw ValidationException::withMessages(['project_code' => 'This project code is already registered.']);
            }

            throw $exception;
        }
    }

    public function update(Project $project, array $data, User $actor): Project
    {
        Gate::forUser($actor)->authorize('update', $project);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($project, $data, $actor, &$storedPath): Project {
                $project->refresh();
                $client = $this->resolveClient($data, $project);
                $project->update($this->projectAttributes($data, $client, $actor, updating: true));

                $this->updatePhaseSchedule($project, $data);
                $this->syncTeam($project, $data);

                if (($data['contract_document'] ?? null) instanceof UploadedFile) {
                    $storedPath = $this->storeContract($project, $data['contract_document'], $actor, $data);
                }

                return $project->fresh(['client', 'phases', 'assignedUsers.role', 'documents']);
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            if ($exception instanceof QueryException && str_contains($exception->getMessage(), 'projects.project_code')) {
                throw ValidationException::withMessages(['project_code' => 'This project code is already registered.']);
            }

            throw $exception;
        }
    }

    private function resolveClient(array $data, ?Project $project = null): Client
    {
        if (($data['client_mode'] ?? 'existing') === 'new') {
            if ($project?->invoices()->exists()) {
                throw ValidationException::withMessages(['client_mode' => 'The project client cannot change after invoices exist.']);
            }

            return Client::create([
                'name' => $data['new_client']['name'],
                'phone' => $data['new_client']['phone'] ?? null,
                'email' => $data['new_client']['email'] ?? null,
                'address' => $data['new_client']['address'] ?? null,
                'notes' => $data['new_client']['notes'] ?? null,
                'is_active' => true,
            ]);
        }

        $client = Client::where('is_active', true)->find($data['client_id'] ?? null);
        if (! $client) {
            throw ValidationException::withMessages(['client_id' => 'Select an active client.']);
        }
        if ($project?->invoices()->exists() && (int) $project->client_id !== (int) $client->id) {
            throw ValidationException::withMessages(['client_id' => 'The project client cannot change after invoices exist.']);
        }

        return $client;
    }

    private function projectAttributes(array $data, Client $client, User $actor, bool $updating = false): array
    {
        $attributes = [
            'client_id' => $client->id,
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'status' => $data['status'],
            'contract_reference' => $data['contract_reference'] ?? null,
            'contract_signed' => (bool) $data['contract_signed'],
            'contract_signed_at' => $data['contract_signed_at'] ?? null,
            'planned_start_date' => $data['planned_start_date'],
            'expected_completion_date' => $data['expected_completion_date'],
            'notes' => $data['notes'] ?? null,
        ];

        if (! $updating) {
            $attributes['project_code'] = $data['project_code'];
            $attributes['created_by'] = $actor->id;
        }

        return $attributes;
    }

    private function createPhases(Project $project, array $data, User $actor): void
    {
        foreach (self::PHASES as $index => $stage) {
            if (! Lookup::where('type', 'construction_stage')->where('code', $stage)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['phases' => "The construction-stage lookup {$stage} is unavailable."]);
            }
            $schedule = $data['phases'][$stage];
            $project->phases()->create([
                'stage_code' => $stage,
                'phase_name' => Lookup::where('type', 'construction_stage')->where('code', $stage)->value('label'),
                'phase_order' => $index + 1,
                'planned_start_date' => $schedule['planned_start_date'],
                'planned_completion_date' => $schedule['planned_completion_date'],
                'status' => $index === 0 ? 'active' : 'not_started',
                'created_by' => $actor->id,
            ]);
        }
    }

    private function updatePhaseSchedule(Project $project, array $data): void
    {
        foreach (self::PHASES as $stage) {
            $project->phases()->where('stage_code', $stage)->update([
                'planned_start_date' => $data['phases'][$stage]['planned_start_date'],
                'planned_completion_date' => $data['phases'][$stage]['planned_completion_date'],
            ]);
        }
    }

    private function syncTeam(Project $project, array $data): void
    {
        $expectedRoles = [
            (int) $data['estimator_id'] => 'estimator_qs',
            (int) $data['project_manager_id'] => 'project_manager',
            (int) $data['site_operator_id'] => 'site_operator',
        ];
        if (count($expectedRoles) !== 3) {
            throw ValidationException::withMessages(['site_operator_id' => 'Each project team role must have a distinct user.']);
        }

        $roles = Role::whereIn('name', array_values($expectedRoles))->pluck('id', 'name');
        $selectedUsers = User::query()->whereIn('id', array_keys($expectedRoles))->where('is_active', true)->get()->keyBy('id');
        foreach ($expectedRoles as $userId => $roleName) {
            if (! isset($selectedUsers[$userId]) || (int) $selectedUsers[$userId]->role_id !== (int) $roles[$roleName]) {
                $field = match ($roleName) {
                    'estimator_qs' => 'estimator_id',
                    'project_manager' => 'project_manager_id',
                    default => 'site_operator_id',
                };
                throw ValidationException::withMessages([$field => "The selected user must have the {$roleName} role and be active."]);
            }
        }

        $managedRoleIds = \App\Models\Role::whereIn('name', ['estimator_qs', 'project_manager', 'site_operator'])->pluck('id');
        $managedAssignedIds = $project->assignedUsers()->whereIn('role_id', $managedRoleIds)->pluck('users.id')->all();
        $project->assignedUsers()->detach($managedAssignedIds);
        $project->assignedUsers()->syncWithoutDetaching(array_keys($expectedRoles));
    }

    private function storeContract(Project $project, UploadedFile $file, User $actor, array $data): string
    {
        $path = $file->store('project-contracts/'.$project->id, 'local');

        try {
            ProjectDocument::create([
                'project_id' => $project->id,
                'document_type' => 'contract',
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $actor->id,
                'uploaded_at' => now(),
                'notes' => $data['contract_reference'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return $path;
    }
}
