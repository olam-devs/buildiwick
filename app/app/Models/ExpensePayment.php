<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePayment extends Model
{
    protected $fillable = ['payment_code', 'idempotency_key', 'expense_request_id', 'project_id', 'project_phase_id', 'amount', 'payment_date', 'payment_method', 'transaction_reference', 'variance_reason', 'invoice_receipt_no', 'evidence_link', 'evidence_original_name', 'recorded_by', 'notes', 'onboarding_import_id'];

    public function onboardingImport(): BelongsTo
    {
        return $this->belongsTo(OnboardingImport::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date'];
    }

    public function expenseRequest(): BelongsTo
    {
        return $this->belongsTo(ExpenseRequest::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
