<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\User;

class ProjectEstimatePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'estimator_qs'], true);
    }

    public function view(User $user, ProjectEstimate $estimate): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('estimator_qs')
            && $user->assignedProjects()->whereKey($estimate->project_id)->exists());
    }

    public function viewProject(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('estimator_qs')
            && $user->assignedProjects()->whereKey($project->id)->exists());
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('estimator_qs')
            && $user->assignedProjects()->whereKey($project->id)->exists());
    }

    public function update(User $user, ProjectEstimate $estimate): bool
    {
        return $estimate->status === 'draft'
            && ! $estimate->invoices()->exists()
            && ($user->hasRole('admin') || ($user->hasRole('estimator_qs')
                && $user->assignedProjects()->whereKey($estimate->project_id)->exists()));
    }

    public function delete(User $user, ProjectEstimate $estimate): bool
    {
        return false; // Estimate versions are retained; entries are the editable detail records.
    }

    public function finalize(User $user, ProjectEstimate $estimate): bool
    {
        return $estimate->status === 'draft'
            && ! $estimate->invoices()->exists()
            && ($user->hasRole('admin') || ($user->hasRole('estimator_qs')
                && $user->assignedProjects()->whereKey($estimate->project_id)->exists()));
    }
}