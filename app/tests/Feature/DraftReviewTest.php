<?php

namespace Tests\Feature;

use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\ExpenseLedgerService;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DraftReviewTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private string $phase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        config(['buildiwick.mode' => 'recording', 'buildiwick.features' => ['client_billing' => false, 'estimates' => false, 'site_operator' => false, 'approvals' => false]]);
        $this->seed(LookupSeeder::class);
        foreach (['data_entry', 'project_manager'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
        $this->project = Project::create(['project_code' => 'BWG-KND-RES-2026-001', 'name' => 'Kinondoni residence', 'status' => 'active', 'location' => 'Dar es Salaam']);
        $this->phase = (string) $this->project->phases()->create(['stage_code' => 'pre', 'phase_name' => 'Preliminaries', 'phase_order' => 1, 'status' => 'active'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_review_groups_problems_with_the_rows_they_concern(): void
    {
        $clerk = $this->user('data_entry');
        app(ExpenseLedgerService::class)->record($this->project, $this->line(['item_description' => 'Blocks procurement', 'subcategory' => 'masonry', 'total_amount' => '850000', 'expense_date' => '2026-11-04']), $clerk);
        $rows = [
            $this->line(['expense_date' => '2026-11-01']),
            $this->line(['expense_date' => '2026-04-08', 'item_description' => 'Sand']),
            $this->line(['expense_date' => '2026-11-05', 'unit_cost' => '', 'total_amount' => '']),
            $this->line(['expense_date' => '2026-11-06', 'unit' => 'lorry', 'quantity' => '2', 'unit_cost' => '1000', 'total_amount' => '2500']),
            $this->line(['expense_date' => '2026-11-06', 'item_description' => '500pcs of blocks', 'subcategory' => 'masonry', 'total_amount' => '850000', 'unit_cost' => '']),
            [],
            $this->line(['expense_date' => '2026-11-07', 'details' => '#REF!']),
            $this->line(['expense_date' => '2026-11-07', 'details' => '#REF!']),
        ];

        $review = $this->actingAs($clerk)->postJson(route('ledger.draft.review', $this->project), ['expense_date' => '2026-11-12', 'rows' => $rows])->assertOk()->json();

        $groups = collect($review['groups'])->keyBy('code');
        $this->assertSame([2], array_column($groups['no_price']['rows'], 'index'));
        $this->assertSame('error', $groups['no_price']['severity']);
        $this->assertSame('08 Apr 2026 may be 04 Aug 2026', $groups['flipped_date']['rows'][0]['note']);
        $this->assertSame('"lorry" is not a unit in the system', $groups['unknown_unit']['rows'][0]['note']);
        $this->assertSame('2 × 1,000.00 = 2,000.00, but the total is 2,500.00', $groups['sum_mismatch']['rows'][0]['note']);
        $this->assertStringStartsWith('Like saved KND-RES-TXN-0001', $groups['possible_double']['rows'][0]['note']);
        $this->assertSame([6, 7], array_column($groups['error_value']['rows'], 'index'));
        $this->assertSame([6, 7], array_column($groups['duplicate_in_draft']['rows'], 'index'));
        $this->assertSame(4, $review['errors']);
        $this->assertSame('error', $review['groups'][0]['severity']);

        // A project manager may leave the price for later: the same line is only a warning.
        $pm = $this->user('project_manager');
        $pmReview = $this->actingAs($pm)->postJson(route('ledger.draft.review', $this->project), ['rows' => [$rows[2]]])->json();
        $this->assertSame(['warning', 0], [$pmReview['groups'][0]['severity'], $pmReview['errors']]);
    }

    public function test_hard_errors_stop_the_save_and_warnings_do_not(): void
    {
        $clerk = $this->user('data_entry');
        $save = fn (array $rows) => $this->actingAs($clerk)->post(route('ledger.expenses.store', $this->project), ['expense_date' => '2026-11-12', 'status' => 'to_confirm', 'rows_json' => json_encode($rows)]);

        $save([$this->line(), $this->line(['unit_cost' => '', 'total_amount' => '', 'item_description' => 'Sand'])])
            ->assertSessionHasErrors(['rows' => 'Item 2: No price (No unit cost or total).']);
        $save([$this->line(['unit' => 'lorry'])])->assertSessionHasErrors('rows');
        $this->assertSame(0, ExpenseRequest::count());

        $save([$this->line(['quantity' => '2', 'unit_cost' => '1000', 'total_amount' => '2500'])])->assertSessionHasNoErrors();
        $this->assertSame(1, ExpenseRequest::count());
    }

    /** @return array<string, string> */
    private function line(array $override = []): array
    {
        return array_merge(['expense_date' => '2026-11-10', 'main_category' => 'materials', 'project_phase_id' => $this->phase, 'item_description' => 'Cement 42.5',
            'subcategory' => 'concrete_cement', 'quantity' => '1', 'unit' => 'bag', 'unit_cost' => '18500', 'total_amount' => ''], $override);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'is_active' => true, 'username' => $role === 'data_entry' ? 'clerk' : null]);
        $this->project->assignedUsers()->attach($user->id);

        return $user;
    }
}
