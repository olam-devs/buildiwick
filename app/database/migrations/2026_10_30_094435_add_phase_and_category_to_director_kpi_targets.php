<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A KPI target can now track one phase (with its subphases) and/or one main category inside a project. */
    public function up(): void
    {
        Schema::table('director_kpi_targets', function (Blueprint $table): void {
            $table->foreignId('project_phase_id')->nullable()->after('project_id')->constrained('project_phases')->cascadeOnDelete();
            $table->string('main_category')->nullable()->after('project_phase_id');
        });

        // Project statuses used in the client's tracker.
        foreach ([['near_closure', 'Near closure', 5], ['overheads', 'Overheads', 6]] as [$code, $label, $order]) {
            DB::table('lookups')->insertOrIgnore([
                'type' => 'project_status', 'code' => $code, 'label' => $label, 'parent_type' => null, 'parent_code' => null,
                'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('director_kpi_targets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_phase_id');
            $table->dropColumn('main_category');
        });
        DB::table('lookups')->where('type', 'project_status')->whereIn('code', ['near_closure', 'overheads'])->delete();
    }
};
