<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Phases become a tree: phase › optional subphase › optional sub-subphase, each freely named. */
    public function up(): void
    {
        Schema::table('project_phases', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('project_id')->constrained('project_phases')->cascadeOnDelete();
            $table->index(['project_id', 'parent_id']);
        });
        // Order is now per parent (subphases number from 1 under each phase), so it can no longer be unique per project.
        Schema::table('project_phases', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'phase_order']);
            $table->index(['project_id', 'phase_order']);
        });
    }

    public function down(): void
    {
        Schema::table('project_phases', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'phase_order']);
            $table->unique(['project_id', 'phase_order']);
        });
        Schema::table('project_phases', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'parent_id']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
