<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountantReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountantReportingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\LookupSeeder::class);
        foreach (['admin', 'accounts', 'project_manager', 'site_operator', 'estimator_qs'] as $role) Role::firstOrCreate(['name' => $role], ['description' => $role]);
    }

    public function test_transaction_register_uses_only_payment_events_and_deterministic_running_balance(): void
    {
        [$project, $accountant, $phase] = $this->project();
        $invoice = ClientInvoice::create(['invoice_code' => 'INV-REG-001', 'project_id' => $project->id, 'client_id' => $project->client_id, 'status' => 'issued', 'issued_at' => '2026-09-01', 'due_at' => '2026-10-01']);
        $invoice->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Invoice', 'unit_price' => '1000.00']);
        ClientPayment::create(['payment_code' => 'RCP-REG-001', 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '1000.00', 'payment_date' => '2026-09-01', 'payment_method' => 'cash', 'recorded_by' => $accountant->id]);
        $request = ExpenseRequest::create(['request_code' => 'REQ-REG-001', 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Cement', 'status' => 'approved', 'requested_at' => '2026-09-01', 'submitted_at' => now()]);
        ExpensePayment::create(['payment_code' => 'PAY-REG-001', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '300.00', 'payment_date' => '2026-09-03', 'payment_method' => 'cash', 'recorded_by' => $accountant->id]);
        $events = app(AccountantReportingService::class)->transactions($project);
        $this->assertCount(2, $events);
        $this->assertSame('1000.00', $events[0]['balance']);
        $this->assertSame('700.00', $events[1]['balance']);
        $this->assertSame(['Client Payment', 'Expense Payment'], $events->pluck('type')->all());
    }

    public function test_statement_period_calculates_opening_movement_and_closing_from_actual_events(): void
    {
        [$project, $accountant, $phase] = $this->project();
        $invoice = ClientInvoice::create(['invoice_code' => 'INV-REG-002', 'project_id' => $project->id, 'client_id' => $project->client_id, 'status' => 'issued', 'issued_at' => '2026-09-01', 'due_at' => '2026-10-01']);
        $invoice->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Invoice', 'unit_price' => '1000.00']);
        ClientPayment::create(['payment_code' => 'RCP-REG-002', 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '1000.00', 'payment_date' => '2026-09-01', 'payment_method' => 'cash']);
        $request = ExpenseRequest::create(['request_code' => 'REQ-REG-002', 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Cement', 'status' => 'approved', 'requested_at' => '2026-09-01', 'submitted_at' => now()]);
        ExpensePayment::create(['payment_code' => 'PAY-REG-002', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '200.00', 'payment_date' => '2026-09-02', 'payment_method' => 'cash']);
        ExpensePayment::create(['payment_code' => 'PAY-REG-003', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '300.00', 'payment_date' => '2026-09-10', 'payment_method' => 'cash']);
        $statement = app(AccountantReportingService::class)->statement($project, ['from' => now()->parse('2026-09-05'), 'to' => now()->parse('2026-09-30')]);
        $this->assertSame('800.00', $statement['opening']);
        $this->assertSame('0.00', $statement['in']);
        $this->assertSame('300.00', $statement['out']);
        $this->assertSame('500.00', $statement['closing']);
    }

    public function test_receivables_and_payables_exclude_draft_void_pending_rejected_and_cancelled_records(): void
    {
        [$project, $accountant, $phase] = $this->project();
        foreach ([['code' => 'INV-ISS-001', 'status' => 'issued'], ['code' => 'INV-DRAFT-001', 'status' => 'draft'], ['code' => 'INV-VOID-001', 'status' => 'void']] as $row) {
            $invoice = ClientInvoice::create(['invoice_code' => $row['code'], 'project_id' => $project->id, 'client_id' => $project->client_id, 'status' => $row['status'], 'issued_at' => $row['status'] === 'issued' ? '2026-09-01' : null, 'due_at' => '2026-10-01']);
            $invoice->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Line', 'unit_price' => '100.00']);
        }
        foreach (['approved', 'pending_approval', 'rejected', 'cancelled'] as $status) ExpenseRequest::create(['request_code' => 'REQ-'.strtoupper($status), 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => $status, 'status' => $status, 'requested_at' => now()->toDateString(), 'submitted_at' => now(), 'approved_amount' => $status === 'approved' ? '100.00' : null]);
        $reporting = app(AccountantReportingService::class);
        $this->assertCount(1, $reporting->receivables());
        $this->assertCount(1, $reporting->payables());
        $this->actingAs($accountant)->get(route('accountant.transactions.index'))->assertOk();
        $this->actingAs($this->user('project_manager'))->get(route('accountant.transactions.index'))->assertForbidden();
    }

    private function project(): array
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id, 'project_code' => 'BWG-REPORT-'.fake()->unique()->numerify('#####')]);
        $phase = $project->phases()->create(['stage_code' => 'preliminaries', 'phase_order' => 1, 'status' => 'active']);
        return [$project, $this->user('accounts'), $phase];
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'is_active' => true]);
    }
}