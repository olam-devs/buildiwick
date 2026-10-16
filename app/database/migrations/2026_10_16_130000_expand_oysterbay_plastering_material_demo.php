<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $project = DB::table('projects')->where('project_code', 'BWG-OYS-BW-2026-DEMO')->first();
        if (! $project) {
            return;
        }

        $phase = DB::table('project_phases')->where('project_id', $project->id)->where('stage_code', 'plastering')->first();
        if (! $phase) {
            return;
        }

        $budget = DB::table('internal_budgets')->where('project_id', $project->id)
            ->where('project_phase_id', $phase->id)->where('main_category', 'materials')
            ->whereIn('status', ['approved', 'finalized', 'draft'])->orderByDesc('version')->first();
        if (! $budget) {
            return;
        }

        $now = now();
        $lines = [
            ['PLA-A-28', 'A', 'Ground Floor', 'concrete_cement', 'Cement', 135, 'bag', 19500],
            ['PLA-A-29', 'A', 'Ground Floor', 'aggregates', 'Sand', 18, 'm3', 32000],
            ['PLA-B-30', 'B', '1st Floor', 'concrete_cement', 'Cement', 150, 'bag', 19500],
            ['PLA-B-31', 'B', '1st Floor', 'aggregates', 'Sand', 21, 'm3', 32000],
            ['PLA-C-32', 'C', 'Terrace Floor', 'concrete_cement', 'Cement', 52, 'bag', 19500],
            ['PLA-C-33', 'C', 'Terrace Floor', 'aggregates', 'Sand', 7, 'm3', 32000],
        ];

        foreach ($lines as [$reference, $code, $name, $subcategory, $description, $quantity, $unit, $unitCost]) {
            DB::table('internal_budget_entries')->updateOrInsert(
                ['internal_budget_id' => $budget->id, 'item_reference' => $reference],
                [
                    'project_phase_id' => $phase->id,
                    'work_area' => 'Main Building',
                    'work_section' => 'Plastering',
                    'construction_category' => 'Walls - 15 mm thick, mix 1:4',
                    'work_package_code' => $code,
                    'work_package_name' => $name,
                    'main_category' => 'materials',
                    'subcategory' => $subcategory,
                    'item_description' => $description,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'unit_cost' => $unitCost,
                    'entered_by' => $budget->created_by,
                    'notes' => 'Realistic demo line based on the supplied plastering material schedule.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        DB::table('internal_budget_entries')->where('internal_budget_id', $budget->id)
            ->whereIn('item_description', ['Cement for plaster and render', 'Plastering sand'])->delete();
    }

    public function down(): void
    {
        $projectId = DB::table('projects')->where('project_code', 'BWG-OYS-BW-2026-DEMO')->value('id');
        if (! $projectId) {
            return;
        }
        $budgetIds = DB::table('internal_budgets')->where('project_id', $projectId)->pluck('id');
        DB::table('internal_budget_entries')->whereIn('internal_budget_id', $budgetIds)
            ->whereIn('item_reference', ['PLA-A-28', 'PLA-A-29', 'PLA-B-30', 'PLA-B-31', 'PLA-C-32', 'PLA-C-33'])->delete();
    }
};
