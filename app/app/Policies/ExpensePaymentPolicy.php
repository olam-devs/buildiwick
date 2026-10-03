<?php

namespace App\Policies;

use App\Models\ExpensePayment;
use App\Models\User;

class ExpensePaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant', 'project_manager'], true);
    }

    public function view(User $user, ExpensePayment $payment): bool
    {
        return match ($user->role?->name) {
            'admin', 'accounts', 'accountant' => true,
            'project_manager' => $user->assignedProjects()->whereKey($payment->project_id)->exists(),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true);
    }

    public function update(User $user, ExpensePayment $payment): bool
    {
        return false;
    }

    public function delete(User $user, ExpensePayment $payment): bool
    {
        return false;
    }
}