<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExpenseRequest extends Model
{
    public const TYPES = [
        'item' => 'Item / Materials',
        'service' => 'Service',
        'salary_wage' => 'Salary / Wage',
        'cash_amount' => 'Direct Amount / Cash Request',
    ];

    public const CATEGORY_GUIDANCE = [
        'materials' => 'Physical materials incorporated into the works, such as cement, steel, blocks, timber, plumbing, electrical and finishes.',
        'labour_subcontracts' => 'Salaries, wages, casual or fundi labour, professional services and subcontracted scopes. Link an agreement where one exists.',
        'logistics_equipment' => 'Transport, delivery, haulage, tools, fuel, equipment hire and plant or machinery needed to execute the work.',
        'preliminaries_overheads' => 'Project-support costs not tied to one building element: site office, temporary utilities, PPE, cleaning, security, permits, communications and insurance.',
    ];

    protected $fillable = [
        'request_code', 'request_batch_code', 'request_line_number', 'supplier_request_group_id', 'procurement_stage',
        'project_id', 'project_phase_id', 'work_area', 'construction_category', 'work_package_code', 'work_package_name',
        'labour_agreement_id', 'internal_budget_entry_id', 'request_type', 'main_category', 'subcategory',
        'item_description', 'is_new_item', 'supplier_fundi_client', 'supplier_payee_id', 'invoice_receipt_no', 'quantity', 'unit',
        'unit_cost', 'total_amount', 'requested_amount', 'commercial_unit_rate', 'commercial_total', 'commercial_payment_method',
        'commercial_payment_destination', 'commercial_payment_destination_id', 'commercial_notes', 'commercial_evidence_link', 'commercial_prepared_by',
        'commercial_prepared_at', 'approved_amount', 'requested_at', 'submitted_at', 'verified_by', 'verified_at',
        'pm_edited', 'pm_edit_summary',
        'status', 'cancelled_at', 'cancelled_by', 'requested_by', 'evidence_link', 'evidence_original_name', 'notes', 'onboarding_import_id'];

    public function onboardingImport(): BelongsTo
    {
        return $this->belongsTo(OnboardingImport::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'unit_cost' => 'decimal:2', 'total_amount' => 'decimal:2', 'requested_amount' => 'decimal:2',
            'commercial_unit_rate' => 'decimal:2', 'commercial_total' => 'decimal:2', 'approved_amount' => 'decimal:2',
            'requested_at' => 'date', 'submitted_at' => 'datetime', 'commercial_prepared_at' => 'datetime',
            'cancelled_at' => 'datetime', 'verified_at' => 'datetime', 'is_new_item' => 'boolean', 'pm_edited' => 'boolean',
            'request_line_number' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function labourAgreement(): BelongsTo
    {
        return $this->belongsTo(LabourAgreement::class);
    }

    public function internalBudgetEntry(): BelongsTo
    {
        return $this->belongsTo(InternalBudgetEntry::class);
    }

    public function supplierPayee(): BelongsTo
    {
        return $this->belongsTo(SupplierPayee::class);
    }

    public function supplierRequestGroup(): BelongsTo
    {
        return $this->belongsTo(SupplierRequestGroup::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function commercialPaymentDestination(): BelongsTo
    {
        return $this->belongsTo(SupplierPaymentDestination::class, 'commercial_payment_destination_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ExpenseRequestApproval::class);
    }

    public function latestApproval(): HasOne
    {
        return $this->hasOne(ExpenseRequestApproval::class)->latestOfMany();
    }

    public function latestPmApproval(): HasOne
    {
        return $this->hasOne(ExpenseRequestApproval::class)->where('approval_stage', 'project_manager')->latestOfMany();
    }

    public function latestDirectorApproval(): HasOne
    {
        return $this->hasOne(ExpenseRequestApproval::class)->where('approval_stage', 'director')->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    protected function paidToDate(): Attribute
    {
        return Attribute::get(fn () => Money::sum($this->relationLoaded('payments')
            ? $this->payments->pluck('amount')
            : $this->payments()->pluck('amount')));
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function (): string {
            $paid = $this->paid_to_date;

            // Imported tracker adjustment lines are negative; they are settled once the matching negative amount is recorded.
            if (Money::compare($this->approved_payable_amount, '0') < 0) {
                return Money::compare($paid, $this->approved_payable_amount) === 0 ? 'paid' : 'unpaid';
            }

            if (Money::compare($paid, '0') <= 0) {
                return 'unpaid';
            }

            return Money::compare($paid, $this->approved_payable_amount) >= 0 ? 'paid' : 'part_paid';
        });
    }

    protected function approvedPayableAmount(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->status !== 'approved') {
                return '0.00';
            }

            return Money::decimal($this->approved_amount ?? $this->commercial_total ?? $this->total_amount ?? '0.00')->__toString();
        });
    }

    protected function approvedOutstandingAmount(): Attribute
    {
        return Attribute::get(function (): string {
            $outstanding = Money::difference($this->approved_payable_amount, $this->paid_to_date);

            return Money::compare($outstanding, '0') < 0 ? '0.00' : $outstanding;
        });
    }

    protected function outstandingAmount(): Attribute
    {
        return Attribute::get(function (): string {
            $outstanding = Money::difference($this->approved_payable_amount, $this->paid_to_date);

            return Money::compare($outstanding, '0') < 0 ? '0.00' : $outstanding;
        });
    }
}
