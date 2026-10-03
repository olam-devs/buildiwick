<?php

namespace App\Policies;

use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\User;

class InternalBudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'project_manager'], true);
    }

    public function view(User $user, InternalBudget $budget): bool
    {
        return match ($user->role?->name) {
            'admin' => true,
            'project_manager' => $user->assignedProjects()->whereKey($budget->project_id)->exists(),
            default => false,
        };
    }

    public function viewProject(User $user, Project $project): bool
    {
        return $user->hasRole('admin')
            || ($user->hasRole('project_manager') && $user->assignedProjects()->whereKey($project->id)->exists());
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('project_manager') && $user->assignedProjects()->whereKey($project->id)->exists());
    }

    public function approve(User $user, InternalBudget $budget): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, InternalBudget $budget): bool
    {
        return $budget->status === 'draft'
            && ($user->hasRole('admin') || ($user->hasRole('project_manager') && $user->assignedProjects()->whereKey($budget->project_id)->exists()));
    }

    public function finalize(User $user, InternalBudget $budget): bool
    {
        return $budget->status === 'draft'
            && ($user->hasRole('admin') || ($user->hasRole('project_manager') && $user->assignedProjects()->whereKey($budget->project_id)->exists()));
    }

    public function delete(User $user, InternalBudget $budget): bool
    {
        return $user->hasRole('admin') && $budget->status === 'draft' && $budget->entries()->doesntExist();
    }
}