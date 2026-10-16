<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DirectorAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoProjectSeeder;
use Database\Seeders\DemoWorkspaceSeeder;
use Database\Seeders\OysterbayBoundaryWallDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OysterbayBoundaryWallDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_workspace_keeps_only_two_complete_test_projects(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoWorkspaceSeeder::class);

        $this->assertSame([
            DemoProjectSeeder::PROJECT_CODE,
            OysterbayBoundaryWallDemoSeeder::PROJECT_CODE,
        ], Project::orderBy('project_code')->pluck('project_code')->all());

        $project = Project::where('project_code', OysterbayBoundaryWallDemoSeeder::PROJECT_CODE)->firstOrFail();
        $this->assertSame(7, $project->phases()->count());
        $this->assertSame('active', $project->phases()->where('stage_code', 'plastering')->value('status'));
        $this->assertSame(4, $project->phases()->where('status', 'completed')->count());

        $estimate = $project->estimates()->with('entries')->firstOrFail();
        $this->assertSame('BWK/OYS-BW/2026', $estimate->estimate_code);
        $this->assertCount(34, $estimate->entries);
        $this->assertSame('78546509.00', $estimate->total_amount);
        $this->assertTrue($estimate->entries->every(fn ($entry) => $entry->markup_percent === '15.0000'));
        $this->assertStringContainsString('published amount TSh 6,905,400.00', $estimate->entries->firstWhere('item_description', 'Concrete C25 strip footing, 600 x 200 mm')->notes);

        $budgets = $project->internalBudgets()->with('entries')->get();
        $entries = $budgets->flatMap->entries;
        $this->assertTrue($budgets->every(fn ($budget) => $budget->status === 'approved'));
        $this->assertTrue($budgets->every(fn ($budget) => filled($budget->project_phase_id) && filled($budget->main_category)));
        $this->assertSame(35, $entries->count());
        $this->assertSame(5, $entries->pluck('project_phase_id')->unique()->count());
        $this->assertTrue($entries->every(fn ($entry) => filled($entry->work_area)
            && filled($entry->construction_category) && filled($entry->work_package_name)));

        $this->assertSame(11, $project->expenseRequests()->count());
        $this->assertSame(7, $project->expenseRequests()->pluck('supplier_request_group_id')->unique()->count());
        $this->assertSame(9, $project->expensePayments()->count());
        $this->assertTrue($project->expenseRequests()->get()->every(fn ($request) => $request->internal_budget_entry_id !== null));
        $invoice = $project->invoices()->with(['items', 'payments'])->firstOrFail();
        $this->assertSame('48794525.00', $invoice->total_amount);
        $this->assertSame('20000000.00', $invoice->paid_to_date);
        $this->assertSame('6720000.00', app(DirectorAnalyticsService::class)
            ->project($project, now()->startOfYear()->toImmutable(), now()->endOfYear()->toImmutable())['cash_balance']);

        $substructure = $project->phases()->where('stage_code', 'substructure')->firstOrFail();
        $estimator = User::where('email', 'twalib@buildiwick.test')->firstOrFail();
        $estimation = $this->actingAs($estimator)->get(route('estimator.projects.show', [$project, 'phase' => $substructure->id]));
        $estimation->assertOk()->assertSee('3.1 Excavation and Earthwork Subtotal')->assertSee('Estimation Total');
        $this->assertSame(2, substr_count($estimation->getContent(), '3.1 Excavation and Earthwork'));

        $plastering = $project->phases()->where('stage_code', 'plastering')->firstOrFail();
        $materialBudget = $project->internalBudgets()->where('project_phase_id', $plastering->id)
            ->where('main_category', 'materials')->firstOrFail();
        $this->assertCount(6, $materialBudget->entries);
        $this->assertSame('8043500.00', $materialBudget->total_amount);
        $pm = User::where('email', 'neema@buildiwick.test')->firstOrFail();
        $this->actingAs($pm)->get(route('pm.projects.show', [$project, 'budget' => $materialBudget->id]))
            ->assertOk()->assertSee('Internal Budget Summary by Phase')->assertSee('Ground Floor')
            ->assertSee('A Subtotal')->assertSee('B Subtotal')->assertSee('C Subtotal')->assertSee('8,043,500.00');

        $director = User::where('email', 'director@buildiwick.test')->firstOrFail();
        $projectPage = $this->actingAs($director)->get(route('director.projects.show', $project).'#phases');
        $projectPage
            ->assertOk()
            ->assertSee('data-project-view="estimate"', false)
            ->assertSee('data-project-view="budget"', false)
            ->assertDontSee('Internal Budget Category and Subcategory Usage')
            ->assertSee('Expense Trace')
            ->assertSee('Budget Origin to Supplier Payment')
            ->assertSee('Jengo Building Materials')
            ->assertSee('Cement for plastering both wall faces');
        $this->actingAs($director)->get(route('director.budgets.show', [$project, $materialBudget]))
            ->assertOk()->assertSee('Plastering · Materials Budget')->assertSee('Ground Floor')
            ->assertSee('A Subtotal')->assertSee('8,043,500.00');
        $this->actingAs($director)->get(route('director.payables.index', ['project_id' => $project->id]))
            ->assertOk()->assertSee('MR-OYS-SUB-001')->assertSee('Materials')
            ->assertSee('Cement for foundation works')->assertSee('Y12 reinforcement steel')
            ->assertSee('Jengo Building Materials')->assertSee('Imara Ready Mix &amp; Steel', false);
    }
}
