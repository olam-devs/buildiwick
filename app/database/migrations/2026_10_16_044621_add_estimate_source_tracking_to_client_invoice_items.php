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
        Schema::table('client_invoice_items', function (Blueprint $table) {
            $table->foreignId('estimate_entry_id')->nullable()->after('project_phase_id')->constrained('estimate_entries')->nullOnDelete();
            $table->string('work_area')->nullable()->after('estimate_entry_id');
            $table->string('work_section')->nullable()->after('work_area');
            $table->string('item_reference', 100)->nullable()->after('work_section');
            $table->decimal('contract_amount', 14, 2)->nullable()->after('unit_price');
            $table->decimal('claim_amount', 14, 2)->nullable()->after('contract_amount');
            $table->index(['estimate_entry_id', 'client_invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_invoice_items', function (Blueprint $table) {
            $table->dropIndex(['estimate_entry_id', 'client_invoice_id']);
            $table->dropConstrainedForeignId('estimate_entry_id');
            $table->dropColumn(['work_area', 'work_section', 'item_reference', 'contract_amount', 'claim_amount']);
        });
    }
};
