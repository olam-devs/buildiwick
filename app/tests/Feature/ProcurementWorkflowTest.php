<?php

namespace Tests\Feature;

use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\SupplierPayee;
use App\Models\User;
use App\Services\SupplierRequestGroupService;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pm_can_move_group_price_and_independently_approve_supplier_items(): void
    {
        $this->seed(LookupSeeder::class);
        $role = Role::create(['name' => 'project_manager', 'description' => 'Project Manager']);
        $pm = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $project = Project::factory()->create();
        $project->assignedUsers()->attach($pm);
        $phase = ProjectPhase::create([
            'project_id' => $project->id, 'stage_code' => 'substructure', 'phase_name' => 'Substructure',
            'phase_order' => 1, 'status' => 'active',
        ]);
        $budget = InternalBudget::create([
            'budget_code' => 'IB-TEST-01', 'project_id' => $project->id, 'version' => 1,
            'status' => 'approved', 'created_by' => $pm->id,
        ]);
        $entry = $budget->entries()->create([
            'project_phase_id' => $phase->id, 'main_category' => 'materials', 'work_area' => 'Main House',
            'construction_category' => 'Substructure', 'work_package_code' => 'A', 'work_package_name' => 'Concrete',
            'item_description' => 'Cement 42.5R', 'quantity' => 100, 'unit' => 'bag', 'unit_cost' => 15000,
            'entered_by' => $pm->id,
        ]);
        $item = ExpenseRequest::create([
            'request_code' => 'REQ-000001', 'request_batch_code' => 'SRQ-000001', 'request_line_number' => 1,
            'project_id' => $project->id, 'project_phase_id' => $phase->id, 'request_type' => 'item',
            'main_category' => 'materials', 'item_description' => 'Cement 42.5R', 'quantity' => 10, 'unit' => 'bag',
            'requested_at' => today(), 'submitted_at' => now(), 'requested_by' => $pm->id,
            'status' => 'pending_approval', 'procurement_stage' => 'site_review', 'work_area' => 'Main House',
            'construction_category' => 'Substructure', 'work_package_code' => 'A', 'work_package_name' => 'Concrete',
            'internal_budget_entry_id' => $entry->id,
        ]);
        $supplier = SupplierPayee::create(['name' => 'Dar Builders Supply', 'type' => 'supplier', 'is_active' => true]);
        $service = app(SupplierRequestGroupService::class);

        $service->move($item, [
            'item_ids' => [$item->id], 'direction' => 'to_supplier_assignment',
            'items' => [$item->id => [
                'item_description' => 'Cement 42.5R', 'quantity' => '12', 'unit' => 'bag',
                'project_phase_id' => $phase->id, 'internal_budget_entry_id' => $entry->id,
                'work_area' => 'Main House', 'construction_category' => 'Substructure',
                'work_package_code' => 'A', 'work_package_name' => 'Concrete',
            ]],
        ], $pm);
        $group = $service->assign($item, ['item_ids' => [$item->id], 'supplier_payee_id' => $supplier->id], $pm);
        $service->price($group, [
            'items' => [$item->id => ['commercial_unit_rate' => '14500']],
            'payment_method' => 'cash', 'notes' => 'Cash on delivery.',
        ], $pm, null);
        $service->decide($group, ['decision' => 'approved', 'notes' => 'Verified against the approved budget.'], $pm);

        $this->assertDatabaseHas('supplier_request_groups', ['id' => $group->id, 'status' => 'approved']);
        $this->assertDatabaseHas('expense_requests', [
            'id' => $item->id, 'status' => 'approved', 'procurement_stage' => 'approved',
            'commercial_total' => '174000.00', 'pm_edited' => true,
        ]);
        $this->assertStringContainsString('quantity', $item->fresh()->pm_edit_summary);
        $this->assertDatabaseHas('expense_request_approvals', [
            'expense_request_id' => $item->id, 'approval_stage' => 'project_manager', 'decision' => 'approved',
        ]);
    }
}
