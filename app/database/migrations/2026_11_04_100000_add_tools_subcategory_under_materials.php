<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** "Tools & Small Equipment" also under Materials (the client's registers file tools there too); codes are unique, so it gets its own. */
    public function up(): void
    {
        if (! DB::table('lookups')->where('type', 'subcategory')->where('code', 'materials_tools')->exists()) {
            DB::table('lookups')->insert([
                'type' => 'subcategory', 'code' => 'materials_tools', 'label' => 'Tools & Small Equipment', 'parent_type' => 'main_category', 'parent_code' => 'materials',
                'sort_order' => 119, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! DB::table('expense_requests')->where('subcategory', 'materials_tools')->exists()) {
            DB::table('lookups')->where('type', 'subcategory')->where('code', 'materials_tools')->delete();
        }
    }
};
