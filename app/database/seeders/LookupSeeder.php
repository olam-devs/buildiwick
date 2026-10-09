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
            ['tax_on_materials', 'Tax on Materials'],
            ['other_materials', 'Other Materials'],
        ], parentType: 'main_category', parentCode: 'materials');

        $this->seedType('subcategory', [
            ['direct_labour', 'Direct Labour'],
            ['fundi_labour', 'Fundi Labour'],
            ['subcontractor', 'Subcontractor'],
            ['salary_wages', 'Salaries & Wages'],
            ['casual_labour', 'Casual Labour'],
            ['professional_services', 'Professional Services'],
            ['other', 'Other'],
        ], parentType: 'main_category', parentCode: 'labour_subcontracts', startOrder: 100);

        $this->seedType('subcategory', [
            ['transport_handling', 'Transport / Handling'],
            ['delivery_haulage', 'Delivery & Haulage'],
            ['equipment_hire', 'Equipment Hire'],
            ['plant_machinery_hire', 'Plant & Machinery Hire'],
            ['tools_small_equipment', 'Tools & Small Equipment'],
            ['fuel_lubricants', 'Fuel & Lubricants'],
        ], parentType: 'main_category', parentCode: 'logistics_equipment', startOrder: 200);

        $this->seedType('subcategory', [
            ['temporary_utilities', 'Temporary Utilities'],
            ['ppe_welfare', 'PPE / Welfare'],
            ['site_cleaning_housekeeping', 'Site Cleaning & Housekeeping'],
            ['site_security', 'Site Security'],
            ['site_office_accommodation', 'Site Office & Accommodation'],
            ['permits_fees', 'Permits & Fees'],
            ['communications', 'Communications'],
            ['insurance_bonds', 'Insurance & Bonds'],
        ], parentType: 'main_category', parentCode: 'preliminaries_overheads', startOrder: 300);

        $this->seedType('unit', [
            ['pc', 'PIECE'], ['bag', 'BAG'], ['kg', 'KG'], ['tonne', 'TONNE'], ['bundle', 'BUNDLE'],
            ['m', 'M'], ['m2', 'M²'], ['m3', 'M³'], ['l', 'L'], ['sheet', 'SHEET'], ['roll', 'ROLL'],
            ['box', 'BOX'], ['trip', 'TRIP'], ['load', 'LOAD'], ['km', 'KM'], ['day', 'DAY'],
            ['worker_day', 'WORKER-DAY'], ['week', 'WEEK'], ['month', 'MONTH'], ['hour', 'HOUR'],
            ['worker_hour', 'WORKER-HOUR'], ['job', 'JOB'], ['task', 'TASK'], ['service', 'SERVICE'],
            ['point', 'POINT'], ['person', 'PERSON'], ['ls', 'LS'], ['no', 'NO.'],
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
            ['active', 'Active'], ['planning', 'Planning'], ['on_hold', 'On Hold'], ['completed', 'Completed'],
        ]);

        $this->seedType('project_phase_status', [
            ['not_started', 'Not Started'], ['active', 'Active'], ['on_hold', 'On Hold'], ['completed', 'Completed'],
        ]);

        $this->seedType('estimate_status', [
            ['draft', 'Draft'], ['issued', 'Issued'], ['superseded', 'Superseded'],
        ]);

        $this->seedType('internal_budget_status', [
            ['draft', 'Draft'], ['finalized', 'Finalized'], ['approved', 'Approved'], ['superseded', 'Superseded'],
        ]);

        $this->seedType('expense_request_status', [
            ['pending_approval', 'Pending PM Approval'], ['pending_director_approval', 'Pending Director Approval'],
            ['approved', 'Director Approved'], ['rejected', 'Rejected'], ['cancelled', 'Cancelled'],
        ]);

        $this->seedType('client_invoice_status', [
            ['draft', 'Draft'], ['issued', 'Issued'], ['cancelled', 'Cancelled'],
        ]);

        $this->seedType('project_document_type', [
            ['estimate', 'Estimate'], ['budget', 'Budget'], ['request_evidence', 'Request Evidence'],
            ['payment_receipt', 'Payment Receipt'], ['invoice', 'Invoice'], ['contract', 'Contract'],
            ['drawing', 'Drawing'], ['other', 'Other'],
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
