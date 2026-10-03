<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalBudgetEntry extends Model
{
    protected $fillable = ['internal_budget_id', 'project_phase_id', 'main_category', 'subcategory', 'item_description', 'supplier_fundi_client', 'quantity', 'unit', 'unit_cost', 'entered_by', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_cost' => 'decimal:2'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(InternalBudget::class, 'internal_budget_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    protected function lineTotal(): Attribute
    {
        return Attribute::get(fn () => Money::product($this->unit_cost, $this->quantity ?? '1'));
    }
}