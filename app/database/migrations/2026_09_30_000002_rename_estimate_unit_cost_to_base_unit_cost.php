<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_entries', function (Blueprint $table) {
            $table->renameColumn('unit_cost', 'base_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_entries', function (Blueprint $table) {
            $table->renameColumn('base_unit_cost', 'unit_cost');
        });
    }
};