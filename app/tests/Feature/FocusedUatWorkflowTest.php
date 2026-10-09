<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FocusedUatWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, LookupSeeder::class]);
    }

    public function test_pm_can_filter_assigned_requests_by_project_phase_and_category(): void
    {
        $pm = $this->user('project_manager');
        [$project, $phase] = $this->project('BWG-UAT-PM-001');
        [$otherProject, $otherPhase] = $this->project('BWG-UAT-PM-002');
        $pm->assignedProjects()->attach([$project->id, $otherProject->id]);
        $matching = $this->expenseRequest($project, $phase->id, 'materials', 'Cement blocks');
        $this->expenseRequest($project, $phase->id, 'labour_subcontracts', 'Mason team');
        $this->expenseRequest($otherProject, $otherPhase->id, 'materials', 'Roof sheets');

        $this->actingAs($pm)->get(route('pm.requests.index', [
            'project_id' => $project->id,
            'phase_id' => $phase->id,
            'category' => 'materials',
        ]))->assertOk()
            ->assertSee($matching->request_code)
            ->assertDontSee('Mason team')
            ->assertDontSee('Roof sheets')
            ->assertSee('Awaiting Pricing');
    }

    public function test_director_lands_on_searchable_projects_before_detailed_analytics(): void
    {
        [$project] = $this->project('BWG-UAT-DIR-001');
        [$other] = $this->project('BWG-UAT-DIR-002');
        $director = $this->user('director');

        $this->actingAs($director)->get(route('director.index', ['search' => $project->project_code]))
            ->assertOk()
            ->assertSee('Start with a project')
            ->assertSee($project->project_code)
            ->assertDontSee($other->project_code)
            ->assertDontSee('Budget vs Approved vs Actual by Project');
    }

    public function test_legacy_new_entry_drawer_is_removed_from_director_dashboards(): void
    {
        [$project] = $this->project('BWG-UAT-ADMIN-001');
        $admin = $this->user('director');

        $this->actingAs($admin)->get(route('dashboard.index'))->assertOk()->assertDontSee('New Entry');
        $this->actingAs($admin)->get(route('dashboard.show', $project))->assertOk()->assertDontSee('New Entry');
    }

    public function test_accounts_dashboard_shows_payment_and_overdue_invoice_action_counts(): void
    {
        [$project, $phase] = $this->project('BWG-UAT-ACC-001');
        ExpenseRequest::create([
            'request_code' => 'REQ-UAT-ACC-001', 'project_id' => $project->id,
            'project_phase_id' => $phase->id, 'main_category' => 'materials',
            'item_description' => 'Approved material', 'quantity' => '1',
            'approved_amount' => '100.00', 'status' => 'approved',
            'requested_at' => today(), 'submitted_at' => now(),
        ]);
        $invoice = ClientInvoice::create([
            'invoice_code' => 'INV-UAT-ACC-001', 'project_id' => $project->id,
            'client_id' => $project->client_id, 'status' => 'issued',
            'issued_at' => today()->subDays(20), 'due_at' => today()->subDay(),
        ]);
        $invoice->items()->create([
            'project_phase_id' => $phase->id, 'main_category' => 'materials',
            'item_description' => 'Client billing', 'quantity' => '1', 'unit_price' => '250.00',
        ]);

        $this->actingAs($this->user('accounts'))->get(route('accountant.index'))
            ->assertOk()->assertSee('Approved Awaiting Payment')->assertSee('Overdue Invoices');
    }

    private function project(string $code): array
    {
        $project = Project::factory()->create([
            'project_code' => $code,
            'client_id' => Client::factory()->create()->id,
        ]);
        $phase = $project->phases()->create([
            'stage_code' => 'preliminaries', 'phase_order' => 1, 'status' => 'active',
        ]);

        return [$project, $phase];
    }

    private function expenseRequest(Project $project, int $phaseId, string $category, string $description): ExpenseRequest
    {
        return ExpenseRequest::create([
            'request_code' => 'REQ-'.fake()->unique()->numerify('######'),
            'project_id' => $project->id, 'project_phase_id' => $phaseId,
            'main_category' => $category, 'item_description' => $description,
            'quantity' => '1', 'status' => 'pending_approval',
            'requested_at' => today(), 'submitted_at' => now(),
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('name', $role)->value('id'),
            'is_active' => true,
        ]);
    }
}
