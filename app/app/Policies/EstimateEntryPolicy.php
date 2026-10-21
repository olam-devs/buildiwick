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

    /** Lines follow the editability of their estimate version. */
    public function create(User $user, ProjectEstimate $estimate): bool
    {
        return (new ProjectEstimatePolicy)->update($user, $estimate);
    }

    public function update(User $user, EstimateEntry $entry): bool
    {
        return (new ProjectEstimatePolicy)->update($user, $entry->estimate);
    }

    public function delete(User $user, EstimateEntry $entry): bool
    {
        return $this->update($user, $entry);
    }
}
