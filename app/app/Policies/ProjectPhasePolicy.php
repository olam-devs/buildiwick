<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;

class ProjectPhasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== null;
    }

    public function view(User $user, ProjectPhase $phase): bool
    {
        return $user->hasRole('admin') || in_array($user->role?->name, ['accounts', 'estimator_qs'], true)
            || $user->assignedProjects()->whereKey($phase->project_id)->exists();
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('project_manager') && $user->assignedProjects()->whereKey($project->id)->exists());
    }

    public function update(User $user, ProjectPhase $phase): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('project_manager') && $user->assignedProjects()->whereKey($phase->project_id)->exists());
    }

    public function delete(User $user, ProjectPhase $phase): bool
    {
        return false; // Standard phases are project structure and are never destructively removed.
    }
}