<?php

namespace App\Policies;

use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\User;

class ExpenseRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'director', 'accounts', 'accountant', 'project_manager', 'site_operator'], true);
    }

    public function view(User $user, ExpenseRequest $request): bool
    {
        return match ($user->role?->name) {
            'admin', 'director', 'accounts', 'accountant' => true,
            'project_manager', 'site_operator' => $user->assignedProjects()->whereKey($request->project_id)->exists(),
            default => false,
        };
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || (($user->hasRole('site_operator') || $user->hasRole('project_manager')) && $user->assignedProjects()->whereKey($project->id)->exists());
    }

    public function update(User $user, ExpenseRequest $request): bool
    {
        return $user->hasRole('site_operator')
            && (int) $request->requested_by === (int) $user->id
            && $request->status === 'pending_approval'
            && $request->approvals()->doesntExist()
            && $request->payments()->doesntExist()
            && $user->assignedProjects()->whereKey($request->project_id)->exists();
    }

    public function cancel(User $user, ExpenseRequest $request): bool
    {
        return $this->update($user, $request);
    }

    public function approve(User $user, ExpenseRequest $request): bool
    {
        return $user->hasRole('project_manager') && $user->assignedProjects()->whereKey($request->project_id)->exists()
            && $request->status === 'pending_approval';
    }

    public function reject(User $user, ExpenseRequest $request): bool
    {
        return $user->hasRole('project_manager') && $user->assignedProjects()->whereKey($request->project_id)->exists()
            && $request->status === 'pending_approval';
    }

    public function recordPayment(User $user, ExpenseRequest $request): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts'], true) && $request->status === 'approved';
    }

    public function prepareCommercial(User $user, ExpenseRequest $request): bool
    {
        return ($user->hasRole('admin') || ($user->hasRole('project_manager') && $user->assignedProjects()->whereKey($request->project_id)->exists()))
            && $request->status === 'pending_approval';
    }

    public function viewProcurementWorkflow(User $user, ExpenseRequest $request): bool
    {
        return $user->hasRole('admin')
            || ($user->hasRole('project_manager') && $user->assignedProjects()->whereKey($request->project_id)->exists());
    }
}
