<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\ProjectSetupController;
use App\Models\DirectorKpiTarget;
use App\Models\ExpenseDraft;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\LabourAgreement;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use App\Services\DirectorKpiService;
use App\Services\ExpenseLedgerService;
use App\Services\LedgerAnalyticsService;
use App\Services\ProjectWarningService;
use Carbon\Carbon;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        foreach (['director', 'accounts', 'project_manager', 'estimator_qs', 'site_operator', 'data_entry'] as $role) {
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
        $this->actingAs($this->user('accounts'))->get('/')->assertRedirect(route('ledger.to-confirm'));
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
        $this->actingAs($pm)->get(route('ledger.expenses', $project))->assertOk()
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
        $this->actingAs($pm)->get(route('ledger.to-confirm'))->assertRedirect(route('ledger.index'));

        $this->actingAs($accounts)->get(route('ledger.to-confirm'))->assertOk()->assertSee($project->name)->assertSee('920,000.00')
            ->assertSee(route('ledger.expenses', [$project, 'status' => 'to_confirm', 'sort' => 'oldest']));
        $this->actingAs($accounts)->post(route('ledger.expenses.confirm', [$project, $line]), [
            'total_amount' => '900000', 'amount' => '900000', 'payment_date' => '2026-10-30', 'payment_method' => 'bank_transfer',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $line->refresh();
        $this->assertSame(['900000.00', 'paid'], [$line->total_amount, $line->payment_status]);
        $this->assertDatabaseHas('project_edit_logs', ['document_id' => $line->id, 'action' => 'ledger_amount_confirmed']);
        $this->actingAs($pm)->get(route('ledger.expenses.edit', [$project, $line]))->assertForbidden();

        $this->actingAs($accounts)->post(route('ledger.expenses.store', $project), $this->line($phases[1]))->assertForbidden();
        $director = $this->user('director');
        $this->actingAs($director)->post(route('ledger.expenses.store', $project), $this->line($phases[1], ['item_description' => 'Office water', 'status' => 'to_confirm']))
            ->assertSessionHasNoErrors();
        $this->assertSame('paid', ExpenseRequest::where('item_description', 'Office water')->sole()->payment_status, 'Director entries are confirmed as they are recorded.');
        $this->actingAs($director)->get(route('ledger.expenses', [$project, 'category' => 'materials']))->assertOk()->assertSee('1,820,000.00');
        $register = $this->actingAs($pm)->get(route('ledger.expenses', [$project, 'category' => 'materials']))->assertOk();
        $register->assertSee('TST-A-TXN-0001')->assertSee('TST-A-TXN-0002')->assertDontSee('1,820,000.00');
        $this->actingAs($pm)->get(route('ledger.expenses', [$project, 'status' => 'to_confirm']))->assertOk()->assertDontSee('Floor tiles');
        $this->actingAs($pm)->get(route('ledger.expenses.export', $project))->assertForbidden();
        $this->actingAs($director)->get(route('ledger.expenses.export', $project))->assertOk()->assertDownload();
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
            $tiling->id => ['materials' => '600000', 'labour_subcontracts' => '400000'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($tiling, ['unit_cost' => '20000']));
        $this->assertSame('0.00', app(LedgerAnalyticsService::class)->project($project)['totals']['recorded'], 'A line awaiting confirmation is not spend yet.');
        $line = ExpenseRequest::query()->latest('id')->first();
        $this->actingAs($this->user('accounts'))->post(route('ledger.expenses.confirm', [$project, $line]), ['amount' => '800000', 'payment_date' => '2026-10-30'])
            ->assertSessionHasNoErrors();

        $rows = app(LedgerAnalyticsService::class)->project($project)['phases']->keyBy(fn ($row) => $row['phase']->id);
        $this->assertSame('1000000.00', $rows[$finishing->id]['budget']);
        $this->assertSame('800000.00', $rows[$finishing->id]['recorded']);
        $this->assertSame('600000.00', $rows[$ground->id]['categories']['materials']['budget']);
        $this->assertNull($rows[$ground->id]['categories']['materials']['own_budget']);
        $this->assertSame('80.00', $rows[$tiling->id]['used']);
        $this->actingAs($pm)->get(route('ledger.structure', $project))->assertOk()->assertSee('Tiling')->assertSee('1,000,000.00')->assertDontSee('Save Budget');
        $this->actingAs($director)->delete(route('ledger.phases.destroy', [$project, $finishing]))->assertSessionHasErrors('phase');

        $this->actingAs($director)->post(route('ledger.budget.save', $project), ['budget' => [$tiling->id => ['materials' => '']]]);
        $this->assertSame('400000.00', app(LedgerAnalyticsService::class)->project($project)['totals']['budget']);
    }

    public function test_expenses_and_category_budgets_go_on_the_lowest_level(): void
    {
        [$project] = $this->project(stages: false);
        $director = $this->user('director');
        $pm = $this->user('project_manager', $project);
        $this->actingAs($director)->post(route('ledger.phases.store', $project), ['phase_name' => 'Walling']);
        $walling = ProjectPhase::where('phase_name', 'Walling')->sole();

        // No budget and no subphases yet: recording can start on the phase itself.
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($walling))->assertSessionHasNoErrors();
        $this->actingAs($director)->post(route('ledger.budget.save', $project), ['budget' => [$walling->id => ['materials' => '500000']]])->assertSessionHasNoErrors();

        $this->actingAs($director)->post(route('ledger.phases.store', $project), ['phase_name' => 'Block 1', 'parent_id' => $walling->id]);
        $block = ProjectPhase::where('phase_name', 'Block 1')->sole();
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($walling, ['item_description' => 'Sand']))->assertSessionHasErrors('project_phase_id');
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($block, ['item_description' => 'Sand']))->assertSessionHasNoErrors();
        $this->actingAs($director)->post(route('ledger.budget.save', $project), ['budget' => [$walling->id => ['labour_subcontracts' => '100']]])->assertSessionHasErrors('budget');
        $this->actingAs($director)->post(route('ledger.budget.save', $project), ['budget' => [$block->id => ['materials' => '300000']]])->assertSessionHasNoErrors();

        $form = $this->actingAs($pm)->get(route('ledger.expenses.create', $project))->assertOk();
        $form->assertSee('value="'.$walling->id.'"  disabled', false)->assertSee('Walling ▸');
        $page = $this->actingAs($director)->get(route('ledger.structure', $project))->assertOk();
        $page->assertSee('name="budget['.$block->id.'][materials]"', false)->assertDontSee('name="budget['.$walling->id.'][labour_subcontracts]"', false)
            ->assertSee('set before subphases');
        $this->assertSame('800000.00', app(LedgerAnalyticsService::class)->project($project)['phases']->firstWhere('label', 'Walling')['budget']);
        $this->assertSame(2, ExpenseRequest::where('project_id', $project->id)->count());
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
        $this->actingAs($director)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['expense_date' => '2026-10-01']));
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[1], ['main_category' => 'labour_subcontracts', 'item_description' => 'Fundi advance', 'quantity' => '1', 'unit_cost' => '1000', 'expense_date' => '2026-10-01']));

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
    public function test_many_items_are_recorded_in_one_go_and_new_material_names_are_learned(): void
    {
        [$project, $phases] = $this->project();
        $pm = $this->user('project_manager', $project);

        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), [
            'expense_date' => '2026-10-29', 'status' => 'to_confirm',
            'rows' => [
                ['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'Cement', 'quantity' => '50', 'unit' => 'bag', 'unit_cost' => '18500'],
                ['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'River sand special', 'total_amount' => '350000', 'details' => '1 trip'],
                ['main_category' => 'materials', 'project_phase_id' => $phases[0]->id],
            ],
        ])->assertRedirect(route('ledger.expenses', $project))->assertSessionHasNoErrors();

        $this->assertSame(['TST-A-TXN-0001', 'TST-A-TXN-0002'], ExpenseRequest::query()->orderBy('id')->pluck('request_code')->all());
        $this->assertSame(['925000.00', '350000.00'], ExpenseRequest::query()->orderBy('id')->pluck('total_amount')->all());
        $this->assertDatabaseHas('lookups', ['type' => 'material_item', 'label' => 'River sand special', 'parent_code' => 'materials']);
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), ['expense_date' => '2026-10-29', 'status' => 'to_confirm',
            'rows' => [['main_category' => 'materials', 'item_description' => 'Nails']]])->assertSessionHasErrors('rows.0.project_phase_id');
        $this->assertSame(2, ExpenseRequest::count());
    }

    public function test_accounts_denies_an_expense_and_can_restore_it(): void
    {
        [$project, $phases] = $this->project();
        $pm = $this->user('project_manager', $project);
        $accounts = $this->user('accounts');
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));
        $line = ExpenseRequest::query()->sole();

        $this->actingAs($pm)->post(route('ledger.expenses.deny', [$project, $line]), ['reason' => 'Duplicate'])->assertForbidden();
        $this->actingAs($accounts)->post(route('ledger.expenses.deny', [$project, $line]), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($accounts)->post(route('ledger.expenses.deny', [$project, $line]), ['reason' => 'Duplicate of the receipt from Monday'])->assertRedirect();
        $this->assertSame('rejected', $line->fresh()->status);
        $this->assertSame('0.00', app(LedgerAnalyticsService::class)->project($project)['totals']['recorded']);
        $this->actingAs($pm)->get(route('ledger.expenses', [$project, 'status' => 'denied']))->assertOk()->assertSee('Duplicate of the receipt from Monday');

        $this->actingAs($accounts)->post(route('ledger.expenses.restore', [$project, $line]))->assertRedirect();
        $this->assertSame('approved', $line->fresh()->status);
        $this->assertSame('920000.00', app(LedgerAnalyticsService::class)->project($project)['totals']['to_confirm']);
    }

    public function test_director_sets_what_each_user_can_do_and_which_projects_they_open(): void
    {
        [$project, $phases] = $this->project();
        [$other] = $this->project('BWG-TST-B-2026-001');
        $director = $this->user('director');
        $accountsRole = Role::where('name', 'accounts')->value('id');

        $this->actingAs($director)->post(route('admin.users.store'), [
            'name' => 'Limited Accountant', 'email' => 'limited@buildiwick.test', 'role_id' => $accountsRole, 'is_active' => '1',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'permissions' => ['record_expenses', 'export'], 'project_ids' => [$project->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $limited = User::where('email', 'limited@buildiwick.test')->sole();
        $this->assertFalse($limited->seesAllProjects());
        $this->assertSame([$project->id], $limited->assignedProjects()->pluck('projects.id')->all());

        $this->actingAs($limited)->get(route('ledger.overview', $other))->assertForbidden();
        $this->actingAs($limited)->get(route('ledger.to-confirm'))->assertRedirect(route('ledger.index'));
        $this->actingAs($limited)->post(route('ledger.expenses.store', $project), $this->line($phases[0]))->assertSessionHasNoErrors();
        $line = ExpenseRequest::query()->sole();
        $this->actingAs($limited)->post(route('ledger.expenses.deny', [$project, $line]), ['reason' => 'No'])->assertForbidden();

        $this->actingAs($director)->put(route('admin.users.update', $limited), [
            'name' => 'Limited Accountant', 'email' => 'limited@buildiwick.test', 'role_id' => $accountsRole, 'is_active' => '1',
            'permissions' => ['record_expenses', 'mark_paid'], 'all_projects' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($limited->fresh())->get(route('ledger.expenses', $other))->assertOk();
        $this->assertTrue($this->user('accounts')->seesAllProjects());
        $this->assertFalse($this->user('project_manager')->seesAllProjects());
    }

    public function test_staff_accountant_only_confirms_and_sees_no_project_totals(): void
    {
        [$project, $phases] = $this->project();
        $pm = $this->user('project_manager', $project);
        $accounts = $this->user('accounts');
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));
        $line = ExpenseRequest::query()->sole();

        $this->actingAs($accounts)->get(route('ledger.overview', $project))->assertRedirect(route('ledger.expenses', $project));
        $this->actingAs($accounts)->get(route('ledger.expenses.create', $project))->assertForbidden();
        $this->actingAs($accounts)->get(route('ledger.structure', $project))->assertForbidden();
        $this->actingAs($accounts)->get(route('ledger.workers', $project))->assertForbidden();
        $this->actingAs($accounts)->get(route('ledger.export'))->assertForbidden();
        $this->actingAs($accounts)->get(route('ledger.index'))->assertOk()->assertSee($project->name)->assertSee('920,000.00')->assertDontSee('Total recorded');
        $this->actingAs($accounts)->get(route('ledger.expenses', [$project, 'status' => 'to_confirm']))->assertOk()
            ->assertSee('✓ Confirm')->assertSee('Deny')->assertDontSee('Spend by phase')->assertDontSee('>Overview</a>', false);
        $this->actingAs($accounts)->post(route('ledger.expenses.confirm', [$project, $line]), ['amount' => '920000', 'payment_date' => '2026-10-30', 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();
        $this->assertSame('paid', $line->fresh()->payment_status);

        $this->actingAs($pm)->get(route('ledger.overview', $project))->assertRedirect(route('ledger.expenses', $project));
        $this->actingAs($pm)->get(route('ledger.structure', $project))->assertOk()->assertDontSee('Confirmed spend');
        $this->actingAs($this->user('director'))->get(route('ledger.structure', $project))->assertOk()->assertSee('Confirmed spend');
    }

    public function test_director_corrects_or_deletes_an_auto_confirmed_line(): void
    {
        [$project, $phases] = $this->project();
        $director = $this->user('director');
        $this->actingAs($director)->post(route('ledger.expenses.store', $project), [
            'expense_date' => '2026-10-28', 'payment_method' => 'mobile_money',
            'rows' => [['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'Cement', 'quantity' => '10', 'unit' => 'bag', 'unit_cost' => '18000']],
        ])->assertSessionHasNoErrors();
        $line = ExpenseRequest::query()->sole();
        $payment = $line->payments()->sole();
        $this->assertSame(['180000.00', 'mobile_money', '2026-10-28'], [(string) $payment->amount, $payment->payment_method, $payment->payment_date->toDateString()]);

        $this->actingAs($director)->get(route('ledger.expenses.edit', [$project, $line]))->assertOk();
        $this->actingAs($director)->put(route('ledger.expenses.update', [$project, $line]), $this->line($phases[0], ['item_description' => 'Cement', 'quantity' => '12', 'unit' => 'bag', 'unit_cost' => '18000']))
            ->assertSessionHasNoErrors();
        $line->refresh();
        $this->assertSame(['216000.00', 'paid', '216000.00'], [(string) $line->total_amount, $line->payment_status, (string) $line->payments()->sole()->amount]);
        $this->assertDatabaseHas('project_edit_logs', ['document_id' => $line->id, 'action' => 'ledger_paid_line_corrected']);

        $this->actingAs($director)->delete(route('ledger.expenses.destroy', [$project, $line]))->assertRedirect();
        $this->assertSame([0, 0], [ExpenseRequest::count(), ExpensePayment::count()]);
    }

    public function test_project_kpis_shared_with_the_team_show_on_their_pages(): void
    {
        [$project] = $this->project();
        $pm = $this->user('project_manager', $project);
        $director = $this->user('director');
        foreach (['Keep spend low' => '1', 'Director only' => '0'] as $note => $visible) {
            $this->actingAs($director)->post(route('director.projects.kpis.store', $project), ['metric' => 'spend', 'period' => 'this_quarter', 'target_value' => '5000000', 'notes' => $note, 'visible_to_team' => $visible])
                ->assertSessionHasNoErrors();
        }
        $this->actingAs($pm)->get(route('ledger.structure', $project))->assertOk()->assertSee('Targets for this project')->assertSee('Keep spend low')->assertDontSee('Director only');
        $this->actingAs($this->user('accounts'))->get(route('ledger.expenses', $project))->assertOk()->assertSee('Keep spend low');

        $shared = DirectorKpiTarget::where('notes', 'Keep spend low')->sole();
        $this->actingAs($pm)->post(route('director.kpis.visibility', $shared))->assertForbidden();
        $this->actingAs($director)->post(route('director.kpis.visibility', $shared))->assertRedirect();
        $this->actingAs($pm)->get(route('ledger.structure', $project))->assertOk()->assertDontSee('Keep spend low');
    }

    public function test_excel_workbooks_and_project_dates(): void
    {
        [$project, $phases] = $this->project();
        $director = $this->user('director');
        $details = ['project_code' => $project->project_code, 'name' => $project->name, 'status' => 'active', 'location' => 'Dar es Salaam', 'planned_start_date' => '2026-03-01'];
        $this->actingAs($director)->put(route('admin.projects.update', $project), $details + ['expected_completion_date' => '2026-02-01'])->assertSessionHasErrors('expected_completion_date');
        $this->actingAs($director)->put(route('admin.projects.update', $project), $details + ['expected_completion_date' => '2026-12-20'])->assertSessionHasNoErrors();
        $this->assertSame(['2026-03-01', '2026-12-20'], [$project->fresh()->planned_start_date->toDateString(), $project->fresh()->expected_completion_date->toDateString()]);
        $this->actingAs($director)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));

        $file = $this->actingAs($director)->get(route('ledger.expenses.export', $project))->assertOk()->assertDownload()->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($file);
        $this->assertSame(['Summary', 'Expenditure'], $book->getSheetNames());
        $this->assertSame('01 Mar 2026 – 20 Dec 2026', $book->getSheet(0)->getCell('B7')->getValue());
        $all = $this->actingAs($director)->get(route('ledger.export', ['range' => 'this_year']))->assertOk()->baseResponse->getFile()->getPathname();
        $this->assertSame(['Projects', 'By Category', 'Monthly', 'All Expenditure'], IOFactory::load($all)->getSheetNames());
        $this->actingAs($this->user('project_manager', $project))->get(route('ledger.export'))->assertForbidden();
    }

    public function test_selected_lines_are_confirmed_denied_or_deleted_in_bulk(): void
    {
        [$project, $phases] = $this->project();
        [$other, $otherPhases] = $this->project('BWG-TST-B-2026-001');
        $pm = $this->user('project_manager', $project);
        $accounts = $this->user('accounts');
        foreach (['Cement', 'Sand', 'Nails', 'Timber'] as $item) {
            $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['item_description' => $item]));
        }
        $this->actingAs($this->user('director'))->post(route('ledger.expenses.store', $other), $this->line($otherPhases[0], ['item_description' => 'Elsewhere']));
        $ids = ExpenseRequest::query()->where('project_id', $project->id)->orderBy('id')->pluck('id');
        $foreign = ExpenseRequest::where('item_description', 'Elsewhere')->value('id');

        $this->actingAs($accounts)->get(route('ledger.expenses', [$project, 'status' => 'to_confirm']))->assertOk()->assertSee('id="bulkBar"', false);
        $this->actingAs($pm)->post(route('ledger.expenses.bulk', $project), ['action' => 'confirm', 'ids' => [$ids[0]], 'payment_date' => '2026-10-30', 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($accounts)->post(route('ledger.expenses.bulk', $project), ['action' => 'deny', 'ids' => [$ids[0]]])->assertSessionHasErrors('reason');

        $this->actingAs($accounts)->post(route('ledger.expenses.bulk', $project), ['action' => 'confirm', 'ids' => [$ids[0], $ids[1], $foreign], 'payment_date' => '2026-10-30', 'payment_method' => 'bank_transfer'])
            ->assertRedirect()->assertSessionHas('success', '2 line(s) confirmed · TSh 1,840,000.00 paid.');
        $this->assertSame(['paid', 'paid', 'unpaid'], ExpenseRequest::query()->whereIn('id', [$ids[0], $ids[1], $ids[2]])->orderBy('id')->get()->map->payment_status->all());
        $this->assertSame(1, ExpensePayment::where('expense_request_id', $foreign)->count(), 'Lines of another project are never touched.');

        $this->actingAs($accounts)->post(route('ledger.expenses.bulk', $project), ['action' => 'deny', 'ids' => [$ids[1], $ids[2]], 'reason' => 'Duplicates'])
            ->assertSessionHas('success', fn ($message) => str_starts_with($message, '1 line(s) denied.') && str_contains($message, 'TST-A-TXN-0002'));
        $this->assertSame('rejected', ExpenseRequest::find($ids[2])->status);

        $this->actingAs($pm)->post(route('ledger.expenses.bulk', $project), ['action' => 'delete', 'ids' => [$ids[0], $ids[3]]])
            ->assertSessionHas('success', fn ($message) => str_starts_with($message, '1 line(s) deleted.'));
        $this->assertNull(ExpenseRequest::find($ids[3]));
        $this->assertNotNull(ExpenseRequest::find($ids[0]), 'A paid line is skipped for the PM.');
    }

    public function test_company_overheads_are_director_only_and_outside_project_totals(): void
    {
        [$project, $phases] = $this->project();
        [$office, $officePhases] = $this->project('BWG-OFC-OVH-2026-001');
        $office->update(['status' => 'overheads', 'is_company_overheads' => true]);
        $director = $this->user('director');
        $pm = $this->user('project_manager', $project);
        $office->assignedUsers()->attach($pm->id);
        $accounts = $this->user('accounts');
        $this->actingAs($director)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));
        $this->actingAs($director)->post(route('ledger.expenses.store', $office), $this->line($officePhases[0], ['main_category' => 'preliminaries_overheads', 'item_description' => 'Staff salaries October', 'unit_cost' => '100000']));

        foreach ([$pm, $accounts] as $staff) {
            $this->actingAs($staff)->get(route('ledger.expenses', $office))->assertForbidden();
            $this->actingAs($staff)->get(route('ledger.overheads'))->assertForbidden();
            $this->actingAs($staff)->get(route('ledger.index'))->assertOk()->assertDontSee($office->name);
        }
        $this->actingAs($director)->get(route('ledger.overheads'))->assertRedirect(route('ledger.overview', $office));
        $this->actingAs($director)->get(route('ledger.overview', $office))->assertOk()->assertSee('Company overheads');
        $this->actingAs($director)->get(route('ledger.index'))->assertOk()->assertSee($project->name)->assertDontSee($office->name)->assertSee('920,000.00');
        $this->actingAs($director)->get(route('director.index', ['range' => 'this_year']))->assertOk()
            ->assertSee('Company overheads')->assertSee('4,000,000.00')->assertSee('920,000.00')->assertDontSee('4,920,000.00');
    }

    public function test_bulk_confirm_takes_amount_method_and_receipt_per_line(): void
    {
        [$project, $phases] = $this->project();
        $pm = $this->user('project_manager', $project);
        $accounts = $this->user('accounts');
        foreach (['Cement', 'Sand'] as $item) {
            $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['item_description' => $item]));
        }
        [$cement, $sand] = ExpenseRequest::query()->orderBy('id')->get()->all();

        $this->actingAs($accounts)->post(route('ledger.expenses.bulk', $project), ['action' => 'confirm', 'ids' => [$cement->id, $sand->id], 'payment_date' => '2026-10-30', 'rows' => [
            $cement->id => ['amount' => '500000', 'payment_method' => 'mobile_money', 'invoice_receipt_no' => 'R-77', 'transaction_reference' => 'MP123'],
            $sand->id => ['amount' => '920000'],
        ]])->assertSessionHas('success', '2 line(s) confirmed · TSh 1,420,000.00 paid.');
        $part = $cement->fresh()->payments()->sole();
        $this->assertSame(['500000.00', 'mobile_money', 'R-77', 'MP123'], [(string) $part->amount, $part->payment_method, $part->invoice_receipt_no, $part->transaction_reference]);
        $this->assertSame('part_paid', $cement->fresh()->payment_status);
        $this->assertSame(['paid', 'other'], [$sand->fresh()->payment_status, $sand->fresh()->payments()->sole()->payment_method]);

        $this->actingAs($accounts)->post(route('ledger.expenses.bulk', $project), ['action' => 'confirm', 'ids' => [$cement->id], 'payment_date' => '2026-10-30',
            'rows' => [$cement->id => ['amount' => '999999']]])->assertSessionHas('success', fn ($message) => str_starts_with($message, '0 line(s) confirmed.'));
        $this->assertSame(1, $cement->fresh()->payments()->count(), 'Paying more than what is open is refused.');
    }

    public function test_possible_duplicates_are_flagged_in_expenditure(): void
    {
        [$project, $phases] = $this->project();
        $director = $this->user('director');
        $pm = $this->user('project_manager', $project);
        $this->actingAs($director)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['item_description' => 'Cement paid', 'expense_date' => '2026-10-20']));
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['item_description' => 'Cement from log', 'expense_date' => '2026-10-25']));
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[1], ['item_description' => 'Unrelated', 'unit_cost' => '1000']));

        $page = $this->actingAs($this->user('accounts'))->get(route('ledger.expenses', [$project, 'check' => 'duplicates']))->assertOk();
        $page->assertSee('Cement from log')->assertSee('Possible duplicate: Same amount as paid line TST-A-TXN-0001')->assertDontSee('Unrelated</b>', false)->assertDontSee('Cement paid</b>', false);
        $this->actingAs($pm)->get(route('ledger.expenses', $project))->assertOk()->assertDontSee('Possible duplicate');
    }

    public function test_needs_attention_links_lines_to_confirm_and_lists_every_project(): void
    {
        [$project, $phases] = $this->project();
        $pm = $this->user('project_manager', $project);
        $director = $this->user('director');
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['expense_date' => '2026-09-01']));
        $link = route('ledger.expenses', [$project, 'status' => 'to_confirm', 'sort' => 'oldest']);

        $this->actingAs($director)->get(route('director.index'))->assertOk()->assertSee('View all 1')->assertSee($link);
        $this->actingAs($director)->get(route('director.attention'))->assertOk()->assertSee($project->name)->assertSee($link)->assertSee('Awaiting confirmation (1)');
        $this->actingAs($pm)->get(route('director.attention'))->assertForbidden();
    }

    public function test_cached_dashboard_figures_follow_every_change(): void
    {
        config(['cache.default' => 'array']);
        [$project, $phases] = $this->project();
        $director = $this->user('director');
        $this->actingAs($director)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));
        $this->actingAs($director)->get(route('director.index', ['range' => 'this_year']))->assertOk()->assertSee('920,000.00')
            ->assertSee('Sign out')->assertSee('id="bwLoader"', false)->assertSee('data-theme="dark"', false);

        $this->actingAs($director)->post(route('ledger.expenses.store', $project), $this->line($phases[1], ['item_description' => 'Sand', 'unit_cost' => '1000']));
        $this->actingAs($director)->get(route('director.index', ['range' => 'this_year']))->assertOk()->assertSee('960,000.00');
        $line = ExpenseRequest::where('item_description', 'Sand')->sole();
        $this->actingAs($director)->delete(route('ledger.expenses.destroy', [$project, $line]))->assertRedirect();
        $this->actingAs($director)->get(route('director.index', ['range' => 'this_year']))->assertOk()->assertSee('920,000.00')->assertDontSee('960,000.00');
    }

    public function test_data_entry_only_picks_a_project_and_enters_expenses(): void
    {
        [$project, $phases] = $this->project();
        [$other] = $this->project('BWG-TST-B-2026-001');
        $clerk = $this->user('data_entry', $project);

        $this->actingAs($clerk)->get('/')->assertRedirect(route('entry.index'));
        $this->actingAs($clerk)->get(route('entry.index'))->assertRedirect(route('entry.form', $project));
        $this->actingAs($clerk)->get(route('entry.form', $project))->assertOk()->assertSee('Entered today')->assertDontSee('Change project')
            ->assertDontSee($other->name)->assertDontSee('>Expenditure</a>', false)->assertDontSee('Confirmed spend');
        [$third] = $this->project('BWG-TST-C-2026-001');
        $third->assignedUsers()->attach($clerk->id);
        $this->actingAs($clerk)->get(route('entry.index'))->assertOk()->assertSee($project->name)->assertSee($third->name)->assertDontSee($other->name);
        $this->actingAs($clerk)->get(route('entry.form', $project))->assertOk()->assertSee('Change project');
        $this->actingAs($clerk)->get(route('entry.form', $other))->assertForbidden();
        foreach (['ledger.expenses', 'ledger.overview', 'ledger.structure', 'ledger.workers'] as $page) {
            $this->actingAs($clerk)->get(route($page, $project))->assertForbidden();
        }
        $this->actingAs($clerk)->get(route('ledger.index'))->assertForbidden();

        $this->actingAs($clerk)->post(route('ledger.expenses.store', $project), ['expense_date' => '2026-10-29', 'status' => 'to_confirm',
            'rows' => [['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'Cement', 'quantity' => '10', 'unit_cost' => '18000']]])
            ->assertRedirect(route('entry.form', $project));
        $line = ExpenseRequest::query()->sole();
        $this->assertSame(['unpaid', (int) $clerk->id], [$line->payment_status, (int) $line->requested_by]);
        $this->actingAs($clerk)->get(route('entry.index'))->assertOk()->assertSee($line->request_code)->assertSee('To confirm');
        $this->actingAs($this->user('accounts'))->get(route('ledger.expenses', [$project, 'status' => 'to_confirm']))->assertOk()->assertSee('Cement');
    }

    public function test_an_unsubmitted_entry_is_kept_as_a_draft_and_continued_later(): void
    {
        [$project, $phases] = $this->project();
        [$other] = $this->project('BWG-TST-B-2026-001');
        $clerk = $this->user('data_entry', $project);
        $clerk->assignedProjects()->attach($other->id);
        $draft = ['header' => ['expense_date' => '2026-11-02', 'default_category' => 'materials', 'default_phase' => (string) $phases[0]->id, 'default_supplier' => 'ABC Hardware'],
            'rows' => [
                ['main_category' => 'materials', 'project_phase_id' => (string) $phases[0]->id, 'item_description' => 'Cement 42.5', 'supplier' => 'ABC Hardware', 'quantity' => '50', 'unit' => 'bag', 'unit_cost' => '18500'],
                ['main_category' => 'materials', 'project_phase_id' => (string) $phases[0]->id, 'item_description' => '', 'quantity' => '1', 'unit' => 'pc'],
            ]];

        $this->actingAs($clerk)->postJson(route('ledger.draft.save', $project), $draft)->assertOk()->assertJson(['saved' => true]);
        $saved = ExpenseDraft::query()->sole();
        $this->assertSame([1, 'Cement 42.5', 'ABC Hardware'], [$saved->item_count, $saved->data['rows'][0]['item_description'], $saved->data['header']['default_supplier']]);

        // Later (any device): the form opens with the draft, and the project list marks it.
        $this->actingAs($clerk)->get(route('entry.index'))->assertOk()->assertSee('Unfinished entry · 1 item');
        $this->actingAs($clerk)->get(route('entry.form', $project))->assertOk()->assertSee('Unfinished entry restored')->assertSee('Cement 42.5');
        $this->actingAs($clerk)->get(route('entry.form', $other))->assertOk()->assertDontSee('Unfinished entry restored');
        $this->actingAs($this->user('project_manager', $project))->get(route('ledger.expenses.create', $project))->assertOk()->assertDontSee('Cement 42.5');
        $this->actingAs($this->user('accounts', $project))->postJson(route('ledger.draft.save', $project), $draft)->assertForbidden();
        [$foreign] = $this->project('BWG-TST-C-2026-001');
        $this->actingAs($clerk)->postJson(route('ledger.draft.save', $foreign), $draft)->assertForbidden();

        // Saving the expenses removes the draft.
        $this->actingAs($clerk)->post(route('ledger.expenses.store', $project), ['expense_date' => now()->toDateString(), 'status' => 'to_confirm',
            'rows' => [['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'Cement 42.5', 'quantity' => '50', 'unit_cost' => '18500']]])->assertSessionHasNoErrors();
        $this->assertSame(0, ExpenseDraft::count());

        // A form emptied again removes its draft; Discard removes it too.
        $this->actingAs($clerk)->postJson(route('ledger.draft.save', $project), $draft)->assertJson(['saved' => true]);
        $this->actingAs($clerk)->postJson(route('ledger.draft.save', $project), ['header' => $draft['header'], 'rows' => [$draft['rows'][1]]])->assertJson(['saved' => false]);
        $this->assertSame(0, ExpenseDraft::count());
        $this->actingAs($clerk)->postJson(route('ledger.draft.save', $project), $draft);
        $this->actingAs($clerk)->delete(route('ledger.draft.discard', $project))->assertRedirect(route('entry.form', $project));
        $this->assertSame(0, ExpenseDraft::count());
    }

    public function test_suppliers_are_recorded_per_item_reused_and_shown_on_entered_today(): void
    {
        [$project, $phases] = $this->project();
        [$other] = $this->project('BWG-TST-B-2026-001');
        $clerk = $this->user('data_entry', $project);
        $clerk->assignedProjects()->attach($other->id);

        $this->actingAs($clerk)->post(route('ledger.expenses.store', $project), ['expense_date' => now()->toDateString(), 'status' => 'to_confirm', 'default_supplier' => 'ABC Hardware',
            'rows' => [
                ['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'Cement', 'supplier' => '  ABC   Hardware ', 'total_amount' => '100000'],
                ['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'Sand', 'supplier' => 'Mama Sand', 'total_amount' => '50000'],
                ['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'Nails', 'total_amount' => '5000'],
                ['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'supplier' => 'ABC Hardware', 'quantity' => '1', 'unit' => 'pc'],
            ]])->assertSessionHasNoErrors();
        $this->assertSame(['ABC Hardware', 'Mama Sand', null], ExpenseRequest::query()->orderBy('id')->pluck('supplier_fundi_client')->all());

        // A differently typed name reuses the spelling saved on the project; the picker lists the project's suppliers.
        $this->actingAs($clerk)->post(route('ledger.expenses.store', $project), ['expense_date' => now()->toDateString(), 'status' => 'to_confirm',
            'rows' => [['main_category' => 'materials', 'project_phase_id' => $phases[0]->id, 'item_description' => 'Lime', 'supplier' => 'abc hardware', 'total_amount' => '7000']]]);
        $this->assertSame('ABC Hardware', ExpenseRequest::query()->latest('id')->value('supplier_fundi_client'));
        $this->assertSame(['ABC Hardware', 'Mama Sand'], ExpenseLedgerService::suppliers($project));
        $this->actingAs($clerk)->get(route('entry.form', $project))->assertOk()->assertSee('<option value="Mama Sand">', false)->assertSee('Preview')->assertSee('Entered Today');
        $this->actingAs($clerk)->get(route('entry.form', $other))->assertOk()->assertDontSee('<option value="Mama Sand">', false);

        // Editing keeps or changes the supplier.
        $sand = ExpenseRequest::query()->where('item_description', 'Sand')->sole();
        $this->actingAs($clerk)->get(route('ledger.expenses.edit', [$project, $sand]))->assertOk()->assertSee('value="Mama Sand"', false);
        $this->actingAs($clerk)->put(route('ledger.expenses.update', [$project, $sand]), ['expense_date' => now()->toDateString(), 'main_category' => 'materials',
            'project_phase_id' => $phases[0]->id, 'item_description' => 'Sand', 'supplier' => 'Kariakoo Sand', 'total_amount' => '50000'])->assertSessionHasNoErrors();
        $this->assertSame('Kariakoo Sand', $sand->fresh()->supplier_fundi_client);

        // Entered Today lists the person's own lines with the supplier; the register can be searched by supplier.
        // Outside a project: choose the project first (with today's count and total per project).
        $this->actingAs($clerk)->get(route('entry.today'))->assertOk()->assertSee($project->name)->assertDontSee($other->name)
            ->assertSee('4 lines · TSh 162,000.00')->assertDontSee('Kariakoo Sand');
        $this->actingAs($this->user('data_entry', $other))->get(route('entry.today'))->assertRedirect(route('entry.today.project', $other));
        $second = $this->user('project_manager', $other);
        $second->assignedProjects()->attach($project->id);
        $this->actingAs($second)->get(route('entry.today'))->assertOk()->assertSee('Nothing entered yet today.')->assertDontSee($project->name);
        // Inside a project: only that project's lines, and the sidebar stays in the project.
        $this->actingAs($clerk)->get(route('entry.today.project', $project))->assertOk()->assertSee('Kariakoo Sand')->assertSee('Cement')
            ->assertSee('Entering for')->assertSee('Enter expenses');
        $this->actingAs($clerk)->get(route('entry.today.project', $other))->assertOk()->assertDontSee('Cement')->assertSee('Nothing entered yet today.');
        $this->actingAs($clerk)->get(route('entry.form', $project))->assertOk()->assertSee(route('entry.today.project', $project), false);
        [$foreign] = $this->project('BWG-TST-C-2026-001');
        $this->actingAs($clerk)->get(route('entry.today.project', $foreign))->assertForbidden();
        $pm = $this->user('project_manager', $project);
        $this->actingAs($pm)->get(route('entry.today'))->assertRedirect(route('entry.today.project', $project));
        $this->actingAs($pm)->get(route('entry.today.project', $project))->assertOk()->assertDontSee('Cement');
        $this->actingAs($pm)->get(route('ledger.expenses', [$project, 'q' => 'Kariakoo']))->assertOk()->assertSee('Supplier: Kariakoo Sand')->assertDontSee('>Cement<', false);
    }

    public function test_data_entry_fixes_own_lines_only_the_same_day_and_adds_workers_by_name(): void
    {
        [$project, $phases] = $this->project();
        $clerk = $this->user('data_entry', $project);
        $pm = $this->user('project_manager', $project);
        $this->actingAs($clerk)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0], ['item_description' => 'PM line']));
        [$own, $pmLine] = ExpenseRequest::query()->orderBy('id')->get()->all();

        $this->actingAs($clerk)->get(route('ledger.expenses.edit', [$project, $own]))->assertOk()->assertSee('Back to entry');
        $this->actingAs($clerk)->put(route('ledger.expenses.update', [$project, $own]), $this->line($phases[0], ['unit_cost' => '24000']))->assertRedirect(route('entry.form', $project));
        $this->assertSame('960000.00', (string) $own->fresh()->total_amount);
        $this->actingAs($clerk)->get(route('ledger.expenses.edit', [$project, $pmLine]))->assertForbidden();

        Carbon::setTestNow('2026-10-31 09:00:00');
        $this->actingAs($clerk)->get(route('ledger.expenses.edit', [$project, $own]))->assertForbidden();
        $this->actingAs($clerk)->delete(route('ledger.expenses.destroy', [$project, $own]))->assertForbidden();
        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->actingAs($clerk)->delete(route('ledger.expenses.destroy', [$project, $own]))->assertRedirect();
        $this->assertNull(ExpenseRequest::find($own->id));

        $first = $this->actingAs($clerk)->postJson(route('ledger.workers.quick', $project), ['fundi_subcontractor' => 'Juma  Mason', 'scope_of_work' => 'Mason'])->assertOk()->json();
        $again = $this->actingAs($clerk)->postJson(route('ledger.workers.quick', $project), ['fundi_subcontractor' => 'juma mason'])->assertOk()->json();
        $this->assertSame([$first['id'], 'Juma Mason'], [$again['id'], $first['name']]);
        $this->assertSame(1, LabourAgreement::count());
        [$other] = $this->project('BWG-TST-B-2026-001');
        $this->actingAs($clerk)->postJson(route('ledger.workers.quick', $other), ['fundi_subcontractor' => 'X'])->assertForbidden();
        $this->actingAs($this->user('director'))->get(route('admin.users.create'))->assertOk()->assertSee('Data Entry');
    }

    public function test_people_sign_in_with_a_username_and_stay_signed_in(): void
    {
        [$project] = $this->project();
        $director = $this->user('director');
        $role = Role::where('name', 'data_entry')->value('id');
        $base = ['name' => 'Rehema', 'role_id' => $role, 'is_active' => '1', 'password' => 'secret-123', 'password_confirmation' => 'secret-123', 'project_ids' => [$project->id], 'permissions' => ['record_expenses']];

        $this->actingAs($director)->post(route('admin.users.store'), $base)->assertSessionHasErrors('username')->assertSessionDoesntHaveErrors('email');
        $pmRole = Role::where('name', 'project_manager')->value('id');
        $this->actingAs($director)->post(route('admin.users.store'), ['role_id' => $pmRole, 'username' => 'pm.user'] + $base)
            ->assertSessionHasErrors('email')->assertSessionDoesntHaveErrors('username');
        $this->actingAs($director)->post(route('admin.users.store'), $base + ['username' => 'Has Space'])->assertSessionHasErrors('username');
        $this->actingAs($director)->post(route('admin.users.store'), $base + ['username' => '  Rehema.M '])->assertRedirect()->assertSessionHasNoErrors();
        $clerk = User::where('username', 'rehema.m')->sole();
        $this->assertNull($clerk->email);
        $this->actingAs($director)->get(route('admin.users.index'))->assertOk()->assertSee('rehema.m');
        $this->post('/logout');

        $this->post('/login', ['login' => 'rehema.m', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $response = $this->post('/login', ['login' => 'REHEMA.M', 'password' => 'secret-123', 'remember' => '1']);
        $response->assertRedirect(route('entry.index'));
        $this->assertAuthenticatedAs($clerk);
        $this->assertNotNull($clerk->fresh()->remember_token, 'Keep me signed in sets a remember token.');
        $this->get(route('entry.index'))->assertRedirect(route('entry.form', $project)); // one project: the form opens directly
        $this->get(route('entry.form', $project))->assertOk()->assertDontSee('Change project');
        $this->post('/logout');

        $this->post('/login', ['login' => $director->email, 'password' => 'password'])->assertRedirect(route('director.index'));
    }

    public function test_completed_projects_are_seen_by_the_director_only(): void
    {
        [$project, $phases] = $this->project();
        $pm = $this->user('project_manager', $project);
        $clerk = $this->user('data_entry', $project);
        $accounts = $this->user('accounts');
        $director = $this->user('director');
        $this->actingAs($pm)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));
        $project->update(['status' => 'completed']);

        $this->actingAs($pm)->get(route('ledger.index'))->assertOk()->assertDontSee($project->name);
        $this->actingAs($pm)->get(route('ledger.expenses', $project))->assertForbidden();
        $this->actingAs($accounts)->get(route('ledger.expenses', $project))->assertForbidden();
        $this->actingAs($accounts)->get(route('ledger.to-confirm'))->assertOk()->assertDontSee($project->name);
        $this->actingAs($clerk)->get(route('entry.form', $project))->assertForbidden();
        $this->actingAs($director)->get(route('ledger.index'))->assertOk()->assertSee($project->name);
        $this->actingAs($director)->get(route('ledger.expenses', $project))->assertOk()->assertSee('Floor tiles');
    }

    public function test_pilot_projects_replace_everything_with_a_clean_start(): void
    {
        [$old] = $this->project();
        $this->user('director');
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['BUILDIWICK - ACTIVE PROJECTS'], null, 'A1');
        $sheet->fromArray(['#', 'Project Code', 'Project Name', 'Type of Work', 'Location', 'Start Date', 'Status', 'Project Manager', 'Notes'], null, 'A4');
        $sheet->fromArray([1, 'MSK-FIN', 'Masaki residence, finishing works', 'Finishing works', 'Kinondoni', 46176, 'Active', null, null], null, 'A5');
        $sheet->fromArray([2, 'MBZ-RES', 'Mbezi residence', 'Construction', null, null, 'Active', null, null], null, 'A6');
        $sheet->fromArray([18, 'OFC-OVH', 'Office & overheads', 'Overheads', 'Head office', null, 'Active', null, 'Not a project - office running costs'], null, 'A7');
        $sheet->fromArray(['Start Date: contract start, DD/MM/YYYY.'], null, 'A9');
        $file = tempnam(sys_get_temp_dir(), 'pilot').'.xlsx';
        (new Xlsx($book))->save($file);

        $this->artisan('app:pilot-projects', ['file' => $file, '--fresh' => true])->assertSuccessful();
        $this->assertNull(Project::find($old->id));
        $this->assertSame(['BWG-MBZ-RES-2026-001', 'BWG-MSK-FIN-2026-001', 'BWG-OFC-OVH-2026-001'], Project::orderBy('project_code')->pluck('project_code')->all());
        $masaki = Project::where('project_code', 'BWG-MSK-FIN-2026-001')->sole();
        $this->assertSame(['Finishing works', 'Kinondoni', '2026-06-03', 8], [$masaki->work_type, $masaki->location, $masaki->planned_start_date->toDateString(), $masaki->phases()->count()]);
        $this->assertNull(Project::where('project_code', 'BWG-MBZ-RES-2026-001')->value('location'), 'A blank district is left blank, not guessed.');
        $this->assertTrue((bool) Project::where('project_code', 'BWG-OFC-OVH-2026-001')->value('is_company_overheads'));
        $this->assertSame(0, ExpenseRequest::count());
        @unlink($file);
    }

    public function test_office_cost_groups_are_managed_by_the_director_and_keep_their_records(): void
    {
        $director = $this->user('director');
        $this->actingAs($director)->post(route('admin.projects.store'), ['project_code' => 'BWG-OFC-OVH-2026-001', 'name' => 'Office & overheads', 'status' => 'active',
            'location' => 'Head office', 'is_company_overheads' => '1', 'standard_stages' => '1'])->assertSessionHasNoErrors();
        $office = Project::where('project_code', 'BWG-OFC-OVH-2026-001')->sole();
        $groups = $office->phases()->orderBy('phase_order')->get()->keyBy('phase_name');
        $this->assertSame(ProjectSetupController::OFFICE_COST_GROUPS, $groups->keys()->all());

        $this->actingAs($director)->get(route('ledger.structure', $office))->assertOk()->assertSee('Cost Groups &amp; Budget', false)->assertSee('Note (optional)');
        $rent = $groups['Office Rent & Utilities'];
        $this->actingAs($director)->put(route('ledger.phases.rename', [$office, $rent]), ['phase_name' => 'Office Rent', 'notes' => 'Kinondoni office, paid quarterly'])->assertSessionHasNoErrors();
        $this->assertSame(['Office Rent', 'Kinondoni office, paid quarterly'], [$rent->fresh()->phase_name, $rent->fresh()->notes]);

        $this->actingAs($director)->post(route('ledger.expenses.store', $office), $this->line($rent, ['main_category' => 'preliminaries_overheads', 'item_description' => 'Rent October', 'quantity' => '1', 'unit_cost' => '1500000']));
        $line = ExpenseRequest::query()->sole();
        $this->actingAs($director)->delete(route('ledger.phases.destroy', [$office, $rent]))->assertSessionHasErrors('move_to');
        $other = $groups['Other Office Costs'];
        $this->actingAs($director)->delete(route('ledger.phases.destroy', [$office, $rent]), ['move_to' => $other->id])->assertSessionHasNoErrors();
        $this->assertNull(ProjectPhase::find($rent->id));
        $this->assertSame([$other->id, $other->id], [(int) $line->fresh()->project_phase_id, (int) $line->payments()->sole()->project_phase_id]);
        $this->assertDatabaseHas('project_edit_logs', ['project_id' => $office->id, 'action' => 'cost_group_deleted']);
        $page = $this->actingAs($director)->get(route('ledger.expenses', $office))->assertOk()->assertDontSee('>To Confirm', false)
            ->assertDontSee('>Workers</a>', false)->assertDontSee('✓ Confirm')->assertDontSee('value="to_confirm"', false)
            ->assertDontSee('<label for="status">', false)->assertSee('Paid via')->assertSee('Total spend')->assertDontSee('data-deny-url', false);
        $this->actingAs($director)->get(route('ledger.overview', $office))->assertOk()->assertDontSee('Awaiting confirmation</th>', false);

        // Office costs have no categories: whatever is posted, a line is filed under one category with no subcategory.
        $this->actingAs($director)->get(route('ledger.expenses.create', $office))->assertOk()->assertSee('ovh-form');
        $this->actingAs($director)->post(route('ledger.expenses.store', $office), $this->line($other, ['main_category' => 'materials', 'subcategory' => 'hardware', 'item_description' => 'Printer paper']));
        $paper = ExpenseRequest::where('item_description', 'Printer paper')->sole();
        $this->assertSame(['preliminaries_overheads', null], [$paper->main_category, $paper->subcategory]);
        $this->actingAs($director)->get(route('ledger.expenses', $office))->assertOk()->assertDontSee('<label for="subcategory">', false)->assertDontSee('<th>Category</th>', false);
        $this->actingAs($director)->get(route('ledger.structure', $office))->assertOk()->assertSee('Budget (TSh)')->assertDontSee('>Labour</span>', false);
        $warnings = $this->actingAs($director)->get(route('director.projects.warnings', $office))->assertOk()->assertSee('All office costs')->assertDontSee('kpi_category');
        $warnings->assertDontSee('<option value="to_confirm"', false);
        $this->actingAs($director)->get(route('admin.projects.show', $office))->assertOk()->assertSee('Company Overheads Settings');
        $this->actingAs($director)->put(route('admin.projects.update', $office), ['project_code' => $office->project_code, 'name' => 'Office costs', 'status' => 'active', 'location' => 'Head office', 'is_company_overheads' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Office costs', true], [$office->fresh()->name, (bool) $office->fresh()->is_company_overheads]);
        $this->actingAs($director)->get(route('ledger.to-confirm'))->assertOk()->assertDontSee($office->name);

        [$project, $phases] = $this->project();
        $this->actingAs($director)->post(route('ledger.expenses.store', $project), $this->line($phases[0]));
        $this->actingAs($director)->delete(route('ledger.phases.destroy', [$project, $phases[0]]), ['move_to' => $phases[1]->id])->assertSessionHasErrors('phase');
        $this->actingAs($this->user('project_manager', $project))->get(route('ledger.structure', $office))->assertForbidden();
    }

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
