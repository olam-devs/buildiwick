<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use App\Services\EstimatorEstimateService;
use App\Support\Money;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EstimatorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const STAGES = [
        'preliminaries', 'substructure', 'superstructure', 'walling',
        'roofing', 'finishing', 'external_works',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        foreach (['admin', 'accounts', 'estimator_qs', 'project_manager', 'site_operator'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
    }

    public function test_estimator_dashboard_only_lists_assigned_projects_and_admin_has_global_read_access(): void
    {
        [$assigned, $estimator] = $this->projectAndEstimatorAssignment();
        $unrelated = $this->projectWithSevenPhases('BWG-OTHER-001');
        $this->assertTrue($estimator->assignedProjects()->whereKey($assigned->id)->exists());

        $assignedResponse = $this->actingAs($estimator)->get(route('estimator.projects.index'));
        $assignedResponse->assertOk();
        $assignedResponse->assertViewHas('projects', fn ($rows) => $rows->contains(fn ($row) => (int) $row->project->id === (int) $assigned->id));
        $assignedResponse->assertViewHas('projects', fn ($rows) => ! $rows->contains(fn ($row) => (int) $row->project->id === (int) $unrelated->id));
        $assignedResponse->assertSee($assigned->project_code);
        $assignedResponse->assertDontSee($unrelated->project_code);
        $this->actingAs($estimator)->get(route('estimator.projects.show', $unrelated))->assertForbidden();
        $this->actingAs($estimator)->get('/projects')->assertForbidden();
        $adminResponse = $this->actingAs($this->user('admin'))->get(route('estimator.projects.index'));
        $adminResponse->assertOk();
        $adminResponse->assertSee($assigned->project_code);
        $adminResponse->assertSee($unrelated->project_code);
        $this->actingAs($this->user('admin'))->get(route('estimator.projects.show', $unrelated))->assertOk();
    }

    public function test_only_estimator_and_admin_can_open_estimator_workspaces_and_accounts_cannot_read_markup(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        foreach (['accounts', 'project_manager', 'site_operator'] as $role) {
            $this->actingAs($this->user($role))->get(route('estimator.projects.index'))->assertForbidden();
        }
        $this->actingAs($this->user('accounts'))->get(route('estimator.projects.show', $project))->assertForbidden();
        $accounts = $this->user('accounts');
        $phase = $project->phases()->firstOrFail();
        $this->actingAs($accounts)->post(route('estimator.entries.store', [$project, $phase]), [])->assertForbidden();
        try {
            app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $accounts);
            $this->fail('Accounts must not create an estimate.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $project->estimates()->count());
        }

        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $phase = $project->phases()->where('stage_code', 'roofing')->firstOrFail();
        $this->actingAs($estimator)->post(route('estimator.entries.store', [$project, $phase]), $this->entryPayload($estimate, $phase, [
            'main_category' => 'materials', 'base_unit_cost' => '100.00', 'markup_percent' => '25.0000',
        ]))->assertRedirect();

        $this->actingAs($this->user('accounts'))->get(route('estimator.projects.show', $project))->assertForbidden();
        $this->actingAs($this->user('site_operator'))->get(route('estimator.entries.edit', [$project, $phase, $estimate->entries()->first()]))->assertForbidden();
    }

    public function test_estimator_can_create_one_working_estimate_and_preserves_prior_versions(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $service = app(EstimatorEstimateService::class);
        $first = $service->ensureWorkingEstimate($project, $estimator);
        $same = $service->ensureWorkingEstimate($project, $estimator);
        $this->assertSame($first->id, $same->id);
        $this->assertSame(1, $project->estimates()->where('status', 'draft')->count());

        $first->update(['status' => 'issued']);
        $this->actingAs($estimator)->post(route('estimator.estimates.edit', [$project, $first]), [])
            ->assertSessionHasErrors('edit_reason');
        $this->assertSame(0, $project->estimates()->where('status', 'draft')->count());
        $second = $service->startEditing($first, 'Client changed the construction specification.', $estimator);
        $this->assertSame(2, $second->version);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('issued', $first->fresh()->status);
        $this->assertSame('draft', $second->status);
        $this->assertSame($project->client_id, $second->client_id);
        $this->assertSame($estimator->id, $second->prepared_by);
        $this->assertDatabaseHas('project_edit_logs', [
            'project_id' => $project->id, 'document_type' => 'estimate', 'action' => 'edit_started',
            'reason' => 'Client changed the construction specification.', 'edited_by' => $estimator->id,
        ]);
        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))->assertOk()
            ->assertSee('Estimate Edit Log')->assertSee('Client changed the construction specification.');
    }

    public function test_no_assigned_projects_has_a_clear_empty_state_and_multiple_drafts_are_not_silently_chosen(): void
    {
        $unassignedEstimator = $this->user('estimator_qs');
        $this->actingAs($unassignedEstimator)->get(route('estimator.projects.index'))
            ->assertOk()->assertSee('No projects are currently assigned to you.');

        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        ProjectEstimate::factory()->create(['project_id' => $project->id, 'version' => 1, 'status' => 'draft']);
        ProjectEstimate::factory()->create(['project_id' => $project->id, 'version' => 2, 'status' => 'draft']);
        $this->expectException(ValidationException::class);
        app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
    }

    public function test_estimator_can_add_entries_to_any_standard_phase_including_not_started_future_phases(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $this->assertSame('not_started', $project->phases()->where('stage_code', 'roofing')->value('status'));
        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))->assertOk()->assertSee('Phase 5 — Roofing');

        $response = $this->actingAs($estimator)->post(route('estimator.entries.store', [$project, $project->phases()->where('stage_code', 'roofing')->first()]), $this->entryPayload($estimate, $project->phases()->where('stage_code', 'roofing')->first()));
        $response->assertRedirect();
        $entry = $estimate->entries()->firstOrFail();
        $this->assertSame('roofing', $entry->phase->stage_code);
        $this->assertSame($estimator->id, $entry->entered_by);
        $this->assertSame('materials', $entry->main_category);
    }

    public function test_entry_submission_rejects_foreign_project_phase_and_other_general(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $other = $this->projectWithSevenPhases('BWG-FOREIGN-001');
        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $foreignPhase = $other->phases()->firstOrFail();

        $this->actingAs($estimator)->post(route('estimator.entries.store', [$project, $foreignPhase]), $this->entryPayload($estimate, $foreignPhase))
            ->assertNotFound();

        $this->expectException(ValidationException::class);
        app(EstimatorEstimateService::class)->createEntry($estimate, new ProjectPhase([
            'project_id' => $project->id, 'stage_code' => 'other_general', 'phase_order' => 8,
        ]), $this->entryPayload($estimate, $project->phases()->first()), $estimator);
    }

    public function test_markup_calculation_rounding_and_exact_aggregations_work_by_phase_category_and_project(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $phases = $project->phases()->orderBy('phase_order')->get();
        $service = app(EstimatorEstimateService::class);

        $materials = $service->createEntry($estimate, $phases[0], $this->entryPayload($estimate, $phases[0], [
            'main_category' => 'materials', 'quantity' => '2.00', 'unit' => 'bag', 'base_unit_cost' => '100.00', 'markup_percent' => '10.0000',
        ]), $estimator);
        $this->assertSame('110.00', $materials->client_unit_cost);
        $this->assertSame('200.00', $materials->base_total);
        $this->assertSame('20.00', $materials->markup_amount);
        $this->assertSame('220.00', $materials->line_total);

        $labour = $service->createEntry($estimate, $phases[1], $this->entryPayload($estimate, $phases[1], [
            'main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour', 'quantity' => '1.00', 'unit' => 'ls', 'base_unit_cost' => '50.00', 'markup_percent' => '0.0000',
        ]), $estimator);
        $rounding = $service->createEntry($estimate, $phases[2], $this->entryPayload($estimate, $phases[2], [
            'main_category' => 'materials', 'quantity' => '1.50', 'unit' => 'bag', 'base_unit_cost' => '19.99', 'markup_percent' => '12.3456',
        ]), $estimator);

        $this->assertSame('22.46', $rounding->client_unit_cost);
        $this->assertSame('29.99', $rounding->base_total);
        $this->assertSame('3.70', $rounding->markup_amount);
        $this->assertSame('33.69', $rounding->line_total);
        $phaseSummary = $service->phaseSummary($estimate->fresh('entries'), $phases[0]);
        $this->assertSame('200.00', $phaseSummary['baseTotal']);
        $this->assertSame('20.00', $phaseSummary['markupTotal']);
        $this->assertSame('220.00', $phaseSummary['clientTotal']);

        $summary = $service->projectSummary($estimate->fresh('entries'));
        $this->assertSame('279.99', $summary['baseTotal']);
        $this->assertSame('23.70', $summary['markupTotal']);
        $this->assertSame('303.69', $summary['clientTotal']);
        $this->assertSame('8.46', $summary['effectiveMarkup']);
        $this->assertSame('253.69', $summary['categories']['materials']['client']);
        $this->assertSame('50.00', $summary['categories']['labour_subcontracts']['client']);
        $this->assertSame('0.00', Money::percentage('0', '0'));
    }

    public function test_lump_sum_can_omit_quantity_and_unit_price_totals_use_the_existing_ls_lookup(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $phase = $project->phases()->where('stage_code', 'preliminaries')->firstOrFail();
        $payload = $this->entryPayload($estimate, $phase, [
            'main_category' => 'preliminaries_overheads', 'subcategory' => null,
            'quantity' => null, 'unit' => 'ls', 'base_unit_cost' => '1000.00', 'markup_percent' => '15.0000',
        ]);
        unset($payload['quantity']);
        $entry = app(EstimatorEstimateService::class)->createEntry($estimate, $phase, $payload, $estimator);
        $this->assertNull($entry->quantity);
        $this->assertSame('ls', $entry->unit);
        $this->assertSame('1000.00', $entry->base_total);
        $this->assertSame('150.00', $entry->markup_amount);
        $this->assertSame('1150.00', $entry->line_total);
    }

    public function test_construction_units_are_additive_unique_and_all_categories_render_entry_guidance(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $phases = $project->phases()->orderBy('phase_order')->get();
        $entries = [
            ['main_category' => 'materials', 'unit' => 'bag'],
            ['main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour', 'unit' => 'worker_day'],
            ['main_category' => 'logistics_equipment', 'subcategory' => null, 'unit' => 'trip'],
            ['main_category' => 'preliminaries_overheads', 'subcategory' => null, 'unit' => 'service'],
        ];
        foreach ($entries as $index => $entry) {
            $created = app(EstimatorEstimateService::class)->createEntry($estimate, $phases[$index], $this->entryPayload($estimate, $phases[$index], $entry), $estimator);
            $this->assertSame('50.00', $created->line_total);
        }

        $codes = Lookup::ofType('unit')->pluck('code');
        $this->assertSame($codes->count(), $codes->unique()->count());
        $this->assertTrue($codes->intersect(['tonne', 'm2', 'worker_day', 'service', 'person'])->count() === 5);
        $this->actingAs($estimator)->get(route('estimator.entries.create', [$project, $phases[0]]))
            ->assertOk()->assertSee('worker_day')->assertDontSee('Supplier / Fundi')->assertDontSee('supplier_fundi_client');
        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))
            ->assertOk()->assertSee('Client Unit Price')->assertDontSee('Supplier / Fundi / Provider')->assertDontSee('supplier_fundi_client');
    }

    public function test_zero_base_cost_with_markup_has_zero_totals_and_effective_markup_is_zero(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $phase = $project->phases()->firstOrFail();
        app(EstimatorEstimateService::class)->createEntry($estimate, $phase, $this->entryPayload($estimate, $phase, [
            'base_unit_cost' => '0.00', 'quantity' => '10.00', 'markup_percent' => '20.0000',
        ]), $estimator);

        $summary = app(EstimatorEstimateService::class)->projectSummary($estimate->fresh('entries'));
        $this->assertSame('0.00', $summary['baseTotal']);
        $this->assertSame('0.00', $summary['markupTotal']);
        $this->assertSame('0.00', $summary['clientTotal']);
        $this->assertSame('0.00', $summary['effectiveMarkup']);
    }

    public function test_category_and_subcategory_must_use_existing_lookup_relationships(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $phase = $project->phases()->firstOrFail();
        $this->actingAs($estimator)->get(route('estimator.entries.create', [$project, $phase]))
            ->assertOk()->assertSee('Base Unit Cost')->assertSee('Client Unit Price')->assertSee('Markup Amount');

        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $payload = $this->entryPayload($estimate, $phase, ['main_category' => 'logistics_equipment', 'subcategory' => 'fundi_labour']);
        $this->actingAs($estimator)->from(route('estimator.entries.create', [$project, $phase]))
            ->post(route('estimator.entries.store', [$project, $phase]), $payload)
            ->assertRedirect(route('estimator.entries.create', [$project, $phase]))
            ->assertSessionHasErrors('subcategory');

        $payload['subcategory'] = null;
        $this->actingAs($estimator)->post(route('estimator.entries.store', [$project, $phase]), $payload)->assertRedirect();
        $this->assertSame(1, $estimate->entries()->count());
    }

    public function test_assigned_estimator_can_edit_and_delete_draft_entries_but_not_after_finalization_or_invoice_reference(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $phase = $project->phases()->firstOrFail();
        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $entry = app(EstimatorEstimateService::class)->createEntry($estimate, $phase, $this->entryPayload($estimate, $phase), $estimator);
        $changed = $this->entryPayload($estimate, $phase, ['quantity' => '3.00', 'base_unit_cost' => '25.00', 'markup_percent' => '20.0000', 'edit_reason' => 'Updated quantity and supplier quotation.']);

        $this->actingAs($estimator)->put(route('estimator.entries.update', [$project, $phase, $entry]), $changed)->assertRedirect();
        $this->assertSame('90.00', $entry->fresh()->line_total);

        $otherEstimator = $this->user('estimator_qs');
        $this->actingAs($otherEstimator)->put(route('estimator.entries.update', [$project, $phase, $entry]), $changed)->assertForbidden();
        $this->actingAs($estimator)->delete(route('estimator.entries.delete', [$project, $phase, $entry]), ['edit_reason' => 'Duplicate estimate line.'])->assertRedirect();
        $this->assertDatabaseMissing('estimate_entries', ['id' => $entry->id]);

        $entry = app(EstimatorEstimateService::class)->createEntry($estimate, $phase, $this->entryPayload($estimate, $phase), $estimator);
        $issued = app(EstimatorEstimateService::class)->finalize($estimate, $estimator);
        $this->assertSame('issued', $issued->status);
        $this->actingAs($estimator)->put(route('estimator.entries.update', [$project, $phase, $entry]), $changed)->assertForbidden();
        $this->actingAs($estimator)->delete(route('estimator.entries.delete', [$project, $phase, $entry]))->assertForbidden();
        $this->actingAs($this->user('admin'))->put(route('estimator.entries.update', [$project, $phase, $entry]), $changed)->assertForbidden();
        $this->actingAs($this->user('admin'))->delete(route('estimator.entries.delete', [$project, $phase, $entry]))->assertForbidden();
        $this->assertDatabaseHas('estimate_entries', ['id' => $entry->id]);
    }

    public function test_finalize_requires_entries_locks_estimate_and_starts_a_new_immutable_version(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $service = app(EstimatorEstimateService::class);
        $draft = $service->ensureWorkingEstimate($project, $estimator);
        try {
            $service->finalize($draft, $estimator);
            $this->fail('Cannot issue an empty estimate.');
        } catch (ValidationException) {
            $this->assertSame('draft', $draft->fresh()->status);
        }
        $phase = $project->phases()->firstOrFail();
        $service->createEntry($draft, $phase, $this->entryPayload($draft, $phase), $estimator);
        $service->finalize($draft, $estimator);
        $this->assertSame('issued', $draft->fresh()->status);

        $next = $service->startEditing($draft->fresh(), 'Client requested an updated estimate.', $estimator);
        $this->assertSame(2, $next->version);
        $service->finalize($next, $estimator);
        $this->assertSame('issued', $next->fresh()->status);
        $this->assertSame('superseded', $draft->fresh()->status);
        $this->assertSame(2, $project->estimates()->count());
    }

    public function test_invoice_referenced_estimate_cannot_be_finalized_or_have_entries_changed(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $service = app(EstimatorEstimateService::class);
        $estimate = $service->ensureWorkingEstimate($project, $estimator);
        $phase = $project->phases()->firstOrFail();
        $entry = $service->createEntry($estimate, $phase, $this->entryPayload($estimate, $phase), $estimator);
        $project->invoices()->create([
            'invoice_code' => 'INV-EST-LOCK-001',
            'client_id' => $project->client_id,
            'project_estimate_id' => $estimate->id,
            'status' => 'draft',
        ]);

        $this->actingAs($estimator)->get(route('estimator.entries.edit', [$project, $phase, $entry]))->assertForbidden();
        $this->actingAs($estimator)->put(route('estimator.entries.update', [$project, $phase, $entry]), $this->entryPayload($estimate, $phase, ['base_unit_cost' => '99.00']))->assertForbidden();
        $this->actingAs($estimator)->delete(route('estimator.entries.delete', [$project, $phase, $entry]))->assertForbidden();
        try {
            $service->finalize($estimate, $estimator);
            $this->fail('An invoice-referenced working estimate must not be silently finalized.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $estimate->fresh()->status);
        }
    }

    public function test_estimator_dashboard_and_project_workspace_render_empty_and_populated_states(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $this->actingAs($estimator)->get(route('estimator.projects.index'))
            ->assertOk()->assertSee('Estimator Dashboard')->assertSee('0.00')->assertSee('Not started');
        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))
            ->assertOk()->assertSee('No estimate has been created for this project yet.');

        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $phase = $project->phases()->where('stage_code', 'preliminaries')->firstOrFail();
        app(EstimatorEstimateService::class)->createEntry($estimate, $phase, $this->entryPayload($estimate, $phase), $estimator);
        $this->actingAs($estimator)->get(route('estimator.projects.index'))
            ->assertOk()->assertSee('50.00')->assertSee('Draft');
        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))
            ->assertOk()->assertSee('Phase 1 — Preliminaries')->assertSee('Phase Base Cost')->assertSee('Markup Amount')
            ->assertSee('Effective Markup')->assertSee('Issue Estimate')->assertSee('Project Base Cost');
    }

    public function test_landing_redirects_estimator_to_estimator_dashboard_and_hides_financial_entry_ui(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $this->actingAs($estimator)->get('/')->assertRedirect(route('estimator.projects.index'));
        $this->actingAs($estimator)->get(route('estimator.projects.index'))
            ->assertOk()->assertSee('Estimator Dashboard')->assertDontSee('New Entry')->assertDontSee('Record Client Payment')->assertDontSee('Pay Expense');
        $this->actingAs($estimator)->get('/login')->assertRedirect(route('estimator.projects.index'));
        $this->actingAs($estimator)->get('/projects')->assertForbidden();
    }

    public function test_estimate_status_and_marked_up_values_are_not_visible_to_non_authorized_roles(): void
    {
        [$project, $estimator] = $this->projectAndEstimatorAssignment();
        $estimate = app(EstimatorEstimateService::class)->ensureWorkingEstimate($project, $estimator);
        $phase = $project->phases()->firstOrFail();
        app(EstimatorEstimateService::class)->createEntry($estimate, $phase, $this->entryPayload($estimate, $phase, [
            'base_unit_cost' => '100.00', 'markup_percent' => '50.0000',
        ]), $estimator);
        $this->actingAs($this->user('site_operator'))->get(route('estimator.projects.show', $project))->assertForbidden();
        $this->actingAs($this->user('project_manager'))->get(route('estimator.projects.show', $project))->assertForbidden();
        $this->actingAs($this->user('accounts'))->get(route('estimator.projects.index'))->assertForbidden();
        $this->actingAs($this->user('admin'))->get(route('estimator.projects.show', $project))->assertOk()->assertSee('50.0000%')->assertSee('50.00');
    }

    private function projectAndEstimatorAssignment(): array
    {
        $project = $this->projectWithSevenPhases('BWG-EST-'.fake()->unique()->numerify('#####'));
        $estimator = $this->user('estimator_qs');
        $project->assignedUsers()->attach($estimator->id);

        return [$project, $estimator];
    }

    private function projectWithSevenPhases(string $code): Project
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create([
            'project_code' => $code,
            'name' => 'Test Project '.$code,
            'client_id' => $client->id,
        ]);
        foreach (self::STAGES as $index => $stage) {
            $project->phases()->create([
                'stage_code' => $stage,
                'phase_order' => $index + 1,
                'status' => $index === 0 ? 'active' : 'not_started',
                'planned_start_date' => '2026-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'-01',
                'planned_completion_date' => '2026-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'-28',
            ]);
        }

        return $project;
    }

    private function user(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function entryPayload(ProjectEstimate $estimate, ProjectPhase $phase, array $override = []): array
    {
        return array_merge([
            'main_category' => 'materials',
            'subcategory' => 'concrete_cement',
            'item_description' => 'Cement estimate',
            'supplier_fundi_client' => 'Supplier Ltd',
            'quantity' => '1.00',
            'unit' => 'bag',
            'base_unit_cost' => '50.00',
            'markup_percent' => '0.0000',
            'notes' => 'Estimator note',
        ], $override);
    }
}
