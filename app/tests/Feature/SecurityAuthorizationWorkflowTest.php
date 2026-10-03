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
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\SupplierPayee;
use App\Models\SupplierPaymentDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityAuthorizationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\LookupSeeder::class);
        foreach (['admin', 'director', 'accounts', 'accountant', 'project_manager', 'estimator_qs', 'site_operator', 'unassigned_role'] as $name) {
            Role::firstOrCreate(['name' => $name], ['description' => $name]);
        }
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_email_password_login_routes_each_supported_role_to_its_role_dashboard(): void
    {
        $expected = [
            'admin' => route('dashboard.index'),
            'director' => route('director.index'),
            'accounts' => route('accountant.index'),
            'accountant' => route('accountant.index'),
            'project_manager' => route('pm.projects.index'),
            'estimator_qs' => route('estimator.projects.index'),
            'site_operator' => route('site.index'),
        ];

        foreach ($expected as $role => $landing) {
            $user = $this->user($role, ['email' => $role.'@security.test', 'password' => Hash::make('correct-secret')]);
            $this->post(route('login'), ['email' => $user->email, 'password' => 'correct-secret'])
                ->assertRedirect($landing);
            $this->assertAuthenticatedAs($user);
            $this->post('/logout')->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_wrong_password_inactive_users_and_unknown_roles_never_receive_privileged_landing(): void
    {
        $active = $this->user('admin', ['email' => 'active-security@test.invalid', 'password' => Hash::make('secret')]);
        $this->from(route('login'))->post(route('login'), ['email' => $active->email, 'password' => 'wrong'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();

        $inactive = $this->user('admin', ['email' => 'inactive-security@test.invalid', 'password' => Hash::make('secret'), 'is_active' => false]);
        $this->from(route('login'))->post(route('login'), ['email' => $inactive->email, 'password' => 'secret'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email', "Those credentials don't match our records.");
        $this->assertGuest();

        $unknown = $this->user('unassigned_role', ['email' => 'unknown-role@test.invalid', 'password' => Hash::make('secret')]);
        $this->post(route('login'), ['email' => $unknown->email, 'password' => 'secret'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($unknown)->get(route('dashboard.index'))->assertForbidden();
    }

    public function test_web_and_api_password_login_routes_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->from(route('login'))->post(route('login'), ['email' => 'guess@example.test', 'password' => 'wrong'])->assertRedirect(route('login'));
        }
        $this->post(route('login'), ['email' => 'guess@example.test', 'password' => 'wrong'])->assertStatus(429);

        // Both entry points share Laravel's IP throttle bucket, so the API
        // endpoint is blocked once web login attempts exhaust that bucket.
        $this->postJson('/api/login', ['email' => 'guess@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_demo_credentials_hint_is_hidden_outside_local_and_testing_environments(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('password for all: password');
        $this->app->detectEnvironment(fn () => 'production');
        $this->get(route('login'))->assertOk()->assertDontSee('password for all: password')->assertDontSee('director@buildiwick.test');
    }

    public function test_deactivated_authenticated_web_session_and_api_token_are_revoked_from_access(): void
    {
        $user = $this->user('accounts');
        $this->actingAs($user)->get(route('accountant.index'))->assertOk();
        $user->update(['is_active' => false]);
        $this->get(route('accountant.index'))->assertRedirect(route('login'));
        $this->assertGuest();

        $apiUser = $this->user('accounts');
        $token = $apiUser->createToken('inactive-test')->plainTextToken;
        $apiUser->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_users_cannot_open_protected_workspaces_and_admin_capability_remains(): void
    {
        foreach ([route('director.index'), route('accountant.index'), route('pm.projects.index'), route('estimator.projects.index'), route('site.index'), route('admin.projects.index')] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('admin.projects.index'))->assertOk();
        $this->get(route('dashboard.index'))->assertOk();
    }

    public function test_roles_cannot_cross_into_other_role_workspaces_by_manual_url_or_write_method(): void
    {
        [$project, $phase] = $this->project('BWG-SEC-ROLE-A');
        $request = ExpenseRequest::create([
            'request_code' => 'REQ-SEC-ROLE-1', 'project_id' => $project->id, 'project_phase_id' => $phase->id,
            'main_category' => 'materials', 'item_description' => 'Need', 'requested_at' => today()->toDateString(),
            'submitted_at' => now(), 'status' => 'pending_approval', 'requested_by' => $this->user('site_operator')->id,
        ]);
        $site = $this->user('site_operator');
        $estimator = $this->user('estimator_qs');
        $director = $this->user('director');
        $pm = $this->user('project_manager');
        $accounts = $this->user('accounts');

        $this->actingAs($site)->post(route('pm.requests.decision', $request), ['decision' => 'approve', 'notes' => 'No'])->assertForbidden();
        $this->actingAs($site)->get(route('pm.suppliers.index'))->assertForbidden();
        $this->actingAs($estimator)->post(route('accountant.payments.store', $request), [])->assertForbidden();
        $this->actingAs($estimator)->get(route('director.index'))->assertForbidden();
        $this->actingAs($director)->post(route('accountant.invoices.store', $project), [])->assertForbidden();
        $invoice = ClientInvoice::create(['invoice_code' => 'INV-SEC-ROLE-1', 'project_id' => $project->id, 'client_id' => $project->client_id, 'status' => 'issued', 'issued_at' => today()->toDateString(), 'due_at' => today()->addDays(10)->toDateString()]);
        $invoice->items()->create(['project_phase_id' => $phase->id, 'main_category' => 'materials', 'item_description' => 'Invoice line', 'unit_price' => '100.00']);
        $this->actingAs($pm)->post(route('accountant.client-payments.store', $invoice), [
            'client_invoice_id' => $invoice->id, 'project_id' => $project->id, 'client_id' => $project->client_id,
            'amount' => '10.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash',
        ])->assertForbidden();
        $this->actingAs($pm)->post(route('accountant.payments.store', $request), [])->assertForbidden();
        $this->actingAs($accounts)->post(route('pm.requests.decision', $request), ['decision' => 'approve', 'notes' => 'No'])->assertForbidden();
        $this->actingAs($accounts)->get(route('pm.suppliers.index'))->assertForbidden();

        $this->actingAs($site)->get(route('director.index'))->assertForbidden();
        $this->actingAs($director)->get(route('pm.projects.index'))->assertForbidden();
    }

    public function test_pm_estimator_and_site_operator_cannot_change_another_projects_records_by_id(): void
    {
        [$projectA, $phaseA] = $this->project('BWG-SEC-IDOR-A');
        [$projectB, $phaseB] = $this->project('BWG-SEC-IDOR-B');
        $pm = $this->user('project_manager');
        $estimator = $this->user('estimator_qs');
        $site = $this->user('site_operator');
        $projectA->assignedUsers()->attach([$pm->id, $estimator->id, $site->id]);

        $requestB = ExpenseRequest::create([
            'request_code' => 'REQ-SEC-IDOR-B', 'project_id' => $projectB->id, 'project_phase_id' => $phaseB->id,
            'main_category' => 'materials', 'item_description' => 'Other project need', 'requested_at' => today()->toDateString(),
            'submitted_at' => now(), 'status' => 'pending_approval', 'requested_by' => $site->id,
        ]);
        $budgetB = InternalBudget::create(['budget_code' => 'BUD-SEC-IDOR-B', 'project_id' => $projectB->id, 'version' => 1, 'status' => 'draft']);
        $budgetEntryB = $budgetB->entries()->create(['project_phase_id' => $phaseB->id, 'main_category' => 'materials', 'item_description' => 'Other budget', 'quantity' => '1', 'unit_cost' => '10.00']);
        $estimateB = ProjectEstimate::create(['estimate_code' => 'EST-SEC-IDOR-B', 'project_id' => $projectB->id, 'client_id' => $projectB->client_id, 'version' => 1, 'status' => 'draft']);
        $estimateEntryB = $estimateB->entries()->create(['project_phase_id' => $phaseB->id, 'main_category' => 'materials', 'item_description' => 'Other estimate', 'quantity' => '1', 'unit' => 'ls', 'base_unit_cost' => '10.00', 'markup_percent' => '0']);

        $this->actingAs($pm)->get(route('pm.projects.show', $projectB))->assertForbidden();
        $this->actingAs($pm)->post(route('pm.requests.decision', $requestB), ['decision' => 'reject', 'notes' => 'IDOR'])->assertForbidden();
        $this->actingAs($pm)->put(route('pm.budget-entries.update', [$projectB, $phaseB, $budgetEntryB]), [])->assertForbidden();
        $this->actingAs($estimator)->get(route('estimator.projects.show', $projectB))->assertForbidden();
        $this->actingAs($estimator)->put(route('estimator.entries.update', [$projectB, $phaseB, $estimateEntryB]), [])->assertForbidden();
        $this->actingAs($site)->get(route('site.projects.show', $projectB))->assertForbidden();
        $showResponse = $this->actingAs($site)->get(route('site.requests.show', [$projectA, $requestB]));
        $this->assertContains($showResponse->getStatusCode(), [403, 404]);
        $updateResponse = $this->actingAs($site)->put(route('site.requests.update', [$projectA, $requestB]), []);
        $this->assertContains($updateResponse->getStatusCode(), [403, 404]);

        $this->assertSame('Other project need', $requestB->fresh()->item_description);
        $this->assertSame('10.00', $budgetEntryB->fresh()->unit_cost);
        $this->assertSame('10.00', $estimateEntryB->fresh()->base_unit_cost);
    }

    public function test_site_operator_views_only_workflow_status_and_never_historical_or_payment_amounts(): void
    {
        [$project, $phase] = $this->project('BWG-SEC-SITE-REDACT');
        $site = $this->user('site_operator');
        $project->assignedUsers()->attach($site->id);
        $request = ExpenseRequest::create([
            'request_code' => 'REQ-SEC-SITE-REDACT', 'project_id' => $project->id, 'project_phase_id' => $phase->id,
            'main_category' => 'materials', 'item_description' => 'Historical priced need', 'unit_cost' => '123456.78',
            'total_amount' => '123456.78', 'approved_amount' => '123456.78', 'requested_at' => today()->toDateString(),
            'submitted_at' => now(), 'status' => 'approved', 'requested_by' => $site->id,
        ]);
        ExpensePayment::create(['payment_code' => 'PAY-SEC-SITE-REDACT', 'expense_request_id' => $request->id, 'project_id' => $project->id, 'project_phase_id' => $phase->id, 'amount' => '123.45', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);

        $this->actingAs($site)->get(route('site.requests.show', [$project, $request]))
            ->assertOk()->assertSee('Payment Status')->assertSee('Paid')
            ->assertDontSee('123,456.78')->assertDontSee('123.45')->assertDontSee('Approved Amount')->assertDontSee('Payment Method');
        $this->actingAs($site)->get(route('site.projects.show', $project))
            ->assertOk()->assertDontSee('123,456.78')->assertDontSee('Total Requested (Historical Priced)');
    }

    public function test_password_hash_is_never_rendered_and_private_disk_files_have_no_unsigned_serving_route(): void
    {
        $admin = $this->user('admin');
        $hash = $admin->password;
        $this->actingAs($admin)->get(route('dashboard.index'))->assertOk()->assertDontSee($hash);
        $this->get('/storage/evidence/private-file.pdf')->assertNotFound();
    }

    public function test_evidence_upload_requires_allowed_content_and_extension_types(): void
    {
        [$project] = $this->project('BWG-SEC-UPLOAD-EXTENSION');
        $site = $this->user('site_operator');
        $project->assignedUsers()->attach($site->id);
        $payload = [
            'requested_at' => today()->toDateString(), 'main_category' => 'materials',
            'item_description' => 'Evidence type check', 'quantity' => '1', 'unit' => 'bag',
            'evidence' => UploadedFile::fake()->createWithContent('document.exe', "%PDF-1.4\npermitted-looking-content"),
        ];

        $this->actingAs($site)->from(route('site.requests.create', $project))
            ->post(route('site.requests.store', $project), $payload)
            ->assertRedirect(route('site.requests.create', $project))->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('expense_requests', 0);
    }

    private function project(string $code): array
    {
        $project = Project::factory()->create(['client_id' => Client::factory(), 'project_code' => $code]);
        $phase = $project->phases()->create(['stage_code' => 'preliminaries', 'phase_order' => 1, 'status' => 'active']);

        return [$project, $phase];
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'role_id' => Role::where('name', $role)->value('id'),
            'is_active' => true,
        ]);
    }
}