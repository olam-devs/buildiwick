<?php

namespace App\Policies;

use App\Models\EstimateEntry;
use App\Models\ProjectEstimate;
use App\Models\User;

class EstimateEntryPolicy
{
    public function view(User $user, EstimateEntry $entry): bool
    {
        return $user->hasRole('admin') || ($user->hasRole('estimator_qs')
            && $user->assignedProjects()->whereKey($entry->estimate->project_id)->exists());
    }

    public function create(User $user, ProjectEstimate $estimate): bool
    {
        return $estimate->status === 'draft'
            && ! $estimate->invoices()->exists()
            && ($user->hasRole('admin') || ($user->hasRole('estimator_qs')
                && $user->assignedProjects()->whereKey($estimate->project_id)->exists()));
    }

    public function update(User $user, EstimateEntry $entry): bool
    {
        $estimate = $entry->estimate;

        return $estimate->status === 'draft'
            && ! $estimate->invoices()->exists()
            && ($user->hasRole('admin') || ($user->hasRole('estimator_qs')
                && $user->assignedProjects()->whereKey($estimate->project_id)->exists()));
    }

    public function delete(User $user, EstimateEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}