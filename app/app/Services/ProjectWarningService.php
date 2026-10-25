<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectWarningRule;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Director-defined warning thresholds. Company defaults (project_id null) apply to every project
 * unless a project has its own row for that rule. Evaluation reads the analytics row the Director
 * pages already build, so warnings always agree with the figures on screen.
 */
class ProjectWarningService
{
    public const RULES = [
        'budget_used_pct' => ['label' => 'Budget used (paid out) above', 'unit' => '%', 'default' => 85, 'active' => true],
        'commitments_pct' => ['label' => 'Approved commitments above', 'unit' => '% of budget', 'default' => 100, 'active' => true],
        'invoice_overdue_days' => ['label' => 'Client invoice overdue by more than', 'unit' => 'days', 'default' => 30, 'active' => true],
        'payable_waiting_days' => ['label' => 'Approved payment unpaid for more than', 'unit' => 'days', 'default' => 30, 'active' => true],
        'phase_late_days' => ['label' => 'Phase past its planned finish by more than', 'unit' => 'days', 'default' => 14, 'active' => true],
        'receivables_outstanding' => ['label' => 'Unpaid client invoices above', 'unit' => 'TSh', 'default' => 50000000, 'active' => false],
    ];

    /** @var Collection<int, ProjectWarningRule>|null */
    private ?Collection $rows = null;

    /**
     * Effective rules for a project (or the company defaults when $projectId is null).
     *
     * @return array<string, array{threshold: string, active: bool, source: string}>
     */
    public function rules(?int $projectId = null): array
    {
        $rows = $this->rows ??= ProjectWarningRule::query()->get();
        $rules = [];
        foreach (self::RULES as $code => $definition) {
            $default = $rows->first(fn ($row) => $row->project_id === null && $row->rule === $code);
            $override = $projectId ? $rows->first(fn ($row) => (int) $row->project_id === $projectId && $row->rule === $code) : null;
            $chosen = $override ?? $default;
            $rules[$code] = [
                'threshold' => $chosen ? (string) $chosen->threshold : number_format($definition['default'], 2, '.', ''),
                'active' => $chosen ? (bool) $chosen->is_active : $definition['active'],
                'source' => $override ? 'project' : 'default',
            ];
        }

        return $rules;
    }

    /**
     * @param  array<string, array{threshold: string|float|int|null, active?: bool}>  $values
     */
    public function save(?Project $project, array $values, User $actor): void
    {
        foreach (self::RULES as $code => $definition) {
            if (! array_key_exists($code, $values)) {
                continue;
            }
            ProjectWarningRule::query()->updateOrCreate(
                ['project_id' => $project?->id, 'rule' => $code],
                ['threshold' => $values[$code]['threshold'] ?? $definition['default'], 'is_active' => (bool) ($values[$code]['active'] ?? false), 'updated_by' => $actor->id],
            );
        }
        $this->rows = null;
    }

    public function resetProject(Project $project): void
    {
        ProjectWarningRule::query()->where('project_id', $project->id)->delete();
        $this->rows = null;
    }

    /**
     * Warnings triggered for one Director analytics project row.
     *
     * @return array<int, array{rule: string, label: string, message: string}>
     */
    public function evaluate(array $row, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $project = $row['project'];
        $warnings = [];
        foreach ($this->rules($project->id) as $code => $rule) {
            if (! $rule['active']) {
                continue;
            }
            $threshold = $rule['threshold'];
            $days = (int) round((float) $threshold);
            $message = match ($code) {
                'budget_used_pct' => $row['actual_utilization'] !== null && Money::compare($row['actual_utilization'], $threshold) > 0
                    ? Money::format($row['actual_utilization']).'% of the budget has been paid out (limit '.Money::format($threshold).'%).' : null,
                'commitments_pct' => $row['committed_utilization'] !== null && Money::compare($row['committed_utilization'], $threshold) > 0
                    ? 'Approved commitments are '.Money::format($row['committed_utilization']).'% of the budget (limit '.Money::format($threshold).'%).' : null,
                'invoice_overdue_days' => $this->overdueInvoices($row, $today, $days),
                'payable_waiting_days' => $this->waitingPayables($row, $today, $days),
                'phase_late_days' => $this->latePhases($project, $today, $days),
                'receivables_outstanding' => Money::compare($row['receivables_current'], $threshold) > 0
                    ? 'TSh '.Money::format($row['receivables_current']).' is still owed by the client (limit TSh '.Money::format($threshold).').' : null,
                default => null,
            };
            if ($message) {
                $warnings[] = ['rule' => $code, 'label' => self::RULES[$code]['label'], 'message' => $message];
            }
        }

        return $warnings;
    }

    private function overdueInvoices(array $row, CarbonImmutable $today, int $days): ?string
    {
        $late = $row['receivable_rows']->filter(fn ($item) => $item['invoice']->due_at && $item['invoice']->due_at->lt($today->subDays($days)));
        if ($late->isEmpty()) {
            return null;
        }
        $oldest = $late->max(fn ($item) => (int) $item['invoice']->due_at->diffInDays($today));

        return $late->count().' client invoice(s) overdue by more than '.$days.' days (oldest '.$oldest.' days), TSh '.Money::format(Money::sum($late->pluck('outstanding'))).' outstanding.';
    }

    private function waitingPayables(array $row, CarbonImmutable $today, int $days): ?string
    {
        $waiting = $row['payable_rows']->filter(function ($item) use ($today, $days) {
            $approvedAt = $item['request']->latestApproval?->decided_at;

            return $approvedAt && CarbonImmutable::parse($approvedAt)->lt($today->subDays($days));
        });
        if ($waiting->isEmpty()) {
            return null;
        }

        return $waiting->count().' approved payment(s) unpaid for more than '.$days.' days, TSh '.Money::format(Money::sum($waiting->pluck('outstanding'))).' outstanding.';
    }

    private function latePhases(Project $project, CarbonImmutable $today, int $days): ?string
    {
        $late = $project->phases->filter(fn ($phase) => $phase->planned_completion_date
            && $phase->status !== 'completed'
            && $phase->planned_completion_date->lt($today->subDays($days)));
        if ($late->isEmpty()) {
            return null;
        }

        return $late->map(fn ($phase) => $phase->stage_label.' ('.(int) $phase->planned_completion_date->diffInDays($today).' days late)')->join(', ').' not completed.';
    }
}
