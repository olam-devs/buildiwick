<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\ProjectEditLog;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Recording-mode budgets: the Director sets one amount per phase node × main category. Each amount is
 * an approved InternalBudget scoped to that phase and category with a single "Budget allowance" line,
 * so existing budget figures keep reading it. Every change is kept in the project edit log.
 */
class PhaseBudgetService
{
    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $amounts  phaseId => [category => amount|null]
     */
    public function save(Project $project, array $amounts, User $actor): int
    {
        Gate::forUser($actor)->authorize('manage-project-structure', $project);

        return $this->database->transaction(function () use ($project, $amounts, $actor): int {
            $phaseIds = $project->phases()->pluck('id')->all();
            $changed = 0;
            foreach ($amounts as $phaseId => $categories) {
                if (! in_array((int) $phaseId, $phaseIds, true)) {
                    throw ValidationException::withMessages(['budget' => 'A budget line refers to a phase outside this project.']);
                }
                $hasSubphases = $project->phases()->where('parent_id', $phaseId)->exists();
                foreach ($categories as $category => $value) {
                    if (! array_key_exists($category, LedgerAnalyticsService::CATEGORIES)) {
                        continue;
                    }
                    // Category budgets live at the lowest level; a phase with subphases shows their totals.
                    if ($hasSubphases && $value !== null && trim((string) $value) !== '' && (float) $value > 0) {
                        throw ValidationException::withMessages(['budget' => 'A phase with subphases takes its budget from them. Set the amounts on its subphases.']);
                    }
                    $changed += $this->set($project, (int) $phaseId, $category, $value, $actor) ? 1 : 0;
                }
            }

            return $changed;
        });
    }

    private function set(Project $project, int $phaseId, string $category, mixed $value, User $actor): bool
    {
        $amount = ($value === null || trim((string) $value) === '') ? null : number_format((float) str_replace(',', '', (string) $value), 2, '.', '');
        if ($amount !== null && (float) $amount < 0) {
            throw ValidationException::withMessages(['budget' => 'Budget amounts cannot be negative.']);
        }
        $budget = InternalBudget::query()->where('project_id', $project->id)->where('project_phase_id', $phaseId)
            ->where('main_category', $category)->where('status', 'approved')->with('entries')->lockForUpdate()->first();
        $current = $budget ? Money::sum($budget->entries->map(fn ($entry) => $entry->line_total)) : null;
        if (($current === null && ($amount === null || (float) $amount === 0.0)) || ($current !== null && $amount !== null && Money::compare($current, $amount) === 0)) {
            return false;
        }

        if ($amount === null || (float) $amount === 0.0) {
            $referenced = ExpenseRequest::query()->whereIn('internal_budget_entry_id', $budget->entries->pluck('id'))->exists();
            if ($referenced) {
                $budget->entries()->whereNotIn('id', ExpenseRequest::query()->whereNotNull('internal_budget_entry_id')->pluck('internal_budget_entry_id'))->delete();
                $budget->entries()->update(['unit_cost' => '0.00', 'quantity' => '1']);
            } else {
                $budget->entries()->delete();
                $budget->delete();
            }
            $this->log($project, $phaseId, $category, $current, null, $actor);

            return true;
        }

        if (! $budget) {
            $budget = InternalBudget::create([
                'budget_code' => 'BUD-'.$project->id.'-'.$phaseId.'-'.$category.'-'.now()->format('YmdHis').random_int(10, 99),
                'project_id' => $project->id,
                'project_phase_id' => $phaseId,
                'main_category' => $category,
                'version' => (int) InternalBudget::query()->where('project_id', $project->id)->where('project_phase_id', $phaseId)->where('main_category', $category)->max('version') + 1,
                'status' => 'approved',
                'created_by' => $actor->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'notes' => 'Director budget (recording mode).',
            ]);
        }
        $entry = $budget->entries()->orderBy('id')->first();
        if ($entry) {
            $budget->entries()->where('id', '!=', $entry->id)->whereNotIn('id', ExpenseRequest::query()->whereNotNull('internal_budget_entry_id')->pluck('internal_budget_entry_id'))->delete();
            $budget->entries()->where('id', '!=', $entry->id)->update(['unit_cost' => '0.00']);
            $entry->update(['quantity' => '1', 'unit_cost' => $amount]);
        } else {
            $budget->entries()->create([
                'project_phase_id' => $phaseId, 'main_category' => $category, 'item_description' => 'Budget allowance',
                'quantity' => '1', 'unit' => 'ls', 'unit_cost' => $amount, 'entered_by' => $actor->id,
            ]);
        }
        $this->log($project, $phaseId, $category, $current, $amount, $actor);

        return true;
    }

    private function log(Project $project, int $phaseId, string $category, ?string $before, ?string $after, User $actor): void
    {
        ProjectEditLog::create([
            'project_id' => $project->id,
            'document_type' => 'budget',
            'document_id' => $phaseId,
            'document_code' => 'Phase budget',
            'action' => 'budget_amount_set',
            'item_description' => LedgerAnalyticsService::CATEGORIES[$category],
            'reason' => 'Director budget update',
            'before_snapshot' => ['amount' => $before],
            'after_snapshot' => ['amount' => $after],
            'edited_by' => $actor->id,
        ]);
    }
}
