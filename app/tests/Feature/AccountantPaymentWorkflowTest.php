<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestApproval;
use App\Models\Project;
use App\Models\Role;
use App\Models\SupplierPayee;
use App\Models\User;
use App\Services\ExpensePaymentRecorder;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountantPaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        foreach (['admin', 'accounts', 'project_manager', 'site_operator', 'estimator_qs'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
        Storage::fake('local');
    }

    public function test_accountant_queue_contains_only_approved_requests_and_dashboard_metrics_are_derived(): void
    {
        [$project, $accountant] = $this->projectAndAccountant();
        $approved = $this->approvedRequest($project, '100.00');
        $pending = $this->request($project, 'pending_approval');
        $rejected = $this->request($project, 'rejected');
        $cancelled = $this->request($project, 'cancelled');

        $this->actingAs($accountant)->get(route('accountant.requests.index'))
            ->assertOk()->assertSee($approved->request_code)
            ->assertDontSee($pending->request_code)->assertDontSee($rejected->request_code)->assertDontSee($cancelled->request_code);
        $this->actingAs($accountant)->get(route('accountant.index'))
            ->assertOk()->assertSee('Approved Awaiting Payment')->assertSee('Amount Awaiting Payment');
        $this->actingAs($accountant)->get(route('accountant.projects.show', $project))
            ->assertOk()->assertSee('Total Approved Requests')->assertDontSee('Pending PM Review')
            ->assertSee('project_id='.$project->id.'&amp;filter=all', false);
        $this->actingAs($accountant)->get(route('accountant.requests.index', ['project_id' => $project->id, 'filter' => 'all']))
            ->assertOk()->assertSee($project->name.' · Approved Payments')
            ->assertSee('project_id='.$project->id.'&amp;filter=part_paid', false)
            ->assertSee('project_id='.$project->id.'&amp;filter=paid', false);
    }

    public function test_accountant_sees_one_complete_request_with_supplier_groups_and_item_totals(): void
    {
        [$project, $accountant] = $this->projectAndAccountant();
        $hardware = SupplierPayee::create(['name' => 'Harbour Hardware', 'type' => 'supplier', 'contact_person' => 'Halima', 'phone' => '0711 000 111', 'address' => 'Kurasini, Dar es Salaam', 'is_active' => true]);
        $plant = SupplierPayee::create(['name' => 'Coastal Plant Hire', 'type' => 'supplier', 'is_active' => true]);
        $first = $this->approvedRequest($project, '100.00');
        $first->update(['request_batch_code' => 'SRQ-GROUP-001', 'request_line_number' => 1, 'supplier_payee_id' => $hardware->id, 'item_description' => 'Cement', 'commercial_notes' => 'PM checked quantity, supplier grouping and internal-budget allocation.']);
        $second = $this->approvedRequest($project, '250.00');
        $second->update(['request_batch_code' => 'SRQ-GROUP-001', 'request_line_number' => 2, 'supplier_payee_id' => $plant->id, 'item_description' => 'Excavator hire']);

        $queue = $this->actingAs($accountant)->get(route('accountant.requests.index', ['filter' => 'all']));
        $queue->assertOk()->assertSeeInOrder(['SRQ-GROUP-001', '2', '2', '350.00'])
            ->assertDontSee('Cement')->assertDontSee('Excavator hire');
        $this->assertSame(1, substr_count($queue->getContent(), '<strong>SRQ-GROUP-001</strong>'));

        $this->actingAs($accountant)->get(route('accountant.requests.show', $first))
            ->assertOk()->assertSee('2 supplier group(s)')->assertSee('Harbour Hardware')
            ->assertSee('Halima')->assertSee('0711 000 111')->assertSee('Kurasini, Dar es Salaam')
            ->assertSee('Coastal Plant Hire')->assertSee('Excavator hire')->assertSee('350.00')
            ->assertSee('Pay This Supplier Group')->assertDontSee('Original Request')->assertDontSee('Requested By')
            ->assertDontSee('PM checked quantity, supplier grouping and internal-budget allocation.')
            ->assertSee('project_id='.$project->id.'&amp;filter=all', false);

        app(ExpensePaymentRecorder::class)->record($first->fresh(), ['amount' => '50.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer'], $accountant);
        $this->actingAs($accountant)->get(route('accountant.projects.show', $project))
            ->assertOk()->assertSeeInOrder(['Approved Awaiting Payment', '1', 'Part Paid', '1']);
    }

    public function test_accountant_records_partial_payments_as_events_and_derives_status_and_outstanding(): void
    {
        [$project, $accountant] = $this->projectAndAccountant();
        $request = $this->approvedRequest($project, '1000.00', 'bank_transfer');
        $recorder = app(ExpensePaymentRecorder::class);
        $first = $recorder->record($request, ['amount' => '600.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer', 'transaction_reference' => 'TX-1'], $accountant);
        $this->assertSame('600.00', $first->amount);
        $this->assertSame('part_paid', $request->fresh()->payment_status);
        $this->assertSame('400.00', $request->fresh()->approved_outstanding_amount);
        $second = $recorder->record($request, ['amount' => '400.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer', 'transaction_reference' => 'TX-2'], $accountant);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, ExpensePayment::where('expense_request_id', $request->id)->count());
        $this->assertSame('paid', $request->fresh()->payment_status);
        $this->assertSame('0.00', $request->fresh()->approved_outstanding_amount);
    }

    public function test_overpayment_zero_payment_and_method_variance_are_server_side_protected(): void
    {
        [$project, $accountant] = $this->projectAndAccountant();
        $request = $this->approvedRequest($project, '100.00', 'bank_transfer');
        $recorder = app(ExpensePaymentRecorder::class);
        try {
            $recorder->record($request, ['amount' => '0.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer'], $accountant);
            $this->fail('Zero payment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        try {
            $recorder->record($request, ['amount' => '101.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer'], $accountant);
            $this->fail('Overpayment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->actingAs($accountant)->post(route('accountant.payments.store', $request), [
            'expense_request_id' => $request->id, 'project_id' => $request->project_id, 'project_phase_id' => $request->project_phase_id,
            'amount' => '50.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash',
        ])->assertSessionHasErrors('variance_reason');
    }

    public function test_private_payment_evidence_and_approval_snapshot_are_visible_to_accountant_not_site_or_estimator(): void
    {
        [$project, $accountant] = $this->projectAndAccountant();
        $request = $this->approvedRequest($project, '250.00');
        $this->actingAs($accountant)->get(route('accountant.requests.show', $request))
            ->assertOk()->assertSee('Supplier Groups, Approved Items and Payments')->assertSee('ABC Hardware')
            ->assertSee('Asha')->assertSee('12345')->assertSee('Pay This Supplier Group')
            ->assertDontSee('Original Request')->assertDontSee('Requested By');
        $payment = app(ExpensePaymentRecorder::class)->record($request, ['amount' => '250.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer'], $accountant, UploadedFile::fake()->create('bank-confirmation.pdf', 100, 'application/pdf'));
        $this->assertSame('bank-confirmation.pdf', $payment->evidence_original_name);
        Storage::disk('local')->assertExists($payment->evidence_link);
        $this->actingAs($accountant)->get(route('accountant.payments.evidence', $payment))->assertDownload('bank-confirmation.pdf');
        $this->actingAs($this->user('site_operator'))->get(route('accountant.requests.show', $request))->assertForbidden();
        $this->actingAs($this->user('estimator_qs'))->get(route('accountant.requests.show', $request))->assertForbidden();
    }

    public function test_pm_can_view_payment_progress_but_cannot_record_payment_and_legacy_transactions_remain_separate(): void
    {
        [$project, $accountant] = $this->projectAndAccountant();
        $pm = $this->user('project_manager');
        $project->assignedUsers()->attach($pm->id);
        $request = $this->approvedRequest($project, '100.00');
        app(ExpensePaymentRecorder::class)->record($request, ['amount' => '40.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer'], $accountant);
        $this->assertSame('40.00', $request->fresh()->paid_to_date);
        $this->actingAs($pm)->post(route('accountant.payments.store', $request), [])->assertForbidden();
        $this->assertDatabaseCount('transactions', 0);
    }

    private function approvedRequest(Project $project, string $amount, string $method = 'bank_transfer'): ExpenseRequest
    {
        $supplier = SupplierPayee::firstOrCreate(['name' => 'ABC Hardware'], ['type' => 'supplier', 'contact_person' => 'Asha', 'phone' => '0712']);
        $request = $this->request($project, 'approved', $amount);
        $request->update(['supplier_payee_id' => $supplier->id, 'commercial_unit_rate' => $amount, 'commercial_total' => $amount, 'commercial_payment_method' => $method, 'commercial_payment_destination' => 'Bank | ABC Account | 12345', 'approved_amount' => $amount]);
        ExpenseRequestApproval::create([
            'expense_request_id' => $request->id, 'decision' => 'approved', 'approved_amount' => $amount,
            'approved_unit_rate' => $amount, 'approved_total' => $amount, 'supplier_payee_id' => $supplier->id,
            'supplier_name_snapshot' => 'ABC Hardware', 'supplier_contact_snapshot' => 'Asha', 'supplier_phone_snapshot' => '0712',
            'payment_method_snapshot' => $method, 'payment_destination_snapshot' => 'Bank | ABC Account | 12345',
            'decided_by' => $this->user('project_manager')->id, 'decided_at' => now(), 'notes' => 'Approved.',
        ]);

        return $request->fresh();
    }

    private function request(Project $project, string $status, ?string $amount = null): ExpenseRequest
    {
        return ExpenseRequest::create([
            'request_code' => 'REQ-'.fake()->unique()->numerify('######'), 'project_id' => $project->id,
            'project_phase_id' => $project->phases()->first()->id, 'main_category' => 'materials',
            'item_description' => 'Cement', 'quantity' => '1.00', 'unit' => 'bag', 'total_amount' => $amount,
            'requested_at' => now()->toDateString(), 'submitted_at' => now(), 'status' => $status,
        ]);
    }

    private function projectAndAccountant(): array
    {
        $project = Project::factory()->create(['client_id' => Client::factory(), 'project_code' => 'BWG-ACC-'.fake()->unique()->numerify('#####')]);
        $project->phases()->create(['stage_code' => 'preliminaries', 'phase_order' => 1, 'status' => 'active']);
        $accountant = $this->user('accounts');

        return [$project, $accountant];
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'is_active' => true]);
    }
}
