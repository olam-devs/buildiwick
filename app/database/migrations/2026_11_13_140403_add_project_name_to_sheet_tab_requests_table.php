<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The project name Data Entry types in row 1 of a new-project tab; the code is made from it.
     */
    public function up(): void
    {
        Schema::table('sheet_tab_requests', function (Blueprint $table) {
            $table->string('project_name', 150)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_tab_requests', function (Blueprint $table) {
            $table->dropColumn('project_name');
        });
    }
};
