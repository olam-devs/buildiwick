<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Undoes the Materials copy of "Tools & Small Equipment": every category can already pick any subcategory
     * (the "Other" group in the form), so the copy only showed the option twice. Lines and drafts using it
     * move to the original code.
     */
    public function up(): void
    {
        DB::table('expense_requests')->where('subcategory', 'materials_tools')->update(['subcategory' => 'tools_small_equipment']);
        foreach (DB::table('expense_drafts')->where('data', 'like', '%materials_tools%')->get(['id', 'data']) as $draft) {
            DB::table('expense_drafts')->where('id', $draft->id)->update(['data' => str_replace('"materials_tools"', '"tools_small_equipment"', $draft->data)]);
        }
        DB::table('lookups')->where('type', 'subcategory')->where('code', 'materials_tools')->delete();
    }

    public function down(): void {}
};
