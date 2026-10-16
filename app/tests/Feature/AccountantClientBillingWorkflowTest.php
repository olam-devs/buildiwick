<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\EstimateEntry;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\Role;
use App\Models\User;
use App\Services\ClientInvoiceService;
use App\Services\ClientPaymentRecorder;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountantClientBillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        foreach (['admin', 'accounts', 'project_manager', 'site_operator', 'estimator_qs'] as $role) Role::firstOrCreate(['name' => $role], ['description' => $role]);
        Storage::fake('local');
    }

    public function test_draft_invoice_uses_issued_estimate_client_price_and_issue_freezes_lifecycle(): void
    {
        [$project, $accountant, $phase] = $this->projectAndAccountant();
        $estimate = ProjectEstimate::create(['estimate_code' => 'EST-TEST-001', 'project_id' => $project->id, 'client_id' => $project->client_id, 'version' => 1, 'status' => 'issued']);
        $estimate->entries()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Cement', 'quantity' => '2', 'unit' => 'bag', 'base_unit_cost' => '100.00', 'markup_percent' => '20.0000']);
        $invoice = app(ClientInvoiceService::class)->createDraft($project, ['project_estimate_id' => $estimate->id, 'due_at' => now()->addDays(30)->toDateString(), 'reference' => 'Progress 1', 'items' => [['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Cement', 'quantity' => '2', 'unit' => 'bag', 'unit_price' => '120.00']],], $accountant);
        $this->assertSame('240.00', $invoice->total_amount);
        $this->assertSame('draft', $invoice->status);
        $issued = app(ClientInvoiceService::class)->issue($invoice, $accountant);
        $this->assertSame('issued', $issued->status);
        $this->assertNotNull($issued->issued_by);
        $this->actingAs($accountant)->get(route('accountant.projects.show', $project))->assertOk()->assertSee('Recorded Cash Balance')->assertSee('Client Billing');
    }

    public function test_client_payment_events_support_partial_payment_and_private_evidence(): void
    {
        [$project, $accountant, $phase] = $this->projectAndAccountant();
        $invoice = $this->issuedInvoice($project, $phase, $accountant, '1000.00');
        $recorder = app(ClientPaymentRecorder::class);
        $first = $recorder->record($invoice, ['amount' => '400.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer', 'transaction_reference' => 'BANK-1'], $accountant, UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'));
        $this->assertSame('receipt.pdf', $first->evidence_original_name);
        Storage::disk('local')->assertExists($first->evidence_link);
        $this->assertSame('part_paid', $invoice->fresh()->payment_status);
        $this->assertSame('600.00', $invoice->fresh()->outstanding_amount);
        $second = $recorder->record($invoice, ['amount' => '600.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer'], $accountant);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, ClientPayment::where('client_invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->fresh()->payment_status);
    }

    public function test_create_from_estimate_is_primary_and_accepts_selected_partial_claim_amount(): void
    {
        [$project, $accountant, $phase] = $this->projectAndAccountant();
        $estimate = ProjectEstimate::create([
            'estimate_code' => 'EST-INVOICE-SOURCE-001', 'project_id' => $project->id,
            'client_id' => $project->client_id, 'version' => 1, 'status' => 'issued',
        ]);
        $entry = $estimate->entries()->create([
            'project_phase_id' => $phase->id, 'main_category' => 'materials',
            'item_description' => 'Foundation concrete', 'quantity' => '10', 'unit' => 'm3',
            'base_unit_cost' => '100.00', 'markup_percent' => '20.0000',
        ]);

        $projectPage = $this->actingAs($accountant)->get(route('accountant.projects.show', $project));
        $projectPage->assertOk()
            ->assertSeeInOrder(['Create from Estimate', 'Manual Invoice', 'Transaction Register'])
            ->assertSee(route('accountant.invoices.create', ['project' => $project, 'from_estimate' => 1]), false);

        $this->actingAs($accountant)->get(route('accountant.invoices.create', ['project' => $project, 'from_estimate' => 1]))
            ->assertOk()->assertSee('Create Invoice from Estimate')->assertSee('Select Phases or Subphases')
            ->assertSee('Partial subphase amount')->assertSee('Foundation concrete')->assertSee('This Invoice');

        $response = $this->actingAs($accountant)->post(route('accountant.invoices.store', $project), [
            'project_id' => $project->id, 'client_id' => $project->client_id,
            'project_estimate_id' => $estimate->id, 'due_at' => today()->addDays(30)->toDateString(),
            'items' => [$entry->id => [
                'selected' => '1', 'estimate_entry_id' => $entry->id,
                'project_phase_id' => $phase->id, 'main_category' => 'materials',
                'item_description' => $entry->item_description, 'quantity' => $entry->quantity,
                'unit' => $entry->unit, 'unit_price' => $entry->client_unit_cost,
                'claim_amount' => '450.00',
            ]],
        ]);

        $invoice = ClientInvoice::latest('id')->firstOrFail();
        $response->assertRedirect(route('accountant.invoices.show', $invoice));
        $this->assertSame($estimate->id, $invoice->project_estimate_id);
        $this->assertSame('450.00', $invoice->total_amount);
        $this->assertSame('450.00', $invoice->items->first()->claim_amount);
    }

    public function test_one_invoice_can_claim_multiple_phases_and_subphases(): void
    {
        [$project, $accountant, $firstPhase] = $this->projectAndAccountant();
        $secondPhase = $project->phases()->create(['stage_code' => 'superstructure', 'phase_order' => 2, 'status' => 'planned']);
        $estimate = ProjectEstimate::create([
            'estimate_code' => 'EST-MULTI-001', 'project_id' => $project->id,
            'client_id' => $project->client_id, 'version' => 1, 'status' => 'issued',
        ]);
        $first = $estimate->entries()->create([
            'project_phase_id' => $firstPhase->id, 'work_section' => '1.1 Site setup',
            'main_category' => 'materials', 'item_description' => 'Site establishment',
            'quantity' => '1', 'unit' => 'ls', 'base_unit_cost' => '1000.00', 'markup_percent' => '0',
        ]);
        $second = $estimate->entries()->create([
            'project_phase_id' => $secondPhase->id, 'work_section' => '4.1 Walling',
            'main_category' => 'materials', 'item_description' => 'Blockwork',
            'quantity' => '1', 'unit' => 'ls', 'base_unit_cost' => '2000.00', 'markup_percent' => '0',
        ]);

        $response = $this->actingAs($accountant)->post(route('accountant.invoices.store', $project), [
            'project_id' => $project->id, 'client_id' => $project->client_id,
            'project_estimate_id' => $estimate->id, 'due_at' => today()->addDays(30)->toDateString(),
            'items' => [
                $first->id => $this->estimateInvoiceItem($first, '1000.00'),
                $second->id => $this->estimateInvoiceItem($second, '750.00'),
            ],
        ]);

        $invoice = ClientInvoice::latest('id')->firstOrFail();
        $response->assertRedirect(route('accountant.invoices.show', $invoice));
        $this->assertSame('1750.00', $invoice->total_amount);
        $this->assertCount(2, $invoice->items);
        $this->assertEqualsCanonicalizing([$firstPhase->id, $secondPhase->id], $invoice->items->pluck('project_phase_id')->all());
    }

    public function test_client_payment_overpayment_zero_and_non_issued_invoice_are_blocked(): void
    {
        [$project, $accountant, $phase] = $this->projectAndAccountant();
        $draft = app(ClientInvoiceService::class)->createDraft($project, ['due_at' => now()->addDays(30)->toDateString(), 'items' => [['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Draft', 'quantity' => '1', 'unit_price' => '100.00']]], $accountant);
        try { app(ClientPaymentRecorder::class)->record($draft, ['amount' => '1.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash'], $accountant); $this->fail('Draft should not accept payment.'); } catch (AuthorizationException) { $this->assertCount(0, $draft->payments); }
        $invoice = $this->issuedInvoice($project, $phase, $accountant, '100.00');
        try { app(ClientPaymentRecorder::class)->record($invoice, ['amount' => '101.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash'], $accountant); $this->fail('Overpayment should be blocked.'); } catch (ValidationException $exception) { $this->assertArrayHasKey('amount', $exception->errors()); }
    }

    public function test_cash_balance_uses_client_payments_minus_expense_payments_and_other_roles_cannot_bill(): void
    {
        [$project, $accountant, $phase] = $this->projectAndAccountant();
        $invoice = $this->issuedInvoice($project, $phase, $accountant, '40000000.00');
        app(ClientPaymentRecorder::class)->record($invoice, ['amount' => '40000000.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer'], $accountant);
        $request = ExpenseRequest::create(['request_code' => 'REQ-CASH-001', 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Paid expense', 'quantity' => '1', 'unit' => 'ls', 'total_amount' => null, 'status' => 'approved', 'requested_at' => now()->toDateString(), 'submitted_at' => now()]);
        ExpensePayment::create(['payment_code' => 'PAY-CASH-001', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '27000000.00', 'payment_date' => now()->toDateString(), 'payment_method' => 'cash']);
        $this->actingAs($accountant)->get(route('accountant.projects.show', $project))->assertOk()->assertSee('13,000,000.00');
        $this->actingAs($this->user('project_manager'))->get(route('accountant.invoices.index'))->assertForbidden();
        $this->actingAs($this->user('site_operator'))->get(route('accountant.invoices.index'))->assertForbidden();
    }

    private function issuedInvoice(Project $project, $phase, User $accountant, string $amount): ClientInvoice
    {
        $invoice = app(ClientInvoiceService::class)->createDraft($project, ['due_at' => now()->addDays(30)->toDateString(), 'items' => [['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Invoice item', 'quantity' => '1', 'unit_price' => $amount]]], $accountant);
        return app(ClientInvoiceService::class)->issue($invoice, $accountant);
    }

    private function estimateInvoiceItem(EstimateEntry $entry, string $claimAmount): array
    {
        return [
            'selected' => '1', 'estimate_entry_id' => $entry->id,
            'project_phase_id' => $entry->project_phase_id, 'main_category' => $entry->main_category,
            'item_description' => $entry->item_description, 'quantity' => $entry->quantity,
            'unit' => $entry->unit, 'unit_price' => $entry->client_unit_cost,
            'claim_amount' => $claimAmount,
        ];
    }

    private function projectAndAccountant(): array
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id, 'project_code' => 'BWG-BILL-'.fake()->unique()->numerify('#####')]);
        $phase = $project->phases()->create(['stage_code' => 'preliminaries', 'phase_order' => 1, 'status' => 'active']);
        return [$project, $this->user('accounts'), $phase];
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'is_active' => true]);
    }
}
