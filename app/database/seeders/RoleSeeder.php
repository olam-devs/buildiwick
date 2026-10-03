<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'admin', 'description' => 'System administrator — full access, all projects'],
            ['name' => 'director', 'description' => 'Director — organization-wide read-only executive analytics'],
            ['name' => 'accounts', 'description' => 'Accounts & Invoicing — bills, expense payments, client invoicing (phase 2)'],
            ['name' => 'estimator_qs', 'description' => 'Estimator / QS — historical pricing only, no cash or profit'],
            ['name' => 'project_manager', 'description' => 'Project Manager — assigned projects, reviews and approves expenses'],
            ['name' => 'site_operator', 'description' => 'Site Operator — write-only expense entry, assigned projects'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
