<?php

namespace App\Policies;

use App\Models\LabourAgreement;
use App\Models\Project;
use App\Models\User;

class LabourAgreementPolicy
{
    /** Not Estimator/QS: agreed values are cash, not historical unit pricing. Not Site Operator: write-only elsewhere. */
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'project_manager'], true);
    }

    public function view(User $user, LabourAgreement $labourAgreement): bool
    {
        return match ($user->role?->name) {
            'admin', 'accounts' => true,
            'project_manager' => $user->assignedProjects()->whereKey($labourAgreement->project_id)->exists(),
            default => false,
        };
    }

    /** Recording a new agreement — Construction team's job in the process doc. */
    public function create(User $user, Project $project): bool
    {
        return match ($user->role?->name) {
            'admin' => true,
            'project_manager' => $user->assignedProjects()->whereKey($project->id)->exists(),
            default => false,
        };
    }

    /** Changing status (Draft/Active/Completed/Cancelled) — Construction or Accounts, not Site. */
    public function update(User $user, LabourAgreement $labourAgreement): bool
    {
        return match ($user->role?->name) {
            'admin', 'accounts' => true,
            'project_manager' => $user->assignedProjects()->whereKey($labourAgreement->project_id)->exists(),
            default => false,
        };
    }

    public function delete(User $user, LabourAgreement $labourAgreement): bool
    {
        return $user->hasRole('admin');
    }
}
