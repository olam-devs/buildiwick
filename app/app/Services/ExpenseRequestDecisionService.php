<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestApproval;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExpenseRequestDecisionService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ProjectManagerBudgetService $budgets,
    ) {}

    public function impact(ExpenseRequest $request): array
    {
        $budget = $this->budgets->budgetFor($request->project, $request->project_phase_id, $request->main_category);
        $approved = Money::sum($request->project->expenseRequests()
            ->where('status', 'approved')
            ->where('id', '!=', $request->id)
            ->where('project_phase_id', $request->project_phase_id)
            ->where('main_category', $request->main_category)
            ->get()
            ->map(fn (ExpenseRequest $item) => $item->commercial_total ?? $item->approved_amount ?? $item->total_amount ?? '0.00'));
        $thisRequest = $request->commercial_total ?? '0.00';
        $projected = Money::sum([$approved, $thisRequest]);

        return [
            'budget' => $budget,
            'approved_commitments' => $approved,
            'this_request' => Money::decimal($thisRequest)->__toString(),
            'projected' => $projected,
            'remaining' => Money::difference($budget, $projected),
            'utilization' => Money::compare($budget, '0') === 0 ? null : Money::percentage($projected, $budget),
            'over_budget' => Money::compare($projected, $budget) > 0,
            'over_budget_amount' => Money::compare($projected, $budget) > 0 ? Money::difference($projected, $budget) : '0.00',
        ];
    }

    public function decide(ExpenseRequest $request, string $decision, string $notes, User $actor): ExpenseRequestApproval
    {
        if (trim($notes) === '') {
            throw ValidationException::withMessages(['notes' => $decision === 'reject' ? 'A rejection reason is required.' : 'Decision notes are required.']);
        }

        return $this->database->transaction(function () use ($request, $decision, $notes, $actor): ExpenseRequestApproval {
            $locked = ExpenseRequest::query()->with(['supplierPayee', 'commercialPaymentDestination'])->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($actor)->authorize($decision === 'approve' ? 'approve' : 'reject', $locked);
            if ($locked->status !== 'pending_approval' || $locked->approvals()->exists() || $locked->payments()->exists()) {
                throw ValidationException::withMessages(['decision' => 'This request has already been decided or has payment history.']);
            }

            if ($decision === 'reject') {
                return $this->recordDecision($locked, 'rejected', $notes, $actor);
            }

            $this->assertCommerciallyComplete($locked);
            $impact = $this->impact($locked);
            if ($impact['over_budget'] && trim($notes) === '') {
                throw ValidationException::withMessages(['notes' => 'An over-budget approval requires a variance justification.']);
            }

            return $this->recordDecision($locked, 'approved', $notes, $actor, $impact);
        });
    }

    private function assertCommerciallyComplete(ExpenseRequest $request): void
    {
        if (! $request->supplierPayee || ! $request->supplierPayee->is_active
            || $request->commercial_unit_rate === null || $request->commercial_total === null
            || ! $request->commercial_payment_method || ! $request->commercial_evidence_link) {
            throw ValidationException::withMessages(['decision' => 'Supplier, rate, commercial total, payment method, and commercial evidence are required before approval.']);
        }
        $methodNeedsDestination = in_array($request->commercial_payment_method, ['bank_transfer', 'mobile_money', 'cheque'], true);
        if ($methodNeedsDestination && ! $request->commercialPaymentDestination) {
            throw ValidationException::withMessages(['decision' => 'Select a valid payment destination for this payment method.']);
        }
        if (Money::compare($request->commercial_total, '0') <= 0) {
            throw ValidationException::withMessages(['decision' => 'Commercial total must be greater than zero.']);
        }
        $expectedTotal = Money::product($request->commercial_unit_rate, $request->quantity ?? '1');
        if (Money::compare($expectedTotal, $request->commercial_total) !== 0) {
            throw ValidationException::withMessages(['decision' => 'Commercial total does not match quantity multiplied by the unit rate. Re-save the commercial details.']);
        }
    }

    private function recordDecision(ExpenseRequest $request, string $status, string $notes, User $actor, ?array $impact = null): ExpenseRequestApproval
    {
        $supplier = $request->supplierPayee;
        $destination = $request->commercialPaymentDestination;
        $approval = $request->approvals()->create([
            'decision' => $status,
            'approved_amount' => $status === 'approved' ? $request->commercial_total : null,
            'approved_unit_rate' => $status === 'approved' ? $request->commercial_unit_rate : null,
            'approved_total' => $status === 'approved' ? $request->commercial_total : null,
            'supplier_payee_id' => $supplier?->id,
            'supplier_name_snapshot' => $supplier?->name,
            'supplier_type_snapshot' => $supplier?->type,
            'supplier_contact_snapshot' => $supplier?->contact_person,
            'supplier_phone_snapshot' => $supplier?->phone,
            'supplier_email_snapshot' => $supplier?->email,
            'payment_method_snapshot' => $request->commercial_payment_method,
            'payment_destination_snapshot' => $destination ? $this->destinationSnapshot($destination) : $request->commercial_payment_destination,
            'commercial_evidence_snapshot' => $request->commercial_evidence_link,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'notes' => $notes,
        ]);
        $request->update(['status' => $status, 'approved_amount' => $status === 'approved' ? $request->commercial_total : null]);

        return $approval;
    }

    private function destinationSnapshot($destination): string
    {
        return implode(' | ', array_filter([
            $destination->payment_method,
            $destination->account_wallet_name,
            $destination->account_wallet_number,
            $destination->bank_provider_name,
            $destination->branch,
        ]));
    }
}