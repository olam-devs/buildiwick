<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The project tab's title and the project name as the sheet sync last left them, so a tab renamed in the sheet
     * (Data Entry renaming the project) can be told apart from a project renamed in Buildiwick.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('sheet_tab_title', 100)->nullable()->after('sheet_tab_id');
            $table->string('sheet_tab_name', 150)->nullable()->after('sheet_tab_title');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['sheet_tab_title', 'sheet_tab_name']);
        });
    }
};
