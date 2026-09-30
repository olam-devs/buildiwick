<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payees', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('other');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['name', 'is_active']);
        });

        Schema::create('supplier_payment_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_payee_id')->constrained('supplier_payees')->cascadeOnDelete();
            $table->string('payment_method');
            $table->string('account_wallet_name')->nullable();
            $table->string('account_wallet_number')->nullable();
            $table->string('bank_provider_name')->nullable();
            $table->string('branch')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['supplier_payee_id', 'is_active']);
        });

        Schema::table('expense_requests', function (Blueprint $table): void {
            $table->decimal('total_amount', 14, 2)->nullable()->change();
            $table->foreignId('supplier_payee_id')->nullable()->after('supplier_fundi_client')->constrained('supplier_payees')->nullOnDelete();
            $table->decimal('commercial_unit_rate', 14, 2)->nullable()->after('unit_cost');
            $table->decimal('commercial_total', 14, 2)->nullable()->after('commercial_unit_rate');
            $table->string('commercial_payment_method')->nullable()->after('commercial_total');
            $table->text('commercial_payment_destination')->nullable()->after('commercial_payment_method');
            $table->foreignId('commercial_payment_destination_id')->nullable()->after('commercial_payment_destination')->constrained('supplier_payment_destinations')->nullOnDelete();
            $table->text('commercial_notes')->nullable()->after('commercial_payment_destination');
            $table->string('commercial_evidence_link')->nullable()->after('commercial_notes');
            $table->foreignId('commercial_prepared_by')->nullable()->after('commercial_evidence_link')->constrained('users')->nullOnDelete();
            $table->timestamp('commercial_prepared_at')->nullable()->after('commercial_prepared_by');
        });

        Schema::table('expense_request_approvals', function (Blueprint $table): void {
            $table->decimal('approved_amount', 14, 2)->nullable()->after('decision');
            $table->decimal('approved_unit_rate', 14, 2)->nullable()->after('approved_amount');
            $table->decimal('approved_total', 14, 2)->nullable()->after('approved_unit_rate');
            $table->foreignId('supplier_payee_id')->nullable()->after('approved_total')->constrained('supplier_payees')->nullOnDelete();
            $table->string('supplier_name_snapshot')->nullable()->after('supplier_payee_id');
            $table->string('supplier_type_snapshot')->nullable()->after('supplier_name_snapshot');
            $table->string('supplier_contact_snapshot')->nullable()->after('supplier_type_snapshot');
            $table->string('supplier_phone_snapshot')->nullable()->after('supplier_contact_snapshot');
            $table->string('supplier_email_snapshot')->nullable()->after('supplier_phone_snapshot');
            $table->string('payment_method_snapshot')->nullable()->after('supplier_email_snapshot');
            $table->text('payment_destination_snapshot')->nullable()->after('payment_method_snapshot');
            $table->string('commercial_evidence_snapshot')->nullable()->after('payment_destination_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commercial_prepared_by');
            $table->dropColumn([
                'commercial_evidence_link', 'commercial_notes', 'commercial_payment_destination',
                'commercial_payment_method', 'commercial_total', 'commercial_unit_rate',
            ]);
            $table->dropConstrainedForeignId('commercial_payment_destination_id');
            $table->dropConstrainedForeignId('supplier_payee_id');
            $table->decimal('total_amount', 14, 2)->nullable(false)->change();
        });
        Schema::table('expense_request_approvals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_payee_id');
            $table->dropColumn([
                'approved_amount', 'approved_unit_rate', 'approved_total', 'supplier_name_snapshot',
                'supplier_type_snapshot', 'supplier_contact_snapshot', 'supplier_phone_snapshot',
                'supplier_email_snapshot', 'payment_method_snapshot', 'payment_destination_snapshot',
                'commercial_evidence_snapshot',
            ]);
        });
        Schema::dropIfExists('supplier_payment_destinations');
        Schema::dropIfExists('supplier_payees');
    }
};