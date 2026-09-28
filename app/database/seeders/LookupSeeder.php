<?php

namespace Database\Seeders;

use App\Models\Lookup;
use Illuminate\Database\Seeder;

/**
 * Every value here is transcribed from the client's real "Lists & Settings" tab
 * (Buildiwick_All_Projects_Interim_Tracker_4.xlsx) — nothing invented. The one
 * exception is the unit "M³": the source cell was corrupted on export ("M ")
 * but real transaction rows (e.g. "20 M³ AGGREGATE") confirm cubic metres.
 */
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedType('main_category', [
            ['materials', 'Materials'],
            ['labour_subcontracts', 'Labour & Subcontracts'],
            ['logistics_equipment', 'Logistics & Equipment'],
            ['preliminaries_overheads', 'Preliminaries & Site Overheads'],
        ]);

        $this->seedType('subcategory', [
            ['concrete_cement', 'Concrete & Cement'],
            ['aggregates', 'Aggregates'],
            ['masonry', 'Masonry'],
            ['plumbing_drainage', 'Plumbing & Drainage'],
            ['waterproofing', 'Waterproofing'],
            ['reinforcement_steel', 'Reinforcement Steel'],
            ['timber_formwork', 'Timber & Formwork'],
            ['electrical', 'Electrical'],
            ['finishes', 'Finishes'],
            ['tools_small_equipment', 'Tools & Small Equipment'],
            ['transport_handling', 'Transport / Handling'],
            ['ppe_welfare', 'PPE / Welfare'],
            ['site_cleaning_housekeeping', 'Site Cleaning & Housekeeping'],
            ['tax_on_materials', 'Tax on Materials'],
            ['other_materials', 'Other Materials'],
        ], parentType: 'main_category', parentCode: 'materials');

        $this->seedType('subcategory', [
            ['direct_labour', 'Direct Labour'],
            ['fundi_labour', 'Fundi Labour'],
            ['subcontractor', 'Subcontractor'],
            ['equipment_hire', 'Equipment Hire'],
            ['temporary_utilities', 'Temporary Utilities'],
            ['other', 'Other'],
        ], parentType: 'main_category', parentCode: 'labour_subcontracts', startOrder: 100);

        $this->seedType('unit', [
            ['pc', 'PC'], ['bag', 'BAG'], ['kg', 'KG'], ['m', 'M'], ['m3', 'M³'],
            ['l', 'L'], ['roll', 'ROLL'], ['trip', 'TRIP'], ['day', 'DAY'],
            ['week', 'WEEK'], ['month', 'MONTH'], ['ls', 'LS'], ['hour', 'HOUR'], ['no', 'NO.'],
        ]);

        $this->seedType('payment_status', [
            ['to_confirm', 'To Confirm'], ['unpaid', 'Unpaid'],
            ['part_paid', 'Part Paid'], ['paid', 'Paid'],
        ]);

        $this->seedType('payment_method', [
            ['cash', 'Cash'], ['bank_transfer', 'Bank Transfer'],
            ['mobile_money', 'Mobile Money'], ['cheque', 'Cheque'], ['other', 'Other'],
        ]);

        $this->seedType('data_status', [
            ['needs_confirmation', 'Needs Confirmation'], ['pending_approval', 'Pending Approval'],
            ['approved', 'Approved'], ['complete', 'Complete'],
        ]);

        $this->seedType('labour_status', [
            ['draft', 'Draft'], ['active', 'Active'],
            ['completed', 'Completed'], ['cancelled', 'Cancelled'],
        ]);

        $this->seedType('construction_stage', [
            ['preliminaries', 'Preliminaries'], ['substructure', 'Substructure (Foundation)'],
            ['superstructure', 'Superstructure'], ['walling', 'Walling'],
            ['roofing', 'Roofing'], ['finishing', 'Finishing'],
            ['external_works', 'External Works'], ['other_general', 'Other / General'],
        ]);

        $this->seedType('transaction_type', [
            ['expense', 'Expense'],
        ]);

        $this->seedType('project_status', [
            ['active', 'Active'], ['planning', 'Planning'],
        ]);
    }

    private function seedType(string $type, array $rows, ?string $parentType = null, ?string $parentCode = null, int $startOrder = 0): void
    {
        foreach ($rows as $i => [$code, $label]) {
            Lookup::updateOrCreate(
                ['type' => $type, 'code' => $code],
                [
                    'label' => $label,
                    'parent_type' => $parentType,
                    'parent_code' => $parentCode,
                    'sort_order' => $startOrder + $i,
                ]
            );
        }
    }
}
