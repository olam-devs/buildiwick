<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A clean list of item names for Record Expense (lookup type "material_item"), grouped by main
     * category (parent_code) so the form can suggest the right items for the category chosen. Names
     * only — no amounts or notes. Taken from the most frequent items in the client's tracker; new names
     * typed when recording are added automatically under the category they were recorded in.
     */
    private const ITEMS = [
        'materials' => [
            'Cement', 'Sand', 'Aggregate', 'Ballast', 'Blocks 4"', 'Blocks 6"', 'Binding wire', 'Nails', 'Concrete nails',
            'Roofing nails', 'Mbao 2x2', 'Mbao 2x4', 'Mbao 2x6', 'Mirunda', 'Marine board', 'Plywood', 'Steel bar 8mm',
            'Steel bar 10mm', 'Steel bar 12mm', 'Steel bar 16mm', 'Steel bar 20mm', 'BRC / wire mesh', 'DPM', 'Waterproofing',
            'Concrete premix', 'Bati (roofing sheets)', 'Ridge', 'Gutters', 'PVC pipe 1/2"', 'PVC pipe 3/4"', 'PVC pipe 2"',
            'PVC pipe 4"', 'PVC elbow', 'PPR pipe', 'Tap / fittings', 'Conduit pipe 20mm', 'Conduit pipe 25mm', 'Electrical cable',
            'Flexible wire', 'Switch / socket', 'Metal box', 'Tiles', 'Tile adhesive', 'Grout', 'Gypsum board', 'Ceiling materials',
            'Paint', 'Putty / skimming', 'Glass', 'Aluminium profile', 'Door', 'Window', 'Cutting disc', 'Grinding disc',
            'Circular blade', 'Hardware supplies', 'Tools',
        ],
        'labour_subcontracts' => [
            'Fundi labour', 'Casual labour', 'Mason', 'Carpenter', 'Steel fixer', 'Plumber', 'Electrician', 'Painter',
            'Tiler', 'Welder', 'Site cleaning', 'Subcontract works', 'Technician allowance',
        ],
        'logistics_equipment' => [
            'Transport', 'Truck hire', 'Delivery', 'Equipment hire', 'Excavator hire', 'Concrete mixer hire', 'Vibrator hire',
            'Scaffolding hire', 'Water bowser', 'Diesel / fuel', 'Loading & offloading',
        ],
        'preliminaries_overheads' => [
            'Water', 'Electricity (LUKU)', 'Permit / fees', 'Site security', 'PPE / safety gear', 'First aid', 'Site office',
            'Temporary toilet', 'Communication / airtime', 'Printing', 'Meals / welfare', 'Staff salaries', 'Office rent', 'Vehicle & travel',
        ],
    ];

    public function up(): void
    {
        $now = now();
        $order = 0;
        foreach (self::ITEMS as $category => $items) {
            foreach ($items as $label) {
                DB::table('lookups')->insertOrIgnore([
                    'type' => 'material_item', 'code' => Str::limit(Str::slug($label, '_'), 60, ''), 'label' => $label,
                    'parent_type' => 'main_category', 'parent_code' => $category, 'sort_order' => ++$order, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('lookups')->where('type', 'material_item')->delete();
    }
};
