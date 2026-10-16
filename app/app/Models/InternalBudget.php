<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalBudget extends Model
{
    use HasFactory;

    protected $fillable = ['budget_code', 'project_id', 'project_phase_id', 'main_category', 'version', 'status', 'created_by', 'approved_by', 'approved_at', 'finalized_by', 'finalized_at', 'notes'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'approved_at' => 'datetime', 'finalized_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(InternalBudgetEntry::class);
    }

    public function scopeWorking($query)
    {
        return $query->where('status', 'draft');
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::get(fn () => Money::sum($this->entries->map(fn (InternalBudgetEntry $entry) => $entry->line_total)));
    }
}
