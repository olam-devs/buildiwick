<?php

namespace App\Services;

use App\Models\EstimateEntry;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEditLog;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EstimatorEstimateService
{
    public const CATEGORIES = [
        'materials' => 'Materials',
        'labour_subcontracts' => 'Labour & Subcontracts',
        'logistics_equipment' => 'Logistics & Equipment',
        'preliminaries_overheads' => 'Preliminaries & Site Overheads',
    ];

    public const PHASES = [
        'preliminaries', 'substructure', 'superstructure', 'walling',
        'roofing', 'finishing', 'external_works',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * A project has at most one working estimate. Estimators may only open one before their first
     * submission or after Admin returns it; Admin works on a submitted estimate in place.
     */
    public function ensureWorkingEstimate(Project $project, User $actor): ProjectEstimate
    {
        if (! Gate::forUser($actor)->allows('create', [ProjectEstimate::class, $project])) {
            $this->denyEstimatorAfterSubmission($project, $actor);
        }

        return $this->database->transaction(function () use ($project, $actor): ProjectEstimate {
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (! Gate::forUser($actor)->allows('create', [ProjectEstimate::class, $lockedProject])) {
                $this->denyEstimatorAfterSubmission($lockedProject, $actor);
            }
            $working = $lockedProject->estimates()->whereIn('status', ['draft', 'submitted'])->lockForUpdate()->get();
            if ($working->count() > 1) {
                throw ValidationException::withMessages(['estimate' => 'This project has more than one working estimate; administrator review is required.']);
            }
            if ($working->isNotEmpty()) {
                return $working->first();
            }
            if ($lockedProject->estimates()->exists()) {
                throw ValidationException::withMessages(['estimate' => 'Use Edit Estimate and provide a justification to change the issued estimate.']);
            }

            $version = ((int) $lockedProject->estimates()->max('version')) + 1;

            return $lockedProject->estimates()->create([
                'estimate_code' => $this->estimateCode($lockedProject, $version),
                'client_id' => $lockedProject->client_id,
                'version' => $version,
                'status' => 'draft',
                'editor_role' => $actor->hasRole('admin') ? 'admin' : 'estimator',
                'prepared_at' => now()->toDateString(),
                'prepared_by' => $actor->id,
            ]);
        });
    }

    /** Admin opens an audited revision of an issued estimate, either for themself or for the Estimator. */
    public function startEditing(ProjectEstimate $source, string $reason, User $actor, string $editorRole = 'admin'): ProjectEstimate
    {
        $ability = $editorRole === 'estimator' ? 'returnToEstimator' : 'revise';
        Gate::forUser($actor)->authorize($ability, $source);

        return $this->database->transaction(function () use ($source, $reason, $actor, $editorRole, $ability): ProjectEstimate {
            $project = Project::query()->whereKey($source->project_id)->lockForUpdate()->firstOrFail();
            $locked = ProjectEstimate::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($ability, $locked);
            $this->requireReason($reason);
            if ($locked->status !== 'issued') {
                throw ValidationException::withMessages(['estimate' => 'Only the issued estimate can be opened for a revision.']);
            }
            if ($project->estimates()->whereIn('status', ['draft', 'submitted'])->exists()) {
                throw ValidationException::withMessages(['estimate' => 'Finish the existing working estimate before editing the issued estimate.']);
            }

            $version = ((int) $project->estimates()->max('version')) + 1;
            $returnAudit = $editorRole === 'estimator'
                ? ['returned_by' => $actor->id, 'returned_at' => now(), 'review_notes' => $reason]
                : [];
            $draft = $project->estimates()->create([
                'estimate_code' => $this->estimateCode($project, $version),
                'client_id' => $locked->client_id,
                'version' => $version,
                'status' => 'draft',
                'editor_role' => $editorRole,
                'prepared_at' => now()->toDateString(),
                'prepared_by' => $actor->id,
                'valid_until' => $locked->valid_until,
                'notes' => $locked->notes,
            ] + $returnAudit);
            foreach ($locked->entries()->get() as $entry) {
                $draft->entries()->create($entry->only([
                    'project_phase_id', 'work_area', 'work_section', 'item_reference', 'main_category', 'subcategory', 'item_description', 'quantity', 'unit',
                    'base_unit_cost', 'markup_percent', 'notes',
                ]) + ['entered_by' => $actor->id]);
            }
            $this->logLifecycle($draft, $editorRole === 'estimator' ? 'returned_to_estimator' : 'edit_started', $reason, $actor, $locked->version);

            return $draft->load('entries');
        });
    }

    /** The Estimator hands the estimate to Admin; their editing rights end here. */
    public function submit(ProjectEstimate $estimate, User $actor): ProjectEstimate
    {
        Gate::forUser($actor)->authorize('submit', $estimate);

        return $this->database->transaction(function () use ($estimate, $actor): ProjectEstimate {
            Project::query()->whereKey($estimate->project_id)->lockForUpdate()->firstOrFail();
            $current = ProjectEstimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('submit', $current);
            if ($current->entries()->doesntExist()) {
                throw ValidationException::withMessages(['estimate' => 'Add at least one estimate line before submitting.']);
            }
            $current->update(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->logLifecycle($current, 'submitted', 'Submitted to Director for approval.', $actor);

            return $current->fresh();
        });
    }

    /** Admin sends a submitted (or issued) estimate back so the Estimator can change it again. */
    public function returnToEstimator(ProjectEstimate $estimate, string $notes, User $actor): ProjectEstimate
    {
        Gate::forUser($actor)->authorize('returnToEstimator', $estimate);
        $this->requireReason($notes, 'review_notes');
        if ($estimate->status === 'issued') {
            return $this->startEditing($estimate, $notes, $actor, 'estimator');
        }

        return $this->database->transaction(function () use ($estimate, $notes, $actor): ProjectEstimate {
            $current = ProjectEstimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('returnToEstimator', $current);
            if ($current->status !== 'submitted') {
                throw ValidationException::withMessages(['estimate' => 'Only a submitted estimate can be returned.']);
            }
            $current->update([
                'status' => 'draft',
                'editor_role' => 'estimator',
                'returned_by' => $actor->id,
                'returned_at' => now(),
                'review_notes' => $notes,
            ]);
            $this->logLifecycle($current, 'returned_to_estimator', $notes, $actor);

            return $current->fresh();
        });
    }

    /**
     * Replaces every line of a working estimate with uploaded rows that were already resolved and validated.
     *
     * @param  array<int, array<string, mixed>>  $rows  Entry attributes plus `project_phase_id`.
     */
    public function replaceEntries(ProjectEstimate $estimate, array $rows, ?string $reason, User $actor): ProjectEstimate
    {
        return $this->database->transaction(function () use ($estimate, $rows, $reason, $actor): ProjectEstimate {
            $locked = ProjectEstimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->version > 1) {
                $this->requireReason($reason);
            }
            $previousCount = $locked->entries()->count();
            $locked->entries()->delete();
            foreach ($rows as $row) {
                $this->assertPhaseBelongsToEstimate($locked, ProjectPhase::findOrFail($row['project_phase_id']));
                $this->assertLookupPair($row);
                $locked->entries()->create($this->entryAttributes($row) + [
                    'project_phase_id' => $row['project_phase_id'],
                    'entered_by' => $actor->id,
                ]);
            }
            if ($locked->version > 1) {
                ProjectEditLog::create([
                    'project_id' => $locked->project_id,
                    'document_type' => 'estimate',
                    'document_id' => $locked->id,
                    'document_code' => $locked->estimate_code,
                    'from_version' => $locked->version,
                    'to_version' => $locked->version,
                    'action' => 'entries_replaced_by_upload',
                    'item_description' => 'Excel upload replaced '.$previousCount.' line(s) with '.count($rows).' line(s)',
                    'reason' => $reason,
                    'edited_by' => $actor->id,
                ]);
            }

            return $locked->fresh('entries');
        });
    }

    public function createEntry(ProjectEstimate $estimate, ProjectPhase $phase, array $data, User $actor): EstimateEntry
    {
        return $this->database->transaction(function () use ($estimate, $phase, $data, $actor): EstimateEntry {
            $lockedEstimate = ProjectEstimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', [EstimateEntry::class, $lockedEstimate]);
            $this->assertPhaseBelongsToEstimate($lockedEstimate, $phase);
            $this->assertLookupPair($data);

            if ($lockedEstimate->version > 1) {
                $this->requireReason($data['edit_reason'] ?? null);
            }
            $entry = $lockedEstimate->entries()->create($this->entryAttributes($data) + [
                'project_phase_id' => $phase->id,
                'entered_by' => $actor->id,
            ]);
            if ($lockedEstimate->version > 1) {
                $this->logEntryChange($lockedEstimate, $entry, 'entry_added', $data['edit_reason'], $actor, null, $this->snapshot($entry));
            }

            return $entry;
        });
    }

    public function updateEntry(EstimateEntry $entry, array $data, User $actor): EstimateEntry
    {
        return $this->database->transaction(function () use ($entry, $data, $actor): EstimateEntry {
            ProjectEstimate::query()->whereKey($entry->project_estimate_id)->lockForUpdate()->firstOrFail();
            $lockedEntry = EstimateEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $lockedEntry);
            if ($lockedEntry->estimate->version > 1) {
                $this->requireReason($data['edit_reason'] ?? null);
            }
            $this->assertLookupPair($data);
            $before = $this->snapshot($lockedEntry);
            $lockedEntry->update($this->entryAttributes($data));
            if ($lockedEntry->estimate->version > 1) {
                $this->logEntryChange($lockedEntry->estimate, $lockedEntry, 'entry_updated', $data['edit_reason'], $actor, $before, $this->snapshot($lockedEntry->fresh()));
            }

            return $lockedEntry->refresh();
        });
    }

    public function deleteEntry(EstimateEntry $entry, ?string $reason, User $actor): void
    {
        $this->database->transaction(function () use ($entry, $reason, $actor): void {
            ProjectEstimate::query()->whereKey($entry->project_estimate_id)->lockForUpdate()->firstOrFail();
            $lockedEntry = EstimateEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $lockedEntry);
            if ($lockedEntry->estimate->version > 1) {
                $this->requireReason($reason);
                $this->logEntryChange($lockedEntry->estimate, $lockedEntry, 'entry_deleted', $reason, $actor, $this->snapshot($lockedEntry), null);
            }
            $lockedEntry->delete();
        });
    }

    /** Admin approval: the estimate becomes the issued version Accounts can invoice from. */
    public function finalize(ProjectEstimate $estimate, User $actor): ProjectEstimate
    {
        Gate::forUser($actor)->authorize('finalize', $estimate);

        return $this->database->transaction(function () use ($estimate, $actor): ProjectEstimate {
            $project = Project::query()->whereKey($estimate->project_id)->lockForUpdate()->firstOrFail();
            $current = ProjectEstimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('finalize', $current);
            if ($project->estimates()->whereIn('status', ['draft', 'submitted'])->count() !== 1
                || $current->entries()->doesntExist()) {
                throw ValidationException::withMessages(['estimate' => 'Exactly one working estimate must exist, with at least one entry, before it can be issued.']);
            }

            $project->estimates()->where('status', 'issued')->where('id', '!=', $current->id)->update(['status' => 'superseded']);
            $current->update([
                'status' => 'issued',
                'prepared_at' => $current->prepared_at ?? now()->toDateString(),
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ]);

            return $current->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function projectSummary(ProjectEstimate $estimate): array
    {
        $entries = $estimate->entries;
        $subcategoryLabels = Lookup::where('type', 'subcategory')->pluck('label', 'code');
        $categories = [];
        $baseTotal = '0.00';
        $markupTotal = '0.00';
        $clientTotal = '0.00';

        foreach (self::CATEGORIES as $code => $label) {
            $group = $entries->where('main_category', $code);
            $base = Money::sum($group->map(fn (EstimateEntry $entry) => $entry->base_total));
            $markup = Money::sum($group->map(fn (EstimateEntry $entry) => $entry->markup_amount));
            $client = Money::sum($group->map(fn (EstimateEntry $entry) => $entry->line_total));
            $subcategoryKeys = $group->pluck('subcategory')->map(fn ($value) => $value ?: '__other')->unique()->values();
            $subcategories = $subcategoryKeys->map(function (string $key) use ($group, $subcategoryLabels) {
                $matches = fn ($entry) => (($entry->subcategory ?: '__other') === $key);
                $rows = $group->filter($matches);

                return [
                    'key' => $key,
                    'label' => $key === '__other' ? 'Other / Unclassified' : ($subcategoryLabels[$key] ?? ucwords(str_replace('_', ' ', $key))),
                    'base' => Money::sum($rows->map(fn (EstimateEntry $entry) => $entry->base_total)),
                    'markup' => Money::sum($rows->map(fn (EstimateEntry $entry) => $entry->markup_amount)),
                    'client' => Money::sum($rows->map(fn (EstimateEntry $entry) => $entry->line_total)),
                ];
            })->values();
            $categories[$code] = compact('label', 'base', 'markup', 'client', 'subcategories');
            $baseTotal = Money::sum([$baseTotal, $base]);
            $markupTotal = Money::sum([$markupTotal, $markup]);
            $clientTotal = Money::sum([$clientTotal, $client]);
        }

        $effectiveMarkup = Money::percentage($markupTotal, $baseTotal);
        foreach ($categories as &$category) {
            $category['share'] = Money::compare($clientTotal, '0') === 0 ? '0.00' : Money::percentage($category['client'], $clientTotal);
        }
        unset($category);

        return compact('categories', 'baseTotal', 'markupTotal', 'clientTotal', 'effectiveMarkup');
    }

    /** @return array<string, mixed> */
    public function phaseSummary(ProjectEstimate $estimate, ProjectPhase $phase): array
    {
        $entries = $estimate->entries->where('project_phase_id', $phase->id);
        $categories = [];
        $baseTotal = '0.00';
        $markupTotal = '0.00';
        $clientTotal = '0.00';

        foreach (self::CATEGORIES as $code => $label) {
            $group = $entries->where('main_category', $code);
            $base = Money::sum($group->map(fn (EstimateEntry $entry) => $entry->base_total));
            $markup = Money::sum($group->map(fn (EstimateEntry $entry) => $entry->markup_amount));
            $client = Money::sum($group->map(fn (EstimateEntry $entry) => $entry->line_total));
            $categories[$code] = compact('label', 'base', 'markup', 'client');
            $baseTotal = Money::sum([$baseTotal, $base]);
            $markupTotal = Money::sum([$markupTotal, $markup]);
            $clientTotal = Money::sum([$clientTotal, $client]);
        }

        return compact('categories', 'baseTotal', 'markupTotal', 'clientTotal');
    }

    /** @return array<string, mixed> */
    public function entryAttributes(array $data): array
    {
        return [
            'work_area' => $data['work_area'] ?? null,
            'work_section' => $data['work_section'] ?? null,
            'item_reference' => $data['item_reference'] ?? null,
            'main_category' => $data['main_category'],
            'subcategory' => $data['subcategory'] ?? null,
            'item_description' => $data['item_description'],
            'quantity' => ($data['quantity'] ?? null) === '' ? null : ($data['quantity'] ?? null),
            'unit' => $data['unit'] ?? null,
            'base_unit_cost' => $data['base_unit_cost'],
            'markup_percent' => $data['markup_percent'] ?? '0',
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function assertPhaseBelongsToEstimate(ProjectEstimate $estimate, ProjectPhase $phase): void
    {
        if (! $phase->exists || (int) $phase->project_id !== (int) $estimate->project_id) {
            throw ValidationException::withMessages(['project_phase_id' => 'Select a phase belonging to this project.']);
        }
    }

    private function assertLookupPair(array $data): void
    {
        if (! empty($data['subcategory'])) {
            $valid = Lookup::where('type', 'subcategory')
                ->where('code', $data['subcategory'])
                ->where('parent_code', $data['main_category'])
                ->where('is_active', true)
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['subcategory' => 'Choose a subcategory belonging to the selected category.']);
            }
        }
    }

    private function estimateCode(Project $project, int $version): string
    {
        return $project->project_code.'-EST-'.str_pad((string) $version, 3, '0', STR_PAD_LEFT);
    }

    private function requireReason(?string $reason, string $field = 'edit_reason'): void
    {
        if (trim((string) $reason) === '') {
            throw ValidationException::withMessages([$field => 'Explain why this change is needed.']);
        }
    }

    private function denyEstimatorAfterSubmission(Project $project, User $actor): never
    {
        if ($actor->hasRole('estimator_qs') && $actor->assignedProjects()->whereKey($project->id)->exists()) {
            throw ValidationException::withMessages(['estimate' => 'This estimate has been submitted to the Director and is read-only. The Director can return it to you if changes are needed.']);
        }

        Gate::forUser($actor)->authorize('create', [ProjectEstimate::class, $project]);
        abort(403);
    }

    private function logLifecycle(ProjectEstimate $estimate, string $action, string $reason, User $actor, ?int $fromVersion = null): void
    {
        ProjectEditLog::create([
            'project_id' => $estimate->project_id,
            'document_type' => 'estimate',
            'document_id' => $estimate->id,
            'document_code' => $estimate->estimate_code,
            'from_version' => $fromVersion ?? $estimate->version,
            'to_version' => $estimate->version,
            'action' => $action,
            'reason' => $reason,
            'edited_by' => $actor->id,
        ]);
    }

    private function logEntryChange(ProjectEstimate $estimate, EstimateEntry $entry, string $action, string $reason, User $actor, ?array $before, ?array $after): void
    {
        ProjectEditLog::create([
            'project_id' => $estimate->project_id,
            'document_type' => 'estimate',
            'document_id' => $estimate->id,
            'document_code' => $estimate->estimate_code,
            'from_version' => $estimate->version,
            'to_version' => $estimate->version,
            'action' => $action,
            'item_description' => $entry->item_description,
            'reason' => $reason,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'edited_by' => $actor->id,
        ]);
    }

    private function snapshot(EstimateEntry $entry): array
    {
        return $entry->only([
            'project_phase_id', 'work_area', 'work_section', 'item_reference', 'main_category', 'subcategory', 'item_description', 'quantity', 'unit',
            'base_unit_cost', 'markup_percent', 'notes',
        ]);
    }
}
