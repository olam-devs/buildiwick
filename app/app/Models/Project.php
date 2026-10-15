<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_code', 'name', 'status', 'location', 'created_by', 'client_id',
        'contract_reference', 'contract_signed', 'contract_signed_at',
        'planned_start_date', 'expected_completion_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'contract_signed' => 'boolean',
            'contract_signed_at' => 'date',
            'planned_start_date' => 'date',
            'expected_completion_date' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class);
    }

    public function activePhase(): HasOne
    {
        return $this->hasOne(ProjectPhase::class)->where('status', 'active')->orderBy('phase_order');
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(ProjectEstimate::class);
    }

    public function internalBudgets(): HasMany
    {
        return $this->hasMany(InternalBudget::class);
    }

    public function editLogs(): HasMany
    {
        return $this->hasMany(ProjectEditLog::class);
    }

    public function expenseRequests(): HasMany
    {
        return $this->hasMany(ExpenseRequest::class);
    }

    public function expensePayments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(ClientInvoice::class);
    }

    public function clientPayments(): HasMany
    {
        return $this->hasMany(ClientPayment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public function contractDocuments(): HasMany
    {
        return $this->documents()->where('document_type', 'contract')->latest('uploaded_at');
    }

    public function labourAgreements(): HasMany
    {
        return $this->hasMany(LabourAgreement::class);
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_assignments');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Category totals + cash paid/unpaid — the same numbers the old Consolidated tab held by hand. */
    public function categoryTotals(): array
    {
        $rows = $this->transactions()
            ->selectRaw('main_category, SUM(total_amount) as total, SUM(amount_paid_received) as paid')
            ->groupBy('main_category')
            ->get()
            ->keyBy('main_category');

        $categories = ['materials', 'labour_subcontracts', 'logistics_equipment', 'preliminaries_overheads'];
        $totals = [];
        foreach ($categories as $cat) {
            $totals[$cat] = (float) ($rows[$cat]->total ?? 0);
        }

        $totalExpenses = array_sum($totals);
        $cashPaidOut = (float) $rows->sum('paid');

        return [
            'categories' => $totals,
            'total_expenses' => $totalExpenses,
            'cash_paid_out' => $cashPaidOut,
            'unpaid_expenses' => $totalExpenses - $cashPaidOut,
        ];
    }
}
