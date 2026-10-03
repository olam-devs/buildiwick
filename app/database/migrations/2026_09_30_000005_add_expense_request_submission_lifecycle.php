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
            'type' => 'expense_request_status',
            'code' => 'cancelled',
            'label' => 'Cancelled',
            'parent_type' => null,
            'parent_code' => null,
            'sort_order' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('expense_requests', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('requested_at');
            $table->decimal('approved_amount', 14, 2)->nullable()->after('total_amount');
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
        });

        DB::table('expense_requests')->whereNull('submitted_at')->update(['submitted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['submitted_at', 'approved_amount', 'cancelled_at']);
        });

    }
};