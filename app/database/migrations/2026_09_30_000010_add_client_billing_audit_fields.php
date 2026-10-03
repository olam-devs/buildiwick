<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->string('reference')->nullable()->after('invoice_code');
            $table->foreignId('issued_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable()->change();
            $table->foreignId('voided_by')->nullable()->after('issued_at')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
            $table->text('void_reason')->nullable()->after('voided_at');
        });

        Schema::table('client_payments', function (Blueprint $table): void {
            $table->string('transaction_reference')->nullable()->after('payment_method');
            $table->string('evidence_original_name')->nullable()->after('evidence_link');
        });
    }

    public function down(): void
    {
        Schema::table('client_payments', function (Blueprint $table): void {
            $table->dropColumn(['transaction_reference', 'evidence_original_name']);
        });
        Schema::table('client_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('issued_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['reference', 'voided_at', 'void_reason']);
        });
    }
};