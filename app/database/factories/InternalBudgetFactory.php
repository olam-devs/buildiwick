<?php

namespace Database\Factories;

use App\Models\InternalBudget;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternalBudget> */
class InternalBudgetFactory extends Factory
{
    protected $model = InternalBudget::class;

    public function definition(): array
    {
        return [
            'budget_code' => 'BDG-'.fake()->unique()->numerify('########'),
            'project_id' => Project::factory(),
            'version' => 1,
            'status' => 'draft',
        ];
    }
}