<?php

namespace App\Policies;

use App\Models\ClientPayment;
use App\Models\User;

class ClientPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true);
    }

    public function view(User $user, ClientPayment $payment): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true);
    }
}