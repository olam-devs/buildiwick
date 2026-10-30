<?php

namespace Tests\Feature;

use App\Models\DirectorKpiTarget;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\LabourAgreement;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use App\Services\DirectorKpiService;
use App\Services\LedgerAnalyticsService;
use App\Services\ProjectWarningService;
use Carbon\Carbon;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RecordingModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 10:00:00');
        config(['buildiwick.mode' => 'recording', 'buildiwick.features' => ['client_billing' => false, 'estimates' => false, 'site_operator' => false, 'approvals' => false]]);
        $this->seed(LookupSeeder::class);
        foreach (['director', 'accounts', 'project_manager', 'estimator_qs', 'site_operator'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_director_pm_and_accounts_use_the_system_and_switched_off_areas_are_gone(): void
    {
        [$project] = $this->project();
        $pm = $this->user('project_manager', $project);

        $this->actingAs($pm)->get('/')->assertRedirect(route('ledger.index'));
        $this->actingAs($this->user('accounts'))->get('/')->assertRedirect(route('ledger.index'));
        $this->actingAs($this->user('director'))->get('/')->assertRedirect(route('director.index'));
        foreach (['estimator_qs', 'site_operator'] as $role) {
            $this->post('/logout');
            $user = $this->user($role);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $director = $this->user('director');
        foreach (['/accountant/invoices', '/accountant/receivables', '/director/receivables', '/estimator/projects', '/site', '/pm/projects', '/director/request-approvals'] as $url) {
            $this->actingAs($director)->get($url)->assertNotFound();
        }
        $this->actingAs($director)->get(route('director.projects.show', $project))->assertRedirect(route('ledger.overview', $project));
        $page = $this->actingAs($director)->get(route('director.index'))->assertOk()->assertSee('Company Dashboard');
        $page->assertDontSee('Receivable')->assertDontSee('Invoice')->assertDontSee('Estimator');
        $this->actingAs($pm)->get(route('ledger.overview', $project))->assertOk()
            ->assertSee('>Expenditure</a>', false)->assertSee('>Record Expense</a>', false)->assertSee('>Phases &amp; Budget</a>', false)->assertSee('>Workers</a>', false)
            ->assertDontSee('Warnings &amp; KPIs', false);
        $this->actingAs($director)->get(route('admin.users.create'))->assertOk()->assertDontSee('estimator_qs')->assertDontSee('site_operator');
    }

    public function test_pm_logs_to_confirm_and_accounts_confirms_the_amount_paid(): void
    {
        [$project, $phases] = $this->project();
        $pm = $this->user('project_manager', $project);
        $accounts = $this->user('accounts');

        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['status' => 'paid', 'payment_method' => 'cash', 'payment_date' => '2026-10-29']))
            ->assertSessionHasErrors('status');
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0]))->assertRedirect(route('ledger.expenses', $project));
        $line = ExpenseRequest::query()->sole();
        $this->assertSame('TST-A-TXN-0001', $line->request_code);
        $this->assertSame(['approved', 'unpaid', '920000.00'], [$line->status, $line->payment_status, $line->total_amount]);
        $this->actingAs($pm)->get(route('ledger.to-confirm'))->assertForbidden();

        $this->actingAs($accounts)->get(route('ledger.to-confirm'))->assertOk()->assertSee('Floor tiles')->assertSee('920,000.00');
        $this->actingAs($accounts)->post(route('ledger.expenses.confirm', [$project, $line]), [
            'total_amount' => '900000', 'amount' => '900000', 'payment_date' => '2026-10-30', 'payment_method' => 'bank_transfer',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $line->refresh();
        $this->assertSame(['900000.00', 'paid'], [$line->total_amount, $line->payment_status]);
        $this->assertDatabaseHas('project_edit_logs', ['document_id' => $line->id, 'action' => 'ledger_amount_confirmed']);
        $this->actingAs($pm)->get(route('ledger.expenses.edit', [$project, $line]))->assertForbidden();

        $this->actingAs($accounts)->post(route('ledger.expenses.store', $project), $this->line($phases[1], ['item_description' => 'Office water', 'status' => 'paid', 'payment_method' => 'mobile_money', 'payment_date' => '2026-10-30']))
            ->assertSessionHasNoErrors();
        $register = $this->actingAs($pm)->get(route('ledger.expenses', [$project, 'category' => 'materials']))->assertOk();
        $register->assertSee('TST-A-TXN-0001')->assertSee('TST-A-TXN-0002')->assertSee('1,820,000.00');
        $this->actingAs($pm)->get(route('ledger.expenses', [$project, 'status' => 'to_confirm']))->assertOk()->assertDontSee('Floor tiles');
        $this->actingAs($pm)->get(route('ledger.expenses.export', $project))->assertOk()->assertDownload();
    }

    public function test_pms_only_work_on_their_projects_and_their_own_unpaid_lines(): void
    {
        [$project, $phases] = $this->project();
        [$other] = $this->project('BWG-TST-B-2026-001');
        $pm = $this->user('project_manager', $project);
        $colleague = $this->user('project_manager', $project);

        $this->actingAs($pm)->get(route('ledger.overview', $other))->assertForbidden();
        $this->actingAs($pm)->post(route('ledger.expenses.store', $other), $this->line($other->phases()->first()))->assertForbidden();
        $this->actingAs($pm)->get(route('ledger.index'))->assertOk()->assertSee($project->name)->assertDontSee($other->name);

        $this->actingAs($colleague)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));
        $line = ExpenseRequest::query()->sole();
        $this->actingAs($pm)->put(route('ledger.expenses.update', [$project, $line]), $this->line($phases[0], ['item_description' => 'Changed']))->assertForbidden();
        $this->actingAs($colleague)->put(route('ledger.expenses.update', [$project, $line]), $this->line($phases[1], ['item_description' => 'Floor tiles 60x60', 'unit_cost' => '25000']))->assertRedirect();
        $this->assertSame(['1000000.00', $phases[1]->id], [$line->fresh()->total_amount, $line->fresh()->project_phase_id]);
        $this->actingAs($colleague)->delete(route('ledger.expenses.destroy', [$project, $line]))->assertRedirect();
        $this->assertSame(0, ExpenseRequest::count());
    }

    public function test_director_builds_the_phase_tree_and_budget_with_roll_ups(): void
    {
        [$project] = $this->project(stages: false);
        $director = $this->user('director');
        $pm = $this->user('project_manager', $project);

        $this->actingAs($pm)->post(route('ledger.phases.store', $project), ['phase_name' => 'Nope'])->assertForbidden();
        $this->actingAs($director)->post(route('ledger.phases.store', $project), ['phase_name' => 'Finishing'])->assertRedirect();
        $finishing = ProjectPhase::where('phase_name', 'Finishing')->sole();
        $this->actingAs($director)->post(route('ledger.phases.store', $project), ['phase_name' => 'Ground floor', 'parent_id' => $finishing->id]);
        $ground = ProjectPhase::where('phase_name', 'Ground floor')->sole();
        $this->actingAs($director)->post(route('ledger.phases.store', $project), ['phase_name' => 'Tiling', 'parent_id' => $ground->id]);
        $tiling = ProjectPhase::where('phase_name', 'Tiling')->sole();
        $this->actingAs($director)->post(route('ledger.phases.store', $project), ['phase_name' => 'Too deep', 'parent_id' => $tiling->id])->assertSessionHasErrors('parent_id');

        $this->actingAs($director)->post(route('ledger.budget.save', $project), ['budget' => [
            $ground->id => ['materials' => '1000000', 'labour_subcontracts' => '400000'], $tiling->id => ['materials' => '600000'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($tiling, ['unit_cost' => '20000']));

        $rows = app(LedgerAnalyticsService::class)->project($project)['phases']->keyBy(fn ($row) => $row['phase']->id);
        $this->assertSame('2000000.00', $rows[$finishing->id]['budget']);
        $this->assertSame('800000.00', $rows[$finishing->id]['recorded']);
        $this->assertSame('1600000.00', $rows[$ground->id]['categories']['materials']['budget']);
        $this->assertSame('1000000.00', $rows[$ground->id]['categories']['materials']['own_budget']);
        $this->assertSame('133.33', $rows[$tiling->id]['used']);
        $this->actingAs($pm)->get(route('ledger.structure', $project))->assertOk()->assertSee('Tiling')->assertSee('2,000,000.00')->assertDontSee('Save Budget');
        $this->actingAs($director)->delete(route('ledger.phases.destroy', [$project, $finishing]))->assertSessionHasErrors('phase');

        $this->actingAs($director)->post(route('ledger.budget.save', $project), ['budget' => [$tiling->id => ['materials' => '']]]);
        $this->assertSame('1400000.00', app(LedgerAnalyticsService::class)->project($project)['totals']['budget']);
    }

    public function test_workers_link_labour_payments_and_show_contract_progress(): void
    {
        [$project, $phases] = $this->project();
        $pm = $this->user('project_manager', $project);
        $this->actingAs($pm)->post(route('ledger.workers.store', $project), ['fundi_subcontractor' => 'Henry (builder)', 'agreed_value' => '500000', 'labour_type' => 'fundi_labour'])->assertRedirect();
        $worker = LabourAgreement::query()->sole();
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0], [
            'main_category' => 'labour_subcontracts', 'item_description' => 'Tiling labour advance', 'quantity' => '1', 'unit_cost' => '', 'total_amount' => '200000', 'labour_agreement_id' => $worker->id,
        ]))->assertSessionHasNoErrors();
        $this->actingAs($pm)->get(route('ledger.workers', $project))->assertOk()->assertSee('Henry (builder)')->assertSee('40.00% of contract')->assertSee('300,000.00');
        $this->actingAs($pm)->get(route('ledger.expenses', [$project, 'worker' => $worker->id]))->assertOk()->assertSee('Tiling labour advance');
        $this->actingAs($this->user('accounts'))->post(route('ledger.workers.store', $project), ['fundi_subcontractor' => 'X'])->assertForbidden();
    }

    public function test_kpis_and_warnings_follow_phase_and_category_budgets(): void
    {
        [$project, $phases] = $this->project();
        $director = $this->user('director');
        $pm = $this->user('project_manager', $project);
        $this->actingAs($director)->post(route('ledger.budget.save', $project), ['budget' => [$phases[0]->id => ['materials' => '1000000']]]);
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['expense_date' => '2026-10-01']));

        $this->actingAs($director)->post(route('director.projects.kpis.store', $project), [
            'metric' => 'spend', 'project_phase_id' => $phases[0]->id, 'main_category' => 'materials', 'period' => 'this_month', 'target_value' => '500000',
        ])->assertSessionHasNoErrors();
        $this->actingAs($director)->post(route('director.projects.kpis.store', $project), ['metric' => 'budget_used_pct', 'period' => 'this_year', 'target_value' => '95'])->assertSessionHasNoErrors();
        $this->actingAs($director)->post(route('director.kpis.store'), ['metric' => 'client_receipts', 'period' => 'this_month', 'target_value' => '1'])->assertSessionHasErrors('metric');
        $rows = app(DirectorKpiService::class)->evaluate(DirectorKpiTarget::orderBy('id')->get())->values();
        $this->assertSame(['920000.00', 'over'], [$rows[0]['actual'], $rows[0]['status']]);
        $this->assertSame(['92.00', 'within'], [$rows[1]['actual'], $rows[1]['status']]);

        $warnings = collect(app(ProjectWarningService::class)->evaluateLedger(app(LedgerAnalyticsService::class)->project($project)))->pluck('message');
        $this->assertTrue($warnings->contains(fn ($m) => str_contains($m, 'Preliminaries › Materials: 92.00% of budget')));
        $this->assertTrue($warnings->contains(fn ($m) => str_contains($m, '1 line(s) recorded more than 14 days ago are still not confirmed')));
        $this->assertSame(1, $warnings->filter(fn ($m) => str_contains($m, '% of budget ('))->count());
        $this->actingAs($director)->get(route('director.projects.warnings', $project))->assertOk()->assertSee('92.00% of budget');
        $this->actingAs($director)->get(route('director.index'))->assertOk()->assertSee('Needs attention')->assertSee($project->name);
    }

    public function test_director_creates_a_project_with_the_tracker_fields(): void
    {
        $director = $this->user('director');
        $pm = $this->user('project_manager');
        $this->actingAs($director)->post(route('admin.projects.store'), ['project_code' => 'BWG-NEW-RES-2026-001', 'name' => 'New residence'])
            ->assertSessionHasErrors(['status', 'location']);
        $this->actingAs($director)->post(route('admin.projects.store'), [
            'project_code' => 'BWG-NEW-RES-2026-001', 'name' => 'New residence', 'status' => 'near_closure', 'location' => 'Mbezi, Dar es Salaam',
            'project_manager_ids' => [$pm->id], 'standard_stages' => '1',
        ])->assertRedirect();
        $project = Project::where('project_code', 'BWG-NEW-RES-2026-001')->sole();
        $this->assertNull($project->client_id);
        $this->assertSame(8, $project->phases()->count());
        $this->assertTrue($pm->assignedProjects()->whereKey($project->id)->exists());
        $this->actingAs($pm)->get(route('ledger.index'))->assertSee('New residence');
    }

    public function test_tracker_workbook_import_keeps_the_recorded_data(): void
    {
        $this->user('director');
        [$old] = $this->project('BWG-OLD-DEMO-2026-001');
        $path = $this->trackerWorkbook();

        $this->artisan('app:import-tracker', ['file' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, ExpenseRequest::count());
        $this->artisan('app:import-tracker', ['file' => $path, '--fresh' => true])->assertSuccessful();

        $this->assertNull(Project::find($old->id));
        $project = Project::where('project_code', 'BWG-TST-A-2026-001')->sole();
        $this->assertSame(['Test project A', 'active', 'Oysterbay, Dar es Salaam'], [$project->name, $project->status, $project->location]);
        $this->assertSame(8, $project->phases()->count());
        $lines = ExpenseRequest::where('project_id', $project->id)->orderBy('id')->get()->keyBy('request_code');
        $this->assertSame(['TST-A-TXN-0001', 'TST-A-TXN-0002', 'TST-A-TXN-0003', 'TST-A-TXN-0004'], $lines->keys()->all());
        $this->assertSame(['310500.00', 'paid', '2026-03-04'], [$lines['TST-A-TXN-0001']->total_amount, $lines['TST-A-TXN-0001']->payment_status, $lines['TST-A-TXN-0001']->requested_at->toDateString()]);
        $this->assertSame(['210000.00', 'unpaid', 'timber_formwork', 'pc'], [$lines['TST-A-TXN-0002']->total_amount, $lines['TST-A-TXN-0002']->payment_status, $lines['TST-A-TXN-0002']->subcategory, $lines['TST-A-TXN-0002']->unit]);
        $this->assertSame(['-1000.00', 'paid'], [$lines['TST-A-TXN-0003']->total_amount, $lines['TST-A-TXN-0003']->payment_status]);
        $this->assertSame('Henry (builder)', $lines['TST-A-TXN-0004']->labourAgreement?->fundi_subcontractor);
        $this->assertSame('Roofing', $lines['TST-A-TXN-0002']->phase->stage_label);
        $this->assertEqualsWithDelta(569500.0, (float) ExpenseRequest::where('project_id', $project->id)->sum('total_amount'), 0.001);
        $this->assertEqualsWithDelta(359500.0, (float) ExpensePayment::where('project_id', $project->id)->sum('amount'), 0.001);
        $this->artisan('app:import-tracker', ['file' => $path])->assertSuccessful();
        $this->assertSame(4, ExpenseRequest::where('project_id', $project->id)->count());
    }

    /** @return array{0: Project, 1: Collection<int, ProjectPhase>} */
    private function project(string $code = 'BWG-TST-A-2026-001', bool $stages = true): array
    {
        $project = Project::create(['project_code' => $code, 'name' => 'Project '.$code, 'status' => 'active', 'location' => 'Dar es Salaam']);
        if ($stages) {
            foreach (['Preliminaries', 'Superstructure'] as $index => $name) {
                $project->phases()->create(['stage_code' => strtolower($name), 'phase_name' => $name, 'phase_order' => $index + 1, 'status' => 'active']);
            }
        }

        return [$project, $project->phases()->orderBy('phase_order')->get()];
    }

    private function user(string $role, ?Project $project = null): User
    {
        $user = User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'is_active' => true, 'password' => bcrypt('password')]);
        $project?->assignedUsers()->attach($user->id);

        return $user;
    }

    private function line(ProjectPhase $phase, array $override = []): array
    {
        return array_merge([
            'expense_date' => '2026-10-29', 'main_category' => 'materials', 'project_phase_id' => $phase->id, 'item_description' => 'Floor tiles 60x60',
            'quantity' => '40', 'unit' => 'm2', 'unit_cost' => '23000', 'status' => 'to_confirm',
        ], $override);
    }

    private function trackerWorkbook(): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Consolidated');
        $sheet = $book->createSheet();
        $sheet->setTitle('TST-A Test project');
        $sheet->fromArray(['Project ID', 'BWG-TST-A-2026-001', 'Project Name', 'Test project A', 'Status', 'Active', 'Location', 'Oysterbay, Dar es Salaam'], null, 'A2');
        $header = ['Transaction ID', 'Date', 'Transaction Type', 'Main Category', 'Subcategory', 'Item / Description', 'Supplier / Fundi', 'Invoice / Receipt No.', 'Quantity', 'Unit', 'Unit Cost (TSh)', 'Total Amount (TSh)', 'Amount Paid (TSh)', 'Payment Status', 'Payment Method', 'Work Area / Stage', 'Evidence Link'];
        $sheet->fromArray($header, null, 'A5');
        $rows = [
            ['TST-A-TXN-0001', 46085, 'Expense', 'Materials', 'Other Materials', 'Mobilization materials', null, null, 1, 'LS', 310500, null, 310500, 'Paid', null, 'Preliminaries', 'Payment record'],
            ['TST-A-TXN-0002', 46086, 'Material log - to confirm', 'Materials', 'Timber & Formwork', 'Mirunda', null, null, 70, 'PC', 3000, null, null, 'To Confirm', null, 'Roofing', 'Procurement log'],
            $header,
            ['TST-A-TXN-0003', 46099, 'Expense', 'Materials', 'Tools & Small Equipment', 'Difference between payment and procurement log (rounding/discount)', null, null, 1, 'LS', -1000, null, -1000, 'Paid', null, 'Superstructure', null],
            ['TST-A-TXN-0004', 46100, 'Expense', 'Labour & Subcontracts', 'Fundi Labour', 'Gen works', 'Henry (builder)', null, 1, 'LS', 50000, null, 50000, 'Paid', null, 'Walling', null],
        ];
        $sheet->fromArray($rows, null, 'A6', true);
        $sheet->setCellValue('A20', 'LABOUR & SUBCONTRACT AGREEMENTS');
        $sheet->setCellValue('A30', 'TST-A-TXN-9999');
        $sheet->setCellValue('F30', 'Must not import');
        $path = tempnam(sys_get_temp_dir(), 'tracker-').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
