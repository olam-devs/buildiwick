<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A ledger that holds company running costs (salaries, rent, statutory, subscriptions) rather than
     * a construction project: Director-only and kept out of every project total, ranking and warning.
     * The tracker's "Office running costs, staff, tools & overheads" sheet (status Overheads) is one.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('is_company_overheads')->default(false)->after('status');
        });
        DB::table('projects')->where('status', 'overheads')->update(['is_company_overheads' => true]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('is_company_overheads');
        });
    }
};
