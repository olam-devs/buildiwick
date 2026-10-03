<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectEstimate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectEstimate> */
class ProjectEstimateFactory extends Factory
{
    protected $model = ProjectEstimate::class;

    public function definition(): array
    {
        return [
            'estimate_code' => 'EST-'.fake()->unique()->numerify('########'),
            'project_id' => Project::factory(),
            'version' => 1,
            'status' => 'draft',
        ];
    }
}