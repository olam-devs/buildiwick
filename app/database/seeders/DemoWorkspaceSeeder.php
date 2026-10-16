<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Explicitly rebuilds the two-project demo workspace requested for testing. */
class DemoWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoProjectSeeder::class,
            OysterbayBoundaryWallDemoSeeder::class,
            PendingMaterialRequestDemoSeeder::class,
        ]);

        DB::transaction(function (): void {
            Project::whereNotIn('project_code', [
                DemoProjectSeeder::PROJECT_CODE,
                OysterbayBoundaryWallDemoSeeder::PROJECT_CODE,
            ])->get()->each->delete();
        });
    }
}
