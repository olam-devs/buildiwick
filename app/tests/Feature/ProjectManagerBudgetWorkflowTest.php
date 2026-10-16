<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\LabourAgreement;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\SupplierPayee;
use App\Models\SupplierPaymentDestination;
use App\Models\User;
use App\Policies\ClientInvoicePolicy;
use App\Policies\ClientPaymentPolicy;
use App\Policies\InternalBudgetEntryPolicy;
use App\Policies\InternalBudgetPolicy;
use App\Services\ExpensePaymentRecorder;
use App\Services\ExpenseRequestDecisionService;
use App\Services\ProjectManagerBudgetService;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProjectManagerBudgetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const STAGES = ['preliminaries', 'substructure', 'superstructure', 'walling', 'roofing', 'finishing', 'external_works'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        foreach (['admin', 'director', 'accounts', 'estimator_qs', 'project_manager', 'site_operator'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
        Storage::fake('local');
    }

    public function test_pm_dashboard_shows_assigned_projects_only_and_unrelated_project_is_forbidden(): void
    {
        [$assigned, $pm] = $this->assignedProject();
        $other = $this->project('BWG-PM-OTHER-001');
        $this->actingAs($pm)->get(route('pm.projects.index'))->assertOk()->assertSee($assigned->project_code)->assertDontSee($other->project_code);
        $this->actingAs($pm)->get(route('pm.projects.show', $other))->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('pm.projects.index'))->assertOk()->assertSee($assigned->project_code)->assertSee($other->project_code);
    }

    public function test_only_pm_and_admin_can_access_pm_workspace_and_roles_cannot_mutate_budget(): void
    {
        [$project, $pm] = $this->assignedProject();
        foreach (['accounts', 'estimator_qs', 'site_operator'] as $role) {
            $this->actingAs($this->user($role))->get(route('pm.projects.index'))->assertForbidden();
            $this->actingAs($this->user($role))->get(route('pm.projects.show', $project))->assertForbidden();
        }
        $this->actingAs($this->user('admin'))->get(route('pm.projects.show', $project))->assertOk();
        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm);
        $this->assertFalse(app(InternalBudgetPolicy::class)->create($this->user('site_operator'), $project));
        $this->assertFalse(app(InternalBudgetEntryPolicy::class)->create($this->user('site_operator'), $budget));
    }

    public function test_pm_can_create_only_one_draft_and_finalized_versions_are_preserved(): void
    {
        [$project, $pm] = $this->assignedProject();
        $service = app(ProjectManagerBudgetService::class);
        $draft = $service->ensureWorkingBudget($project, $pm);
        $again = $service->ensureWorkingBudget($project, $pm);
        $this->assertSame($draft->id, $again->id);
        $this->assertSame(1, $project->internalBudgets()->where('status', 'draft')->count());

        $phase = $project->phases()->firstOrFail();
        $service->createEntry($draft, $phase, $this->entryPayload(), $pm);
        $finalized = $service->finalize($draft, $pm);
        $this->assertSame('finalized', $finalized->status);
        $this->assertSame($pm->id, $finalized->finalized_by);
        $this->assertNotNull($finalized->finalized_at);

        $director = $this->user('director');
        $approved = $service->approve($finalized, $director, 'Verified against the project plan.');
        $this->assertSame('approved', $approved->status);
        $this->assertSame($director->id, $approved->approved_by);

        $this->actingAs($pm)->post(route('pm.budgets.edit', [$project, $approved]), [])
            ->assertSessionHasErrors('edit_reason');
        $this->assertSame(0, $project->internalBudgets()->where('status', 'draft')->count());

        $revised = $service->startEditing($approved, 'Supplier rates changed after tender clarification.', $pm);
        $this->assertSame(2, $revised->version);
        $this->assertSame('approved', $finalized->fresh()->status);
        $this->assertSame(2, $project->internalBudgets()->count());
        $copiedEntry = $revised->entries()->firstOrFail();
        $service->updateEntry($copiedEntry, $this->entryPayload(['unit_cost' => '25.00', 'edit_reason' => 'Apply the confirmed supplier rate.']), $pm);
        $service->finalize($revised, $pm);
        $service->approve($revised->fresh(), $director);
        $this->assertSame('superseded', $finalized->fresh()->status);
        $this->assertSame('approved', $revised->fresh()->status);
        $this->assertSame('25.00', $service->projectComparison($project)['overall']['budget']);
        $this->assertSame(2, $project->internalBudgets()->count());
        $this->assertDatabaseHas('project_edit_logs', [
            'project_id' => $project->id, 'document_type' => 'internal_budget', 'action' => 'edit_started',
            'reason' => 'Supplier rates changed after tender clarification.', 'edited_by' => $pm->id,
        ]);
        $this->actingAs($pm)->get(route('pm.projects.show', $project))->assertOk()
            ->assertSee('Edit Internal Budget')->assertSee('Supplier rates changed after tender clarification.');
        $this->actingAs($director)->get(route('director.projects.show', $project))->assertOk()
            ->assertSee('Project Estimate and Internal Budget Edit Log')->assertSee('Supplier rates changed after tender clarification.');
    }

    public function test_director_can_return_then_verify_an_internal_budget(): void
    {
        [$project, $pm] = $this->assignedProject();
        $director = $this->user('director');
        $service = app(ProjectManagerBudgetService::class);
        $budget = $service->ensureWorkingBudget($project, $pm);
        $entry = $service->createEntry($budget, $project->phases()->firstOrFail(), $this->entryPayload(), $pm);
        $service->finalize($budget, $pm);

        $this->actingAs($pm)->get(route('pm.projects.show', $project))
            ->assertOk()->assertSee('Awaiting Director Verification')->assertDontSee('Create Revised Budget Version');
        $this->actingAs($director)->get(route('director.projects.show', $project))
            ->assertOk()->assertSee('Internal Budgets Awaiting Your Verification')->assertSee($budget->budget_code);

        $this->actingAs($director)->post(route('director.budgets.decision', [$project, $budget]), [
            'decision' => 'return', 'notes' => 'Separate roofing labour from materials.',
        ])->assertRedirect();
        $this->assertSame('draft', $budget->fresh()->status);
        $this->assertSame('Separate roofing labour from materials.', $budget->fresh()->notes);

        $service->updateEntry($entry->fresh(), $this->entryPayload(['unit_cost' => '30.00', 'edit_reason' => 'Separate roofing labour from materials as requested.']), $pm);
        $service->finalize($budget->fresh(), $pm);
        $this->actingAs($director)->post(route('director.budgets.decision', [$project, $budget]), [
            'decision' => 'approve', 'notes' => 'Verified.',
        ])->assertRedirect();
        $this->assertSame('approved', $budget->fresh()->status);
        $this->assertSame($director->id, $budget->fresh()->approved_by);
        $this->assertNotNull($budget->fresh()->approved_at);
    }

    public function test_pm_can_budget_future_standard_phases_and_entry_records_actor_without_markup(): void
    {
        [$project, $pm] = $this->assignedProject();
        $future = $project->phases()->where('stage_code', 'roofing')->firstOrFail();
        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm, $future, 'labour_subcontracts');
        $this->assertSame('not_started', $future->status);
        $entry = app(ProjectManagerBudgetService::class)->createEntry($budget, $future, $this->entryPayload([
            'main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour',
            'quantity' => null, 'unit' => 'ls', 'unit_cost' => '250.00',
        ]), $pm);

        $this->assertSame('roofing', $entry->phase->stage_code);
        $this->assertSame($pm->id, $entry->entered_by);
        $this->assertSame('250.00', $entry->line_total);
        $this->assertArrayNotHasKey('markup_percent', $entry->getAttributes());
        $this->assertArrayNotHasKey('client_unit_cost', $entry->getAttributes());
    }

    public function test_pm_budget_accepts_all_cost_categories_and_construction_units(): void
    {
        [$project, $pm] = $this->assignedProject();
        $phases = $project->phases()->orderBy('phase_order')->get();
        foreach ([
            ['main_category' => 'materials', 'unit' => 'bag'],
            ['main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour', 'unit' => 'worker_day'],
            ['main_category' => 'logistics_equipment', 'subcategory' => null, 'unit' => 'trip'],
            ['main_category' => 'preliminaries_overheads', 'subcategory' => null, 'unit' => 'service'],
        ] as $index => $entry) {
            $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm, $phases[$index], $entry['main_category']);
            $created = app(ProjectManagerBudgetService::class)->createEntry($budget, $phases[$index], $this->entryPayload($entry), $pm);
            $this->assertSame('50.00', $created->line_total);
        }
        $this->actingAs($pm)->get(route('pm.budget-entries.create', [$project, $phases[0]]))
            ->assertOk()->assertSee('Expected Supplier / Fundi / Service Provider')->assertSee('worker_day');
    }

    public function test_each_phase_has_independent_budget_types_and_each_work_package_has_its_own_subtotal(): void
    {
        [$project, $pm] = $this->assignedProject();
        $phase = $project->phases()->where('stage_code', 'substructure')->firstOrFail();
        $service = app(ProjectManagerBudgetService::class);
        $materials = $service->ensureWorkingBudget($project, $pm, $phase, 'materials');
        $labour = $service->ensureWorkingBudget($project, $pm, $phase, 'labour_subcontracts');

        $this->assertNotSame($materials->id, $labour->id);
        $this->assertSame(1, $materials->version);
        $this->assertSame(1, $labour->version);

        foreach ([
            ['work_package_code' => '3.1', 'work_package_name' => 'Foundation strip footing', 'item_description' => 'Cement'],
            ['work_package_code' => '3.1', 'work_package_name' => 'Foundation strip footing', 'item_description' => 'Aggregate'],
            ['work_package_code' => '3.2', 'work_package_name' => 'Foundation walling', 'item_description' => 'Concrete blocks'],
        ] as $line) {
            $service->createEntry($materials, $phase, $this->entryPayload($line + [
                'work_area' => 'Main Building',
                'construction_category' => 'Substructure',
            ]), $pm);
        }

        $response = $this->actingAs($pm)->get(route('pm.projects.show', $project));
        $response->assertOk()->assertSee('3.1 Subtotal')->assertSee('3.2 Subtotal');
        $this->assertSame(1, substr_count($response->getContent(), 'Foundation strip footing'));
        $this->assertSame(1, substr_count($response->getContent(), 'Foundation walling'));
    }

    public function test_budget_entry_category_subcategory_relationship_and_foreign_phase_are_rejected(): void
    {
        [$project, $pm] = $this->assignedProject();
        [$other] = $this->assignedProject('BWG-PM-OTHER-002');
        $phase = $project->phases()->firstOrFail();
        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm, $phase, 'logistics_equipment');
        $service = app(ProjectManagerBudgetService::class);

        try {
            $service->createEntry($budget, $other->phases()->firstOrFail(), $this->entryPayload(), $pm);
            $this->fail('Foreign project phases must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project_phase_id', $exception->errors());
        }
        try {
            $service->createEntry($budget, $phase, $this->entryPayload(['main_category' => 'logistics_equipment', 'subcategory' => 'fundi_labour']), $pm);
            $this->fail('Category/subcategory mismatch must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subcategory', $exception->errors());
        }
    }

    public function test_fixed_point_budget_line_totals_rounding_and_lump_sum_are_exact(): void
    {
        [$project, $pm] = $this->assignedProject();
        $service = app(ProjectManagerBudgetService::class);
        $phase = $project->phases()->firstOrFail();
        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm, $phase, 'materials');
        $quantity = $service->createEntry($budget, $phase, $this->entryPayload(['quantity' => '1.33', 'unit_cost' => '19.99']), $pm);
        $lumpSum = $service->createEntry($budget, $phase, $this->entryPayload(['quantity' => null, 'unit' => 'ls', 'unit_cost' => '1000.25']), $pm);

        $this->assertSame('26.59', $quantity->line_total);
        $this->assertSame('1000.25', $lumpSum->line_total);
        $this->assertNull($lumpSum->quantity);
        $zeroQuantity = $service->createEntry($budget, $phase, $this->entryPayload(['quantity' => '0', 'unit_cost' => '100.00']), $pm);
        $this->assertSame('0.00', $zeroQuantity->line_total);
        $this->assertSame('0.00', $zeroQuantity->quantity);
        $this->assertSame('1026.84', $budget->fresh()->total_amount);
    }

    public function test_pm_budget_form_ignores_submitted_total_and_accepts_only_budget_fields(): void
    {
        [$project, $pm] = $this->assignedProject();
        $phase = $project->phases()->where('stage_code', 'finishing')->firstOrFail();
        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm, $phase, 'materials');
        $payload = $this->entryPayload(['quantity' => '3.00', 'unit_cost' => '20.00', 'line_total' => '1.00', 'markup_percent' => '99.0000']);
        $response = $this->actingAs($pm)->post(route('pm.budget-entries.store', [$project, $phase]), $payload);
        $response->assertRedirect();
        $entry = $budget->entries()->firstOrFail();
        $this->assertSame('60.00', $entry->line_total);
        $this->assertArrayNotHasKey('markup_percent', $entry->getAttributes());
    }

    public function test_project_phase_and_category_comparisons_preserve_over_budget_and_zero_budget_values(): void
    {
        [$project, $pm] = $this->assignedProject();
        $service = app(ProjectManagerBudgetService::class);
        $materialsPhase = $project->phases()->where('stage_code', 'roofing')->firstOrFail();
        $materialsBudget = $service->ensureWorkingBudget($project, $pm, $materialsPhase, 'materials');
        $labourBudget = $service->ensureWorkingBudget($project, $pm, $materialsPhase, 'labour_subcontracts');
        $service->createEntry($materialsBudget, $materialsPhase, $this->entryPayload(['main_category' => 'materials', 'quantity' => '1', 'unit_cost' => '100.00']), $pm);
        $service->createEntry($labourBudget, $materialsPhase, $this->entryPayload(['main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour', 'quantity' => '1', 'unit_cost' => '300.00']), $pm);
        $materialsRequest = $this->request($project, $materialsPhase, ['main_category' => 'materials', 'subcategory' => 'concrete_cement', 'quantity' => '1', 'total_amount' => '120.00', 'status' => 'approved']);
        $labourRequest = $this->request($project, $materialsPhase, ['main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour', 'quantity' => '1', 'total_amount' => '250.00', 'status' => 'approved']);
        $wallingPhase = $project->phases()->where('stage_code', 'walling')->firstOrFail();
        $logisticsRequest = $this->request($project, $wallingPhase, ['main_category' => 'logistics_equipment', 'quantity' => '1', 'total_amount' => '500.00', 'status' => 'approved']);
        foreach ([[$materialsRequest, '120.00'], [$labourRequest, '250.00'], [$logisticsRequest, '500.00']] as [$request, $amount]) {
            ExpensePayment::create([
                'payment_code' => 'PAY-CMP-'.fake()->unique()->numerify('#####'),
                'expense_request_id' => $request->id,
                'project_id' => $project->id,
                'project_phase_id' => $request->project_phase_id,
                'amount' => $amount,
                'payment_date' => today()->toDateString(),
                'payment_method' => 'cash',
            ]);
        }
        $cancelled = $this->request($project, $materialsPhase, ['main_category' => 'materials', 'quantity' => '1', 'total_amount' => '999.00']);
        $cancelled->update(['status' => 'cancelled']);

        $comparison = $service->projectComparison($project);
        $this->assertSame('400.00', $comparison['overall']['budget']);
        $this->assertSame('870.00', $comparison['overall']['approved']);
        $this->assertSame('870.00', $comparison['overall']['actual']);
        $this->assertSame('-470.00', $comparison['overall']['remaining']);
        $this->assertSame('217.50', $comparison['overall']['utilization']);
        $this->assertTrue($comparison['overall']['over_budget']);
        $this->assertSame('-20.00', $comparison['categories']['materials']['remaining']);
        $this->assertSame('120.00', $comparison['categories']['materials']['utilization']);
        $this->assertSame('370.00', $comparison['phases'][$materialsPhase->id]['comparison']['actual']);
        $this->assertSame('30.00', $comparison['phases'][$materialsPhase->id]['comparison']['remaining']);
        $this->assertSame('120.00', $comparison['categories']['materials']['subcategories']->firstWhere('key', 'concrete_cement')['actual']);
        $this->assertSame('No budget set', $comparison['phases'][$project->phases()->where('stage_code', 'walling')->value('id')]['comparison']['utilization'] === null ? 'No budget set' : 'Unexpected percentage');
    }

    public function test_zero_budget_with_request_shows_negative_remaining_and_no_budget_set_not_zero_percent(): void
    {
        [$project, $pm] = $this->assignedProject();
        $service = app(ProjectManagerBudgetService::class);
        $phase = $project->phases()->firstOrFail();
        $request = $this->request($project, $phase, ['quantity' => '1', 'total_amount' => '500000.00', 'status' => 'approved']);
        ExpensePayment::create([
            'payment_code' => 'PAY-ZERO-001', 'expense_request_id' => $request->id,
            'project_id' => $project->id, 'project_phase_id' => $phase->id,
            'amount' => '500000.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash',
        ]);
        $comparison = $service->projectComparison($project);
        $this->assertSame('0.00', $comparison['overall']['budget']);
        $this->assertSame('500000.00', $comparison['overall']['actual']);
        $this->assertSame('-500000.00', $comparison['overall']['remaining']);
        $this->assertNull($comparison['overall']['utilization']);
    }

    public function test_pm_can_edit_and_delete_only_assigned_draft_entries_and_finalized_history_is_locked(): void
    {
        [$project, $pm] = $this->assignedProject();
        [$otherProject, $otherPm] = $this->assignedProject('BWG-PM-OTHER-003');
        $service = app(ProjectManagerBudgetService::class);
        $budget = $service->ensureWorkingBudget($project, $pm);
        $phase = $project->phases()->firstOrFail();
        $entry = $service->createEntry($budget, $phase, $this->entryPayload(), $pm);

        $changed = $this->entryPayload(['quantity' => '2.00', 'unit_cost' => '30.00', 'edit_reason' => 'Quantity and current rate were confirmed.']);
        $this->actingAs($pm)->put(route('pm.budget-entries.update', [$project, $phase, $entry]), $changed)->assertRedirect();
        $this->assertSame('60.00', $entry->fresh()->line_total);
        $this->actingAs($otherPm)->put(route('pm.budget-entries.update', [$project, $phase, $entry]), $changed)->assertForbidden();
        $this->actingAs($pm)->delete(route('pm.budget-entries.delete', [$project, $phase, $entry]), ['edit_reason' => 'This line was entered twice.'])->assertRedirect();

        $entry = $service->createEntry($budget, $phase, $this->entryPayload(), $pm);
        $service->finalize($budget, $pm);
        $this->actingAs($pm)->put(route('pm.budget-entries.update', [$project, $phase, $entry]), $changed)->assertForbidden();
        $this->actingAs($pm)->delete(route('pm.budget-entries.delete', [$project, $phase, $entry]))->assertForbidden();
        $this->assertDatabaseHas('internal_budget_entries', ['id' => $entry->id]);
        $this->assertSame($otherProject->id, $otherPm->assignedProjects()->first()->id);
    }

    public function test_pm_cannot_edit_estimator_entries_pay_expense_requests_or_approve_them_in_phase_five(): void
    {
        [$project, $pm] = $this->assignedProject();
        $estimator = $this->user('estimator_qs');
        $project->assignedUsers()->attach($estimator->id);
        $estimate = ProjectEstimate::create(['estimate_code' => 'EST-PM-LOCK-001', 'project_id' => $project->id, 'version' => 1, 'status' => 'draft']);
        $phase = $project->phases()->firstOrFail();
        $estimateEntry = $estimate->entries()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Estimator-only', 'base_unit_cost' => '100.00']);
        $request = $this->request($project, $phase, ['quantity' => '1', 'total_amount' => '25.00']);

        $this->actingAs($pm)->get(route('estimator.projects.show', $project))->assertForbidden();
        $this->actingAs($pm)->get(route('estimator.entries.edit', [$project, $phase, $estimateEntry]))->assertForbidden();
        $this->assertTrue($pm->can('approve', $request));
        $this->assertFalse($pm->can('recordPayment', $request));
        $this->assertFalse(app(ClientInvoicePolicy::class)->create($pm, $project));
        $this->assertFalse(app(ClientPaymentPolicy::class)->create($pm));
        $this->actingAs($pm)->post(route('site.requests.store', $project), [])->assertForbidden();
        try {
            app(ExpensePaymentRecorder::class)->record($request, ['amount' => '1.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash'], $pm);
            $this->fail('PM may not record payment in Phase 5.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('expense_payments', 0);
        }
    }

    public function test_pm_login_navigation_and_legacy_transaction_bypass_are_role_specific(): void
    {
        [$project, $pm] = $this->assignedProject();
        $this->actingAs($pm)->get('/')->assertRedirect(route('pm.projects.index'));
        $this->actingAs($pm)->get('/login')->assertRedirect(route('pm.projects.index'));
        $this->actingAs($pm)->get(route('pm.projects.index'))->assertOk()->assertSee('Project Manager Dashboard')->assertDontSee('New Entry')->assertDontSee('Estimator Estimates');
        $this->actingAs($pm)->post('/entries/transactions', [
            'project_id' => $project->id, 'main_category' => 'materials', 'item_description' => 'Legacy bypass',
            'total_amount' => '1.00', 'transaction_date' => now()->toDateString(),
        ])->assertForbidden();
        $token = $pm->createToken('pm-test')->plainTextToken;
        $this->withToken($token)->postJson('/api/transactions', [
            'project_id' => $project->id, 'main_category' => 'materials', 'item_description' => 'API bypass',
            'total_amount' => '1.00', 'transaction_date' => now()->toDateString(),
        ])->assertForbidden();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_pm_site_request_page_is_read_only_and_scoped_to_assigned_projects(): void
    {
        [$project, $pm] = $this->assignedProject();
        [$unassigned] = $this->assignedProject('BWG-PM-REQUEST-OTHER');
        $request = $this->request($project, $project->phases()->firstOrFail(), ['quantity' => '1', 'total_amount' => '75.00']);
        $otherRequest = $this->request($unassigned, $unassigned->phases()->firstOrFail(), ['quantity' => '1', 'total_amount' => '500.00']);

        $this->actingAs($pm)->get(route('pm.requests.index'))
            ->assertOk()->assertSee($request->request_code)->assertDontSee($otherRequest->request_code)
            ->assertSee('commercial details')->assertSee('Review')->assertDontSee('Record Payment');
        $this->actingAs($pm)->get(route('pm.projects.show', $project))
            ->assertOk()->assertSee($request->request_code)->assertDontSee($otherRequest->request_code);
    }

    public function test_pm_can_create_supplier_payee_and_prepare_commercial_details_without_approving_or_paying(): void
    {
        [$project, $pm] = $this->assignedProject();
        $request = $this->request($project, $project->phases()->firstOrFail(), ['quantity' => '2', 'total_amount' => null]);
        $this->actingAs($pm)->post(route('pm.suppliers.store'), [
            'name' => 'ABC Hardware', 'type' => 'supplier', 'phone' => '0712345678',
        ])->assertRedirect(route('pm.suppliers.index'));
        $supplier = SupplierPayee::firstOrFail();
        $this->actingAs($pm)->put(route('pm.requests.commercial.update', $request), [
            'supplier_payee_id' => $supplier->id, 'commercial_unit_rate' => '125.00', 'commercial_payment_method' => 'bank_transfer',
            'commercial_payment_destination' => 'Account reference',
        ])->assertRedirect();
        $request = $request->fresh();
        $this->assertSame($supplier->id, $request->supplier_payee_id);
        $this->assertSame('250.00', $request->commercial_total);
        $this->assertSame('pending_approval', $request->status);
        $this->assertDatabaseCount('expense_payments', 0);
        $this->actingAs($this->user('site_operator'))->get(route('pm.suppliers.index'))->assertForbidden();
    }

    public function test_pm_can_correct_phase_and_category_or_link_the_exact_internal_budget_line(): void
    {
        [$project, $pm] = $this->assignedProject();
        $phase = $project->phases()->where('stage_code', 'substructure')->firstOrFail();
        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm, $phase, 'materials');
        $entry = app(ProjectManagerBudgetService::class)->createEntry($budget, $phase, $this->entryPayload([
            'main_category' => 'materials', 'subcategory' => 'reinforcement_steel',
            'item_description' => 'Foundation reinforcement steel',
        ]), $pm);
        app(ProjectManagerBudgetService::class)->finalize($budget, $pm);
        app(ProjectManagerBudgetService::class)->approve($budget->fresh(), $this->user('director'));
        $request = $this->request($project, $project->phases()->firstOrFail(), [
            'main_category' => 'preliminaries_overheads', 'subcategory' => null,
        ]);

        $this->actingAs($pm)->put(route('pm.requests.classification.update', $request), [
            'project_phase_id' => $project->phases()->firstOrFail()->id,
            'main_category' => 'preliminaries_overheads',
            'internal_budget_entry_id' => $entry->id,
        ])->assertRedirect(route('pm.requests.commercial.edit', $request));

        $request->refresh();
        $this->assertSame($entry->id, $request->internal_budget_entry_id);
        $this->assertSame($phase->id, $request->project_phase_id);
        $this->assertSame('materials', $request->main_category);
        $this->assertSame('reinforcement_steel', $request->subcategory);
    }

    public function test_pm_approval_requires_complete_commercial_details_and_creates_immutable_snapshot_without_payment(): void
    {
        [$project, $pm] = $this->assignedProject();
        $phase = $project->phases()->firstOrFail();
        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm, $phase, 'materials');
        app(ProjectManagerBudgetService::class)->createEntry($budget, $phase, $this->entryPayload(['unit_cost' => '100.00']), $pm);
        $request = $this->request($project, $phase, ['quantity' => '2', 'total_amount' => null]);
        $supplier = SupplierPayee::create(['name' => 'ABC Hardware', 'type' => 'supplier', 'contact_person' => 'Asha', 'phone' => '0712']);
        $destination = SupplierPaymentDestination::create(['supplier_payee_id' => $supplier->id, 'payment_method' => 'bank_transfer', 'account_wallet_name' => 'ABC Account', 'account_wallet_number' => '12345', 'bank_provider_name' => 'Bank']);
        $request->update([
            'supplier_payee_id' => $supplier->id, 'commercial_unit_rate' => '60.00', 'commercial_total' => '120.00',
            'commercial_payment_method' => 'bank_transfer', 'commercial_payment_destination_id' => $destination->id,
            'commercial_evidence_link' => 'expense-requests/quote.pdf',
        ]);
        $service = app(ExpenseRequestDecisionService::class);
        $approval = $service->decide($request, 'approve', 'Within budget.', $pm);
        $this->assertSame('approved', $request->fresh()->status);
        $this->actingAs($pm)->put(route('pm.requests.classification.update', $request), [
            'project_phase_id' => $request->project_phase_id, 'main_category' => 'materials',
        ])->assertForbidden();
        $this->assertSame('project_manager', $approval->approval_stage);
        $this->assertSame('120.00', $approval->approved_total);
        $this->assertSame('ABC Hardware', $approval->supplier_name_snapshot);
        $this->assertStringContainsString('12345', $approval->payment_destination_snapshot);
        $this->assertDatabaseCount('expense_payments', 0);
        $supplier->update(['name' => 'Changed Supplier', 'phone' => '0999']);
        $this->assertSame('ABC Hardware', $approval->fresh()->supplier_name_snapshot);
        $this->actingAs($pm)->put(route('pm.requests.commercial.update', $request), [])->assertForbidden();
    }

    public function test_over_budget_approval_requires_justification_and_zero_budget_is_safe(): void
    {
        [$project, $pm] = $this->assignedProject();
        $request = $this->request($project, $project->phases()->firstOrFail(), ['quantity' => '1', 'total_amount' => null]);
        $supplier = SupplierPayee::create(['name' => 'Security Company', 'type' => 'service_provider']);
        $request->update(['supplier_payee_id' => $supplier->id, 'commercial_unit_rate' => '500.00', 'commercial_total' => '500.00', 'commercial_payment_method' => 'cash', 'commercial_evidence_link' => 'quote.pdf']);
        $service = app(ExpenseRequestDecisionService::class);
        try {
            $service->decide($request, 'approve', '', $pm);
            $this->fail('Over-budget approval requires justification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('notes', $exception->errors());
        }
        $approval = $service->decide($request, 'approve', 'No budget was set; urgent site need.', $pm);
        $this->assertSame('approved', $approval->expenseRequest->fresh()->status);
        $this->assertNull(app(ExpenseRequestDecisionService::class)->impact($request)['utilization']);
    }

    public function test_pm_can_create_classify_and_approve_a_typed_request_directly_for_accounts(): void
    {
        [$project, $pm] = $this->assignedProject();
        $director = $this->user('director');
        $accounts = $this->user('accounts');

        $this->actingAs($pm)->post(route('pm.labour.store', $project), [
            'project_id' => $project->id, 'fundi_subcontractor' => 'Mason Team Lead',
            'labour_type' => 'subcontractor', 'scope_of_work' => 'Masonry labour',
            'agreement_date' => now()->toDateString(), 'agreed_value' => '900000.00',
        ])->assertRedirect(route('pm.labour.index'));
        $agreement = LabourAgreement::firstOrFail();
        $this->actingAs($pm)->get(route('pm.labour.index'))->assertOk()->assertSee($agreement->agreement_code);

        $this->actingAs($pm)->post(route('pm.requests.store', $project), [
            'requested_at' => now()->toDateString(), 'request_type' => 'salary_wage',
            'main_category' => 'labour_subcontracts', 'item_description' => 'Weekly mason wages',
            'requested_amount' => '300000.00', 'unit' => 'ls', 'notes' => 'Week one wages.',
            'labour_agreement_id' => $agreement->id,
        ])->assertRedirect();
        $request = ExpenseRequest::latest('id')->firstOrFail();
        $this->assertSame($pm->id, $request->requested_by);
        $this->assertSame('salary_wage', $request->request_type);
        $this->assertNull($request->requested_amount);
        $this->assertSame($agreement->id, $request->labour_agreement_id);
        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm, $request->phase, 'labour_subcontracts');
        $entry = app(ProjectManagerBudgetService::class)->createEntry($budget, $request->phase, $this->entryPayload([
            'main_category' => 'labour_subcontracts', 'subcategory' => 'direct_labour',
            'item_description' => 'Weekly masonry labour budget', 'unit_cost' => '300000.00',
        ]), $pm);
        app(ProjectManagerBudgetService::class)->finalize($budget, $pm);
        app(ProjectManagerBudgetService::class)->approve($budget->fresh(), $director);
        $this->actingAs($pm)->put(route('pm.requests.classification.update', $request), [
            'project_phase_id' => $request->project_phase_id,
            'main_category' => 'labour_subcontracts',
            'subcategory' => 'direct_labour',
            'internal_budget_entry_id' => $entry->id,
        ])->assertRedirect();

        $supplier = SupplierPayee::create(['name' => 'Mason Team Lead', 'type' => 'service_provider', 'is_active' => true]);
        $this->actingAs($pm)->put(route('pm.requests.commercial.update', $request), [
            'supplier_payee_id' => $supplier->id, 'commercial_unit_rate' => '300000.00',
            'commercial_payment_method' => 'cash',
            'commercial_evidence' => UploadedFile::fake()->create('wage-sheet.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        $this->actingAs($pm)->post(route('pm.requests.decision', $request), [
            'decision' => 'approve', 'notes' => 'Verified against attendance.',
        ])->assertRedirect();

        $this->assertSame('approved', $request->fresh()->status);
        $this->actingAs($accounts)->get(route('accountant.requests.index'))->assertOk()->assertSee($request->request_batch_code);
        $this->actingAs($accounts)->get(route('accountant.requests.show', $request))->assertOk();
        $this->actingAs($director)->get(route('director.requests.index'))->assertOk()->assertSee($request->request_batch_code);

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame(['project_manager'], $request->approvals()->orderBy('id')->pluck('approval_stage')->all());
        $this->actingAs($accounts)->get(route('accountant.requests.index'))->assertOk()->assertSee($request->request_batch_code);
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_labour_agreement_table_reviews_linked_requests_and_exports_all_labour_requests_as_xlsx(): void
    {
        [$project, $pm] = $this->assignedProject();
        $phase = $project->phases()->firstOrFail();
        $agreement = LabourAgreement::create([
            'agreement_code' => 'AGR-TEST-001', 'project_id' => $project->id,
            'fundi_subcontractor' => 'Kijani Masonry Team', 'scope_of_work' => 'Masonry labour',
            'labour_type' => 'subcontractor', 'agreement_date' => today(), 'agreed_value' => '900000.00',
            'status' => 'active', 'created_by' => $pm->id,
        ]);
        $labourRequest = $this->request($project, $phase, ['main_category' => 'labour_subcontracts']);
        $labourRequest->update([
            'request_type' => 'salary_wage', 'labour_agreement_id' => $agreement->id,
            'item_description' => 'Weekly mason wages', 'requested_by' => $pm->id,
        ]);
        $materialRequest = $this->request($project, $phase, ['main_category' => 'materials']);

        $this->actingAs($pm)->get(route('pm.labour.index', ['project_id' => $project->id]))
            ->assertOk()->assertSee('Request Review / Reference')->assertSee('Review '.$labourRequest->request_code)
            ->assertSee('Download All Labour Requests Excel')->assertDontSee('New request');

        $export = $this->actingAs($pm)->get(route('pm.requests.export', [
            'project_id' => $project->id, 'category' => 'labour_subcontracts',
        ]));
        $export->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheet = IOFactory::load($export->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Labour & Subcontract Requests', $sheet->getCell('A1')->getValue());
        $values = json_encode($sheet->toArray());
        $this->assertStringContainsString($labourRequest->request_code, $values);
        $this->assertStringNotContainsString($materialRequest->request_code, $values);
    }

    public function test_rejection_requires_reason_and_unrelated_pm_cannot_decide(): void
    {
        [$project, $pm] = $this->assignedProject();
        [$otherProject, $otherPm] = $this->assignedProject('BWG-PM-DECISION-OTHER');
        $request = $this->request($project, $project->phases()->firstOrFail(), ['total_amount' => null]);
        $this->assertFalse($otherPm->can('reject', $request));
        try {
            app(ExpenseRequestDecisionService::class)->decide($request, 'reject', '', $pm);
            $this->fail('Rejection requires a reason.');
        } catch (ValidationException $exception) {
            $this->assertSame('pending_approval', $request->fresh()->status);
        }
        $approval = app(ExpenseRequestDecisionService::class)->decide($request, 'reject', 'Duplicate need report.', $pm);
        $this->assertSame('rejected', $approval->expenseRequest->fresh()->status);
        $this->assertSame('Duplicate need report.', $approval->notes);
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_pm_dashboard_has_role_specific_landing_and_no_estimator_or_generic_entry_actions(): void
    {
        [$project, $pm] = $this->assignedProject();
        $this->actingAs($pm)->get('/')->assertRedirect(route('pm.projects.index'));
        $this->actingAs($pm)->get('/login')->assertRedirect(route('pm.projects.index'));
        $this->actingAs($pm)->get(route('pm.projects.index'))
            ->assertOk()->assertSee('Project Manager Dashboard')->assertSee($project->project_code)
            ->assertSee('No budget set')->assertDontSee('Estimator Estimates')->assertDontSee('New Entry');
        $this->get(route('pm.projects.show', $project))->assertOk()
            ->assertSee('Phase 7 · External Works')->assertSee('Not created')->assertSee('Independent materials budget for this phase');
    }

    public function test_pm_budget_workspace_shows_future_phases_over_budget_warnings_and_read_only_requests(): void
    {
        [$project, $pm] = $this->assignedProject();
        $service = app(ProjectManagerBudgetService::class);
        $future = $project->phases()->where('stage_code', 'roofing')->firstOrFail();
        $budget = $service->ensureWorkingBudget($project, $pm, $future, 'materials');
        $service->createEntry($budget, $future, $this->entryPayload(['unit_cost' => '100.00']), $pm);
        $estimate = ProjectEstimate::create(['estimate_code' => 'EST-PM-HIDDEN-001', 'project_id' => $project->id, 'version' => 1, 'status' => 'issued']);
        $estimate->entries()->create(['project_phase_id' => $future->id, 'main_category' => 'materials', 'subcategory' => 'concrete_cement', 'item_description' => 'Director-only estimate', 'quantity' => '1', 'unit' => 'ls', 'base_unit_cost' => '987654.00', 'markup_percent' => '0']);
        $request = $this->request($project, $future, ['quantity' => '1', 'total_amount' => '120.00', 'status' => 'approved']);
        ExpensePayment::create([
            'payment_code' => 'PAY-PM-WORKSPACE-001', 'expense_request_id' => $request->id,
            'project_id' => $project->id, 'project_phase_id' => $future->id,
            'amount' => '120.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash',
        ]);
        $response = $this->actingAs($pm)->get(route('pm.projects.show', $project));
        $response->assertOk()->assertSee('Phase 5 · Roofing')->assertSee('Over internal budget by')
            ->assertSee('Site Requests · Read Only')->assertSee('Submit for Director Verification')
            ->assertDontSee('Estimator Base')->assertDontSee('987,654.00')
            ->assertDontSee('Approve Request')->assertDontSee('Reject Request')->assertDontSee('Record Payment');
    }

    public function test_site_item_list_is_grouped_by_supplier_allocated_to_budget_and_pm_approved_directly_for_accounts(): void
    {
        [$project, $pm] = $this->assignedProject();
        $site = $this->user('site_operator');
        $accounts = $this->user('accounts');
        $director = $this->user('director');
        $project->assignedUsers()->attach($site->id);

        $this->actingAs($site)->post(route('site.requests.store', $project), [
            'requested_at' => today()->toDateString(),
            'items' => [
                ['request_type' => 'item', 'item_description' => 'Cement 42.5R', 'quantity' => '10', 'unit' => 'bag', 'requested_amount' => '150000.00', 'notes' => 'Use approved brand'],
                ['request_type' => 'item', 'item_description' => 'Binding wire', 'quantity' => '5', 'unit' => 'kg', 'requested_amount' => '25000.00', 'notes' => '1.6 mm'],
            ],
        ])->assertRedirect();

        $items = ExpenseRequest::query()->where('request_batch_code', 'like', 'SRQ-%')->orderBy('request_line_number')->get();
        $this->assertCount(2, $items);
        $this->assertSame($items[0]->request_batch_code, $items[1]->request_batch_code);
        $this->assertSame('unclassified', $items[0]->main_category);

        $budget = app(ProjectManagerBudgetService::class)->ensureWorkingBudget($project, $pm);
        $entry = app(ProjectManagerBudgetService::class)->createEntry($budget, $items[0]->phase, $this->entryPayload([
            'item_description' => 'General concrete materials', 'unit_cost' => '500000.00',
        ]), $pm);
        app(ProjectManagerBudgetService::class)->finalize($budget, $pm);
        app(ProjectManagerBudgetService::class)->approve($budget->fresh(), $director);
        $supplier = SupplierPayee::create(['name' => 'Grouped Building Supplier', 'type' => 'supplier', 'is_active' => true]);
        $this->actingAs($pm)->get(route('pm.requests.commercial.edit', $items[0]))->assertOk()
            ->assertSee('Supplier Grouping and Commercial Preparation')
            ->assertSee('Cement 42.5R')->assertSee('Binding wire')
            ->assertSee('Approve All Items for Accounts');
        $payload = ['items' => []];
        foreach ($items as $index => $item) {
            $payload['items'][$item->id] = [
                'internal_budget_entry_id' => $entry->id,
                'supplier_payee_id' => $supplier->id,
                'commercial_unit_rate' => $index === 0 ? '14000.00' : '4500.00',
                'commercial_payment_method' => 'cash',
                'commercial_notes' => 'Grouped under one supplier.',
            ];
        }
        $this->actingAs($pm)->put(route('pm.request-batches.commercial.update', $items[0]), $payload)->assertRedirect();
        $this->actingAs($pm)->post(route('pm.request-batches.decision', $items[0]), [
            'decision' => 'approve', 'notes' => 'Prices and budget allocations verified.',
        ])->assertRedirect();

        $items->each(function (ExpenseRequest $item) use ($entry, $supplier): void {
            $item->refresh();
            $this->assertSame('approved', $item->status);
            $this->assertSame($entry->id, $item->internal_budget_entry_id);
            $this->assertSame($supplier->id, $item->supplier_payee_id);
            $this->assertSame(['project_manager'], $item->approvals()->pluck('approval_stage')->all());
        });
        $lineImpact = app(ExpenseRequestDecisionService::class)->impact($items[0]->fresh());
        $this->assertSame($entry->line_total, $lineImpact['budget']);
        $this->assertSame('22500.00', $lineImpact['approved_commitments']);
        $this->actingAs($accounts)->get(route('accountant.requests.index'))->assertOk()
            ->assertSee($items[0]->request_batch_code)->assertDontSee('Cement 42.5R')->assertDontSee('Binding wire');
        $this->actingAs($director)->get(route('director.requests.index'))->assertOk()
            ->assertSee('Cement 42.5R')->assertSee('Binding wire')->assertSee('No Director approval step');
        $this->actingAs($director)->post('/director/request-approvals/'.$items[0]->id.'/decision')->assertNotFound();
    }

    private function assignedProject(?string $code = null): array
    {
        $project = $this->project($code ?? 'BWG-PM-'.fake()->unique()->numerify('#####'));
        $pm = $this->user('project_manager');
        $project->assignedUsers()->attach($pm->id);

        return [$project, $pm];
    }

    private function project(string $code): Project
    {
        $project = Project::factory()->create(['project_code' => $code, 'name' => 'PM Test '.$code, 'client_id' => Client::factory()]);
        foreach (self::STAGES as $index => $stage) {
            $project->phases()->create(['stage_code' => $stage, 'phase_order' => $index + 1, 'status' => $index === 0 ? 'active' : 'not_started']);
        }

        return $project;
    }

    private function user(string $roleName): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $roleName)->value('id'), 'is_active' => true]);
    }

    private function entryPayload(array $override = []): array
    {
        return array_merge(['main_category' => 'materials', 'subcategory' => 'concrete_cement', 'item_description' => 'Concrete materials', 'supplier_fundi_client' => 'Supplier', 'quantity' => '1.00', 'unit' => 'bag', 'unit_cost' => '50.00', 'notes' => 'Budget note'], $override);
    }

    private function request(Project $project, ProjectPhase $phase, array $override = []): ExpenseRequest
    {
        $quantity = $override['quantity'] ?? '1';
        $amount = $override['total_amount'] ?? '50.00';

        return ExpenseRequest::create([
            'request_code' => 'REQ-CMP-'.fake()->unique()->numerify('#####'), 'project_id' => $project->id,
            'project_phase_id' => $phase->id, 'main_category' => $override['main_category'] ?? 'materials',
            'subcategory' => $override['subcategory'] ?? null,
            'item_description' => 'Requested work', 'quantity' => $quantity, 'unit_cost' => $amount,
            'total_amount' => $amount, 'requested_at' => now()->toDateString(), 'submitted_at' => now(),
            'approved_amount' => ($override['status'] ?? 'pending_approval') === 'approved' ? $amount : null,
            'status' => $override['status'] ?? 'pending_approval',
        ]);
    }
}
