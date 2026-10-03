<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientInvoice extends Model
{
    protected $fillable = ['invoice_code', 'reference', 'project_id', 'client_id', 'project_estimate_id', 'status', 'issued_at', 'due_at', 'created_by', 'issued_by', 'voided_by', 'voided_at', 'void_reason', 'notes'];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'due_at' => 'date', 'voided_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(ProjectEstimate::class, 'project_estimate_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClientInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ClientPayment::class);
    }

    protected function paidToDate(): Attribute
    {
        return Attribute::get(fn () => Money::sum($this->relationLoaded('payments')
            ? $this->payments->pluck('amount')
            : $this->payments()->pluck('amount')));
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::get(fn () => Money::sum($this->items->map(fn (ClientInvoiceItem $item) => $item->line_total)));
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function (): string {
            $paid = $this->paid_to_date;

            if (Money::compare($paid, '0') <= 0) {
                return 'unpaid';
            }

            return Money::compare($paid, $this->total_amount) >= 0 ? 'paid' : 'part_paid';
        });
    }

    protected function outstandingAmount(): Attribute
    {
        return Attribute::get(function (): string {
            $outstanding = Money::difference($this->total_amount, $this->paid_to_date);

            return Money::compare($outstanding, '0') < 0 ? '0.00' : $outstanding;
        });
    }

    protected function overdue(): Attribute
    {
        return Attribute::get(fn () => $this->status === 'issued'
            && $this->due_at?->isBefore(today())
            && Money::compare($this->outstanding_amount, '0') > 0);
    }
}