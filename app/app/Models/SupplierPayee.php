<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayee extends Model
{
    protected $fillable = ['name', 'type', 'contact_person', 'phone', 'email', 'address', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function expenseRequests(): HasMany
    {
        return $this->hasMany(ExpenseRequest::class);
    }

    public function paymentDestinations(): HasMany
    {
        return $this->hasMany(SupplierPaymentDestination::class);
    }

    public function requestGroups(): HasMany
    {
        return $this->hasMany(SupplierRequestGroup::class);
    }
}
