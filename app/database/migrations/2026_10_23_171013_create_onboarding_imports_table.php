<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Records imported when an ongoing project is brought into the system, tagged so they stay distinguishable. */
    private const TAGGED_TABLES = ['expense_requests', 'expense_payments', 'client_invoices', 'client_payments'];

    public function up(): void
    {
        Schema::create('onboarding_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('import_code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->unsignedInteger('expense_count')->default(0);
            $table->unsignedInteger('invoice_count')->default(0);
            $table->unsignedInteger('client_payment_count')->default(0);
            $table->decimal('expense_total', 14, 2)->default(0);
            $table->decimal('expense_paid_total', 14, 2)->default(0);
            $table->decimal('invoice_total', 14, 2)->default(0);
            $table->decimal('client_payment_total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('undo_reason')->nullable();
            $table->timestamps();
        });

        foreach (self::TAGGED_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('onboarding_import_id')->nullable()->constrained('onboarding_imports')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TAGGED_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('onboarding_import_id');
            });
        }
        Schema::dropIfExists('onboarding_imports');
    }
};
