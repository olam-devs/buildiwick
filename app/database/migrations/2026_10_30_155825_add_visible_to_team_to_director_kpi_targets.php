<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A project KPI target the Director chose to show to the project's assigned PMs and Accountants. */
    public function up(): void
    {
        Schema::table('director_kpi_targets', function (Blueprint $table): void {
            $table->boolean('visible_to_team')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('director_kpi_targets', function (Blueprint $table): void {
            $table->dropColumn('visible_to_team');
        });
    }
};
