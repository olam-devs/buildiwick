<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** "Other" as a fifth main category, asked for by the owner on 2026-11-18 for costs that fit none of the four. */
    public function up(): void
    {
        if (! DB::table('lookups')->where('type', 'main_category')->where('code', 'other')->exists()) {
            DB::table('lookups')->insert([
                'type' => 'main_category', 'code' => 'other', 'label' => 'Other',
                'sort_order' => (int) DB::table('lookups')->where('type', 'main_category')->max('sort_order') + 1,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! DB::table('expense_requests')->where('main_category', 'other')->exists()) {
            DB::table('lookups')->where('type', 'main_category')->where('code', 'other')->delete();
        }
    }
};
