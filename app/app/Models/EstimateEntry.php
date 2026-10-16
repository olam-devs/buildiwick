<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateEntry extends Model
{
    protected $fillable = ['project_estimate_id', 'project_phase_id', 'work_area', 'work_section', 'item_reference', 'main_category', 'subcategory', 'item_description', 'supplier_fundi_client', 'quantity', 'unit', 'base_unit_cost', 'markup_percent', 'client_unit_rate_override', 'line_total_override', 'entered_by', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'base_unit_cost' => 'decimal:2', 'markup_percent' => 'decimal:4', 'client_unit_rate_override' => 'decimal:2', 'line_total_override' => 'decimal:2'];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(ProjectEstimate::class, 'project_estimate_id');
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
        return Attribute::get(fn () => $this->line_total_override !== null
            ? Money::decimal($this->line_total_override)->__toString()
            : Money::product($this->client_unit_cost, $this->quantity ?? '1'));
    }

    protected function clientUnitCost(): Attribute
    {
        return Attribute::get(fn () => $this->client_unit_rate_override !== null
            ? Money::decimal($this->client_unit_rate_override)->__toString()
            : Money::estimateClientUnitCost($this->base_unit_cost, $this->markup_percent));
    }

    protected function baseTotal(): Attribute
    {
        return Attribute::get(fn () => Money::product($this->base_unit_cost, $this->quantity ?? '1'));
    }

    protected function markupAmount(): Attribute
    {
        return Attribute::get(fn () => Money::difference($this->line_total, $this->base_total));
    }
}
