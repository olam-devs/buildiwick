<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInvoiceItem extends Model
{
    protected $fillable = ['client_invoice_id', 'project_phase_id', 'estimate_entry_id', 'work_area', 'work_section', 'item_reference', 'main_category', 'subcategory', 'item_description', 'quantity', 'unit', 'unit_price', 'contract_amount', 'claim_amount', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'contract_amount' => 'decimal:2', 'claim_amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function estimateEntry(): BelongsTo
    {
        return $this->belongsTo(EstimateEntry::class);
    }

    protected function lineTotal(): Attribute
    {
        return Attribute::get(fn () => $this->claim_amount !== null
            ? Money::decimal($this->claim_amount)->__toString()
            : Money::product($this->unit_price, $this->quantity ?? '1'));
    }
}
