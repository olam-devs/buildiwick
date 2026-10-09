<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->foreignId('labour_agreement_id')->nullable()->after('project_phase_id')
                ->constrained('labour_agreements')->nullOnDelete();
        });

        $parents = [
            'tools_small_equipment' => 'logistics_equipment',
            'transport_handling' => 'logistics_equipment',
            'equipment_hire' => 'logistics_equipment',
            'temporary_utilities' => 'preliminaries_overheads',
            'ppe_welfare' => 'preliminaries_overheads',
            'site_cleaning_housekeeping' => 'preliminaries_overheads',
        ];
        foreach ($parents as $code => $parent) {
            DB::table('lookups')->where('type', 'subcategory')->where('code', $code)->update(['parent_code' => $parent]);
        }

        $this->insertSubcategories('labour_subcontracts', [
            'salary_wages' => 'Salaries & Wages',
            'casual_labour' => 'Casual Labour',
            'professional_services' => 'Professional Services',
        ], 106);
        $this->insertSubcategories('logistics_equipment', [
            'fuel_lubricants' => 'Fuel & Lubricants',
            'plant_machinery_hire' => 'Plant & Machinery Hire',
            'delivery_haulage' => 'Delivery & Haulage',
        ], 200);
        $this->insertSubcategories('preliminaries_overheads', [
            'site_security' => 'Site Security',
            'site_office_accommodation' => 'Site Office & Accommodation',
            'permits_fees' => 'Permits & Fees',
            'communications' => 'Communications',
            'insurance_bonds' => 'Insurance & Bonds',
        ], 300);
    }

    public function down(): void
    {
        DB::table('lookups')->where('type', 'subcategory')->whereIn('code', [
            'salary_wages', 'casual_labour', 'professional_services', 'fuel_lubricants',
            'plant_machinery_hire', 'delivery_haulage', 'site_security',
            'site_office_accommodation', 'permits_fees', 'communications', 'insurance_bonds',
        ])->delete();

        foreach (['tools_small_equipment', 'transport_handling', 'ppe_welfare', 'site_cleaning_housekeeping'] as $code) {
            DB::table('lookups')->where('type', 'subcategory')->where('code', $code)->update(['parent_code' => 'materials']);
        }
        foreach (['equipment_hire', 'temporary_utilities'] as $code) {
            DB::table('lookups')->where('type', 'subcategory')->where('code', $code)->update(['parent_code' => 'labour_subcontracts']);
        }

        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('labour_agreement_id');
        });
    }

    private function insertSubcategories(string $parent, array $items, int $startOrder): void
    {
        $index = 0;
        foreach ($items as $code => $label) {
            DB::table('lookups')->updateOrInsert(
                ['type' => 'subcategory', 'code' => $code],
                ['label' => $label, 'parent_type' => 'main_category', 'parent_code' => $parent,
                    'sort_order' => $startOrder + $index, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
            );
            $index++;
        }
    }
};
