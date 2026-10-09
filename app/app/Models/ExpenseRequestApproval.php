<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseRequestApproval extends Model
{
    protected $fillable = [
        'expense_request_id', 'approval_stage', 'decision', 'approved_amount', 'approved_unit_rate', 'approved_total',
        'supplier_payee_id', 'supplier_name_snapshot', 'supplier_type_snapshot', 'supplier_contact_snapshot',
        'supplier_phone_snapshot', 'supplier_email_snapshot', 'payment_method_snapshot',
        'payment_destination_snapshot', 'commercial_evidence_snapshot', 'decided_by', 'decided_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_amount' => 'decimal:2', 'approved_unit_rate' => 'decimal:2', 'approved_total' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public function expenseRequest(): BelongsTo
    {
        return $this->belongsTo(ExpenseRequest::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
