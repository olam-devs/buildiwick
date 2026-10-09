<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->string('request_type')->default('item')->after('project_phase_id')->index();
            $table->decimal('requested_amount', 14, 2)->nullable()->after('total_amount');
        });

        Schema::table('expense_request_approvals', function (Blueprint $table) {
            $table->string('approval_stage')->default('project_manager')->after('expense_request_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('expense_request_approvals', function (Blueprint $table) {
            $table->dropIndex(['approval_stage']);
            $table->dropColumn('approval_stage');
        });

        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropIndex(['request_type']);
            $table->dropColumn(['request_type', 'requested_amount']);
        });
    }
};
