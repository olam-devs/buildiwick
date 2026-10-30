<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Subcategories the client's tracker uses ("Lists & Settings") that were missing from the list.
     * Imported lines kept them as plain text; they are linked to the new codes here (same label shown).
     */
    private const ADDED = [
        ['hardware', 'Hardware', 'materials', 115],
        ['roofing_materials', 'Roofing Materials', 'materials', 116],
        ['statutory_tax', 'Statutory & Tax', 'preliminaries_overheads', 140],
        ['staff_salaries', 'Staff Salaries', 'preliminaries_overheads', 141],
        ['office_rent', 'Office Rent', 'preliminaries_overheads', 142],
        ['software_subscriptions', 'Software & Subscriptions', 'preliminaries_overheads', 143],
        ['vehicle_travel', 'Vehicle & Travel', 'preliminaries_overheads', 144],
        ['design_printing', 'Design & Printing', 'preliminaries_overheads', 145],
    ];

    public function up(): void
    {
        foreach (self::ADDED as [$code, $label, $parent, $order]) {
            if (! DB::table('lookups')->where('type', 'subcategory')->where('code', $code)->exists()) {
                DB::table('lookups')->insert([
                    'type' => 'subcategory', 'code' => $code, 'label' => $label, 'parent_type' => 'main_category', 'parent_code' => $parent,
                    'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('expense_requests')->where('subcategory', $label)->update(['subcategory' => $code]);
        }
    }

    public function down(): void
    {
        foreach (self::ADDED as [$code, $label]) {
            DB::table('expense_requests')->where('subcategory', $code)->update(['subcategory' => $label]);
            DB::table('lookups')->where('type', 'subcategory')->where('code', $code)->delete();
        }
    }
};
