<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['project_code', 'name', 'status', 'location', 'created_by'];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
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
