<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectPhase extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'stage_code', 'phase_name', 'phase_order', 'planned_start_date', 'planned_completion_date',
        'actual_start_date', 'actual_completion_date', 'physical_progress_percentage',
        'status', 'started_at', 'completed_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'phase_order' => 'integer',
            'planned_start_date' => 'date',
            'planned_completion_date' => 'date',
            'actual_start_date' => 'date',
            'actual_completion_date' => 'date',
            'physical_progress_percentage' => 'decimal:2',
            'started_at' => 'date',
            'completed_at' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function estimateEntries(): HasMany
    {
        return $this->hasMany(EstimateEntry::class);
    }

    public function internalBudgetEntries(): HasMany
    {
        return $this->hasMany(InternalBudgetEntry::class);
    }

    public function expenseRequests(): HasMany
    {
        return $this->hasMany(ExpenseRequest::class);
    }

    public function expensePayments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function clientInvoiceItems(): HasMany
    {
        return $this->hasMany(ClientInvoiceItem::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    protected function stageLabel(): Attribute
    {
        return Attribute::get(fn () => $this->phase_name
            ?: Lookup::where('type', 'construction_stage')->where('code', $this->stage_code)->value('label')
            ?: str($this->stage_code)->replace(['_', '-'], ' ')->title()->toString());
    }
}
