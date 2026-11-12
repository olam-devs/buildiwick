<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per line typed in the Google entry sheet, matched by the hidden row key the system writes in column A.
     * It remembers what was last read from the sheet and where the line is now (in the draft, saved, or removed).
     */
    public function up(): void
    {
        Schema::create('sheet_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('row_key', 20)->unique();
            $table->string('sheet_hash', 40);
            $table->string('state', 20)->default('draft');
            $table->foreignId('expense_request_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_lines');
    }
};
