<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Versions finalized before Director verification existed are accepted as
        // historical baselines. New PM submissions remain `finalized` until a
        // Director explicitly changes them to `approved`.
        DB::table('internal_budgets')->where('status', 'finalized')->update([
            'status' => 'approved',
            'approved_at' => DB::raw('COALESCE(finalized_at, updated_at)'),
        ]);
    }

    public function down(): void
    {
        // Approval may have been explicit after this migration, so reversing it
        // would destroy review state. The schema itself is unchanged.
    }
};
