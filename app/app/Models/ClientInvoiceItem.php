<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInvoiceItem extends Model
{
    protected $fillable = ['client_invoice_id', 'project_phase_id', 'main_category', 'subcategory', 'item_description', 'quantity', 'unit', 'unit_price', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    protected function lineTotal(): Attribute
    {
        return Attribute::get(fn () => Money::product($this->unit_price, $this->quantity ?? '1'));
    }
}