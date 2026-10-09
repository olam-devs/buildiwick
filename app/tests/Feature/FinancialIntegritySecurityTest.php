<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\ExpenseRequestApproval;
use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\SupplierPayee;
use App\Models\SupplierPaymentDestination;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClientInvoiceService;
use App\Services\ClientPaymentRecorder;
use App\Services\ExpensePaymentRecorder;
use App\Services\ExpenseRequestDecisionService;
use App\Services\ProjectManagerBudgetService;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialIntegritySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        foreach (['admin', 'director', 'accounts', 'accountant', 'project_manager', 'estimator_qs', 'site_operator'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_site_request_forged_actor_status_project_and_commercial_fields_are_ignored(): void
    {
        [$projectA] = $this->project('BWG-SEC-FORGE-A');
        [$projectB] = $this->project('BWG-SEC-FORGE-B');
        $site = $this->user('site_operator');
        $other = $this->user('site_operator');
        $projectA->assignedUsers()->attach($site->id);
        $projectB->assignedUsers()->attach($other->id);

        $this->actingAs($site)->post(route('site.requests.store', $projectA), [
            'requested_at' => today()->toDateString(), 'main_category' => 'materials', 'item_description' => 'Need only',
            'quantity' => '2', 'unit' => 'bag', 'notes' => 'Please deliver',
            'project_id' => $projectB->id, 'project_phase_id' => $projectB->phases()->first()->id,
            'requested_by' => $other->id, 'status' => 'approved', 'approved_amount' => '1.00',
            'commercial_total' => '1.00', 'commercial_unit_rate' => '0.01', 'supplier_payee_id' => 98765,
            'approved_by' => $other->id, 'request_code' => 'REQ-FORGED', 'submitted_at' => '2000-01-01 00:00:00',
        ])->assertRedirect();

        $request = ExpenseRequest::firstOrFail();
        $this->assertSame($projectA->id, $request->project_id);
        $this->assertSame($projectA->phases()->first()->id, $request->project_phase_id);
        $this->assertSame($site->id, $request->requested_by);
        $this->assertSame('pending_approval', $request->status);
        $this->assertNull($request->approved_amount);
        $this->assertNull($request->commercial_total);
        $this->assertSame('REQ-'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT), $request->request_code);
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_pm_commercial_totals_and_actor_are_calculated_server_side_and_locked_snapshot_is_stable(): void
    {
        [$project, $phase] = $this->project('BWG-SEC-COMMERCIAL');
        $pm = $this->user('project_manager');
        $project->assignedUsers()->attach($pm->id);
        $supplier = SupplierPayee::create(['name' => 'Secure Supplier', 'type' => 'supplier', 'is_active' => true]);
        $request = $this->request($project, $phase, 'pending_approval', ['quantity' => '2.00']);

        $this->actingAs($pm)->put(route('pm.requests.commercial.update', $request), [
            'supplier_payee_id' => $supplier->id,
            'commercial_unit_rate' => '250.00',
            'commercial_payment_method' => 'cash',
            'commercial_total' => '0.01',
            'commercial_prepared_by' => $this->user('admin')->id,
            'status' => 'approved',
            'approved_amount' => '0.01',
        ])->assertRedirect();

        $request->refresh();
        $this->assertSame('500.00', $request->commercial_total);
        $this->assertSame($pm->id, $request->commercial_prepared_by);
        $this->assertSame('pending_approval', $request->status);
        $this->assertNull($request->approved_amount);

        $request->update(['commercial_evidence_link' => 'quotes/server-created.pdf']);
        $approval = app(ExpenseRequestDecisionService::class)->decide($request, 'approve', 'Approved for procurement.', $pm);
        $this->assertSame($pm->id, $approval->decided_by);
        $this->assertSame('500.00', $approval->approved_total);
        $snapshot = $approval->fresh()->toArray();
        $this->actingAs($pm)->put(route('pm.requests.commercial.update', $request), [
            'supplier_payee_id' => $supplier->id, 'commercial_unit_rate' => '999.00', 'commercial_payment_method' => 'cash',
        ])->assertForbidden();
        $this->assertSame($snapshot, $approval->fresh()->toArray());
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_invoice_route_scope_and_server_generated_lifecycle_ignore_forged_invoice_fields(): void
    {
        [$projectA, $phaseA, $accountant] = $this->projectAndAccountant('BWG-SEC-INVOICE-A');
        [$projectB, $phaseB] = $this->project('BWG-SEC-INVOICE-B');
        $payload = [
            'project_id' => $projectB->id,
            'client_id' => $projectB->client_id,
            'due_at' => today()->addDays(10)->toDateString(),
            'invoice_code' => 'INV-FORGED', 'status' => 'issued', 'issued_by' => $accountant->id,
            'created_by' => $accountant->id, 'total_amount' => '0.01',
            'items' => [['project_phase_id' => $phaseB->id, 'main_category' => 'materials', 'item_description' => 'Line', 'quantity' => '1', 'unit_price' => '10.00']],
        ];
        $this->actingAs($accountant)->from(route('accountant.invoices.create', $projectA))
            ->post(route('accountant.invoices.store', $projectA), $payload)->assertRedirect(route('accountant.invoices.create', $projectA));
        $this->assertDatabaseCount('client_invoices', 0);

        $payload['project_id'] = $projectA->id;
        $payload['client_id'] = $projectA->client_id;
        $payload['items'][0]['project_phase_id'] = $phaseA->id;
        $this->actingAs($accountant)->post(route('accountant.invoices.store', $projectA), $payload)->assertRedirect();
        $invoice = ClientInvoice::firstOrFail();
        $this->assertSame($projectA->id, $invoice->project_id);
        $this->assertSame($projectA->client_id, $invoice->client_id);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($accountant->id, $invoice->created_by);
        $this->assertStringStartsWith('INV-', $invoice->invoice_code);
        $this->assertNotSame('INV-FORGED', $invoice->invoice_code);
    }

    public function test_payment_route_ids_overpayment_retries_and_immutable_event_methods_are_protected(): void
    {
        [$project, $phase, $accounts] = $this->projectAndAccountant('BWG-SEC-PAYMENTS');
        $request = $this->request($project, $phase, 'approved', ['approved_amount' => '100.00', 'commercial_total' => '100.00']);
        $pm = $this->user('project_manager');
        $request->approvals()->create(['decision' => 'approved', 'approved_amount' => '100.00', 'approved_total' => '100.00', 'decided_by' => $pm->id, 'decided_at' => now(), 'notes' => 'Approved']);
        $another = $this->request($project, $phase, 'approved', ['approved_amount' => '100.00', 'commercial_total' => '100.00']);
        $recorder = app(ExpensePaymentRecorder::class);
        $payment = $recorder->record($request, ['amount' => '60.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash'], $accounts);

        $valid = [
            'expense_request_id' => $another->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'amount' => '60.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash',
            'recorded_by' => $pm->id, 'payment_code' => 'FORGED', 'payment_status' => 'paid',
        ];
        $this->actingAs($accounts)->from(route('accountant.requests.show', $request))
            ->post(route('accountant.payments.store', $another), $valid)->assertRedirect(route('accountant.requests.show', $another));
        $this->assertSame(1, ExpensePayment::where('expense_request_id', $another->id)->count());
        $newPayment = ExpensePayment::where('expense_request_id', $another->id)->firstOrFail();
        $this->assertSame($accounts->id, $newPayment->recorded_by);
        $this->assertNotSame('FORGED', $newPayment->payment_code);
        $this->actingAs($accounts)->post(route('accountant.payments.store', $another), $valid)->assertRedirect();
        $this->assertSame(1, ExpensePayment::where('expense_request_id', $another->id)->count());

        $crossRoutePayload = array_merge($valid, ['expense_request_id' => $request->id, 'amount' => '10.00']);
        $this->actingAs($accounts)->from(route('accountant.requests.show', $another))
            ->post(route('accountant.payments.store', $another), $crossRoutePayload)->assertSessionHasErrors('idempotency_key');
        $overpay = array_merge($valid, [
            'expense_request_id' => $request->id,
            'amount' => '50.00',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->actingAs($accounts)->from(route('accountant.requests.show', $request))
            ->post(route('accountant.payments.store', $request), $overpay)->assertSessionHasErrors('amount');
        $this->assertSame(1, ExpensePayment::where('expense_request_id', $request->id)->count());
        $this->assertFalse($accounts->can('update', $payment));
        $this->assertFalse($accounts->can('delete', $payment));
        $this->actingAs($accounts)->put('/accountant/payments/'.$payment->id, [])->assertNotFound();
        $this->actingAs($accounts)->delete('/accountant/payments/'.$payment->id)->assertNotFound();
    }

    public function test_client_payment_recording_binds_invoice_actor_and_balance_and_has_no_mutation_routes(): void
    {
        [$project, $phase, $accounts] = $this->projectAndAccountant('BWG-SEC-RECEIPTS');
        $invoice = $this->issuedInvoice($project, $phase, $accounts, '100.00');
        $otherInvoice = $this->issuedInvoice($project, $phase, $accounts, '100.00');
        $recorder = app(ClientPaymentRecorder::class);
        $receiptKey = (string) \Illuminate\Support\Str::uuid();
        $first = $recorder->record($invoice, ['idempotency_key' => $receiptKey, 'amount' => '60.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash'], $accounts);
        $this->assertSame($accounts->id, $first->recorded_by);
        $this->assertSame('40.00', $invoice->fresh()->outstanding_amount);

        try {
            $recorder->record($invoice, ['client_invoice_id' => $otherInvoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '1.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash'], $accounts);
            $this->fail('A mismatched invoice ID should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('client_invoice_id', $exception->errors());
        }

        $retryPayload = [
            'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id,
            'idempotency_key' => $receiptKey, 'amount' => '60.00', 'payment_date' => today()->toDateString(),
            'payment_method' => 'cash', 'recorded_by' => $this->user('admin')->id, 'payment_code' => 'FORGED',
        ];
        $this->actingAs($accounts)->post(route('accountant.client-payments.store', $invoice), $retryPayload)->assertRedirect();
        $this->assertSame(1, ClientPayment::where('client_invoice_id', $invoice->id)->count());

        $overpayPayload = array_merge($retryPayload, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'amount' => '50.00']);
        $this->actingAs($accounts)->from(route('accountant.invoices.show', $invoice))
            ->post(route('accountant.client-payments.store', $invoice), $overpayPayload)->assertSessionHasErrors('amount');
        $this->assertSame(1, ClientPayment::where('client_invoice_id', $invoice->id)->count());
        $this->assertFalse($accounts->can('update', $first));
        $this->assertFalse($accounts->can('delete', $first));
        $this->actingAs($accounts)->put('/accountant/client-payments/'.$first->id, [])->assertNotFound();
        $this->actingAs($accounts)->delete('/accountant/client-payments/'.$first->id)->assertNotFound();
    }

    public function test_issued_invoice_void_is_an_explicit_audited_transition_for_unpaid_invoices_only(): void
    {
        [$project, $phase, $accounts] = $this->projectAndAccountant('BWG-SEC-INVOICE-VOID');
        $unpaid = $this->issuedInvoice($project, $phase, $accounts, '80.00');
        $voided = app(ClientInvoiceService::class)->void($unpaid, 'Duplicate billing document.', $accounts);
        $this->assertSame('void', $voided->status);
        $this->assertSame($accounts->id, $voided->voided_by);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Duplicate billing document.', $voided->void_reason);

        $paidInvoice = $this->issuedInvoice($project, $phase, $accounts, '40.00');
        app(ClientPaymentRecorder::class)->record($paidInvoice, ['amount' => '40.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash'], $accounts);
        try {
            app(ClientInvoiceService::class)->void($paidInvoice, 'Already paid.', $accounts);
            $this->fail('A paid issued invoice must not be voidable.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame('issued', $paidInvoice->fresh()->status);
        }

        $anotherUnpaid = $this->issuedInvoice($project, $phase, $accounts, '25.00');
        $directorVoided = app(ClientInvoiceService::class)->void($anotherUnpaid, 'Director correction.', $this->user('director'));
        $this->assertSame('void', $directorVoided->status);
        $this->assertSame('Director correction.', $directorVoided->void_reason);
    }

    public function test_estimate_issue_records_authenticated_actor_and_timestamp(): void
    {
        [$project, $phase] = $this->project('BWG-SEC-ESTIMATE-AUDIT');
        $estimator = $this->user('estimator_qs');
        $project->assignedUsers()->attach($estimator->id);
        $estimate = app(\App\Services\EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $estimate->entries()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Entry', 'quantity' => '1', 'unit' => 'ls', 'base_unit_cost' => '100.00', 'markup_percent' => '0']);

        $issued = app(\App\Services\EstimatorEstimateService::class)->finalize($estimate, $estimator);
        $this->assertSame('issued', $issued->status);
        $this->assertSame($estimator->id, $issued->issued_by);
        $this->assertNotNull($issued->issued_at);
    }

    public function test_additive_audit_and_idempotency_migrations_preserve_existing_financial_rows(): void
    {
        $idempotencyMigration = require database_path('migrations/2026_09_30_000013_add_payment_idempotency_keys.php');
        $estimateAuditMigration = require database_path('migrations/2026_09_30_000012_add_estimate_issue_audit_fields.php');
        $idempotencyMigration->down();
        $estimateAuditMigration->down();
        $this->assertFalse(Schema::hasColumn('expense_payments', 'idempotency_key'));
        $this->assertFalse(Schema::hasColumn('client_payments', 'idempotency_key'));
        $this->assertFalse(Schema::hasColumn('project_estimates', 'issued_by'));

        [$project, $phase, $accounts] = $this->projectAndAccountant('BWG-SEC-MIGRATION-UPGRADE');
        $estimate = ProjectEstimate::create(['estimate_code' => 'EST-SEC-UPGRADE-001', 'project_id' => $project->id, 'client_id' => $project->client_id, 'version' => 1, 'status' => 'issued']);
        $request = $this->request($project, $phase, 'approved', ['approved_amount' => '75.00']);
        $expense = ExpensePayment::create(['payment_code' => 'PAY-SEC-UPGRADE-001', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '25.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $invoice = ClientInvoice::create(['invoice_code' => 'INV-SEC-UPGRADE-001', 'project_id' => $project->id, 'client_id' => $project->client_id, 'status' => 'issued', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(10)->toDateString()]);
        $invoice->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Existing invoice', 'unit_price' => '50.00']);
        $receipt = ClientPayment::create(['payment_code' => 'RCP-SEC-UPGRADE-001', 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '10.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);

        $financialSnapshot = [$estimate->fresh()->status, $request->fresh()->status, $expense->fresh()->amount, $invoice->fresh()->status, $receipt->fresh()->amount];
        $estimateAuditMigration->up();
        $idempotencyMigration->up();

        $this->assertTrue(Schema::hasColumn('expense_payments', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('client_payments', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('project_estimates', 'issued_by'));
        $this->assertSame($financialSnapshot, [$estimate->fresh()->status, $request->fresh()->status, $expense->fresh()->amount, $invoice->fresh()->status, $receipt->fresh()->amount]);
        $this->assertNull($estimate->fresh()->issued_by);
        $this->assertNull($expense->fresh()->idempotency_key);
        $this->assertNull($receipt->fresh()->idempotency_key);
    }

    public function test_transaction_recorder_stores_new_evidence_privately(): void
    {
        [$project] = $this->project('BWG-SEC-TRANSACTION-FILE');
        $admin = $this->user('admin');
        [$transaction] = app(\App\Services\TransactionRecorder::class)->record([
            'project_id' => $project->id, 'main_category' => 'materials', 'item_description' => 'Secure upload', 'total_amount' => '10.00',
            'transaction_date' => today()->toDateString(),
        ], $project, $admin, UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'));

        $this->assertNotNull($transaction->evidence_link);
        Storage::disk('local')->assertExists($transaction->evidence_link);
        Storage::disk('public')->assertMissing($transaction->evidence_link);
        $this->get('/storage/'.$transaction->evidence_link)->assertNotFound();
    }

    public function test_legacy_public_transaction_evidence_command_migrates_files_without_rewriting_financial_rows(): void
    {
        [$project] = $this->project('BWG-SEC-LEGACY-EVIDENCE');
        $path = 'evidence/legacy-receipt.pdf';
        Storage::disk('public')->put($path, 'legacy private evidence bytes');
        $transaction = $project->transactions()->create([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'transaction_code' => $project->project_code.'-TXN-0001',
            'main_category' => 'materials', 'item_description' => 'Historical record',
            'total_amount' => '123.45', 'amount_paid_received' => '0.00',
            'payment_status' => 'unpaid', 'data_status' => 'needs_confirmation',
            'transaction_date' => today()->toDateString(), 'evidence_link' => $path,
        ]);
        $attributesBefore = $transaction->fresh()->getAttributes();

        $this->assertSame(0, Artisan::call('app:secure-legacy-transaction-evidence', ['--force' => true]));
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame($attributesBefore, $transaction->fresh()->getAttributes());

        $this->assertSame(0, Artisan::call('app:secure-legacy-transaction-evidence', ['--force' => true]));
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'total_amount' => '123.45', 'evidence_link' => $path]);
    }

    public function test_payment_and_commercial_evidence_download_routes_enforce_role_authorization(): void
    {
        [$project, $phase, $accounts] = $this->projectAndAccountant('BWG-SEC-EVIDENCE');
        [$otherProject, $otherPhase] = $this->project('BWG-SEC-EVIDENCE-OTHER');
        $invoice = $this->issuedInvoice($project, $phase, $accounts, '200.00');
        $clientReceipt = app(ClientPaymentRecorder::class)->record(
            $invoice,
            ['amount' => '40.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash'],
            $accounts,
            UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')
        );
        $request = $this->request($project, $phase, 'approved', ['approved_amount' => '100.00', 'commercial_total' => '100.00']);
        $request->approvals()->create(['decision' => 'approved', 'approved_amount' => '100.00', 'approved_total' => '100.00', 'decided_by' => $this->user('project_manager')->id, 'decided_at' => now(), 'notes' => 'Approved']);
        $outgoing = app(ExpensePaymentRecorder::class)->record(
            $request,
            ['amount' => '20.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash'],
            $accounts,
            UploadedFile::fake()->create('payment.pdf', 20, 'application/pdf')
        );

        $pm = $this->user('project_manager');
        $site = $this->user('site_operator');
        $estimator = $this->user('estimator_qs');
        $director = $this->user('director');
        $project->assignedUsers()->attach([$pm->id, $site->id, $estimator->id]);
        $quotePath = 'expense-requests/'.$project->id.'/commercial/private-quote.pdf';
        Storage::disk('local')->put($quotePath, 'private quotation');
        $request->update(['commercial_evidence_link' => $quotePath]);

        $this->actingAs($accounts)->get(route('accountant.client-payments.evidence', $clientReceipt))->assertDownload('receipt.pdf');
        $this->actingAs($director)->get(route('accountant.client-payments.evidence', $clientReceipt))->assertDownload('receipt.pdf');
        $this->actingAs($site)->get(route('accountant.client-payments.evidence', $clientReceipt))->assertForbidden();
        $this->actingAs($accounts)->get(route('accountant.payments.evidence', $outgoing))->assertDownload('payment.pdf');
        $this->actingAs($estimator)->get(route('accountant.payments.evidence', $outgoing))->assertForbidden();
        $this->actingAs($pm)->get(route('pm.requests.commercial.evidence', $request))->assertDownload('private-quote.pdf');
        $this->actingAs($site)->get(route('pm.requests.commercial.evidence', $request))->assertForbidden();
        $this->assertFalse(Storage::disk('public')->exists($clientReceipt->evidence_link));
        $this->assertFalse(Storage::disk('public')->exists($outgoing->evidence_link));

        // A private document ID from another project is not made downloadable by changing the route project ID.
        $this->assertNotSame($project->id, $otherProject->id);
        $this->assertNotSame($phase->id, $otherPhase->id);
    }

    public function test_supplier_destinations_remain_limited_to_admin_and_pm_and_are_absent_from_director_and_site_views(): void
    {
        [$project, $phase] = $this->project('BWG-SEC-DESTINATION');
        $pm = $this->user('project_manager');
        $site = $this->user('site_operator');
        $estimator = $this->user('estimator_qs');
        $director = $this->user('director');
        $project->assignedUsers()->attach([$pm->id, $site->id, $estimator->id]);
        $supplier = SupplierPayee::create(['name' => 'Sensitive Payee', 'type' => 'supplier', 'is_active' => true]);
        $destination = SupplierPaymentDestination::create(['supplier_payee_id' => $supplier->id, 'payment_method' => 'bank_transfer', 'account_wallet_name' => 'Sensitive Wallet', 'account_wallet_number' => '998877665544']);
        $request = $this->request($project, $phase, 'pending_approval');

        $this->actingAs($pm)->get(route('pm.suppliers.destinations', $supplier))->assertOk()->assertSee('998877665544');
        $this->actingAs($site)->get(route('pm.suppliers.destinations', $supplier))->assertForbidden();
        $this->actingAs($estimator)->get(route('pm.suppliers.destinations', $supplier))->assertForbidden();
        $this->actingAs($site)->get(route('site.requests.show', [$project, $request]))->assertOk()->assertDontSee('998877665544');
        $this->actingAs($estimator)->get(route('estimator.projects.index'))->assertOk()->assertDontSee('998877665544');
        $this->actingAs($director)->get(route('director.index'))->assertOk()->assertDontSee('998877665544');
        $this->assertSame($supplier->id, $destination->supplier_payee_id);
    }

    private function project(string $code): array
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id, 'project_code' => $code]);
        $phase = $project->phases()->create(['stage_code' => 'preliminaries', 'phase_order' => 1, 'status' => 'active']);

        return [$project, $phase];
    }

    private function projectAndAccountant(string $code): array
    {
        [$project, $phase] = $this->project($code);
        return [$project, $phase, $this->user('accounts')];
    }

    private function request(Project $project, ProjectPhase $phase, string $status, array $overrides = []): ExpenseRequest
    {
        return ExpenseRequest::create(array_merge([
            'request_code' => 'REQ-'.fake()->unique()->numerify('######'), 'project_id' => $project->id,
            'project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Security test need',
            'quantity' => '1.00', 'unit' => 'bag', 'requested_at' => today()->toDateString(), 'submitted_at' => now(), 'status' => $status,
        ], $overrides));
    }

    private function issuedInvoice(Project $project, ProjectPhase $phase, User $accountant, string $amount): ClientInvoice
    {
        $invoice = app(ClientInvoiceService::class)->createDraft($project, [
            'due_at' => today()->addDays(14)->toDateString(),
            'items' => [['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Security test invoice', 'quantity' => '1', 'unit_price' => $amount]],
        ], $accountant);

        return app(ClientInvoiceService::class)->issue($invoice, $accountant);
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create($overrides + ['role_id' => Role::where('name', $role)->value('id'), 'is_active' => true]);
    }
}
