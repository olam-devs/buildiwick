<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** "AC" (air conditioning) under Materials, asked for by the owner on 2026-11-16 for Data Entry's sheet and form. */
    public function up(): void
    {
        if (! DB::table('lookups')->where('type', 'subcategory')->where('code', 'ac')->exists()) {
            DB::table('lookups')->insert([
                'type' => 'subcategory', 'code' => 'ac', 'label' => 'AC', 'parent_type' => 'main_category', 'parent_code' => 'materials',
                'sort_order' => 119, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! DB::table('expense_requests')->where('subcategory', 'ac')->exists()) {
            DB::table('lookups')->where('type', 'subcategory')->where('code', 'ac')->delete();
        }
    }
};
