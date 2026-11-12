<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The Google Sheet tab (sheetId) where Data Entry types this project's lines. */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('sheet_tab_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['sheet_tab_id']);
            $table->dropColumn('sheet_tab_id');
        });
    }
};
