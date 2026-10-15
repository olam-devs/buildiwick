<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectPhaseService
{
    public const DEFAULT_PHASES = [
        'Preliminaries', 'Substructure', 'Superstructure', 'Walling',
        'Roofing', 'Finishing', 'External Works',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    public function isLocked(Project $project): bool
    {
        return $project->estimates()->whereIn('status', ['issued', 'superseded'])->exists()
            || $project->internalBudgets()->where('status', 'approved')->exists()
            || $project->expenseRequests()->where('status', 'approved')->exists()
            || $project->expensePayments()->exists()
            || $project->invoices()->where('status', 'issued')->exists();
    }

    public function create(Project $project, array $data, User $actor): ProjectPhase
    {
        $this->authorizeEditor($project, $actor);
        $this->assertEditable($project);

        return $this->database->transaction(function () use ($project, $data, $actor): ProjectPhase {
            $name = trim($data['phase_name']);
            $baseCode = Str::slug($name, '_') ?: 'phase';
            $code = $baseCode;
            $suffix = 2;
            while ($project->phases()->where('stage_code', $code)->exists()) {
                $code = $baseCode.'_'.$suffix++;
            }

            $order = (int) ($project->phases()->max('phase_order') ?? 0) + 1;

            return $project->phases()->create([
                'stage_code' => $code,
                'phase_name' => $name,
                'phase_order' => $order,
                'planned_start_date' => $data['planned_start_date'] ?? null,
                'planned_completion_date' => $data['planned_completion_date'] ?? null,
                'status' => $order === 1 ? 'active' : 'not_started',
                'created_by' => $actor->id,
            ]);
        });
    }

    public function update(ProjectPhase $phase, array $data, User $actor): ProjectPhase
    {
        $project = $phase->project;
        $this->authorizeEditor($project, $actor);
        $this->assertEditable($project);
        $phase->update([
            'phase_name' => trim($data['phase_name']),
            'planned_start_date' => $data['planned_start_date'] ?? null,
            'planned_completion_date' => $data['planned_completion_date'] ?? null,
        ]);

        return $phase->refresh();
    }

    public function delete(ProjectPhase $phase, User $actor): void
    {
        $project = $phase->project;
        $this->authorizeEditor($project, $actor);
        $this->assertEditable($project);

        $this->database->transaction(function () use ($phase, $project): void {
            $phase->delete();
            $this->normalizeOrder($project);
        });
    }

    public function reset(Project $project, User $actor): void
    {
        $this->authorizeEditor($project, $actor);
        $this->assertEditable($project);

        $this->database->transaction(function () use ($project, $actor): void {
            $project->phases()->delete();
            foreach (self::DEFAULT_PHASES as $index => $name) {
                $project->phases()->create([
                    'stage_code' => Str::slug($name, '_'),
                    'phase_name' => $name,
                    'phase_order' => $index + 1,
                    'status' => $index === 0 ? 'active' : 'not_started',
                    'created_by' => $actor->id,
                ]);
            }
        });
    }

    private function normalizeOrder(Project $project): void
    {
        foreach ($project->phases()->orderBy('phase_order')->orderBy('id')->get()->values() as $index => $phase) {
            $phase->update(['phase_order' => $index + 1]);
        }
    }

    private function authorizeEditor(Project $project, User $actor): void
    {
        $allowed = $actor->hasRole('admin') || ($actor->hasRole('estimator_qs')
            && $actor->assignedProjects()->whereKey($project->id)->exists());
        abort_unless($allowed, 403);
    }

    private function assertEditable(Project $project): void
    {
        if ($this->isLocked($project)) {
            throw ValidationException::withMessages([
                'phases' => 'Project phases are locked because an estimate or internal budget has been approved. Create an audited document edit instead of changing the approved phase structure.',
            ]);
        }
    }
}
