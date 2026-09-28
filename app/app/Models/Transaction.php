<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'client_uuid', 'transaction_code', 'project_id', 'transaction_type',
        'main_category', 'subcategory', 'item_description', 'supplier_fundi_client',
        'invoice_receipt_no', 'quantity', 'unit', 'unit_cost', 'total_amount',
        'amount_paid_received', 'payment_status', 'payment_method', 'agreement_id',
        'work_area_stage', 'entered_by', 'approved_by', 'approved_at',
        'evidence_link', 'notes', 'data_status', 'synced_at', 'transaction_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid_received' => 'decimal:2',
            'approved_at' => 'datetime',
            'synced_at' => 'datetime',
            'transaction_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(LabourAgreement::class, 'agreement_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Computed, never stored — matches the "status is derived, not trusted" rule from the schema notes. */
    protected function balance(): Attribute
    {
        return Attribute::get(fn () => (float) $this->total_amount - (float) $this->amount_paid_received);
    }
}
