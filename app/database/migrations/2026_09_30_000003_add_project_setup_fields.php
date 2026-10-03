<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['type' => 'project_status', 'code' => 'on_hold', 'label' => 'On Hold', 'sort_order' => 2],
            ['type' => 'project_status', 'code' => 'completed', 'label' => 'Completed', 'sort_order' => 3],
            ['type' => 'project_phase_status', 'code' => 'not_started', 'label' => 'Not Started', 'sort_order' => 0],
        ] as $lookup) {
            DB::table('lookups')->insertOrIgnore($lookup + [
                'parent_type' => null,
                'parent_code' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('lookups')->where('type', 'project_phase_status')->where('code', 'active')->update(['sort_order' => 1]);
        DB::table('lookups')->where('type', 'project_phase_status')->where('code', 'on_hold')->update(['sort_order' => 2]);
        DB::table('lookups')->where('type', 'project_phase_status')->where('code', 'completed')->update(['sort_order' => 3]);

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->string('contract_reference')->nullable();
            $table->boolean('contract_signed')->default(false);
            $table->date('contract_signed_at')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('expected_completion_date')->nullable();
            $table->text('notes')->nullable();
        });

        Schema::table('project_phases', function (Blueprint $table) {
            $table->date('planned_start_date')->nullable()->after('phase_order');
            $table->date('planned_completion_date')->nullable()->after('planned_start_date');
            $table->date('actual_start_date')->nullable()->after('planned_completion_date');
            $table->date('actual_completion_date')->nullable()->after('actual_start_date');
            $table->decimal('physical_progress_percentage', 5, 2)->nullable()->after('status');
        });

        // Normalize only untouched seven-row seeder-created projects. Never reset a phase set
        // that already has actual dates or a non-initial status.
        $projectIds = DB::table('project_phases')
            ->select('project_id')
            ->groupBy('project_id')
            ->havingRaw('COUNT(*) = 7')
            ->pluck('project_id');

        foreach ($projectIds as $projectId) {
            $phases = DB::table('project_phases')->where('project_id', $projectId)->get();
            if ($phases->every(fn ($phase) => $phase->status === 'active' && $phase->started_at === null && $phase->completed_at === null)) {
                DB::table('project_phases')->where('project_id', $projectId)->where('phase_order', 1)->update(['status' => 'active']);
                DB::table('project_phases')->where('project_id', $projectId)->where('phase_order', '>', 1)->update(['status' => 'not_started']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('project_phases', function (Blueprint $table) {
            $table->dropColumn([
                'planned_start_date', 'planned_completion_date', 'actual_start_date',
                'actual_completion_date', 'physical_progress_percentage',
            ]);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn([
                'contract_reference', 'contract_signed', 'contract_signed_at',
                'planned_start_date', 'expected_completion_date', 'notes',
            ]);
        });
    }
};