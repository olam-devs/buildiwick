<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->foreignId('internal_budget_entry_id')->nullable()->after('labour_agreement_id')
                ->constrained('internal_budget_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('internal_budget_entry_id');
        });
    }
};
