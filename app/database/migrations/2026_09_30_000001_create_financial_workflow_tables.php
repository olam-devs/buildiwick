<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_identifier')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['name', 'is_active']);
        });

        Schema::create('project_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('stage_code'); // construction_stage lookup code; other_general is not a phase
            $table->unsignedTinyInteger('phase_order');
            $table->string('status')->default('active');
            $table->date('started_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'stage_code']);
            $table->unique(['project_id', 'phase_order']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('project_estimates', function (Blueprint $table) {
            $table->id();
            $table->string('estimate_code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft');
            $table->date('prepared_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('estimate_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_phase_id')->constrained()->cascadeOnDelete();
            $table->string('main_category');
            $table->string('subcategory')->nullable();
            $table->text('item_description');
            $table->string('supplier_fundi_client')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('markup_percent', 7, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['project_phase_id', 'main_category']);
        });

        Schema::create('internal_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('budget_code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('internal_budget_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internal_budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_phase_id')->constrained()->cascadeOnDelete();
            $table->string('main_category');
            $table->string('subcategory')->nullable();
            $table->text('item_description');
            $table->string('supplier_fundi_client')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_cost', 14, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['project_phase_id', 'main_category']);
        });

        Schema::create('expense_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_phase_id')->constrained()->cascadeOnDelete();
            $table->string('main_category');
            $table->string('subcategory')->nullable();
            $table->text('item_description');
            $table->string('supplier_fundi_client')->nullable();
            $table->string('invoice_receipt_no')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2);
            $table->date('requested_at');
            $table->string('status')->default('pending_approval');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evidence_link')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['project_phase_id', 'status']);
        });

        Schema::create('expense_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_request_id')->constrained()->cascadeOnDelete();
            $table->string('decision'); // approved or rejected; one immutable decision per row
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['expense_request_id', 'decided_at']);
        });

        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_code')->unique();
            $table->foreignId('expense_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_phase_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('payment_date');
            $table->string('payment_method');
            $table->string('invoice_receipt_no')->nullable();
            $table->string('evidence_link')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['expense_request_id', 'payment_date']);
            $table->index(['project_id', 'project_phase_id']);
        });

        Schema::create('client_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('client_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_phase_id')->constrained()->cascadeOnDelete();
            $table->string('main_category');
            $table->string('subcategory')->nullable();
            $table->text('item_description');
            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['project_phase_id', 'main_category']);
        });

        Schema::create('client_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_code')->unique();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('payment_date');
            $table->string('payment_method');
            $table->string('reference')->nullable();
            $table->string('evidence_link')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['client_invoice_id', 'payment_date']);
            $table->index(['project_id', 'client_id']);
        });

        Schema::create('project_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_phase_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('documentable');
            $table->string('document_type')->default('other');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'document_type']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('project_phase_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_phase_id');
        });

        Schema::dropIfExists('project_documents');
        Schema::dropIfExists('client_payments');
        Schema::dropIfExists('client_invoice_items');
        Schema::dropIfExists('client_invoices');
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expense_request_approvals');
        Schema::dropIfExists('expense_requests');
        Schema::dropIfExists('internal_budget_entries');
        Schema::dropIfExists('internal_budgets');
        Schema::dropIfExists('estimate_entries');
        Schema::dropIfExists('project_estimates');
        Schema::dropIfExists('project_phases');
        Schema::dropIfExists('clients');
    }
};