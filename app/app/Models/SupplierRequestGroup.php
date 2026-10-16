<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierRequestGroup extends Model
{
    protected $fillable = [
        'group_code', 'project_id', 'request_batch_code', 'supplier_payee_id', 'payment_destination_id',
        'status', 'payment_method', 'notes', 'evidence_link', 'evidence_original_name', 'created_by',
        'approved_by', 'approved_at', 'decision_notes',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierPayee::class, 'supplier_payee_id');
    }

    public function paymentDestination(): BelongsTo
    {
        return $this->belongsTo(SupplierPaymentDestination::class, 'payment_destination_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseRequest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::get(fn (): string => Money::sum(
            ($this->relationLoaded('items') ? $this->items : $this->items()->get())
                ->map(fn (ExpenseRequest $item): string => $item->commercial_total ?? '0.00')
        ));
    }
}
