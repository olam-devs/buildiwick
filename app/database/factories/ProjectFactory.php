<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'project_code' => 'BWG-TST-'.fake()->unique()->numerify('########'),
            'name' => fake()->unique()->words(3, true),
            'status' => 'active',
            'location' => fake()->city(),
        ];
    }
}