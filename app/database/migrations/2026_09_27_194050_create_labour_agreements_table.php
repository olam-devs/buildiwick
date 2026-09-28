<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agreement's own terms only. Instalment payments are NOT a separate table —
     * they are rows in `transactions` (main_category = Labour & Subcontracts) carrying
     * this agreement's id. Paid-to-date and outstanding are computed from those rows,
     * never stored here, so they can't drift out of sync.
     */
    public function up(): void
    {
        Schema::create('labour_agreements', function (Blueprint $table) {
            $table->id();
            $table->string('agreement_code')->unique(); // e.g. AGR-0001
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('fundi_subcontractor');
            $table->text('scope_of_work')->nullable();
            $table->string('labour_type')->nullable(); // lookups: type=subcategory, parent_code=labour_subcontracts
            $table->date('agreement_date');
            $table->decimal('agreed_value', 14, 2);
            $table->date('start_date')->nullable();
            $table->date('expected_completion')->nullable();
            $table->string('contact')->nullable();
            $table->string('status')->default('draft'); // lookups: type=labour_status
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_agreements');
    }
};
