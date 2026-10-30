<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\PhaseTree;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Director-owned phase tree for recording mode: freely named phases, optional subphases and optional
 * sub-subphases. Unlike the estimate workflow, the structure stays editable while recording, but a
 * phase that already holds expenses or budget amounts cannot be deleted.
 */
class PhaseStructureService
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function create(Project $project, string $name, ?int $parentId, User $actor): ProjectPhase
    {
        Gate::forUser($actor)->authorize('manage-project-structure', $project);
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['phase_name' => 'Enter a name.']);
        }

        return $this->database->transaction(function () use ($project, $name, $parentId, $actor): ProjectPhase {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $phases = $locked->phases()->get();
            if ($parentId !== null) {
                if (! $phases->contains('id', $parentId)) {
                    throw ValidationException::withMessages(['parent_id' => 'Choose a phase of this project.']);
                }
                if ((new PhaseTree($phases))->depth($parentId) >= PhaseTree::MAX_DEPTH) {
                    throw ValidationException::withMessages(['parent_id' => 'Phases go at most three levels deep (phase › subphase › sub-subphase).']);
                }
            }
            $base = Str::slug($name, '_') ?: 'phase';
            $code = $base;
            $suffix = 2;
            while ($phases->contains('stage_code', $code)) {
                $code = $base.'_'.$suffix++;
            }
            $order = (int) ($phases->where('parent_id', $parentId)->max('phase_order') ?? 0) + 1;

            return $locked->phases()->create([
                'parent_id' => $parentId,
                'stage_code' => $code,
                'phase_name' => $name,
                'phase_order' => $order,
                'status' => $parentId === null && $phases->whereNull('parent_id')->isEmpty() ? 'active' : 'not_started',
                'created_by' => $actor->id,
            ]);
        });
    }

    public function rename(ProjectPhase $phase, string $name, User $actor): ProjectPhase
    {
        Gate::forUser($actor)->authorize('manage-project-structure', $phase->project);
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['phase_name' => 'Enter a name.']);
        }
        $phase->update(['phase_name' => $name]);

        return $phase->fresh();
    }

    public function move(ProjectPhase $phase, string $direction, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage-project-structure', $phase->project);
        $this->database->transaction(function () use ($phase, $direction): void {
            $siblings = ProjectPhase::query()->where('project_id', $phase->project_id)->where('parent_id', $phase->parent_id)
                ->orderBy('phase_order')->orderBy('id')->lockForUpdate()->get()->values();
            $index = $siblings->search(fn ($sibling) => $sibling->id === $phase->id);
            $swap = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || $swap < 0 || $swap >= $siblings->count()) {
                return;
            }
            $ordered = $siblings->all();
            [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
            foreach ($ordered as $position => $sibling) {
                $sibling->update(['phase_order' => $position + 1]);
            }
        });
    }

    public function delete(ProjectPhase $phase, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage-project-structure', $phase->project);
        $this->database->transaction(function () use ($phase): void {
            $tree = new PhaseTree(ProjectPhase::query()->where('project_id', $phase->project_id)->get());
            $ids = $tree->withDescendants($phase->id);
            $used = ExpenseRequest::query()->whereIn('project_phase_id', $ids)->exists()
                || InternalBudget::query()->whereIn('project_phase_id', $ids)->exists()
                || InternalBudgetEntry::query()->whereIn('project_phase_id', $ids)->exists();
            if ($used) {
                throw ValidationException::withMessages(['phase' => 'This phase (or a part under it) already has expenses or budget amounts, so it cannot be deleted. Rename it instead.']);
            }
            ProjectPhase::query()->whereIn('id', $ids)->orderByDesc('id')->get()->each->delete();
        });
    }
}
