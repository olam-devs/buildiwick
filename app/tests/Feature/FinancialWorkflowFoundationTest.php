<?php

namespace Tests\Feature;

use App\Http\Requests\StoreClientPaymentRequest;
use App\Http\Requests\StoreEstimateEntryRequest;
use App\Http\Requests\StoreExpensePaymentRequest;
use App\Http\Requests\StoreInternalBudgetEntryRequest;
use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceItem;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestApproval;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\ExpenseRequestPolicy;
use App\Services\ClientPaymentRecorder;
use App\Services\ExpensePaymentRecorder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\ProjectPhaseSeeder;
use Database\Seeders\ProjectSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialWorkflowFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const STANDARD_PHASES = [
        ['preliminaries', 'Preliminaries'],
        ['substructure', 'Substructure (Foundation)'],
        ['superstructure', 'Superstructure'],
        ['walling', 'Walling'],
        ['roofing', 'Roofing'],
        ['finishing', 'Finishing'],
        ['external_works', 'External Works'],
    ];

    public function test_phase_seeder_creates_the_seven_ordered_phases_for_each_seeded_project_and_is_idempotent(): void
    {
        $this->seed([ProjectSeeder::class, LookupSeeder::class, ProjectPhaseSeeder::class]);

        $projects = Project::all();
        $this->assertCount(16, $projects);
        foreach ($projects as $project) {
            $phases = $project->phases()->orderBy('phase_order')->get();
            $this->assertCount(7, $phases);
            $this->assertSame(self::STANDARD_PHASES, $phases->map(fn (ProjectPhase $phase) => [$phase->stage_code, $phase->stage_label])->all());
            $this->assertSame(range(1, 7), $phases->pluck('phase_order')->all());
        }

        $this->assertSame(0, ProjectPhase::where('stage_code', 'other_general')->count());

        $preserved = $projects->first()->phases()->where('stage_code', 'substructure')->firstOrFail();
        $preserved->update(['status' => 'completed', 'started_at' => '2026-01-15', 'completed_at' => '2026-02-03']);

        $this->seed(ProjectPhaseSeeder::class);

        $this->assertSame(16 * 7, ProjectPhase::count());
        $this->assertDatabaseHas('project_phases', [
            'id' => $preserved->id,
            'status' => 'completed',
            'started_at' => '2026-01-15 00:00:00',
            'completed_at' => '2026-02-03 00:00:00',
        ]);
    }

    public function test_estimate_entries_store_base_rate_and_derive_client_price_markup_and_totals_exactly(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $estimate = ProjectEstimate::factory()->create(['project_id' => $project->id]);

        $entry = $estimate->entries()->create([
            'project_phase_id' => $phase->id,
            'main_category' => 'materials',
            'item_description' => 'Cement',
            'quantity' => '3.00',
            'unit' => 'bag',
            'base_unit_cost' => '10.00',
            'markup_percent' => '20.0000',
        ]);

        $this->assertSame($project->id, $estimate->project->id);
        $this->assertSame($estimate->id, $entry->estimate->id);
        $this->assertSame($phase->id, $entry->phase->id);
        $this->assertSame('10.00', $entry->base_unit_cost);
        $this->assertSame('12.00', $entry->client_unit_cost);
        $this->assertSame('30.00', $entry->base_total);
        $this->assertSame('6.00', $entry->markup_amount);
        $this->assertSame('36.00', $entry->line_total);
        $this->assertSame('36.00', $estimate->total_amount);
    }

    public function test_estimate_entry_validation_rejects_a_phase_from_a_different_project(): void
    {
        [$project] = $this->projectWithPhase();
        [, $foreignPhase] = $this->projectWithPhase();
        $estimate = ProjectEstimate::factory()->create(['project_id' => $project->id]);

        $errors = $this->validationErrors(StoreEstimateEntryRequest::class, [
            'project_estimate_id' => $estimate->id,
            'project_phase_id' => $foreignPhase->id,
            'main_category' => 'materials',
            'item_description' => 'Cement',
            'base_unit_cost' => '10.00',
        ]);

        $this->assertContains('project_phase_id', $errors);
    }

    public function test_internal_budget_entries_belong_to_project_phase_and_totals_are_computed(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $budget = InternalBudget::factory()->create(['project_id' => $project->id]);
        $entry = $budget->entries()->create([
            'project_phase_id' => $phase->id,
            'main_category' => 'materials',
            'item_description' => 'Blocks',
            'quantity' => '2.50',
            'unit_cost' => '4.20',
        ]);

        $this->assertSame($project->id, $budget->project->id);
        $this->assertSame($phase->id, $entry->phase->id);
        $this->assertSame('10.50', $entry->line_total);
        $this->assertSame('10.50', $budget->total_amount);
    }

    public function test_internal_budget_entry_validation_rejects_a_foreign_project_phase(): void
    {
        [$project] = $this->projectWithPhase();
        [, $foreignPhase] = $this->projectWithPhase();
        $budget = InternalBudget::factory()->create(['project_id' => $project->id]);

        $errors = $this->validationErrors(StoreInternalBudgetEntryRequest::class, [
            'internal_budget_id' => $budget->id,
            'project_phase_id' => $foreignPhase->id,
            'main_category' => 'materials',
            'item_description' => 'Blocks',
            'unit_cost' => '4.20',
        ]);

        $this->assertContains('project_phase_id', $errors);
    }

    public function test_expense_request_approval_and_payment_status_are_independent_and_history_is_related(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $request = $this->expenseRequest($project, $phase, '100.00');

        $this->assertSame('pending_approval', $request->status);
        $this->assertSame('unpaid', $request->payment_status);
        $this->assertSame('0.00', $request->paid_to_date);
        $this->assertSame($project->id, $request->project->id);
        $this->assertSame($phase->id, $request->phase->id);

        $approval = $request->approvals()->create([
            'decision' => 'approved',
            'decided_at' => now(),
            'notes' => 'Approved for purchase',
        ]);
        $request->update(['status' => 'approved']);
        $request->refresh();

        $this->assertSame($approval->id, $request->approvals()->first()->id);
        $this->assertSame('approved', $request->status);
        $this->assertSame('unpaid', $request->payment_status);
        $this->assertFalse(app(ExpenseRequestPolicy::class)->recordPayment($this->userWithRole('accounts'), $this->expenseRequest($project, $phase, '25.00', 'rejected')));
    }

    public function test_approved_expense_request_accepts_multiple_payments_and_derives_exact_payment_states(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $request = $this->expenseRequest($project, $phase, '100.00', 'approved');
        $accounts = $this->userWithRole('accounts');
        $recorder = app(ExpensePaymentRecorder::class);

        $first = $recorder->record($request, $this->expensePaymentData('33.33'), $accounts);
        $request->refresh();
        $this->assertInstanceOf(ExpensePayment::class, $first);
        $this->assertSame('33.33', $request->paid_to_date);
        $this->assertSame('66.67', $request->outstanding_amount);
        $this->assertSame('part_paid', $request->payment_status);

        $recorder->record($request, $this->expensePaymentData('66.67'), $accounts);
        $request->refresh();
        $this->assertCount(2, $request->payments);
        $this->assertSame('100.00', $request->paid_to_date);
        $this->assertSame('0.00', $request->outstanding_amount);
        $this->assertSame('paid', $request->payment_status);
        $this->assertSame('approved', $request->status);
    }

    public function test_expense_payments_reject_pending_rejected_and_overpaid_requests(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $accounts = $this->userWithRole('accounts');
        $recorder = app(ExpensePaymentRecorder::class);

        foreach (['pending_approval', 'rejected'] as $status) {
            $request = $this->expenseRequest($project, $phase, '100.00', $status);
            try {
                $recorder->record($request, $this->expensePaymentData('1.00'), $accounts);
                $this->fail("Expected {$status} request to be unpayable.");
            } catch (AuthorizationException) {
                $this->assertCount(0, $request->payments);
            }
        }

        $approved = $this->expenseRequest($project, $phase, '10.00', 'approved');
        $recorder->record($approved, $this->expensePaymentData('9.99'), $accounts);
        try {
            $recorder->record($approved, $this->expensePaymentData('0.02'), $accounts);
            $this->fail('Expected an overpayment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->assertCount(1, $approved->payments()->get());
    }

    public function test_client_invoice_items_and_multiple_payments_produce_exact_receipts_and_balance(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $client = Client::factory()->create();
        $invoice = ClientInvoice::create([
            'invoice_code' => 'INV-TEST-001',
            'project_id' => $project->id,
            'client_id' => $client->id,
            'status' => 'issued',
        ]);
        $invoice->items()->createMany([
            ['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Cement', 'quantity' => '2.00', 'unit_price' => '25.25'],
            ['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Sand', 'quantity' => '1.00', 'unit_price' => '49.50'],
        ]);

        $this->assertSame($project->id, $invoice->project->id);
        $this->assertSame($client->id, $invoice->client->id);
        $this->assertSame('100.00', $invoice->total_amount);
        $this->assertSame('unpaid', $invoice->payment_status);

        $accounts = $this->userWithRole('accounts');
        $recorder = app(ClientPaymentRecorder::class);
        $recorder->record($invoice, $this->clientPaymentData('60.00'), $accounts);
        $invoice->refresh();
        $this->assertSame('60.00', $invoice->paid_to_date);
        $this->assertSame('40.00', $invoice->outstanding_amount);
        $this->assertSame('part_paid', $invoice->payment_status);

        $recorder->record($invoice, $this->clientPaymentData('40.00'), $accounts);
        $invoice->refresh();
        $this->assertCount(2, $invoice->payments);
        $this->assertSame('100.00', $invoice->paid_to_date);
        $this->assertSame('0.00', $invoice->outstanding_amount);
        $this->assertSame('paid', $invoice->payment_status);
    }

    public function test_client_payment_recorder_rejects_draft_invoice_and_overpayment(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $client = Client::factory()->create();
        $draft = ClientInvoice::create(['invoice_code' => 'INV-DRAFT-001', 'project_id' => $project->id, 'client_id' => $client->id, 'status' => 'draft']);
        $draft->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Item', 'unit_price' => '10.00']);
        $accounts = $this->userWithRole('accounts');
        $recorder = app(ClientPaymentRecorder::class);

        try {
            $recorder->record($draft, $this->clientPaymentData('1.00'), $accounts);
            $this->fail('Expected draft invoice payment to be rejected.');
        } catch (AuthorizationException) {
            $this->assertCount(0, $draft->payments);
        }

        $draft->update(['status' => 'issued']);
        try {
            $recorder->record($draft, $this->clientPaymentData('10.01'), $accounts);
            $this->fail('Expected client invoice overpayment to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
    }

    public function test_project_documents_link_to_project_phase_and_polymorphic_owner(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $request = $this->expenseRequest($project, $phase, '25.00');
        $document = new ProjectDocument([
            'document_type' => 'request_evidence',
            'file_name' => 'quote.pdf',
            'file_path' => 'documents/quote.pdf',
        ]);
        $document->project()->associate($project);
        $document->phase()->associate($phase);
        $document->documentable()->associate($request);
        $document->save();

        $this->assertSame($project->id, $document->project->id);
        $this->assertSame($phase->id, $document->phase->id);
        $this->assertInstanceOf(ExpenseRequest::class, $document->documentable);
        $this->assertSame($request->id, $document->documentable->id);
        $this->assertSame($document->id, $project->documents()->first()->id);
        $this->assertSame($document->id, $phase->documents()->first()->id);
    }

    public function test_project_and_user_phase_and_financial_relationships_resolve(): void
    {
        [$project, $phase] = $this->projectWithPhase();
        $user = $this->userWithRole('project_manager');
        $accounts = $this->userWithRole('accounts');
        $user->assignedProjects()->attach($project);
        $estimate = ProjectEstimate::factory()->create(['project_id' => $project->id, 'prepared_by' => $user->id]);
        $budget = InternalBudget::factory()->create(['project_id' => $project->id, 'created_by' => $user->id]);
        $request = $this->expenseRequest($project, $phase, '15.00', 'approved');
        $request->update(['requested_by' => $user->id]);
        $decision = $request->approvals()->create(['decision' => 'approved', 'decided_by' => $user->id, 'decided_at' => now()]);
        $expensePayment = app(ExpensePaymentRecorder::class)->record($request, $this->expensePaymentData('15.00'), $accounts);

        $client = Client::factory()->create();
        $invoice = ClientInvoice::create(['invoice_code' => 'INV-REL-001', 'project_id' => $project->id, 'client_id' => $client->id, 'status' => 'issued']);
        $invoice->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Item', 'unit_price' => '8.00']);
        $clientPayment = app(ClientPaymentRecorder::class)->record($invoice, $this->clientPaymentData('8.00'), $accounts);
        $document = ProjectDocument::create([
            'project_id' => $project->id,
            'project_phase_id' => $phase->id,
            'documentable_type' => ExpenseRequest::class,
            'documentable_id' => $request->id,
            'document_type' => 'request_evidence',
            'file_name' => 'request.pdf',
            'file_path' => 'request.pdf',
            'uploaded_by' => $user->id,
        ]);
        $legacyTransaction = Transaction::create([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'transaction_code' => $project->project_code.'-TXN-PHASE-001',
            'project_id' => $project->id,
            'project_phase_id' => $phase->id,
            'transaction_type' => 'expense',
            'main_category' => 'materials',
            'item_description' => 'Legacy phase-linked row',
            'total_amount' => '1.00',
            'transaction_date' => now()->toDateString(),
        ]);

        $this->assertTrue($user->assignedProjects->contains('id', $project->id));
        $this->assertTrue($project->assignedUsers->contains('id', $user->id));
        $this->assertSame($phase->id, $project->phases()->first()->id);
        $this->assertSame($estimate->id, $project->estimates()->first()->id);
        $this->assertSame($budget->id, $project->internalBudgets()->first()->id);
        $this->assertSame($request->id, $project->expenseRequests()->first()->id);
        $this->assertSame($expensePayment->id, $project->expensePayments()->first()->id);
        $this->assertSame($invoice->id, $project->invoices()->first()->id);
        $this->assertSame($clientPayment->id, $project->clientPayments()->first()->id);
        $this->assertSame($document->id, $project->documents()->first()->id);
        $this->assertSame($estimate->id, $user->preparedEstimates()->first()->id);
        $this->assertSame($budget->id, $user->createdBudgets()->first()->id);
        $this->assertSame($request->id, $user->requestedExpenses()->first()->id);
        $this->assertSame($decision->id, $user->expenseDecisions()->first()->id);
        $this->assertSame($expensePayment->id, $accounts->expensePaymentsRecorded()->first()->id);
        $this->assertSame($clientPayment->id, $accounts->clientPaymentsRecorded()->first()->id);
        $this->assertSame($document->id, $user->uploadedProjectDocuments()->first()->id);
        $phase->update(['created_by' => $user->id]);
        $this->assertSame($user->id, $phase->createdBy->id);
        $this->assertSame($phase->id, $legacyTransaction->phase->id);
    }

    private function projectWithPhase(): array
    {
        $project = Project::factory()->create();
        $phase = ProjectPhase::factory()->create(['project_id' => $project->id]);

        return [$project, $phase];
    }

    private function expenseRequest(Project $project, ProjectPhase $phase, string $amount, string $status = 'pending_approval'): ExpenseRequest
    {
        return ExpenseRequest::create([
            'request_code' => 'REQ-'.fake()->unique()->numerify('########'),
            'project_id' => $project->id,
            'project_phase_id' => $phase->id,
            'main_category' => 'materials',
            'item_description' => 'Test expense',
            'total_amount' => $amount,
            'requested_at' => now()->toDateString(),
            'status' => $status,
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['description' => $roleName]);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function expensePaymentData(string $amount): array
    {
        return ['amount' => $amount, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash'];
    }

    private function clientPaymentData(string $amount): array
    {
        return ['amount' => $amount, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank_transfer'];
    }

    private function validationErrors(string $requestClass, array $data): array
    {
        $request = $requestClass::create('/', 'POST', $data);
        $validator = Validator::make($data, $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }
        $validator->passes();

        return $validator->errors()->keys();
    }
}