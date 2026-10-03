<?php

namespace App\Policies;

use App\Models\ClientInvoice;
use App\Models\Project;
use App\Models\User;

class ClientInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true);
    }

    public function view(User $user, ClientInvoice $invoice): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true);
    }

    public function create(User $user, Project $project): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true);
    }

    public function update(User $user, ClientInvoice $invoice): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true) && $invoice->status === 'draft';
    }

    public function void(User $user, ClientInvoice $invoice): bool
    {
        return in_array($user->role?->name, ['admin', 'accounts', 'accountant'], true)
            && $invoice->status === 'issued'
            && $invoice->payments()->doesntExist();
    }
}