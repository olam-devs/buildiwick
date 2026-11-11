<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** GAL (gallon), asked for by the client. */
    public function up(): void
    {
        $existing = DB::table('lookups')->where('type', 'unit')->where('code', 'gal');
        if ($existing->exists()) {
            $existing->update(['label' => 'GAL', 'is_active' => true, 'updated_at' => now()]);

            return;
        }
        DB::table('lookups')->insert([
            'type' => 'unit', 'code' => 'gal', 'label' => 'GAL',
            'sort_order' => (int) DB::table('lookups')->where('type', 'unit')->max('sort_order') + 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! DB::table('expense_requests')->where('unit', 'gal')->exists()) {
            DB::table('lookups')->where('type', 'unit')->where('code', 'gal')->delete();
        }
    }
};
