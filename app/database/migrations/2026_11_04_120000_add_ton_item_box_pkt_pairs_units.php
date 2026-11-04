<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Units the client asked for. TON and BOX reuse the seeded `tonne` and `box` codes when present. */
    private const UNITS = [['tonne', 'TON'], ['item', 'ITEM'], ['box', 'BOX'], ['pkt', 'PKT'], ['pairs', 'PAIRS']];

    public function up(): void
    {
        $order = (int) DB::table('lookups')->where('type', 'unit')->max('sort_order');
        foreach (self::UNITS as [$code, $label]) {
            $existing = DB::table('lookups')->where('type', 'unit')->where('code', $code);
            if ($existing->exists()) {
                $existing->update(['label' => $label, 'is_active' => true, 'updated_at' => now()]);

                continue;
            }
            DB::table('lookups')->insert([
                'type' => 'unit', 'code' => $code, 'label' => $label, 'sort_order' => ++$order,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['item', 'pkt', 'pairs'] as $code) {
            if (! DB::table('expense_requests')->where('unit', $code)->exists()) {
                DB::table('lookups')->where('type', 'unit')->where('code', $code)->delete();
            }
        }
    }
};
