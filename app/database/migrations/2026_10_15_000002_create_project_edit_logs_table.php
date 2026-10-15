<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_edit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_code');
            $table->unsignedInteger('from_version')->nullable();
            $table->unsignedInteger('to_version')->nullable();
            $table->string('action');
            $table->string('item_description')->nullable();
            $table->text('reason');
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->foreignId('edited_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'document_type']);
            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_edit_logs');
    }
};
