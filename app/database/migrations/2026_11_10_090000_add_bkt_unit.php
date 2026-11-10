<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** BKT (bucket), asked for by the client. */
    public function up(): void
    {
        $existing = DB::table('lookups')->where('type', 'unit')->where('code', 'bkt');
        if ($existing->exists()) {
            $existing->update(['label' => 'BKT', 'is_active' => true, 'updated_at' => now()]);

            return;
        }
        DB::table('lookups')->insert([
            'type' => 'unit', 'code' => 'bkt', 'label' => 'BKT',
            'sort_order' => (int) DB::table('lookups')->where('type', 'unit')->max('sort_order') + 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! DB::table('expense_requests')->where('unit', 'bkt')->exists()) {
            DB::table('lookups')->where('type', 'unit')->where('code', 'bkt')->delete();
        }
    }
};
