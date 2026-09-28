<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            LookupSeeder::class,
            ProjectSeeder::class,
            UserSeeder::class,
        ]);

        $adminRole = Role::where('name', 'admin')->first();
        User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'admin@buildiwick.test',
            'role_id' => $adminRole->id,
        ]);
    }
}
