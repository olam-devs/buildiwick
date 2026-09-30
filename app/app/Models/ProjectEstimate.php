<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectEstimate extends Model
{
    use HasFactory;

    protected $fillable = ['estimate_code', 'project_id', 'client_id', 'version', 'status', 'prepared_at', 'issued_at', 'valid_until', 'prepared_by', 'issued_by', 'notes'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'prepared_at' => 'date', 'issued_at' => 'datetime', 'valid_until' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(EstimateEntry::class);
    }

    public function scopeWorking($query)
    {
        return $query->where('status', 'draft');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(ClientInvoice::class);
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::get(fn () => Money::sum($this->entries->map(fn (EstimateEntry $entry) => $entry->line_total)));
    }
}