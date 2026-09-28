<?php

namespace App\Policies;

use App\Models\Lookup;
use App\Models\User;

class LookupPolicy
{
    /** Every role needs the dropdown values to fill any form — read access is universal. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lookup $lookup): bool
    {
        return true;
    }

    /** Editing the managed lists themselves (Section 8/9 of the process doc) is Admin-only. */
    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Lookup $lookup): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Lookup $lookup): bool
    {
        return $user->hasRole('admin');
    }
}
