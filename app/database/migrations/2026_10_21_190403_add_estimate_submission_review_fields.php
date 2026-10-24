<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('lookups')->insertOrIgnore([
            'type' => 'estimate_status',
            'code' => 'submitted',
            'label' => 'Submitted',
            'parent_type' => null,
            'parent_code' => null,
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('project_estimates', function (Blueprint $table): void {
            // Who may edit a draft: 'estimator' (assigned Estimator/QS and Admin) or 'admin' (Admin only).
            // Null on historical drafts, which keep the original estimator-editable behaviour.
            $table->string('editor_role')->nullable()->after('status');
            $table->foreignId('submitted_by')->nullable()->after('issued_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('returned_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('review_notes')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_estimates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['editor_role', 'submitted_at', 'returned_at', 'review_notes']);
        });
        DB::table('lookups')->where('type', 'estimate_status')->where('code', 'submitted')->delete();
    }
};
