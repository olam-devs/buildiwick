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
        Schema::create('supplier_request_groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('request_batch_code')->index();
            $table->foreignId('supplier_payee_id')->constrained('supplier_payees')->restrictOnDelete();
            $table->foreignId('payment_destination_id')->nullable()->constrained('supplier_payment_destinations')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->string('evidence_link')->nullable();
            $table->string('evidence_original_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['request_batch_code', 'status']);
            $table->index(['supplier_payee_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_request_groups');
    }
};
