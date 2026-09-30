<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;

class ProjectDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role !== null;
    }

    public function view(User $user, ProjectDocument $document): bool
    {
        return $user->hasRole('admin') || in_array($user->role?->name, ['accounts', 'estimator_qs'], true)
            || $user->assignedProjects()->whereKey($document->project_id)->exists();
    }

    public function viewContract(User $user, ProjectDocument $document): bool
    {
        return $document->document_type === 'contract' && $user->hasRole('admin');
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || in_array($user->role?->name, ['accounts', 'estimator_qs'], true)
            || ($user->assignedProjects()->whereKey($project->id)->exists());
    }

    public function delete(User $user, ProjectDocument $document): bool
    {
        return $user->hasRole('admin');
    }
}