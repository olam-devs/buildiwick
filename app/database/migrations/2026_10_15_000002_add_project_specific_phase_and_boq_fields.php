<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_phases', function (Blueprint $table) {
            $table->string('phase_name')->nullable()->after('stage_code');
        });

        foreach (['estimate_entries', 'internal_budget_entries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('work_area')->nullable()->after('project_phase_id');
                $table->string('work_section')->nullable()->after('work_area');
                $table->string('item_reference', 100)->nullable()->after('work_section');
            });
        }
    }

    public function down(): void
    {
        foreach (['estimate_entries', 'internal_budget_entries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['work_area', 'work_section', 'item_reference']);
            });
        }

        Schema::table('project_phases', function (Blueprint $table) {
            $table->dropColumn('phase_name');
        });
    }
};
