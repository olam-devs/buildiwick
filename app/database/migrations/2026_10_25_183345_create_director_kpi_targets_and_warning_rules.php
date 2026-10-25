<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Director-set targets for a period, company-wide (project_id null) or for one project.
        Schema::create('director_kpi_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('metric');
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 16, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['period_start', 'period_end']);
        });

        // Warning thresholds: project_id null rows are the company defaults; project rows override them.
        Schema::create('project_warning_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('rule');
            $table->decimal('threshold', 16, 2);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'rule']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_warning_rules');
        Schema::dropIfExists('director_kpi_targets');
    }
};
