<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\User;

/**
 * Estimate lifecycle: the assigned Estimator/QS prepares a draft and submits it; from then on the
 * estimate is Admin's. Admin may edit a submitted estimate in place, approve and issue it, return it
 * to the Estimator with notes, or open an audited revision of an issued estimate.
 */
class ProjectEstimatePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'estimator_qs'], true);
    }

    public function view(User $user, ProjectEstimate $estimate): bool
    {
        return $user->hasRole('admin') || $this->isAssignedEstimator($user, $estimate->project_id);
    }

    public function viewProject(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || $this->isAssignedEstimator($user, $project->id);
    }

    /** Opening a working estimate: Estimators only before first submission or after Admin returns it. */
    public function create(User $user, Project $project): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        if (! $this->isAssignedEstimator($user, $project->id)) {
            return false;
        }

        $draft = $project->estimates()->where('status', 'draft')->first();

        return $draft ? $draft->isEstimatorEditable() : $project->estimates()->doesntExist();
    }

    public function update(User $user, ProjectEstimate $estimate): bool
    {
        if ($estimate->invoices()->exists()) {
            return false;
        }
        if ($user->hasRole('admin')) {
            return in_array($estimate->status, ['draft', 'submitted'], true);
        }

        return $estimate->isEstimatorEditable() && $this->isAssignedEstimator($user, $estimate->project_id);
    }

    public function submit(User $user, ProjectEstimate $estimate): bool
    {
        return $user->hasRole('estimator_qs')
            && $estimate->isEstimatorEditable()
            && ! $estimate->invoices()->exists()
            && $this->isAssignedEstimator($user, $estimate->project_id);
    }

    /** Approve and issue: Admin only, from a submitted estimate or Admin's own draft. */
    public function finalize(User $user, ProjectEstimate $estimate): bool
    {
        return $user->hasRole('admin')
            && in_array($estimate->status, ['draft', 'submitted'], true)
            && ! $estimate->invoices()->exists();
    }

    public function returnToEstimator(User $user, ProjectEstimate $estimate): bool
    {
        return $user->hasRole('admin') && in_array($estimate->status, ['submitted', 'issued'], true);
    }

    /** Editing an issued estimate opens an Admin-held audited revision. */
    public function revise(User $user, ProjectEstimate $estimate): bool
    {
        return $user->hasRole('admin') && $estimate->status === 'issued';
    }

    public function delete(User $user, ProjectEstimate $estimate): bool
    {
        return false; // Estimate versions are retained; entries are the editable detail records.
    }

    private function isAssignedEstimator(User $user, int $projectId): bool
    {
        return $user->hasRole('estimator_qs') && $user->assignedProjects()->whereKey($projectId)->exists();
    }
}
