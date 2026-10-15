<?php

namespace App\Policies;

use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\User;

class InternalBudgetEntryPolicy
{
    public function create(User $user, InternalBudget $budget): bool
    {
        return $budget->status === 'draft'
            && ($user->hasRole('admin') || ($user->hasRole('project_manager')
                && $user->assignedProjects()->whereKey($budget->project_id)->exists()));
    }

    public function update(User $user, InternalBudgetEntry $entry): bool
    {
        return $entry->budget->status === 'draft'
            && ($user->hasRole('admin') || ($user->hasRole('project_manager')
                && $user->assignedProjects()->whereKey($entry->budget->project_id)->exists()));
    }

    public function delete(User $user, InternalBudgetEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
