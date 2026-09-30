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
            'type' => 'internal_budget_status',
            'code' => 'finalized',
            'label' => 'Finalized',
            'parent_type' => null,
            'parent_code' => null,
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('internal_budgets', function (Blueprint $table) {
            $table->foreignId('finalized_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable()->after('finalized_by');
        });

        Schema::table('internal_budget_entries', function (Blueprint $table) {
            $table->foreignId('entered_by')->nullable()->after('unit_cost')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('internal_budget_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entered_by');
        });

        Schema::table('internal_budgets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn('finalized_at');
        });

        DB::table('lookups')->where('type', 'internal_budget_status')->where('code', 'finalized')->delete();
    }
};