<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\Role;
use App\Models\User;
use App\Services\DirectorAnalyticsService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class DirectorAnalyticsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        foreach (['admin', 'director', 'accounts', 'project_manager', 'site_operator', 'estimator_qs'] as $name) {
            Role::firstOrCreate(['name' => $name], ['description' => $name]);
        }
    }

    public function test_organization_summary_uses_current_estimate_and_payment_event_sources_without_cross_project_leakage(): void
    {
        [$project, $phase] = $this->project('BWG-DIR-001', 'active');
        [$other, $otherPhase] = $this->project('BWG-DIR-002', 'completed');
        $this->estimate($project, $phase, 1, 'superseded', '500.00');
        $this->estimate($project, $phase, 2, 'issued', '100.00');
        $this->estimate($other, $otherPhase, 1, 'issued', '300.00');

        $budget = InternalBudget::create(['budget_code' => 'BUD-DIR-001', 'project_id' => $project->id, 'version' => 1, 'status' => 'approved']);
        $budget->entries()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Planned cement', 'quantity' => '1', 'unit_cost' => '100.00']);

        $approved = $this->expenseRequest($project, $phase, 'approved', '80.00', 'materials');
        ExpensePayment::create(['payment_code' => 'PAY-DIR-001', 'expense_request_id' => $approved->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '60.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $this->expenseRequest($project, $phase, 'pending_approval', '900.00', 'materials');

        $invoice = $this->invoice($project, $phase, 'issued', '70.00', today()->toDateString(), today()->addDays(10)->toDateString());
        ClientPayment::create(['payment_code' => 'RCP-DIR-001', 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '20.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $this->invoice($project, $phase, 'draft', '999.00', null, null);
        $this->invoice($other, $otherPhase, 'issued', '200.00', today()->toDateString(), today()->addDays(10)->toDateString());

        $report = app(DirectorAnalyticsService::class)->portfolio(CarbonImmutable::today()->startOfYear(), CarbonImmutable::today());

        $this->assertSame(1, $report['summary']['active_projects']);
        $this->assertSame('400.00', $report['summary']['estimated_client_value']);
        $this->assertSame('270.00', $report['summary']['invoiced_current']);
        $this->assertSame('20.00', $report['summary']['received_current']);
        $this->assertSame('60.00', $report['summary']['expenses_current']);
        $this->assertSame('-40.00', $report['summary']['cash_balance']);
        $this->assertSame('250.00', $report['summary']['receivables']);
        $this->assertSame('20.00', $report['summary']['payables']);
        $this->assertSame(2, $report['projects']->count());

        $projectA = $report['projects']->firstWhere('project.id', $project->id);
        $projectB = $report['projects']->firstWhere('project.id', $other->id);
        $this->assertSame('100.00', $projectA['estimated_client_value']);
        $this->assertSame('100.00', $projectA['budget']);
        $this->assertSame('80.00', $projectA['approved_commitments']);
        $this->assertSame('60.00', $projectA['actual_expenses']);
        $this->assertSame('70.00', $projectA['invoiced_current']);
        $this->assertSame('20.00', $projectA['received_current']);
        $this->assertSame('50.00', $projectA['receivables_current']);
        $this->assertSame('20.00', $projectA['payables_current']);
        $this->assertSame('-40.00', $projectA['cash_balance']);
        $this->assertSame('300.00', $projectB['estimated_client_value']);
        $this->assertSame('0.00', $projectB['approved_commitments']);
        $this->assertSame('0.00', $projectB['actual_expenses']);
        $this->assertSame('200.00', $projectB['invoiced_current']);
        $this->assertSame('0.00', $projectB['received_current']);
        $this->assertSame('200.00', $projectB['receivables_current']);
        $this->assertSame('0.00', $projectB['payables_current']);
        $this->assertSame('0.00', $projectB['cash_balance']);
    }

    public function test_project_analytics_separates_commitment_actual_invoicing_receipt_and_budget_utilization(): void
    {
        [$project, $phase] = $this->project('BWG-DIR-PROJ-001');
        $estimate = $this->estimate($project, $phase, 1, 'issued', '1000.00');
        $estimate->entries()->firstOrFail()->update(['subcategory' => 'concrete_cement']);
        $budget = InternalBudget::create(['budget_code' => 'BUD-DIR-PROJ-001', 'project_id' => $project->id, 'version' => 1, 'status' => 'approved']);
        $budget->entries()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'subcategory' => 'concrete_cement', 'item_description' => 'Cement budget', 'quantity' => '1', 'unit_cost' => '100000000.00']);
        $request = $this->expenseRequest($project, $phase, 'approved', '80000000.00', 'materials');
        $request->update(['subcategory' => 'concrete_cement']);
        ExpensePayment::create(['payment_code' => 'PAY-DIR-PROJ-001', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '60000000.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $invoice = $this->invoice($project, $phase, 'issued', '90.00', today()->toDateString(), today()->addDays(10)->toDateString());
        ClientPayment::create(['payment_code' => 'RCP-DIR-PROJ-001', 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '25.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);

        $director = $this->user('director');
        $response = $this->actingAs($director)->get(route('director.projects.show', $project));
        $response->assertOk()
            ->assertSee($project->name)
            ->assertSee('Current Phase')
            ->assertSee('Phase History')
            ->assertSee('Trace the Project at the End of a Day')
            ->assertSee('Show graphs')
            ->assertSee('Financial Activity for Selected Dates')
            ->assertSee('60.00')
            ->assertSee('80.00');
        $this->assertSame(0, substr_count($response->getContent(), 'Estimator Client Value'));
        $response->assertViewHas('row', function (array $row) {
            return $row['budget'] === '100000000.00'
                && $row['approved_commitments'] === '80000000.00'
                && $row['actual_expenses'] === '60000000.00'
                && $row['invoiced_current'] === '90.00'
                && $row['received_current'] === '25.00'
                && $row['receivables_current'] === '65.00'
                && $row['payables_current'] === '20000000.00'
                && $row['cash_balance'] === '-59999975.00'
                && $row['committed_utilization'] === '80.00'
                && $row['actual_utilization'] === '60.00'
                && $row['phase_analytics'][0]['approved'] === '80000000.00'
                && $row['phase_analytics'][0]['actual'] === '60000000.00'
                && $row['phase_analytics'][0]['estimator_base'] === '1000.00'
                && $row['category_analytics']['materials']['estimator_base'] === '1000.00'
                && $row['category_analytics']['materials']['budget'] === '100000000.00'
                && $row['category_analytics']['materials']['subcategories']->firstWhere('key', 'concrete_cement')['actual'] === '60000000.00';
        });
    }

    public function test_calendar_snapshot_reconstructs_recorded_phase_and_financial_position_at_day_end(): void
    {
        [$project, $firstPhase] = $this->project('BWG-DIR-HISTORY-001');
        $firstPhase->update([
            'status' => 'completed',
            'started_at' => '2026-01-01',
            'completed_at' => '2026-01-10',
        ]);
        $project->phases()->create([
            'stage_code' => 'substructure',
            'phase_order' => 2,
            'status' => 'active',
            'started_at' => '2026-01-11',
        ]);
        $invoice = $this->invoice($project, $firstPhase, 'issued', '100.00', '2026-01-05', '2026-02-05');
        foreach ([['RCP-HISTORY-001', '40.00', '2026-01-06'], ['RCP-HISTORY-002', '20.00', '2026-01-15']] as [$code, $amount, $date]) {
            ClientPayment::create(['payment_code' => $code, 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'cash']);
        }
        $request = $this->expenseRequest($project, $firstPhase, 'approved', '50.00', 'materials');
        $request->update(['submitted_at' => '2026-01-04 09:00:00']);
        foreach ([['PAY-HISTORY-001', '10.00', '2026-01-07'], ['PAY-HISTORY-002', '20.00', '2026-01-16']] as [$code, $amount, $date]) {
            ExpensePayment::create(['payment_code' => $code, 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $firstPhase->id, 'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'cash']);
        }

        $snapshot = app(DirectorAnalyticsService::class)->projectSnapshot($project, CarbonImmutable::parse('2026-01-10'));

        $this->assertNull($snapshot['current_phase']);
        $this->assertSame(1, $snapshot['completed_phases']);
        $this->assertSame('100.00', $snapshot['invoiced']);
        $this->assertSame('40.00', $snapshot['received']);
        $this->assertSame('10.00', $snapshot['actual_expenses']);
        $this->assertSame('30.00', $snapshot['cash_balance']);
        $this->assertSame('50.00', $snapshot['approved_commitments']);

        $this->actingAs($this->user('director'))
            ->get(route('director.projects.show', [$project, 'as_of' => '2026-01-10']))
            ->assertOk()
            ->assertSee('Recorded position at end of 10 Jan 2026')
            ->assertSee('10 days');
    }

    public function test_approved_request_and_issued_invoice_change_commitment_and_receivable_but_not_actual_cash(): void
    {
        [$project, $phase] = $this->project('BWG-DIR-SOURCE-001');
        $request = $this->expenseRequest($project, $phase, 'approved', '500.00', 'materials');
        $this->invoice($project, $phase, 'issued', '300.00', today()->toDateString(), today()->addDays(10)->toDateString());
        $service = app(DirectorAnalyticsService::class);
        $row = $service->project($project, CarbonImmutable::today()->startOfYear(), CarbonImmutable::today());
        $this->assertSame('500.00', $row['approved_commitments']);
        $this->assertSame('0.00', $row['actual_expenses']);
        $this->assertSame('300.00', $row['receivables_current']);
        $this->assertSame('0.00', $row['received_current']);

        ExpensePayment::create(['payment_code' => 'PAY-DIR-SOURCE-001', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '125.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $row = $service->project($project, CarbonImmutable::today()->startOfYear(), CarbonImmutable::today());
        $this->assertSame('125.00', $row['actual_expenses']);
        $this->assertSame('-125.00', $row['cash_balance']);

        $invoice = ClientInvoice::where('project_id', $project->id)->where('status', 'issued')->firstOrFail();
        ClientPayment::create(['payment_code' => 'RCP-DIR-SOURCE-001', 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '200.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $row = $service->project($project, CarbonImmutable::today()->startOfYear(), CarbonImmutable::today());
        $this->assertSame('200.00', $row['received_current']);
        $this->assertSame('75.00', $row['cash_balance']);
    }

    public function test_monthly_cash_trend_uses_client_and_expense_payment_dates_and_reports_net_movement(): void
    {
        [$project, $phase] = $this->project('BWG-DIR-TREND-001');
        $invoice = $this->invoice($project, $phase, 'issued', '18000000.00', '2026-01-01', '2026-12-31');
        foreach ([['RCP-DIR-TREND-001', '10000000.00', '2026-01-12'], ['RCP-DIR-TREND-002', '5000000.00', '2026-02-12']] as [$code, $amount, $date]) {
            ClientPayment::create(['payment_code' => $code, 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'cash']);
        }
        $this->invoice($project, $phase, 'issued', '2000000.00', '2025-12-01', '2026-12-31');
        ClientPayment::create(['payment_code' => 'RCP-DIR-TREND-003', 'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '3000000.00', 'payment_date' => '2025-12-12', 'payment_method' => 'cash']);
        $request = $this->expenseRequest($project, $phase, 'approved', '12000000.00', 'materials');
        foreach ([['PAY-DIR-TREND-001', '4000000.00', '2026-01-15'], ['PAY-DIR-TREND-002', '7000000.00', '2026-02-15']] as [$code, $amount, $date]) {
            ExpensePayment::create(['payment_code' => $code, 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'cash']);
        }
        ExpensePayment::create(['payment_code' => 'PAY-DIR-TREND-003', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '1000000.00', 'payment_date' => '2025-12-15', 'payment_method' => 'cash']);
        $report = app(DirectorAnalyticsService::class)->portfolio(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-28'));

        $this->assertSame('6000000.00', $report['trend'][0]['net']);
        $this->assertSame('-2000000.00', $report['trend'][1]['net']);
        $this->assertSame('15000000.00', $report['summary']['received_period']);
        $this->assertSame('11000000.00', $report['summary']['expenses_period']);
        $this->assertSame('18000000.00', $report['summary']['received_current']);
        $this->assertSame('12000000.00', $report['summary']['expenses_current']);
        $this->assertSame('20000000.00', $report['summary']['invoiced_current']);
        $this->assertSame('2000000.00', $report['summary']['receivables']);
        $this->assertSame('6000000.00', $report['summary']['cash_balance']);
    }

    public function test_zero_budget_and_over_budget_are_reported_without_misleading_utilization(): void
    {
        [$zeroProject, $zeroPhase] = $this->project('BWG-DIR-ZERO-001');
        $this->expenseRequest($zeroProject, $zeroPhase, 'approved', '10.00', 'materials');
        [$overProject, $overPhase] = $this->project('BWG-DIR-OVER-001');
        $budget = InternalBudget::create(['budget_code' => 'BUD-DIR-OVER-001', 'project_id' => $overProject->id, 'version' => 1, 'status' => 'approved']);
        $budget->entries()->create(['project_phase_id' => $overPhase->id, 'main_category' => 'materials', 'item_description' => 'Budgeted item', 'quantity' => '1', 'unit_cost' => '100.00']);
        $approved = $this->expenseRequest($overProject, $overPhase, 'approved', '120.00', 'materials');
        ExpensePayment::create(['payment_code' => 'PAY-DIR-OVER-001', 'expense_request_id' => $approved->id, 'project_id' => $overProject->id, 'project_phase_id' => $overPhase->id, 'amount' => '150.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);

        $service = app(DirectorAnalyticsService::class);
        $rangeFrom = CarbonImmutable::today()->startOfYear();
        $rangeTo = CarbonImmutable::today();
        $zero = $service->project($zeroProject, $rangeFrom, $rangeTo);
        $over = $service->project($overProject, $rangeFrom, $rangeTo);
        $this->assertTrue($zero['no_budget']);
        $this->assertNull($zero['actual_utilization']);
        $this->assertNull($zero['committed_utilization']);
        $this->assertFalse($zero['over_budget']);
        $this->assertTrue($over['over_budget']);
        $this->assertSame('120.00', $over['committed_utilization']);
        $this->assertSame('150.00', $over['actual_utilization']);
        $this->assertSame('-50.00', $over['budget_remaining_actual']);
        $this->assertSame('-20.00', $over['commitment_headroom']);
    }

    public function test_overdue_receivable_summary_and_read_only_exports_are_available(): void
    {
        [$project, $phase] = $this->project('BWG-DIR-OVERDUE-001');
        $this->invoice($project, $phase, 'issued', '75.00', today()->subDays(40)->toDateString(), today()->subDays(10)->toDateString());
        $director = $this->user('director');
        $this->actingAs($director)->get(route('director.index'))
            ->assertOk()->assertSee('1')->assertSee('75.00')->assertSee('Receivable Attention');
        $export = $this->actingAs($director)->get(route('director.portfolio.export'));
        $export->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheet = IOFactory::load($export->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Director Project Portfolio', $sheet->getCell('A1')->getValue());
        $this->assertSame('Project Code', $sheet->getCell('A4')->getValue());
        $this->actingAs($director)->get(route('director.summary.export'))->assertOk();
        $this->actingAs($director)->get(route('director.transactions.export'))->assertOk();
    }

    public function test_director_receivables_and_payables_reuse_valid_statuses_and_apply_payment_events(): void
    {
        [$project, $phase] = $this->project('BWG-DIR-REGISTERS-001');
        $partPaid = $this->invoice($project, $phase, 'issued', '100.00', today()->subDays(5)->toDateString(), today()->addDays(5)->toDateString());
        ClientPayment::create(['payment_code' => 'RCP-DIR-REG-001', 'client_invoice_id' => $partPaid->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '40.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $overdue = $this->invoice($project, $phase, 'issued', '60.00', today()->subDays(30)->toDateString(), today()->subDays(10)->toDateString());
        $draftInvoice = $this->invoice($project, $phase, 'draft', '500.00', null, today()->addDays(5)->toDateString());
        $this->invoice($project, $phase, 'void', '700.00', today()->subDays(2)->toDateString(), today()->addDays(5)->toDateString());
        $paidInvoice = $this->invoice($project, $phase, 'issued', '80.00', today()->subDays(5)->toDateString(), today()->subDays(1)->toDateString());
        ClientPayment::create(['payment_code' => 'RCP-DIR-REG-002', 'client_invoice_id' => $paidInvoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '80.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);

        $approved = $this->expenseRequest($project, $phase, 'approved', '100.00', 'materials');
        ExpensePayment::create(['payment_code' => 'PAY-DIR-REG-001', 'expense_request_id' => $approved->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '25.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $paidRequest = $this->expenseRequest($project, $phase, 'approved', '20.00', 'materials');
        ExpensePayment::create(['payment_code' => 'PAY-DIR-REG-002', 'expense_request_id' => $paidRequest->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '20.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        foreach (['pending_approval', 'rejected', 'cancelled'] as $status) {
            $this->expenseRequest($project, $phase, $status, '900.00', 'materials');
        }

        $analytics = app(DirectorAnalyticsService::class);
        $receivables = $analytics->receivables($project->id);
        $payables = $analytics->payables($project->id);
        $this->assertCount(2, $receivables);
        $this->assertSame('120.00', Money::sum($receivables->pluck('outstanding')));
        $this->assertSame('60.00', Money::sum($receivables->where('overdue', true)->pluck('outstanding')));
        $this->assertTrue($receivables->firstWhere('invoice.id', $overdue->id)['overdue']);
        $this->assertCount(1, $payables);
        $this->assertSame('75.00', Money::sum($payables->pluck('outstanding')));
        $this->assertSame('40.00', $receivables->firstWhere('invoice.id', $partPaid->id)['received']);
        $this->assertFalse($receivables->contains(fn ($row) => in_array($row['invoice']->id, [$draftInvoice->id, $paidInvoice->id], true)));
    }

    public function test_director_is_the_system_administrator_and_can_open_every_role_workspace(): void
    {
        [$project, $phase] = $this->project('BWG-DIR-SEC-001');
        $director = $this->user('director');
        $this->actingAs($director)->get(route('director.index'))->assertOk()->assertSee('Director Dashboard')->assertSee($project->project_code);
        $this->actingAs($director)->get(route('director.projects.show', $project))->assertOk();
        $this->actingAs($director)->get(route('director.transactions.index'))->assertOk();
        $this->actingAs($director)->get(route('director.receivables.index'))->assertOk();
        $this->actingAs($director)->get(route('director.payables.index'))->assertOk();
        $this->actingAs($director)->get(route('accountant.index'))->assertOk();
        $this->actingAs($director)->get(route('pm.projects.index'))->assertOk();
        $this->actingAs($director)->get(route('estimator.projects.index'))->assertOk();
        $this->actingAs($director)->get(route('site.index'))->assertOk();
        $this->actingAs($director)->get(route('admin.projects.index'))->assertOk();
        $this->actingAs($director)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('director.index'))->assertOk();
    }

    public function test_director_role_migration_is_idempotent_and_preserves_other_users_and_financial_records(): void
    {
        $this->assertDatabaseHas('roles', ['name' => 'director']);
        $admin = $this->user('admin');
        $adminBefore = $admin->role_id;
        $otherUser = $this->user('accounts');
        $otherRoleBefore = $otherUser->role_id;
        $directorAccount = User::factory()->create([
            'email' => 'director@buildiwick.test',
            'role_id' => Role::where('name', 'admin')->value('id'),
            'is_active' => true,
        ]);
        [$project, $phase] = $this->project('BWG-DIR-MIGRATION-001');
        $invoice = $this->invoice($project, $phase, 'issued', '55.00', today()->toDateString(), today()->addDays(10)->toDateString());
        $receipt = ClientPayment::create([
            'payment_code' => 'RCP-DIR-MIGRATION-001', 'client_invoice_id' => $invoice->id,
            'project_id' => $project->id, 'client_id' => $project->client_id, 'amount' => '5.00',
            'payment_date' => today()->toDateString(), 'payment_method' => 'cash',
        ]);
        $invoiceBefore = $invoice->fresh()->toArray();
        $receiptBefore = $receipt->fresh()->toArray();
        $countsBefore = [ClientInvoice::count(), ClientPayment::count(), ExpenseRequest::count(), ExpensePayment::count()];

        $migration = require database_path('migrations/2026_10_09_000001_merge_admin_role_into_director.php');
        $migration->up();
        $migration->up();

        $this->assertSame('director', $directorAccount->fresh()->role->name);
        $this->assertSame('director', $admin->fresh()->role->name);
        $this->assertNotSame($adminBefore, $admin->fresh()->role_id);
        $this->assertSame($otherRoleBefore, $otherUser->fresh()->role_id);
        $this->assertSame(1, Role::where('name', 'director')->count());
        $this->assertDatabaseMissing('roles', ['name' => 'admin']);
        $this->assertSame($countsBefore, [ClientInvoice::count(), ClientPayment::count(), ExpenseRequest::count(), ExpensePayment::count()]);
        $this->assertSame($invoiceBefore, $invoice->fresh()->toArray());
        $this->assertSame($receiptBefore, $receipt->fresh()->toArray());

        $this->seed(UserSeeder::class);
        $this->assertSame('director', User::where('email', 'director@buildiwick.test')->firstOrFail()->role->name);
        $this->assertSame('director', $admin->fresh()->role->name);
    }

    private function project(string $code, string $status = 'active'): array
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id, 'project_code' => $code, 'status' => $status]);
        $phase = $project->phases()->create(['stage_code' => 'preliminaries', 'phase_order' => 1, 'status' => 'active']);

        return [$project, $phase];
    }

    private function estimate(Project $project, $phase, int $version, string $status, string $amount): ProjectEstimate
    {
        $estimate = ProjectEstimate::create(['estimate_code' => $project->project_code.'-EST-'.$version, 'project_id' => $project->id, 'client_id' => $project->client_id, 'version' => $version, 'status' => $status]);
        $estimate->entries()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Estimate line', 'quantity' => '1', 'unit' => 'ls', 'base_unit_cost' => $amount, 'markup_percent' => '0']);

        return $estimate;
    }

    private function invoice(Project $project, $phase, string $status, string $amount, ?string $issuedAt, ?string $dueAt): ClientInvoice
    {
        $invoice = ClientInvoice::create(['invoice_code' => 'INV-'.fake()->unique()->numerify('######'), 'project_id' => $project->id, 'client_id' => $project->client_id, 'status' => $status, 'issued_at' => $issuedAt, 'due_at' => $dueAt]);
        $invoice->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Invoice line', 'unit_price' => $amount]);

        return $invoice;
    }

    private function expenseRequest(Project $project, $phase, string $status, string $amount, string $category): ExpenseRequest
    {
        return ExpenseRequest::create(['request_code' => 'REQ-'.fake()->unique()->numerify('######'), 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'main_category' => $category, 'item_description' => 'Expense item', 'quantity' => '1', 'total_amount' => $amount, 'approved_amount' => $status === 'approved' ? $amount : null, 'requested_at' => today()->toDateString(), 'submitted_at' => now(), 'status' => $status]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'is_active' => true]);
    }
}
