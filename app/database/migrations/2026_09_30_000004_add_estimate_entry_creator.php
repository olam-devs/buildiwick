<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_entries', function (Blueprint $table) {
            $table->foreignId('entered_by')->nullable()->after('markup_percent')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estimate_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entered_by');
        });
    }
};