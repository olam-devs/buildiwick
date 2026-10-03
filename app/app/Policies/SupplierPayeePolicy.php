<?php

namespace App\Policies;

use App\Models\SupplierPayee;
use App\Models\User;

class SupplierPayeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('project_manager');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SupplierPayee $supplierPayee): bool
    {
        return $this->viewAny($user);
    }
}