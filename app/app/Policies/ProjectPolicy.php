<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /** Everyone gets a list — it's filtered to what they're allowed to see, not blocked outright. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return match ($user->role?->name) {
            'admin', 'accounts', 'estimator_qs' => true,
            'project_manager', 'site_operator' => $user->assignedProjects()->whereKey($project->id)->exists(),
            default => false,
        };
    }

    /** Registering a new project (Office Admin's job in the process doc) — kept to Admin for now. */
    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasRole('admin');
    }
}
