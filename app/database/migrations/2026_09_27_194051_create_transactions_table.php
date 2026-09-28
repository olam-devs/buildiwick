<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per transaction — the exact shape of the Excel's per-project tabs,
     * now shared across every project instead of duplicated onto 16 sheets.
     * `balance` is intentionally not a column: it's total_amount - amount_paid_received,
     * computed on the model, so it can never disagree with its own inputs.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique(); // set on-device at capture time; offline sync key
            $table->string('transaction_code')->unique(); // e.g. BWG-KIN-TXN-0001, matches their MWN-TXN-0001 style
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('transaction_type')->default('expense'); // lookups: type=transaction_type
            $table->string('main_category'); // lookups: type=main_category
            $table->string('subcategory')->nullable(); // lookups: type=subcategory
            $table->text('item_description');
            $table->string('supplier_fundi_client')->nullable(); // free text, matches the real tracker today
            $table->string('invoice_receipt_no')->nullable();

            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit')->nullable(); // lookups: type=unit
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2);
            $table->decimal('amount_paid_received', 14, 2)->default(0);

            $table->string('payment_status')->default('to_confirm'); // lookups: type=payment_status
            $table->string('payment_method')->nullable(); // lookups: type=payment_method

            $table->foreignId('agreement_id')->nullable()->constrained('labour_agreements')->nullOnDelete();
            $table->string('work_area_stage')->nullable(); // lookups: type=construction_stage

            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->string('evidence_link')->nullable(); // uploaded file path, or a pasted link during transition
            $table->text('notes')->nullable();
            $table->string('data_status')->default('needs_confirmation'); // lookups: type=data_status

            $table->timestamp('synced_at')->nullable(); // null = still queued on the phone
            $table->date('transaction_date');
            $table->timestamps();

            $table->index(['project_id', 'main_category']);
            $table->index(['project_id', 'work_area_stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
