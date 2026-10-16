<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->boolean('pm_edited')->default(false)->after('verified_at');
            $table->string('pm_edit_summary', 1000)->nullable()->after('pm_edited');
        });
    }

    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropColumn(['pm_edited', 'pm_edit_summary']);
        });
    }
};
