<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_payments', function (Blueprint $table): void {
            $table->string('transaction_reference')->nullable()->after('payment_method');
            $table->text('variance_reason')->nullable()->after('transaction_reference');
            $table->string('evidence_original_name')->nullable()->after('evidence_link');
        });
    }

    public function down(): void
    {
        Schema::table('expense_payments', function (Blueprint $table): void {
            $table->dropColumn(['transaction_reference', 'variance_reason', 'evidence_original_name']);
        });
    }
};