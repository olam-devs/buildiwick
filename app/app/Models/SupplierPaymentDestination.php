<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPaymentDestination extends Model
{
    protected $fillable = [
        'supplier_payee_id', 'payment_method', 'account_wallet_name', 'account_wallet_number',
        'bank_provider_name', 'branch', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function supplierPayee(): BelongsTo
    {
        return $this->belongsTo(SupplierPayee::class);
    }
}