<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\Role;
use App\Models\User;
use App\Services\ClientInvoiceService;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EstimateInvoiceSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_boq_claims_track_remaining_and_prevent_overbilling(): void
    {
        $this->seed(LookupSeeder::class);
        $role = Role::create(['name' => 'accounts', 'description' => 'Accounts']);
        $accountant = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $phase = $project->phases()->create(['stage_code' => 'superstructure', 'phase_name' => 'Superstructure', 'phase_order' => 1, 'status' => 'active']);
        $estimate = ProjectEstimate::create([
            'estimate_code' => 'EST-SELECT-001', 'project_id' => $project->id, 'client_id' => $client->id,
            'version' => 1, 'status' => 'issued',
        ]);
        $entry = $estimate->entries()->create([
            'project_phase_id' => $phase->id, 'work_area' => 'Main House', 'work_section' => 'Concrete Frame',
            'item_reference' => 'A', 'main_category' => 'materials', 'item_description' => 'Reinforced concrete works',
            'quantity' => 1, 'unit' => 'ls', 'base_unit_cost' => 1000, 'markup_percent' => 0,
        ]);
        $service = app(ClientInvoiceService::class);
        $payload = fn (string $claim): array => [
            'project_estimate_id' => $estimate->id, 'due_at' => now()->addDays(30)->toDateString(),
            'items' => [[
                'selected' => true, 'estimate_entry_id' => $entry->id, 'project_phase_id' => $phase->id,
                'main_category' => 'materials', 'item_description' => $entry->item_description,
                'quantity' => 1, 'unit' => 'ls', 'unit_price' => 1000, 'claim_amount' => $claim,
            ]],
        ];

        $first = $service->createDraft($project, $payload('400.00'), $accountant);
        $this->assertSame('400.00', $first->total_amount);
        $this->assertSame($entry->id, $first->items->first()->estimate_entry_id);

        try {
            $service->createDraft($project, $payload('700.00'), $accountant);
            $this->fail('A claim above the remaining BOQ amount should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $second = $service->createDraft($project, $payload('600.00'), $accountant);
        $this->assertSame('600.00', $second->total_amount);
    }
}
