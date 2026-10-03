<?php

namespace Tests\Feature;

use App\Http\Requests\StoreProjectPhaseRequest;
use App\Http\Requests\StoreProjectSetupRequest;
use App\Models\Client;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectPhasePolicy;
use App\Services\ProjectRegistrationService;
use Database\Seeders\LookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AdminProjectSetupTest extends TestCase
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
        foreach (['admin', 'accounts', 'estimator_qs', 'project_manager', 'site_operator'] as $name) {
            Role::firstOrCreate(['name' => $name], ['description' => $name]);
        }
        Storage::fake('local');
    }

    public function test_admin_can_create_a_project_with_existing_client_phases_and_role_correct_assignments(): void
    {
        $admin = $this->user('admin');
        $client = Client::factory()->create(['name' => 'Existing Client']);
        $team = $this->team();

        $response = $this->actingAs($admin)->post(route('admin.projects.store'), $this->payload($client, $team));

        $project = Project::where('project_code', 'BWG-NEW-2026-001')->firstOrFail();
        $response->assertRedirect(route('admin.projects.show', $project));
        $this->assertSame($client->id, $project->client_id);
        $this->assertSame($admin->id, $project->created_by);
        $this->assertSame($team['estimator']->id, $project->assignedUsers()->where('role_id', $team['estimator']->role_id)->value('users.id'));
        $this->assertSame($team['manager']->id, $project->assignedUsers()->where('role_id', $team['manager']->role_id)->value('users.id'));
        $this->assertSame($team['operator']->id, $project->assignedUsers()->where('role_id', $team['operator']->role_id)->value('users.id'));
        $this->assertSame(7, $project->phases()->count());
    }

    public function test_admin_can_create_a_new_client_during_project_registration_and_client_can_have_multiple_projects(): void
    {
        $team = $this->team();
        $admin = $this->user('admin');
        $payload = $this->payload(null, $team);
        $payload['client_mode'] = 'new';
        $payload['new_client'] = [
            'name' => 'New Buildiwick Client',
            'phone' => '+255700000001',
            'email' => 'new-client@example.test',
            'address' => 'Dar es Salaam',
            'notes' => 'Created with project',
        ];

        $this->actingAs($admin)->post(route('admin.projects.store'), $payload)->assertRedirect();
        $client = Client::where('name', 'New Buildiwick Client')->firstOrFail();
        $this->assertSame('+255700000001', $client->phone);
        $this->assertSame('new-client@example.test', $client->email);

        $second = $this->payload($client, $team, 'BWG-NEW-2026-002');
        $this->actingAs($admin)->post(route('admin.projects.store'), $second)->assertRedirect();
        $this->assertCount(2, $client->projects()->get());
    }

    public function test_project_code_must_be_unique_and_duplicate_submission_never_replaces_project(): void
    {
        $team = $this->team();
        $admin = $this->user('admin');
        $client = Client::factory()->create();
        $payload = $this->payload($client, $team);
        $this->actingAs($admin)->get(route('admin.projects.create'))->assertOk()->assertSee('Register Confirmed Project');
        $this->actingAs($admin)->post(route('admin.projects.store'), $payload)->assertRedirect();
        $original = Project::where('project_code', $payload['project_code'])->firstOrFail();

        $duplicate = $payload;
        $duplicate['name'] = 'Must Not Replace Original';
        $this->actingAs($admin)->from(route('admin.projects.create'))->post(route('admin.projects.store'), $duplicate)
            ->assertRedirect(route('admin.projects.create'))->assertSessionHasErrors('project_code');

        $this->assertSame('Confirmed Test Project', $original->fresh()->name);
        $this->assertSame(1, Project::where('project_code', $payload['project_code'])->count());
    }

    public function test_registering_a_project_does_not_rewrite_existing_legacy_transactions(): void
    {
        $legacyProject = Project::factory()->create(['project_code' => 'BWG-LEGACY-001']);
        $legacyTransaction = $legacyProject->transactions()->create([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'transaction_code' => 'BWG-LEGACY-001-TXN-0001',
            'transaction_type' => 'expense',
            'main_category' => 'materials',
            'item_description' => 'Historical cement',
            'total_amount' => '24500.00',
            'amount_paid_received' => '0.00',
            'payment_status' => 'to_confirm',
            'data_status' => 'needs_confirmation',
            'transaction_date' => '2026-07-23',
        ]);

        $this->actingAs($this->user('admin'))->post(route('admin.projects.store'), $this->payload(Client::factory()->create(), $this->team()))->assertRedirect();

        $this->assertDatabaseHas('transactions', [
            'id' => $legacyTransaction->id,
            'project_id' => $legacyProject->id,
            'transaction_code' => 'BWG-LEGACY-001-TXN-0001',
            'total_amount' => '24500.00',
            'project_phase_id' => null,
        ]);
    }

    public function test_registration_rejects_users_with_wrong_roles_and_duplicate_team_identity(): void
    {
        $team = $this->team();
        $admin = $this->user('admin');
        $client = Client::factory()->create();
        $wrongRole = $this->user('accounts');
        foreach ([
            ['estimator_id', 'BWG-NEW-2026-002'],
            ['project_manager_id', 'BWG-NEW-2026-003'],
            ['site_operator_id', 'BWG-NEW-2026-004'],
        ] as [$field, $code]) {
            $payload = $this->payload($client, $team, $code);
            $payload[$field] = $wrongRole->id;
            $this->actingAs($admin)->from(route('admin.projects.create'))->post(route('admin.projects.store'), $payload)
                ->assertRedirect(route('admin.projects.create'))->assertSessionHasErrors($field);
        }

        $payload = $this->payload($client, $team, 'BWG-NEW-2026-005');
        $payload['site_operator_id'] = $team['manager']->id;
        $this->actingAs($admin)->from(route('admin.projects.create'))->post(route('admin.projects.store'), $payload)
            ->assertRedirect(route('admin.projects.create'))->assertSessionHasErrors('site_operator_id');
    }

    public function test_project_and_each_phase_schedule_dates_are_validated_and_persisted(): void
    {
        $team = $this->team();
        $admin = $this->user('admin');
        $client = Client::factory()->create();
        $payload = $this->payload($client, $team);
        $payload['expected_completion_date'] = '2025-12-31';
        $payload['phases']['roofing']['planned_completion_date'] = '2025-01-01';

        $this->actingAs($admin)->from(route('admin.projects.create'))->post(route('admin.projects.store'), $payload)
            ->assertRedirect(route('admin.projects.create'))
            ->assertSessionHasErrors(['expected_completion_date', 'phases.roofing.planned_completion_date']);

        $payload = $this->payload($client, $team);
        $payload['phases']['substructure']['planned_start_date'] = $payload['phases']['preliminaries']['planned_start_date'];
        $payload['phases']['substructure']['planned_completion_date'] = $payload['phases']['preliminaries']['planned_completion_date'];
        $this->actingAs($admin)->post(route('admin.projects.store'), $payload)->assertRedirect();

        $phase = ProjectPhase::where('stage_code', 'substructure')->firstOrFail();
        $this->assertSame('2026-01-01', $phase->planned_start_date->format('Y-m-d'));
        $this->assertSame('2026-01-28', $phase->planned_completion_date->format('Y-m-d'));
    }

    public function test_seven_phases_get_correct_order_initial_status_nullable_actual_dates_and_progress(): void
    {
        $team = $this->team();
        $payload = $this->payload(Client::factory()->create(), $team);
        $admin = $this->user('admin');
        $this->actingAs($admin)->post(route('admin.projects.store'), $payload)->assertRedirect();

        $phases = ProjectPhase::where('project_id', Project::where('project_code', $payload['project_code'])->value('id'))->orderBy('phase_order')->get();
        $this->assertCount(7, $phases);
        $this->assertSame(self::STAGES, $phases->pluck('stage_code')->all());
        $this->assertSame(range(1, 7), $phases->pluck('phase_order')->all());
        $this->assertSame(['active', 'not_started', 'not_started', 'not_started', 'not_started', 'not_started', 'not_started'], $phases->pluck('status')->all());
        $this->assertSame(0, ProjectPhase::where('stage_code', 'other_general')->count());
        foreach ($phases as $index => $phase) {
            $expectedMonth = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $this->assertSame('2026-'.$expectedMonth.'-01', $phase->planned_start_date->format('Y-m-d'));
            $this->assertSame('2026-'.$expectedMonth.'-28', $phase->planned_completion_date->format('Y-m-d'));
            $this->assertNull($phase->actual_start_date);
            $this->assertNull($phase->actual_completion_date);
            $this->assertNull($phase->physical_progress_percentage);
        }
    }

    public function test_physical_progress_validation_accepts_zero_and_one_hundred_and_rejects_out_of_range(): void
    {
        $admin = $this->user('admin');
        $project = Project::factory()->create();
        $phase = ProjectPhase::factory()->create(['project_id' => $project->id]);
        $base = [
            'project_id' => $project->id,
            'stage_code' => 'substructure',
            'phase_order' => 2,
            'planned_start_date' => '2026-01-01',
            'planned_completion_date' => '2026-02-01',
        ];

        foreach ([0, 100] as $value) {
            $validator = Validator::make($base + ['physical_progress_percentage' => $value], (new StoreProjectPhaseRequest)->rules());
            $this->assertTrue($validator->passes());
        }
        foreach ([-0.01, 100.01] as $value) {
            $validator = Validator::make($base + ['physical_progress_percentage' => $value], (new StoreProjectPhaseRequest)->rules());
            $this->assertFalse($validator->passes());
            $this->assertTrue($validator->errors()->has('physical_progress_percentage'));
        }
        $this->assertNotNull($admin);
        $this->assertNotNull($phase);
    }

    public function test_signed_contract_is_stored_as_private_project_document_and_download_requires_admin(): void
    {
        $team = $this->team();
        $client = Client::factory()->create();
        $payload = $this->payload($client, $team);
        $admin = $this->user('admin');
        $payload['contract_signed'] = '1';
        $payload['contract_reference'] = 'CON-2026-12';
        $payload['contract_signed_at'] = '2026-09-01';
        $payload['contract_document'] = UploadedFile::fake()->create('signed-contract.pdf', 20, 'application/pdf');

        $this->actingAs($admin)->post(route('admin.projects.store'), $payload)->assertRedirect();
        $project = Project::where('project_code', $payload['project_code'])->firstOrFail();
        $document = $project->contractDocuments()->firstOrFail();
        $this->assertSame('contract', $document->document_type);
        $this->assertSame('signed-contract.pdf', $document->file_name);
        $this->assertSame($admin->id, $document->uploaded_by);
        Storage::disk('local')->assertExists($document->file_path);

        $this->actingAs($this->user('accounts'))
            ->get(route('admin.projects.contracts.download', [$project, $document]))
            ->assertForbidden();
        $this->actingAs($this->user('admin'))
            ->get(route('admin.projects.contracts.download', [$project, $document]))
            ->assertDownload('signed-contract.pdf');
    }

    public function test_signed_contract_requires_signing_date_and_document_and_rejects_unsafe_file_types(): void
    {
        $team = $this->team();
        $admin = $this->user('admin');
        $payload = $this->payload(Client::factory()->create(), $team);
        $payload['contract_signed'] = '1';

        $this->actingAs($admin)->from(route('admin.projects.create'))->post(route('admin.projects.store'), $payload)
            ->assertRedirect(route('admin.projects.create'))
            ->assertSessionHasErrors(['contract_signed_at', 'contract_document']);

        $payload['contract_signed_at'] = '2026-09-01';
        $payload['contract_document'] = UploadedFile::fake()->create('unsafe.exe', 10, 'application/x-msdownload');
        $this->actingAs($admin)->from(route('admin.projects.create'))->post(route('admin.projects.store'), $payload)
            ->assertRedirect(route('admin.projects.create'))->assertSessionHasErrors('contract_document');
    }

    public function test_non_admin_roles_cannot_access_registration_list_detail_edit_or_mutation_routes(): void
    {
        $team = $this->team();
        $project = Project::factory()->create();
        $document = ProjectDocument::create([
            'project_id' => $project->id,
            'document_type' => 'contract',
            'file_name' => 'contract.pdf',
            'file_path' => 'contract.pdf',
            'uploaded_at' => now(),
        ]);
        $payload = $this->payload(Client::factory()->create(), $team);

        foreach (['accounts', 'estimator_qs', 'project_manager', 'site_operator'] as $role) {
            $this->actingAs($this->user($role));
            $this->get(route('admin.projects.index'))->assertForbidden();
            $this->get(route('admin.projects.create'))->assertForbidden();
            $this->get(route('admin.projects.show', $project))->assertForbidden();
            $this->get(route('admin.projects.edit', $project))->assertForbidden();
            $this->post(route('admin.projects.store'), $payload)->assertForbidden();
            $this->put(route('admin.projects.update', $project), $payload)->assertForbidden();
            $this->get(route('admin.projects.contracts.download', [$project, $document]))->assertForbidden();
        }
    }

    public function test_admin_project_list_and_detail_show_client_team_current_phase_and_programme(): void
    {
        $team = $this->team();
        $payload = $this->payload(Client::factory()->create(['name' => 'Display Client']), $team);
        $this->actingAs($this->user('admin'))->post(route('admin.projects.store'), $payload)->assertRedirect();
        $project = Project::where('project_code', $payload['project_code'])->firstOrFail();

        $this->get(route('admin.projects.index'))
            ->assertOk()->assertSee('Display Client')->assertSee('Preliminaries')
            ->assertSee($team['estimator']->name)->assertSee($team['manager']->name)->assertSee($team['operator']->name)
            ->assertSee('New Project')->assertSee('View Project')->assertSee('Edit Setup');
        $this->get(route('admin.projects.show', $project))
            ->assertOk()->assertSee('Construction Programme')->assertSee('Actual Start')
            ->assertSee('01 Jan 2026')->assertSee('not started')->assertSee('Client');
    }

    public function test_edit_updates_setup_but_keeps_project_code_and_protects_client_after_invoice_history(): void
    {
        $team = $this->team();
        $admin = $this->user('admin');
        $client = Client::factory()->create();
        $payload = $this->payload($client, $team);
        $this->actingAs($admin)->post(route('admin.projects.store'), $payload)->assertRedirect();
        $project = Project::where('project_code', $payload['project_code'])->firstOrFail();

        $changed = $this->payload($client, $team);
        $changed['project_code'] = 'BWG-RENAMED-001';
        $changed['name'] = 'Updated Project Name';
        $this->actingAs($admin)->from(route('admin.projects.edit', $project))->put(route('admin.projects.update', $project), $changed)
            ->assertRedirect(route('admin.projects.edit', $project))->assertSessionHasErrors('project_code');

        $changed['project_code'] = $project->project_code;
        $changed['name'] = 'Updated Project Name';
        $this->actingAs($admin)->put(route('admin.projects.update', $project), $changed)->assertRedirect();
        $this->assertSame('Updated Project Name', $project->fresh()->name);
        $this->assertSame($payload['project_code'], $project->fresh()->project_code);

        $project->invoices()->create([
            'invoice_code' => 'INV-HISTORY-001',
            'client_id' => $client->id,
            'status' => 'draft',
        ]);
        $otherClient = Client::factory()->create();
        $changed['client_id'] = $otherClient->id;
        $this->actingAs($admin)->from(route('admin.projects.edit', $project))->put(route('admin.projects.update', $project), $changed)
            ->assertRedirect(route('admin.projects.edit', $project))->assertSessionHasErrors('client_id');
        $this->assertSame($client->id, $project->fresh()->client_id);
    }

    public function test_project_with_financial_or_document_history_is_not_deletable_through_policy(): void
    {
        $project = Project::factory()->create();
        $phase = ProjectPhase::factory()->create(['project_id' => $project->id]);
        $request = ExpenseRequest::create([
            'request_code' => 'REQ-HISTORY-001', 'project_id' => $project->id,
            'project_phase_id' => $phase->id, 'main_category' => 'materials',
            'item_description' => 'Historical request', 'total_amount' => '1.00',
            'requested_at' => now()->toDateString(), 'status' => 'pending_approval',
        ]);
        $this->assertFalse(app(ProjectPolicy::class)->delete($this->user('admin'), $project));
        $admin = $this->user('admin');
        $this->assertFalse($admin->can('delete', $project));
        $this->assertFalse($admin->can('delete', $phase));
        $this->assertFalse(app(ProjectPhasePolicy::class)->delete($admin, $phase));
        $this->assertNotEmpty($request->project->phases);

        $documented = Project::factory()->create();
        ProjectDocument::create(['project_id' => $documented->id, 'document_type' => 'contract', 'file_name' => 'history.pdf', 'file_path' => 'history.pdf']);
        $this->assertFalse(app(ProjectPolicy::class)->delete($this->user('admin'), $documented));
    }

    public function test_registration_failure_rolls_back_project_phases_and_assignments(): void
    {
        $team = $this->team();
        $admin = $this->user('admin');
        $payload = $this->payload(null, $team);
        $payload['client_mode'] = 'new';
        $payload['new_client'] = ['name' => 'Must Roll Back Client', 'phone' => '+255700000099'];

        DB::statement("CREATE TRIGGER fail_project_assignment BEFORE INSERT ON project_assignments BEGIN SELECT RAISE(ABORT, 'simulated assignment failure'); END");
        try {
            app(ProjectRegistrationService::class)->create($payload, $admin);
            $this->fail('Expected the assignment trigger to abort registration.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('projects', ['project_code' => $payload['project_code']]);
            $this->assertDatabaseMissing('clients', ['name' => 'Must Roll Back Client']);
            $this->assertSame(0, ProjectPhase::whereHas('project', fn ($query) => $query->where('project_code', $payload['project_code']))->count());
            $this->assertSame(0, DB::table('project_assignments')->whereIn('user_id', [$team['estimator']->id, $team['manager']->id, $team['operator']->id])->count());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_project_assignment');
        }
    }

    private function payload(?Client $client, array $team, string $code = 'BWG-NEW-2026-001'): array
    {
        $phases = [];
        foreach (self::STAGES as $index => $stage) {
            $phases[$stage] = [
                'planned_start_date' => '2026-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'-01',
                'planned_completion_date' => '2026-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'-28',
            ];
        }

        return [
            'project_code' => $code,
            'name' => 'Confirmed Test Project',
            'location' => 'Dar es Salaam',
            'status' => 'active',
            'planned_start_date' => '2026-01-01',
            'expected_completion_date' => '2026-12-31',
            'client_mode' => 'existing',
            'client_id' => $client?->id,
            'contract_reference' => null,
            'contract_signed' => '0',
            'contract_signed_at' => null,
            'estimator_id' => $team['estimator']->id,
            'project_manager_id' => $team['manager']->id,
            'site_operator_id' => $team['operator']->id,
            'phases' => $phases,
        ];
    }

    private function team(): array
    {
        return [
            'estimator' => $this->user('estimator_qs'),
            'manager' => $this->user('project_manager'),
            'operator' => $this->user('site_operator'),
        ];
    }

    private function user(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

}