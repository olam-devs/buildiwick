<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\DirectorKpiTarget;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use App\Services\DirectorKpiService;
use App\Services\ProjectWarningService;
use App\Support\ReviewPeriod;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectorStrategicAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(LookupSeeder::class);
        foreach (['director', 'accounts', 'project_manager'] as $name) {
            Role::firstOrCreate(['name' => $name], ['description' => $name]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_review_periods_and_their_comparison_periods(): void
    {
        $today = CarbonImmutable::parse('2026-10-25');
        $cases = [
            'last_quarter' => ['2026-07-01', '2026-09-30', '2026-04-01', '2026-06-30'],
            'this_quarter' => ['2026-10-01', '2026-10-25', '2026-07-01', '2026-07-25'],
            'last_month' => ['2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31'],
            'this_year' => ['2026-01-01', '2026-10-25', '2025-01-01', '2025-10-25'],
            'last_year' => ['2025-01-01', '2025-12-31', '2024-01-01', '2024-12-31'],
        ];
        foreach ($cases as $name => [$from, $to, $previousFrom, $previousTo]) {
            $range = ReviewPeriod::resolve($name, today: $today);
            $previous = ReviewPeriod::previous($range);
            $this->assertSame([$from, $to, $previousFrom, $previousTo], [
                $range['from']->toDateString(), $range['to']->toDateString(), $previous['from']->toDateString(), $previous['to']->toDateString(),
            ], $name);
        }
        $custom = ReviewPeriod::previous(ReviewPeriod::resolve('custom', '2026-10-01', '2026-10-10'));
        $this->assertSame(['2026-09-21', '2026-09-30'], [$custom['from']->toDateString(), $custom['to']->toDateString()]);
    }

    public function test_dashboard_compares_the_period_with_the_previous_one(): void
    {
        [$project, $phase] = $this->project('BWG-STR-001');
        $invoice = $this->invoice($project, $phase, '10000000.00', '2026-01-10', '2026-02-10');
        $this->receipt($invoice, '3000000.00', '2026-08-15');
        $this->receipt($invoice, '1200000.00', '2026-07-05');

        $this->actingAs($this->user('director'))->get(route('director.index', ['range' => 'last_month']))->assertOk()
            ->assertSee('Period Review')->assertSee('compared with 01 Aug 2026 – 31 Aug 2026')
            ->assertSee('TSh 3,000,000.00');
        $this->actingAs($this->user('director'))->get(route('director.index', ['range' => 'last_quarter']))->assertOk()
            ->assertSee('TSh 4,200,000.00');
        $this->actingAs($this->user('director'))->get(route('director.index', ['range' => 'this_year']))->assertOk()
            ->assertSee('Last quarter', false)->assertSee('Estimates Awaiting Approval');
    }

    public function test_kpi_targets_measure_actuals_and_report_status(): void
    {
        [$project, $phase] = $this->project('BWG-STR-002');
        $invoice = $this->invoice($project, $phase, '20000000.00', '2026-10-02', '2026-11-02');
        $this->receipt($invoice, '6000000.00', '2026-10-10');
        $request = $this->approvedRequest($project, $phase, '5000000.00');
        $this->expensePayment($request, '5000000.00', '2026-10-12');
        $budget = InternalBudget::create(['budget_code' => 'BUD-STR-002', 'project_id' => $project->id, 'version' => 1, 'status' => 'approved']);
        $budget->entries()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Planned', 'quantity' => '1', 'unit_cost' => '10000000.00']);
        $director = $this->user('director');

        $this->actingAs($this->user('accounts'))->post(route('director.kpis.store'), ['metric' => 'client_receipts', 'period' => 'this_quarter', 'target_value' => '1000'])->assertForbidden();
        foreach ([
            ['metric' => 'client_receipts', 'period' => 'this_quarter', 'target_value' => '9000000'],
            ['metric' => 'expenses_paid', 'period' => 'this_month', 'target_value' => '4000000'],
            ['metric' => 'budget_used_pct', 'period' => 'this_year', 'target_value' => '80', 'project_id' => $project->id],
            ['metric' => 'invoiced', 'period' => 'next_quarter', 'target_value' => '1000000'],
        ] as $payload) {
            $route = isset($payload['project_id']) ? route('director.projects.kpis.store', $payload['project_id']) : route('director.kpis.store');
            $this->actingAs($director)->post($route, $payload)->assertRedirect()->assertSessionHasNoErrors();
        }

        $rows = app(DirectorKpiService::class)->evaluate(DirectorKpiTarget::query()->orderBy('id')->get())->values();
        // 6m of 9m received 25 days into a 92-day quarter: ahead of the straight-line pace.
        $this->assertSame(['6000000.00', 'on_track'], [$rows[0]['actual'], $rows[0]['status']]);
        $this->assertSame('2026-10-01', $rows[0]['target']->period_start->toDateString());
        $this->assertSame('2026-12-31', $rows[0]['target']->period_end->toDateString());
        $this->assertSame(['5000000.00', 'over'], [$rows[1]['actual'], $rows[1]['status']]);
        $this->assertSame(['50.00', 'within'], [$rows[2]['actual'], $rows[2]['status']]);
        $this->assertSame([null, 'upcoming'], [$rows[3]['actual'], $rows[3]['status']]);

        $this->assertSame($project->id, $rows[2]['target']->project_id);
        $this->assertNull($rows[0]['target']->project_id);
        // Company pages list company targets only; the project target lives inside the project.
        $this->actingAs($director)->get(route('director.kpis.index'))->assertOk()->assertSee('On track')->assertSee('Over limit')->assertSee('Not started')
            ->assertDontSee('Budget used, % paid of budget (ceiling)</td>', false)->assertSee('Company KPI Targets')->assertDontSee('>Warnings &amp; KPIs</a>', false);
        $this->actingAs($director)->get(route('director.index'))->assertOk()->assertSee('Company KPI Targets')->assertSee('Client money received')->assertDontSee('Budget used, % paid of budget');
        $this->actingAs($director)->get(route('director.projects.warnings', $project))->assertOk()->assertSee('Budget used, % paid of budget')->assertDontSee('Client money received</td>', false)
            ->assertSee('>Warnings &amp; KPIs</a>', false)->assertDontSee('>Company KPI Targets</a>', false)->assertDontSee('>Default Warning Rules</a>', false);
        $this->actingAs($director)->get(route('director.projects.show', $project))->assertOk()->assertSee('Open Warnings &amp; KPIs', false);

        $this->actingAs($director)->delete(route('director.kpis.destroy', $rows[3]['target']))->assertRedirect();
        $this->assertSame(3, DirectorKpiTarget::count());
    }

    public function test_warning_rules_use_company_defaults_and_project_overrides(): void
    {
        [$project, $phase] = $this->project('BWG-STR-003');
        $phase->update(['planned_completion_date' => '2026-09-01', 'status' => 'active']);
        $budget = InternalBudget::create(['budget_code' => 'BUD-STR-003', 'project_id' => $project->id, 'version' => 1, 'status' => 'approved']);
        $budget->entries()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Planned', 'quantity' => '1', 'unit_cost' => '1000000.00']);
        $request = $this->approvedRequest($project, $phase, '900000.00');
        $this->expensePayment($request, '900000.00', '2026-10-01');
        $this->invoice($project, $phase, '500000.00', '2026-08-01', '2026-09-01');
        $director = $this->user('director');

        $page = $this->actingAs($director)->get(route('director.projects.show', $project))->assertOk();
        $page->assertSee('90.00% of the budget has been paid out (limit 85.00%).');
        $page->assertSee('1 client invoice(s) overdue by more than 30 days (oldest 54 days)');
        $page->assertSee('Preliminaries (54 days late) not completed.');
        $this->actingAs($director)->get(route('director.index'))->assertOk()->assertSee('3 warning(s)');

        $rules = collect(ProjectWarningService::RULES)->map(fn ($rule) => ['threshold' => $rule['default'], 'active' => $rule['active'] ? '1' : '0'])->all();
        $rules['budget_used_pct']['threshold'] = '95';
        $rules['phase_late_days']['active'] = '0';
        $this->actingAs($this->user('accounts'))->post(route('director.projects.warning-rules.save', $project), ['rules' => $rules])->assertForbidden();
        $this->actingAs($director)->post(route('director.projects.warning-rules.save', $project), ['rules' => $rules])->assertRedirect()->assertSessionHasNoErrors();
        $page = $this->actingAs($director)->get(route('director.projects.show', $project))->assertOk();
        $page->assertDontSee('of the budget has been paid out')->assertDontSee('days late) not completed')->assertSee('overdue by more than 30 days');

        $defaults = $rules;
        $defaults['invoice_overdue_days']['threshold'] = '60';
        $this->actingAs($director)->post(route('director.warning-rules.save'), ['rules' => $defaults])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($director)->delete(route('director.projects.warning-rules.reset', $project))->assertRedirect();
        $page = $this->actingAs($director)->get(route('director.projects.show', $project))->assertOk();
        $page->assertDontSee('client invoice(s) overdue')->assertDontSee('of the budget has been paid out')->assertSee('No warning rule is triggered');
        $this->actingAs($director)->get(route('director.projects.warnings', $project))->assertOk()->assertSee('Company default')->assertDontSee('Use company defaults');
        $this->actingAs($director)->get(route('director.warning-rules.index'))->assertOk()->assertSee('Company defaults')
            ->assertSee('>Default Warning Rules</a>', false)->assertDontSee('>Warnings &amp; KPIs</a>', false);
    }

    /** @return array{0: Project, 1: ProjectPhase} */
    private function project(string $code): array
    {
        $project = Project::factory()->create(['client_id' => Client::factory()->create()->id, 'project_code' => $code, 'status' => 'active']);
        $phase = $project->phases()->create(['stage_code' => 'preliminaries', 'phase_order' => 1, 'status' => 'active']);

        return [$project, $phase];
    }

    private function invoice(Project $project, ProjectPhase $phase, string $amount, string $issuedAt, string $dueAt): ClientInvoice
    {
        $invoice = ClientInvoice::create(['invoice_code' => 'INV-'.fake()->unique()->numerify('######'), 'project_id' => $project->id, 'client_id' => $project->client_id, 'status' => 'issued', 'issued_at' => $issuedAt, 'due_at' => $dueAt]);
        $invoice->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Invoice line', 'unit_price' => $amount]);

        return $invoice;
    }

    private function receipt(ClientInvoice $invoice, string $amount, string $date): void
    {
        ClientPayment::create(['payment_code' => 'RCP-'.fake()->unique()->numerify('######'), 'client_invoice_id' => $invoice->id, 'project_id' => $invoice->project_id, 'client_id' => $invoice->client_id, 'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'bank_transfer']);
    }

    private function approvedRequest(Project $project, ProjectPhase $phase, string $amount): ExpenseRequest
    {
        $request = ExpenseRequest::create(['request_code' => 'REQ-'.fake()->unique()->numerify('######'), 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Expense item', 'quantity' => '1', 'total_amount' => $amount, 'approved_amount' => $amount, 'requested_at' => '2026-09-01', 'submitted_at' => now(), 'status' => 'approved']);
        $request->approvals()->create(['decision' => 'approved', 'decided_at' => '2026-09-01 09:00:00', 'approved_total' => $amount]);

        return $request;
    }

    private function expensePayment(ExpenseRequest $request, string $amount, string $date): void
    {
        ExpensePayment::create(['payment_code' => 'PAY-'.fake()->unique()->numerify('######'), 'expense_request_id' => $request->id, 'project_id' => $request->project_id, 'project_phase_id' => $request->project_phase_id, 'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'cash']);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'is_active' => true]);
    }
}
