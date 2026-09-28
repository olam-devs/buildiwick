<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class LabourAgreement extends Model
{
    protected $fillable = [
        'agreement_code', 'project_id', 'fundi_subcontractor', 'scope_of_work',
        'labour_type', 'agreement_date', 'agreed_value', 'start_date',
        'expected_completion', 'contact', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'agreement_date' => 'date',
            'start_date' => 'date',
            'expected_completion' => 'date',
            'agreed_value' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Instalments are just transactions against this agreement — never a separate payments table. */
    public function payments(): HasMany
    {
        return $this->hasMany(Transaction::class, 'agreement_id');
    }

    public function paidToDate(): float
    {
        return (float) $this->payments()->sum('amount_paid_received');
    }

    public function outstanding(): float
    {
        return (float) $this->agreed_value - $this->paidToDate();
    }
}
