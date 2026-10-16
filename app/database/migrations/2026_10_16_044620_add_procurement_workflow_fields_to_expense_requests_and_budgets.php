<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('internal_budget_entries', function (Blueprint $table) {
            $table->string('construction_category')->nullable()->after('work_section');
            $table->string('work_package_code', 50)->nullable()->after('construction_category');
            $table->string('work_package_name')->nullable()->after('work_package_code');
            $table->index(['project_phase_id', 'main_category', 'work_area'], 'budget_phase_category_area_index');
        });

        Schema::table('expense_requests', function (Blueprint $table) {
            $table->foreignId('supplier_request_group_id')->nullable()->after('request_line_number')
                ->constrained('supplier_request_groups')->nullOnDelete();
            $table->string('procurement_stage')->default('site_review')->after('supplier_request_group_id');
            $table->string('work_area')->nullable()->after('project_phase_id');
            $table->string('construction_category')->nullable()->after('work_area');
            $table->string('work_package_code', 50)->nullable()->after('construction_category');
            $table->string('work_package_name')->nullable()->after('work_package_code');
            $table->boolean('is_new_item')->default(false)->after('item_description');
            $table->foreignId('verified_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->index(['project_id', 'procurement_stage'], 'request_project_procurement_stage_index');
            $table->index(['supplier_request_group_id', 'status'], 'request_supplier_group_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropIndex('request_project_procurement_stage_index');
            $table->dropIndex('request_supplier_group_status_index');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('supplier_request_group_id');
            $table->dropColumn([
                'procurement_stage', 'work_area', 'construction_category', 'work_package_code',
                'work_package_name', 'is_new_item', 'verified_at',
            ]);
        });

        Schema::table('internal_budget_entries', function (Blueprint $table) {
            $table->dropIndex('budget_phase_category_area_index');
            $table->dropColumn(['construction_category', 'work_package_code', 'work_package_name']);
        });
    }
};
