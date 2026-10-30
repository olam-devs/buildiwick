<?php

namespace App\Support;

use App\Models\ProjectPhase;
use Illuminate\Support\Collection;

/**
 * Works with a project's phases as a tree (phase › subphase › sub-subphase) from one flat collection,
 * so pages never query phases level by level.
 */
class PhaseTree
{
    public const MAX_DEPTH = 3;

    /** @var Collection<int, ProjectPhase> keyed by id */
    private Collection $byId;

    /** @var Collection<int|string, Collection<int, ProjectPhase>> keyed by parent id ('' for top level) */
    private Collection $byParent;

    public function __construct(Collection $phases)
    {
        $this->byId = $phases->keyBy('id');
        $this->byParent = $phases->sortBy([['phase_order', 'asc'], ['id', 'asc']])->groupBy(fn (ProjectPhase $phase) => $phase->parent_id ?? '');
    }

    /**
     * Depth-first order with depth and full path, ready for indented lists and dropdowns.
     *
     * @return Collection<int, array{phase: ProjectPhase, depth: int, path: string, label: string}>
     */
    public function flatten(): Collection
    {
        $rows = collect();
        $walk = function ($parentId, int $depth, array $trail) use (&$walk, $rows): void {
            foreach ($this->byParent->get($parentId ?? '', collect()) as $phase) {
                $label = (string) $phase->stage_label;
                $path = [...$trail, $label];
                $rows->push(['phase' => $phase, 'depth' => $depth, 'path' => implode(' › ', $path), 'label' => $label]);
                $walk($phase->id, $depth + 1, $path);
            }
        };
        $walk(null, 1, []);

        return $rows;
    }

    /** @return Collection<int, ProjectPhase> */
    public function roots(): Collection
    {
        return $this->byParent->get('', collect())->values();
    }

    /** @return Collection<int, ProjectPhase> */
    public function children(?int $phaseId): Collection
    {
        return $this->byParent->get($phaseId ?? '', collect())->values();
    }

    /** The phase id plus every phase below it. @return array<int, int> */
    public function withDescendants(int $phaseId): array
    {
        $ids = [$phaseId];
        foreach ($this->children($phaseId) as $child) {
            array_push($ids, ...$this->withDescendants($child->id));
        }

        return $ids;
    }

    /** A phase with no subphases: the level where expenses are recorded and category budgets are set. */
    public function isLeaf(int $phaseId): bool
    {
        return $this->children($phaseId)->isEmpty();
    }

    public function depth(int $phaseId): int
    {
        $depth = 1;
        $phase = $this->byId->get($phaseId);
        while ($phase && $phase->parent_id && $depth <= self::MAX_DEPTH + 1) {
            $depth++;
            $phase = $this->byId->get($phase->parent_id);
        }

        return $depth;
    }

    public function path(?int $phaseId): string
    {
        $parts = [];
        $phase = $phaseId ? $this->byId->get($phaseId) : null;
        while ($phase && count($parts) <= self::MAX_DEPTH) {
            array_unshift($parts, (string) $phase->stage_label);
            $phase = $phase->parent_id ? $this->byId->get($phase->parent_id) : null;
        }

        return implode(' › ', $parts);
    }

    /** The top-level phase a phase belongs to (itself when top level). */
    public function rootOf(?int $phaseId): ?ProjectPhase
    {
        $phase = $phaseId ? $this->byId->get($phaseId) : null;
        while ($phase && $phase->parent_id) {
            $phase = $this->byId->get($phase->parent_id);
        }

        return $phase;
    }
}
