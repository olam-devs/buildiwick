<?php

namespace Database\Seeders;

use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Adds two untouched, multi-item Site Operator requests for PM training. */
class PendingMaterialRequestDemoSeeder extends Seeder
{
    public const TEST_BATCH = 'SRQ-OYS-TEST-001';

    public const PRESENTATION_BATCH = 'SRQ-OYS-PRESENTATION-001';

    public const MIXED_BATCH = 'SRQ-OYS-MIXED-001';

    public function run(): void
    {
        DB::transaction(function (): void {
            $project = Project::where('project_code', OysterbayBoundaryWallDemoSeeder::PROJECT_CODE)->firstOrFail();
            $phase = $project->phases()->where('stage_code', 'plastering')->firstOrFail();
            $siteOperator = User::where('email', 'amani@buildiwick.test')->firstOrFail();
            $budgets = $project->internalBudgets()->where('project_phase_id', $phase->id)
                ->where('status', 'approved')->with('entries')->get()->keyBy('main_category');
            $entries = $budgets->get('materials')->entries->sortBy('id')->values();

            $this->createBatch($project, $phase->id, $siteOperator, $entries, self::TEST_BATCH, 'TEST', [
                ['Cement 42.5N for ground-floor external plaster', '45', 'bag', 'Confirm manufacture date is within the last 60 days; keep bags dry.'],
                ['Washed fine plaster sand for ground-floor walls', '7', 'm3', 'Clean, well graded and free from clay or organic matter.'],
                ['Cement 42.5N for first-floor plastering', '55', 'bag', 'Same approved brand as the ground-floor delivery.'],
                ['Washed fine plaster sand for first-floor walls', '8', 'm3', 'Deliver before the scaffold team starts the north elevation.'],
                ['Cement for terrace parapet plaster and making good', '20', 'bag', 'Keep separately marked for terrace works.'],
                ['Fine plaster sand for terrace parapet works', '3', 'm3', 'Offload near the terrace hoist access without blocking circulation.'],
            ]);

            $this->createBatch($project, $phase->id, $siteOperator, $entries, self::PRESENTATION_BATCH, 'PRESENTATION', [
                ['Cement 42.5N for boundary-wall internal face plaster', '36', 'bag', 'Approved brand only; submit delivery note to the PM.'],
                ['Washed plaster sand for boundary-wall internal face', '6', 'm3', 'Fine clean sand suitable for 15 mm plaster.'],
                ['Cement 42.5N for boundary-wall external face plaster', '40', 'bag', 'Protect the delivery from rain and ground moisture.'],
                ['Washed plaster sand for boundary-wall external face', '7', 'm3', 'Deliver in one load if access conditions allow.'],
                ['Cement for wall-cap plaster, grooves and patch repairs', '18', 'bag', 'Use for wall-cap finishing and approved making-good areas.'],
                ['Fine sand for wall-cap plaster and groove finishing', '3', 'm3', 'Screen before mixing; keep separate from blockwork sand.'],
            ]);

            $this->createMixedBatch($project, $phase->id, $siteOperator, [
                [$entries[0], 'Cement 42.5N for the next plastering work front', '30', 'bag', 'item', 'Deliver an unopened approved brand and stack on pallets.'],
                [$entries[1], 'Washed fine sand for the next plastering work front', '5', 'm3', 'item', 'Site Operator to inspect cleanliness before offloading.'],
                [$entries[2], 'Additional cement for first-floor patching works', '16', 'bag', 'item', 'Keep this quantity marked separately for first-floor making good.'],
                [$budgets->get('labour_subcontracts')->entries->sortBy('id')->first(), 'Plastering gang wages for five working days', '5', 'day', 'salary_wage', 'One foreman, four plasterers and four helpers; attendance sheet required.'],
                [$budgets->get('labour_subcontracts')->entries->sortBy('id')->first(), 'Specialist groove-line and corner finishing labour', '1', 'ls', 'salary_wage', 'Measure completed groove lines before PM approval.'],
                [$budgets->get('logistics_equipment')->entries->sortBy('id')->first(), 'Mobile scaffolding hire for high-level wall plaster', '7', 'day', 'service', 'Include erection, weekly inspection tag and dismantling.'],
                [$budgets->get('logistics_equipment')->entries->sortBy('id')->first(), 'Protective sheeting and scaffold relocation service', '1', 'ls', 'service', 'Move access equipment between elevations without damaging finished work.'],
            ]);
        });
    }

    private function createBatch(Project $project, int $phaseId, User $siteOperator, $entries, string $batchCode, string $prefix, array $items): void
    {
        foreach ($items as $index => [$description, $quantity, $unit, $notes]) {
            $entry = $entries[$index];
            $line = $index + 1;

            ExpenseRequest::firstOrCreate(
                ['request_code' => 'REQ-OYS-'.$prefix.'-'.str_pad((string) $line, 2, '0', STR_PAD_LEFT)],
                [
                    'request_batch_code' => $batchCode,
                    'request_line_number' => $line,
                    'procurement_stage' => 'site_review',
                    'project_id' => $project->id,
                    'project_phase_id' => $phaseId,
                    'work_area' => $entry->work_area,
                    'construction_category' => $entry->construction_category,
                    'work_package_code' => $entry->work_package_code,
                    'work_package_name' => $entry->work_package_name,
                    'internal_budget_entry_id' => $entry->id,
                    'request_type' => 'item',
                    'main_category' => 'materials',
                    'subcategory' => $entry->subcategory,
                    'item_description' => $description,
                    'is_new_item' => false,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'requested_at' => today()->toDateString(),
                    'submitted_at' => now(),
                    'status' => 'pending_approval',
                    'requested_by' => $siteOperator->id,
                    'notes' => $notes.' Demo purpose: '.strtolower($prefix).' request.',
                ]
            );
        }
    }

    private function createMixedBatch(Project $project, int $phaseId, User $siteOperator, array $items): void
    {
        foreach ($items as $index => [$entry, $description, $quantity, $unit, $requestType, $notes]) {
            $line = $index + 1;

            ExpenseRequest::firstOrCreate(
                ['request_code' => 'REQ-OYS-MIXED-'.str_pad((string) $line, 2, '0', STR_PAD_LEFT)],
                [
                    'request_batch_code' => self::MIXED_BATCH,
                    'request_line_number' => $line,
                    'procurement_stage' => 'site_review',
                    'project_id' => $project->id,
                    'project_phase_id' => $phaseId,
                    'work_area' => $entry->work_area,
                    'construction_category' => $entry->construction_category,
                    'work_package_code' => $entry->work_package_code,
                    'work_package_name' => $entry->work_package_name,
                    'internal_budget_entry_id' => $entry->id,
                    'request_type' => $requestType,
                    'main_category' => $entry->main_category,
                    'subcategory' => $entry->subcategory,
                    'item_description' => $description,
                    'is_new_item' => false,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'requested_at' => today()->toDateString(),
                    'submitted_at' => now(),
                    'status' => 'pending_approval',
                    'requested_by' => $siteOperator->id,
                    'notes' => $notes.' Demo purpose: mixed-category PM workflow test.',
                ]
            );
        }
    }
}
