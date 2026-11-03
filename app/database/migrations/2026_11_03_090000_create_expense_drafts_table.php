<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unsubmitted Record Expense forms, kept per person and project so the entry can be continued later
 * on any device. A draft is removed when its expenses are saved or when it is discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->longText('data');
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_drafts');
    }
};
