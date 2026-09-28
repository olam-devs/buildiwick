<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One demo user per role, using the names already established in the
 * Review Desk sketch, so the mockups and the real backend agree with each
 * other. Password for every seeded account is "password" — dev-only.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $roleId = fn (string $name) => Role::where('name', $name)->value('id');

        $director = User::updateOrCreate(
            ['email' => 'director@buildiwick.test'],
            ['name' => 'Emmanuel (Technical Director)', 'password' => Hash::make('password'), 'role_id' => $roleId('admin'), 'is_active' => true]
        );

        $accounts = User::updateOrCreate(
            ['email' => 'grace@buildiwick.test'],
            ['name' => 'Grace Kileo', 'password' => Hash::make('password'), 'role_id' => $roleId('accounts'), 'is_active' => true]
        );

        $estimator = User::updateOrCreate(
            ['email' => 'twalib@buildiwick.test'],
            ['name' => 'Twalib (Estimator/QS)', 'password' => Hash::make('password'), 'role_id' => $roleId('estimator_qs'), 'is_active' => true]
        );

        $pm = User::updateOrCreate(
            ['email' => 'neema@buildiwick.test'],
            ['name' => 'Neema Mushi', 'password' => Hash::make('password'), 'role_id' => $roleId('project_manager'), 'is_active' => true]
        );

        $siteOperator = User::updateOrCreate(
            ['email' => 'amani@buildiwick.test'],
            ['name' => 'Amani K. (Site Operator)', 'password' => Hash::make('password'), 'role_id' => $roleId('site_operator'), 'is_active' => true]
        );

        // Neema and Amani are scoped to the two projects with real activity so far.
        $projectIds = Project::whereIn('project_code', ['BWG-KIN-2026-001', 'BWG-KICD-2026-001'])->pluck('id');
        $pm->assignedProjects()->syncWithoutDetaching($projectIds);
        $siteOperator->assignedProjects()->syncWithoutDetaching($projectIds);
    }
}
