<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_entries', function (Blueprint $table) {
            $table->decimal('client_unit_rate_override', 14, 2)->nullable()->after('markup_percent');
            $table->decimal('line_total_override', 14, 2)->nullable()->after('client_unit_rate_override');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_entries', function (Blueprint $table) {
            $table->dropColumn(['client_unit_rate_override', 'line_total_override']);
        });
    }
};
