<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExpenseRequestClassificationService
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function classify(ExpenseRequest $expenseRequest, array $data, User $actor): ExpenseRequest
    {
        return $this->database->transaction(function () use ($expenseRequest, $data, $actor): ExpenseRequest {
            $request = ExpenseRequest::query()->lockForUpdate()->findOrFail($expenseRequest->id);
            Gate::forUser($actor)->authorize('prepareCommercial', $request);
            if ($request->status !== 'pending_approval' || $request->approvals()->exists() || $request->payments()->exists()) {
                throw ValidationException::withMessages(['classification' => 'Budget classification is locked after a request is decided.']);
            }

            $phase = ProjectPhase::query()->whereKey($data['project_phase_id'])
                ->where('project_id', $request->project_id)->first();
            if (! $phase) {
                throw ValidationException::withMessages(['project_phase_id' => 'Select a construction phase belonging to this project.']);
            }

            $attributes = [
                'project_phase_id' => $phase->id,
                'main_category' => $data['main_category'],
                'subcategory' => $data['subcategory'] ?? null,
                'internal_budget_entry_id' => null,
            ];

            if (! empty($data['internal_budget_entry_id'])) {
                $currentBudget = InternalBudget::query()->where('project_id', $request->project_id)
                    ->where('status', 'approved')
                    ->orderByDesc('version')->first();
                $entry = $currentBudget?->entries()->whereKey($data['internal_budget_entry_id'])->first();
                if (! $entry instanceof InternalBudgetEntry) {
                    throw ValidationException::withMessages(['internal_budget_entry_id' => 'Select a line from the project’s Director-approved internal budget.']);
                }
                $attributes = [
                    'project_phase_id' => $entry->project_phase_id,
                    'main_category' => $entry->main_category,
                    'subcategory' => $entry->subcategory,
                    'internal_budget_entry_id' => $entry->id,
                ];
            }

            if (($request->request_type === 'salary_wage' || $request->labour_agreement_id !== null)
                && $attributes['main_category'] !== 'labour_subcontracts') {
                throw ValidationException::withMessages(['main_category' => 'Salary/wage requests and linked labour agreements must remain under Labour & Subcontracts.']);
            }

            $request->update($attributes);

            return $request->fresh(['phase', 'internalBudgetEntry']);
        });
    }
}
