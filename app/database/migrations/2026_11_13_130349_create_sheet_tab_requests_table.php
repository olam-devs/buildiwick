<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tab added to the entry sheet by hand: a new project waiting for the Director's approval.
     */
    public function up(): void
    {
        Schema::create('sheet_tab_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sheet_tab_id')->unique();
            $table->string('title');
            $table->unsignedInteger('line_count')->default(0);
            $table->boolean('layout_ok')->default(true);
            $table->string('status')->default('pending');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('tab_ready_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_tab_requests');
    }
};
