<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectPhase;
use Illuminate\Database\Seeder;

class ProjectPhaseSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            'preliminaries',
            'substructure',
            'superstructure',
            'walling',
            'roofing',
            'finishing',
            'external_works',
        ];

        foreach (Project::query()->get(['id']) as $project) {
            foreach ($stages as $index => $stage) {
                ProjectPhase::firstOrCreate(
                    ['project_id' => $project->id, 'stage_code' => $stage],
                    ['phase_order' => $index + 1, 'status' => $index === 0 ? 'active' : 'not_started']
                );
            }
        }
    }
}