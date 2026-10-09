<?php

namespace App\Services;

use App\Models\EstimateEntry;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

    /** A project has at most one editable draft; issued estimates remain immutable versions. */
    public function ensureWorkingEstimate(Project $project, User $actor): ProjectEstimate
    {
        Gate::forUser($actor)->authorize('create', [ProjectEstimate::class, $project]);

        return $this->database->transaction(function () use ($project, $actor): ProjectEstimate {
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', [ProjectEstimate::class, $lockedProject]);
            $drafts = $lockedProject->estimates()->where('status', 'draft')->lockForUpdate()->get();
            if ($drafts->count() > 1) {
                throw ValidationException::withMessages(['estimate' => 'This project has more than one working estimate; administrator review is required.']);
            }
            if ($drafts->isNotEmpty()) {
                return $drafts->first();
            }

            $version = ((int) $lockedProject->estimates()->max('version')) + 1;

            return $lockedProject->estimates()->create([
                'estimate_code' => $this->estimateCode($lockedProject, $version),
                'client_id' => $lockedProject->client_id,
                'version' => $version,
                'status' => 'draft',
                'prepared_at' => now()->toDateString(),
                'prepared_by' => $actor->id,
            ]);
        });
    }

    public function createEntry(ProjectEstimate $estimate, ProjectPhase $phase, array $data, User $actor): EstimateEntry
    {
        return $this->database->transaction(function () use ($estimate, $phase, $data, $actor): EstimateEntry {
            $lockedEstimate = ProjectEstimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', [EstimateEntry::class, $lockedEstimate]);
            $this->assertPhaseBelongsToEstimate($lockedEstimate, $phase);
            $this->assertLookupPair($data);

            return $lockedEstimate->entries()->create($this->entryAttributes($data) + [
                'project_phase_id' => $phase->id,
                'entered_by' => $actor->id,
            ]);
        });
    }

    public function updateEntry(EstimateEntry $entry, array $data, User $actor): EstimateEntry
    {
        return $this->database->transaction(function () use ($entry, $data, $actor): EstimateEntry {
            ProjectEstimate::query()->whereKey($entry->project_estimate_id)->lockForUpdate()->firstOrFail();
            $lockedEntry = EstimateEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $lockedEntry);
            $this->assertLookupPair($data);
            $lockedEntry->update($this->entryAttributes($data));

            return $lockedEntry->refresh();
        });
    }

    public function deleteEntry(EstimateEntry $entry, User $actor): void
    {
        $this->database->transaction(function () use ($entry, $actor): void {
            ProjectEstimate::query()->whereKey($entry->project_estimate_id)->lockForUpdate()->firstOrFail();
            $lockedEntry = EstimateEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $lockedEntry);
            $lockedEntry->delete();
        });
    }

    public function finalize(ProjectEstimate $estimate, User $actor): ProjectEstimate
    {
        Gate::forUser($actor)->authorize('finalize', $estimate);

        return $this->database->transaction(function () use ($estimate, $actor): ProjectEstimate {
            $project = Project::query()->whereKey($estimate->project_id)->lockForUpdate()->firstOrFail();
            $current = ProjectEstimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('finalize', $current);
            if ($current->status !== 'draft'
                || $project->estimates()->where('status', 'draft')->count() !== 1
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
        $subcategoryLabels = \App\Models\Lookup::where('type', 'subcategory')->pluck('label', 'code');
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
        if ((int) $phase->project_id !== (int) $estimate->project_id
            || ! in_array($phase->stage_code, self::PHASES, true)) {
            throw ValidationException::withMessages(['project_phase_id' => 'Select one of this project’s seven standard construction phases.']);
        }
    }

    private function assertLookupPair(array $data): void
    {
        if (! empty($data['subcategory'])) {
            $valid = \App\Models\Lookup::where('type', 'subcategory')
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
}
