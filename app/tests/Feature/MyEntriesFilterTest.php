<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\ExpenseLedgerService;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MyEntriesFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_my_entries_filter_by_expense_date_and_search_together(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        config(['buildiwick.mode' => 'recording', 'buildiwick.features' => ['client_billing' => false, 'estimates' => false, 'site_operator' => false, 'approvals' => false]]);
        $this->seed(LookupSeeder::class);
        $project = Project::create(['project_code' => 'BWG-KND-RES-2026-001', 'name' => 'Kinondoni residence', 'status' => 'active', 'location' => 'Dar es Salaam']);
        $phase = $project->phases()->create(['stage_code' => 'pre', 'phase_name' => 'Preliminaries', 'phase_order' => 1, 'status' => 'active']);
        $clerk = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'data_entry'], ['description' => 'data entry'])->id, 'username' => 'clerk', 'is_active' => true]);
        $project->assignedUsers()->attach($clerk->id);
        $record = fn (string $date, string $item) => app(ExpenseLedgerService::class)->record($project, ['expense_date' => $date, 'main_category' => 'materials',
            'project_phase_id' => $phase->id, 'item_description' => $item, 'unit_cost' => '1000'], $clerk);
        // All entered today; the expense dates are older.
        $record('2026-11-03', 'Cement 42.5');
        $record('2026-10-20', 'Cement bags');
        $record('2026-11-04', 'Sand');

        $page = fn (array $query) => $this->actingAs($clerk)->get(route('entry.today.project', [$project] + $query))->assertOk();

        // By date entered (default), today shows all three.
        $page([])->assertSee('Cement 42.5')->assertSee('Sand')->assertSee('Cement bags');
        // By expense date, today shows none; this month shows the two November lines.
        $page(['by' => 'expense'])->assertDontSee('Cement 42.5')->assertSee('Nothing entered in this period.');
        $page(['by' => 'expense', 'period' => 'month'])->assertSee('Cement 42.5')->assertSee('Sand')->assertDontSee('Cement bags')->assertSee('Expense 03 Nov 2026');
        // Search, with the filter applied to it.
        $page(['by' => 'expense', 'period' => 'month', 'q' => 'cement'])->assertSee('Cement 42.5')->assertDontSee('Sand')->assertDontSee('Cement bags');
        $page(['by' => 'expense', 'period' => 'year', 'q' => 'cement'])->assertSee('Cement 42.5')->assertSee('Cement bags')->assertDontSee('>Sand<', false);
        // Picking another period keeps the date basis and the search.
        $page(['by' => 'expense', 'q' => 'cement'])->assertSee(route('entry.today.project', [$project, 'period' => 'week', 'by' => 'expense', 'q' => 'cement']));
    }
}
