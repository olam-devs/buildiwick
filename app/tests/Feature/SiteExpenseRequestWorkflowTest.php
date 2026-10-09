<?php

namespace Tests\Feature;

use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\ExpensePaymentRecorder;
use App\Services\SiteExpenseRequestService;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteExpenseRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const STAGES = ['preliminaries', 'substructure', 'superstructure', 'walling', 'roofing', 'finishing', 'external_works'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        foreach (['admin', 'accounts', 'estimator_qs', 'project_manager', 'site_operator'] as $name) {
            Role::firstOrCreate(['name' => $name], ['description' => $name]);
        }
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_site_operator_sees_only_assigned_projects_and_unassigned_project_urls_are_denied(): void
    {
        [$assigned, $operator] = $this->assignedProject();
        $unassigned = $this->project('BWG-UNASSIGNED-001');

        $this->actingAs($operator)->get(route('site.index'))->assertOk()->assertSee($assigned->project_code)->assertDontSee($unassigned->project_code);
        $this->actingAs($operator)->get(route('site.projects.show', $unassigned))->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('site.index'))->assertOk()->assertSee($assigned->project_code)->assertSee($unassigned->project_code);
        foreach (['accounts', 'estimator_qs', 'project_manager'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get(route('site.index'))->assertForbidden();
            $this->get(route('site.requests.index'))->assertForbidden();
            $this->get(route('site.projects.show', $assigned))->assertForbidden();
            $this->get(route('site.requests.create', $assigned))->assertForbidden();
            $this->post(route('site.requests.store', $assigned), $this->payload())->assertForbidden();
        }
    }

    public function test_request_creation_binds_exactly_one_active_phase_and_ignores_submitted_phase_and_total(): void
    {
        [$project, $operator, $phases] = $this->assignedProject();
        $payload = $this->payload([
            'quantity' => '2.50', 'unit_cost' => '100.25',
            'project_phase_id' => $phases[4]->id,
            'total_amount' => '0.01',
        ]);

        $this->actingAs($operator)->post(route('site.requests.store', $project), $payload)->assertRedirect();
        $request = ExpenseRequest::firstOrFail();
        $this->assertSame($project->id, $request->project_id);
        $this->assertSame($phases[0]->id, $request->project_phase_id);
        $this->assertSame($operator->id, $request->requested_by);
        $this->assertSame('pending_approval', $request->status);
        $this->assertNotNull($request->submitted_at);
        $this->assertNull($request->total_amount);
        $this->assertNull($request->unit_cost);
        $this->assertSame('unpaid', $request->payment_status);
        $this->assertSame('REQ-'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT), $request->request_code);
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_unconfigured_or_ambiguous_current_phase_blocks_request_creation(): void
    {
        [$project, $operator, $phases] = $this->assignedProject();
        $project->phases()->whereKey($phases[0]->id)->update(['status' => 'completed']);
        $this->actingAs($operator)->get(route('site.requests.create', $project))->assertOk()->assertSee('no active construction phase configured');
        $this->actingAs($operator)->from(route('site.projects.show', $project))->post(route('site.requests.store', $project), $this->payload())
            ->assertRedirect(route('site.projects.show', $project))->assertSessionHasErrors('project_phase_id');

        $project->phases()->whereKey($phases[0]->id)->update(['status' => 'active']);
        $project->phases()->whereKey($phases[1]->id)->update(['status' => 'active']);
        $this->actingAs($operator)->get(route('site.requests.create', $project))->assertOk()->assertSee('multiple active construction phases');
        $this->actingAs($operator)->from(route('site.projects.show', $project))->post(route('site.requests.store', $project), $this->payload())
            ->assertRedirect(route('site.projects.show', $project))->assertSessionHasErrors('project_phase_id');
    }

    public function test_requested_total_uses_exact_server_calculation_and_lump_sum_defaults_quantity_to_one(): void
    {
        [$project, $operator] = $this->assignedProject();
        $payload = $this->payload(['quantity' => '1.33', 'unit_cost' => '19.99', 'total_amount' => '999999.99']);
        $this->actingAs($operator)->post(route('site.requests.store', $project), $payload)->assertRedirect();
        $request = ExpenseRequest::firstOrFail();
        $this->assertNull($request->total_amount);
        $this->assertSame('1.33', $request->quantity);

        $payload = $this->payload(['quantity' => '', 'unit' => 'ls', 'unit_cost' => '1234.56']);
        $this->actingAs($operator)->post(route('site.requests.store', $project), $payload)->assertRedirect();
        $lumpSum = ExpenseRequest::where('unit', 'ls')->firstOrFail();
        $this->assertNull($lumpSum->quantity);
        $this->assertNull($lumpSum->total_amount);
    }

    public function test_categories_and_subcategories_validate_while_nullable_fallback_is_allowed(): void
    {
        [$project, $operator] = $this->assignedProject();
        $bad = $this->payload(['main_category' => 'logistics_equipment', 'subcategory' => 'fundi_labour']);
        $this->actingAs($operator)->from(route('site.requests.create', $project))->post(route('site.requests.store', $project), $bad)
            ->assertRedirect(route('site.requests.create', $project))->assertSessionHasErrors('subcategory');

        $fallback = $this->payload(['main_category' => 'logistics_equipment', 'subcategory' => null]);
        $this->actingAs($operator)->post(route('site.requests.store', $project), $fallback)->assertRedirect();
        $this->assertSame('logistics_equipment', ExpenseRequest::firstOrFail()->main_category);
        $this->assertNull(ExpenseRequest::firstOrFail()->subcategory);
    }

    public function test_site_operator_cannot_inject_commercial_fields_into_a_need_request(): void
    {
        [$project, $operator] = $this->assignedProject();
        $payload = $this->payload([
            'supplier_fundi_client' => 'Injected Supplier',
            'unit_cost' => '999.00',
            'total_amount' => '999.00',
            'quantity' => '50',
        ]);

        $this->actingAs($operator)->post(route('site.requests.store', $project), $payload)->assertRedirect();
        $request = ExpenseRequest::firstOrFail();
        $this->assertNull($request->supplier_fundi_client);
        $this->assertNull($request->unit_cost);
        $this->assertNull($request->total_amount);
    }

    public function test_all_cost_categories_and_construction_units_are_available_to_site_requests(): void
    {
        [$project, $operator] = $this->assignedProject();
        foreach ([
            ['main_category' => 'materials', 'unit' => 'bag'],
            ['main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour', 'unit' => 'worker_day'],
            ['main_category' => 'logistics_equipment', 'subcategory' => null, 'unit' => 'trip'],
            ['main_category' => 'preliminaries_overheads', 'subcategory' => null, 'unit' => 'service'],
        ] as $entry) {
            $this->actingAs($operator)->post(route('site.requests.store', $project), $this->payload($entry))->assertRedirect();
        }
        $this->assertSame(4, ExpenseRequest::count());
        $this->actingAs($operator)->get(route('site.requests.create', $project))
            ->assertOk()->assertSee('Suggested units move to the top by category')->assertSee('worker_day');
    }

    public function test_request_submission_does_not_change_approval_to_paid_and_only_expense_payment_rows_are_paid(): void
    {
        [$project, $operator] = $this->assignedProject();
        $this->actingAs($operator)->post(route('site.requests.store', $project), $this->payload())->assertRedirect();
        $request = ExpenseRequest::firstOrFail();
        $this->assertSame('pending_approval', $request->status);
        $this->assertSame('unpaid', $request->payment_status);
        $this->assertSame('0.00', $request->paid_to_date);
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_only_the_request_owner_can_edit_untouched_pending_request_and_edit_recalculates_server_total(): void
    {
        [$project, $operator] = $this->assignedProject();
        $this->actingAs($operator)->post(route('site.requests.store', $project), $this->payload())->assertRedirect();
        $request = ExpenseRequest::firstOrFail();
        $otherOperator = $this->user('site_operator');
        $project->assignedUsers()->attach($otherOperator->id);

        $this->actingAs($otherOperator)->get(route('site.requests.edit', [$project, $request]))->assertForbidden();
        $this->actingAs($otherOperator)->put(route('site.requests.update', [$project, $request]), [])->assertForbidden();

        $update = $this->payload(['quantity' => '4.00', 'unit_cost' => '12.25', 'total_amount' => '0.01']);
        $this->actingAs($operator)->put(route('site.requests.update', [$project, $request]), $update)->assertRedirect();
        $this->assertNull($request->fresh()->total_amount);
        $this->assertNull($request->fresh()->unit_cost);
        $this->assertSame('pending_approval', $request->fresh()->status);
    }

    public function test_approved_rejected_paid_or_decided_requests_are_locked_and_cancellation_retains_history(): void
    {
        [$project, $operator] = $this->assignedProject();
        $service = app(SiteExpenseRequestService::class);
        $base = $service->create($project, $this->payload(), $operator);
        $base->approvals()->create(['decision' => 'approved', 'decided_by' => $this->user('project_manager')->id, 'decided_at' => now()]);
        $base->update(['status' => 'approved', 'approved_amount' => '100.00']);
        $this->actingAs($operator)->put(route('site.requests.update', [$project, $base]), $this->payload())->assertForbidden();
        $this->actingAs($operator)->post(route('site.requests.cancel', [$project, $base]))->assertForbidden();

        $rejected = $service->create($project, $this->payload(), $operator);
        $rejected->approvals()->create(['decision' => 'rejected', 'decided_by' => $this->user('project_manager')->id, 'decided_at' => now()]);
        $rejected->update(['status' => 'rejected']);
        $this->actingAs($operator)->put(route('site.requests.update', [$project, $rejected]), $this->payload())->assertForbidden();

        $paid = $service->create($project, $this->payload(), $operator);
        $paid->update(['status' => 'approved', 'approved_amount' => $paid->total_amount]);
        $accounts = $this->user('accounts');
        app(ExpensePaymentRecorder::class)->record($paid, [
            'amount' => $paid->total_amount,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], $accounts);
        $this->actingAs($operator)->put(route('site.requests.update', [$project, $paid]), $this->payload())->assertForbidden();
        $this->actingAs($operator)->post(route('site.requests.cancel', [$project, $paid]))->assertForbidden();

        $cancelled = $service->create($project, $this->payload(), $operator);
        $this->actingAs($operator)->post(route('site.requests.cancel', [$project, $cancelled]))->assertRedirect();
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertNotNull($cancelled->fresh()->cancelled_at);
        $this->assertDatabaseHas('expense_requests', ['id' => $cancelled->id]);
    }

    public function test_site_operator_cannot_create_actual_payments_or_use_legacy_transaction_api_or_web_form(): void
    {
        [$project, $operator] = $this->assignedProject();
        $request = ExpenseRequest::create([
            'request_code' => 'REQ-TEST-000999', 'project_id' => $project->id,
            'project_phase_id' => $project->phases()->first()->id, 'main_category' => 'materials',
            'item_description' => 'Approved test purchase', 'total_amount' => '50.00',
            'approved_amount' => '50.00', 'requested_at' => now()->toDateString(),
            'submitted_at' => now(), 'status' => 'approved', 'requested_by' => $operator->id,
        ]);
        try {
            app(ExpensePaymentRecorder::class)->record($request, [
                'amount' => '1.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
            ], $operator);
            $this->fail('Site Operator cannot record actual payment.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('expense_payments', 0);
        }

        $this->actingAs($operator)->post('/entries/transactions', [
            'project_id' => $project->id, 'main_category' => 'materials', 'item_description' => 'Bypass attempt',
            'total_amount' => '5.00', 'transaction_date' => now()->toDateString(),
        ])->assertForbidden();

        $token = $operator->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/transactions', [
            'project_id' => $project->id, 'main_category' => 'materials', 'item_description' => 'API bypass attempt',
            'total_amount' => '5.00', 'transaction_date' => now()->toDateString(),
        ])->assertForbidden();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_private_request_evidence_is_retrievable_only_to_authorized_assigned_project_users(): void
    {
        [$project, $operator] = $this->assignedProject();
        $payload = $this->payload(['evidence' => UploadedFile::fake()->create('supplier-quote.pdf', 100, 'application/pdf')]);
        $this->actingAs($operator)->post(route('site.requests.store', $project), $payload)->assertRedirect();
        $request = ExpenseRequest::firstOrFail();
        $this->assertSame('supplier-quote.pdf', $request->evidence_original_name);
        Storage::disk('local')->assertExists($request->evidence_link);
        $this->assertFalse(Storage::disk('public')->exists($request->evidence_link));

        $this->actingAs($operator)->get(route('site.requests.evidence', [$project, $request]))->assertDownload('supplier-quote.pdf');
        $other = $this->project('BWG-EVIDENCE-OTHER');
        $this->actingAs($operator)->get(route('site.requests.evidence', [$other, $request]))->assertNotFound();
        $this->actingAs($this->user('estimator_qs'))->get(route('site.requests.evidence', [$project, $request]))->assertForbidden();
    }

    public function test_request_detail_renders_request_approval_payment_sections_and_status_badges(): void
    {
        [$project, $operator] = $this->assignedProject();
        $this->actingAs($operator)->post(route('site.requests.store', $project), $this->payload())->assertRedirect();
        $request = ExpenseRequest::firstOrFail();

        $this->actingAs($operator)->get(route('site.requests.show', [$project, $request]))
            ->assertOk()
            ->assertSee($request->request_code)
            ->assertSee('Approval History')
            ->assertSee('Payment Summary')
            ->assertSee('Pending Approval')
            ->assertSee('Unpaid')
            ->assertSee('Edit Pending Request')
            ->assertSee('Cancel Request');
    }

    public function test_unsafe_evidence_file_types_are_rejected_before_request_creation(): void
    {
        [$project, $operator] = $this->assignedProject();
        $payload = $this->payload(['evidence' => UploadedFile::fake()->create('malware.exe', 25, 'application/x-msdownload')]);

        $this->actingAs($operator)->from(route('site.requests.create', $project))
            ->post(route('site.requests.store', $project), $payload)
            ->assertRedirect(route('site.requests.create', $project))
            ->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('expense_requests', 0);
    }

    public function test_project_summaries_derive_requested_pending_approved_rejected_paid_outstanding_and_categories(): void
    {
        [$project, $operator] = $this->assignedProject();
        $service = app(SiteExpenseRequestService::class);
        $pending = $service->create($project, $this->payload(['main_category' => 'materials', 'quantity' => '2', 'unit_cost' => '10.00']), $operator);
        $approved = $service->create($project, $this->payload(['main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour', 'quantity' => '1', 'unit_cost' => '100.00']), $operator);
        $approved->update(['status' => 'approved', 'approved_amount' => '80.00']);
        $rejected = $service->create($project, $this->payload(['main_category' => 'logistics_equipment', 'subcategory' => null, 'quantity' => '1', 'unit_cost' => '30.00']), $operator);
        $rejected->update(['status' => 'rejected']);
        $cancelled = $service->create($project, $this->payload(['main_category' => 'preliminaries_overheads', 'subcategory' => null, 'quantity' => '1', 'unit_cost' => '999.00']), $operator);
        $cancelled->update(['status' => 'cancelled']);

        ExpensePayment::create([
            'payment_code' => 'EXP-PAY-TEST-0001', 'expense_request_id' => $approved->id,
            'project_id' => $project->id, 'project_phase_id' => $approved->project_phase_id,
            'amount' => '25.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
        ]);

        $summary = $service->projectSummary($project);
        $this->assertSame('150.00', $summary['requested']);
        $this->assertSame(1, $summary['pending_count']);
        $this->assertSame('20.00', $summary['pending_amount']);
        $this->assertSame('80.00', $summary['approved_amount']);
        $this->assertSame('30.00', $summary['rejected_amount']);
        $this->assertSame('25.00', $summary['paid']);
        $this->assertSame('55.00', $summary['approved_outstanding']);
        $this->assertSame('20.00', $summary['categories']['materials']['requested']);
        $this->assertSame('80.00', $summary['categories']['labour_subcontracts']['approved']);
        $this->assertSame('25.00', $summary['categories']['labour_subcontracts']['paid']);
    }

    public function test_site_dashboard_project_detail_and_navigation_render_role_specific_actions_and_empty_states(): void
    {
        [$project, $operator] = $this->assignedProject();
        $this->actingAs($operator)->get('/')->assertRedirect(route('site.index'));
        $this->actingAs($operator)->get('/login')->assertRedirect(route('site.index'));
        $this->actingAs($operator)->get(route('site.index'))->assertOk()
            ->assertSee('Site Operator Dashboard')->assertSee('No requests')
            ->assertDontSee('Estimator Dashboard')->assertDontSee('Estimator Estimates')->assertDontSee('New Entry');
        $this->actingAs($operator)->get(route('site.projects.show', $project))->assertOk()
            ->assertSee('Total Requests')->assertSee('Awaiting PM Pricing/Review')->assertSee('No expense requests have been submitted');
        $this->actingAs($operator)->get(route('site.requests.index'))->assertOk()->assertSee('No expense requests yet.');
    }

    public function test_salary_and_cash_requests_require_an_amount_while_items_and_services_do_not(): void
    {
        [$project, $operator] = $this->assignedProject();
        $this->actingAs($operator)->from(route('site.requests.create', $project))
            ->post(route('site.requests.store', $project), $this->payload(['request_type' => 'salary_wage', 'requested_amount' => null]))
            ->assertSessionHasErrors('requested_amount');

        $this->actingAs($operator)->post(route('site.requests.store', $project), $this->payload([
            'request_type' => 'cash_amount', 'requested_amount' => '75000.00', 'item_description' => 'Petty cash float',
        ]))->assertRedirect();
        $cash = ExpenseRequest::latest('id')->firstOrFail();
        $this->assertSame('cash_amount', $cash->request_type);
        $this->assertSame('75000.00', $cash->requested_amount);

        $this->actingAs($operator)->post(route('site.requests.store', $project), $this->payload([
            'request_type' => 'service', 'requested_amount' => null, 'item_description' => 'Crane hire service',
        ]))->assertRedirect();
        $this->assertSame('service', ExpenseRequest::latest('id')->value('request_type'));
    }

    private function assignedProject(): array
    {
        $project = $this->project('BWG-SITE-'.fake()->unique()->numerify('#####'));
        $operator = $this->user('site_operator');
        $project->assignedUsers()->attach($operator->id);

        return [$project, $operator, $project->phases()->orderBy('phase_order')->get()];
    }

    private function project(string $code): Project
    {
        $project = Project::factory()->create(['project_code' => $code, 'name' => 'Site Test '.$code]);
        foreach (self::STAGES as $index => $stage) {
            $project->phases()->create([
                'stage_code' => $stage, 'phase_order' => $index + 1,
                'status' => $index === 0 ? 'active' : 'not_started',
            ]);
        }

        return $project;
    }

    private function user(string $roleName): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $roleName)->value('id'), 'is_active' => true]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'requested_at' => now()->toDateString(),
            'request_type' => 'item',
            'main_category' => 'materials',
            'subcategory' => 'concrete_cement',
            'item_description' => 'Cement for current work',
            'supplier_fundi_client' => 'Supplier Ltd',
            'quantity' => '1.00',
            'unit' => 'bag',
            'unit_cost' => '100.00',
            'notes' => 'Site request note',
        ], $override);
    }
}
