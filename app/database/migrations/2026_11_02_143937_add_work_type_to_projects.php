<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** "Type of Work" from the client's Active Projects list (Construction, Finishing works, Labour only, MEP …). */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('work_type', 80)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('work_type');
        });
    }
};
