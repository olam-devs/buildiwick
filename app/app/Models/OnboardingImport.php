<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One upload of historical records for an ongoing project; every record it created points back here. */
class OnboardingImport extends Model
{
    protected $fillable = [
        'import_code', 'project_id', 'imported_by', 'original_filename', 'stored_path',
        'expense_count', 'invoice_count', 'client_payment_count',
        'expense_total', 'expense_paid_total', 'invoice_total', 'client_payment_total',
        'notes', 'undone_at', 'undone_by', 'undo_reason',
    ];

    protected function casts(): array
    {
        return [
            'expense_total' => 'decimal:2', 'expense_paid_total' => 'decimal:2',
            'invoice_total' => 'decimal:2', 'client_payment_total' => 'decimal:2',
            'undone_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    public function expenseRequests(): HasMany
    {
        return $this->hasMany(ExpenseRequest::class);
    }

    public function expensePayments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function clientInvoices(): HasMany
    {
        return $this->hasMany(ClientInvoice::class);
    }

    public function clientPayments(): HasMany
    {
        return $this->hasMany(ClientPayment::class);
    }
}
