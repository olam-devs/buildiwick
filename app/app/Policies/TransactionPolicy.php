<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    /**
     * Site Operator gets no viewAny at all — there is no index route for
     * this role, not just a denied one. That's the whole "write-only" rule
     * enforced at the authorization layer, not just hidden in the UI.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'project_manager', 'estimator_qs'], true);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return match ($user->role?->name) {
            'admin', 'accounts' => true,
            'estimator_qs' => $transaction->main_category === 'materials', // unit_cost history only
            'project_manager' => $user->assignedProjects()->whereKey($transaction->project_id)->exists(),
            default => false, // includes site_operator
        };
    }

    /** Called as $this->authorize('create', [Transaction::class, $project]). */
    public function create(User $user, Project $project): bool
    {
        return match ($user->role?->name) {
            'admin' => true,
            default => false, // accounts and estimator_qs never originate a cost line
        };
    }

    /** Project Manager reviewing an entry: sets data_status + approved_by, nothing else. */
    public function approve(User $user, Transaction $transaction): bool
    {
        return match ($user->role?->name) {
            'admin' => true,
            'project_manager' => $user->assignedProjects()->whereKey($transaction->project_id)->exists(),
            default => false,
        };
    }

    /** Accounts recording money moving: sets amount_paid_received + payment_status, nothing else. */
    public function recordPayment(User $user, Transaction $transaction): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts'], true);
    }

    /** Nobody edits a submitted entry's own facts after the fact — correct it with a new entry instead. */
    public function update(User $user, Transaction $transaction): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $user->hasRole('admin');
    }
}
