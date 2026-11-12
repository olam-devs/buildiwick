<?php

namespace Tests\Feature;

use App\Models\ExpenseDraft;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\SheetLine;
use App\Models\User;
use App\Services\ExpenseLedgerService;
use App\Services\GoogleSheets\ProjectSheetTabs;
use App\Services\GoogleSheets\SheetsClient;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SheetLinesSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeSheetGrid $sheets;

    private Project $project;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        config(['buildiwick.mode' => 'recording', 'buildiwick.features' => ['client_billing' => false, 'estimates' => false, 'site_operator' => false, 'approvals' => false]]);
        $this->seed(LookupSeeder::class);
        Role::firstOrCreate(['name' => 'data_entry'], ['description' => 'data entry']);
        $this->project = Project::create(['project_code' => 'BWG-KND-RES-2026-001', 'name' => 'Kinondoni residence', 'status' => 'active', 'location' => 'Dar es Salaam']);
        $this->project->forceFill(['sheet_tab_id' => ProjectSheetTabs::TAB_ID_OFFSET + $this->project->id])->save();
        foreach (['Preliminaries', 'Superstructure'] as $index => $name) {
            $this->project->phases()->create(['stage_code' => strtolower($name), 'phase_name' => $name, 'phase_order' => $index + 1, 'status' => 'active']);
        }
        $this->clerk = User::factory()->create(['role_id' => Role::where('name', 'data_entry')->value('id'), 'username' => 'user', 'is_active' => true]);
        $this->project->assignedUsers()->attach($this->clerk->id);
        $this->sheets = new FakeSheetGrid([['id' => $this->project->sheet_tab_id, 'title' => 'Kinondoni residence']]);
        $this->app->instance(SheetsClient::class, $this->sheets);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_changed_and_deleted_sheet_lines_follow_into_the_draft(): void
    {
        $this->sheets->rows = [
            $this->sheetRow('', 46331, 'Materials', 'Concrete & Cement', 'Cement 42.5', 50, 'BAG', 18500, 925000, 'Preliminaries'),
            array_fill(0, 14, ''),
            $this->sheetRow('', '05/11/2026', 'Materials', '', 'Sand', 1, 'lorry', '', '350,000', 'Superstructure'),
        ];

        $this->artisan('app:sheets-sync')->expectsOutputToContain('Added 2, updated 0, removed 0 draft line(s); 1 need checking.')->assertSuccessful();

        $rows = ExpenseDraft::for($this->clerk, $this->project)->data['rows'];
        $this->assertCount(2, $rows);
        $phases = $this->project->phases()->orderBy('phase_order')->pluck('id');
        $this->assertSame(['2026-11-05', 'materials', 'concrete_cement', 'bag', '18500', '925000', (string) $phases[0]],
            [$rows[0]['expense_date'], $rows[0]['main_category'], $rows[0]['subcategory'], $rows[0]['unit'], $rows[0]['unit_cost'], $rows[0]['total_amount'], $rows[0]['project_phase_id']]);
        $this->assertSame(['2026-11-05', '', '350000'], [$rows[1]['expense_date'], $rows[1]['unit'], $rows[1]['total_amount']]);
        // Row keys go into hidden column A; the Status column says where each line is.
        $this->assertSame([$rows[0]['sheet_key'], 'Draft'], [$this->sheets->cell('A3'), $this->sheets->cell('B3')]);
        $this->assertSame('Needs check: unit "lorry" not in the list', $this->sheets->cell('B5'));

        // Unchanged sheet: nothing happens. A changed cell updates the line; a deleted row leaves the draft.
        $this->artisan('app:sheets-sync')->expectsOutputToContain('Added 0, updated 0, removed 0')->assertSuccessful();
        $this->sheets->rows[0][8] = 60;
        unset($this->sheets->rows[2]);
        $this->artisan('app:sheets-sync')->expectsOutputToContain('Added 0, updated 1, removed 1')->assertSuccessful();
        $rows = ExpenseDraft::for($this->clerk, $this->project)->data['rows'];
        $this->assertSame([1, '60'], [count($rows), $rows[0]['quantity']]);
        $this->assertSame(1, SheetLine::count());
    }

    public function test_an_open_form_keeps_lines_that_arrive_from_the_sheet_and_saved_lines_are_never_added_again(): void
    {
        $this->sheets->rows = [$this->sheetRow('', 46331, 'Materials', '', 'Cement 42.5', 50, 'BAG', 18500, 925000, 'Preliminaries')];
        $phase = (string) $this->project->phases()->orderBy('phase_order')->value('id');
        $typed = ['main_category' => 'materials', 'project_phase_id' => $phase, 'item_description' => 'Nails', 'quantity' => '2', 'unit' => 'kg', 'unit_cost' => '4000'];

        // The page was opened before the sync and knows no sheet line: its autosave keeps the sheet line.
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $this->actingAs($this->clerk)->postJson(route('ledger.draft.save', $this->project), ['header' => ['expense_date' => '2026-11-12'], 'rows' => [$typed], 'known_keys' => []])
            ->assertOk()->assertJson(['saved' => true, 'arrived' => 1]);
        $rows = ExpenseDraft::for($this->clerk, $this->project)->data['rows'];
        $this->assertSame(['Nails', 'Cement 42.5'], array_column($rows, 'item_description'));

        // A page holding an older copy of a sheet line does not overwrite the newer sheet version.
        $stale = $rows[1];
        $this->sheets->rows[0][8] = 55;
        Carbon::setTestNow('2026-11-12 10:05:00');
        $this->artisan('app:sheets-sync')->expectsOutputToContain('updated 1')->assertSuccessful();
        $this->actingAs($this->clerk)->postJson(route('ledger.draft.save', $this->project), ['header' => ['expense_date' => '2026-11-12'], 'rows' => [$typed, $stale], 'known_keys' => [$stale['sheet_key']]])
            ->assertJson(['arrived' => 0]);
        $sheetLine = ExpenseDraft::for($this->clerk, $this->project)->data['rows'][1];
        $this->assertSame('55', $sheetLine['quantity']);

        // Saving it in Buildiwick marks the sheet line saved; the next sync shows the code and does not add it again.
        $this->actingAs($this->clerk)->post(route('ledger.expenses.store', $this->project), ['expense_date' => '2026-11-12', 'status' => 'to_confirm',
            'rows_json' => json_encode([$sheetLine]), 'keep_json' => json_encode([$typed]), 'known_keys_json' => json_encode([$sheetLine['sheet_key']])])->assertSessionHasNoErrors();
        $saved = ExpenseRequest::query()->sole();
        $this->assertSame(['saved', $saved->id], [SheetLine::query()->sole()->state, SheetLine::query()->sole()->expense_request_id]);
        $this->assertSame(['Nails'], array_column(ExpenseDraft::for($this->clerk, $this->project)->data['rows'], 'item_description'));
        $this->artisan('app:sheets-sync')->expectsOutputToContain('Added 0, updated 0, removed 0')->assertSuccessful();
        $this->assertSame('Saved '.$saved->request_code, $this->sheets->cell('B3'));
        $this->assertCount(1, ExpenseDraft::for($this->clerk, $this->project)->data['rows']);
    }

    public function test_a_line_removed_from_the_draft_in_buildiwick_is_not_put_back(): void
    {
        $this->sheets->rows = [$this->sheetRow('', 46331, 'Materials', '', 'Cement 42.5', 50, 'BAG', 18500, 925000, 'Preliminaries')];
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $key = $this->sheets->cell('A3');

        $this->actingAs($this->clerk)->postJson(route('ledger.draft.save', $this->project), ['header' => [], 'rows' => [], 'known_keys' => [$key]])->assertJson(['saved' => false]);
        $this->artisan('app:sheets-sync')->expectsOutputToContain('Added 0')->assertSuccessful();

        $this->assertNull(ExpenseDraft::for($this->clerk, $this->project));
        $this->assertSame('Removed from the draft in Buildiwick', $this->sheets->cell('B3'));
    }

    public function test_the_fill_writes_saved_and_draft_lines_into_the_sheet_and_an_open_page_does_not_double_them(): void
    {
        $phase = (string) $this->project->phases()->orderBy('phase_order')->value('id');
        $saved = app(ExpenseLedgerService::class)->record($this->project, ['expense_date' => '2026-11-03', 'main_category' => 'materials', 'subcategory' => 'concrete_cement',
            'project_phase_id' => $phase, 'item_description' => 'Cement 42.5', 'quantity' => '10', 'unit' => 'bag', 'unit_cost' => '18500', 'details' => 'Site A'], $this->clerk);
        $typed = ['expense_date' => '2026-11-05', 'main_category' => 'materials', 'project_phase_id' => $phase, 'item_description' => 'Nails', 'quantity' => '2', 'unit' => 'kg', 'unit_cost' => '4000', 'total_amount' => '8000'];
        $this->actingAs($this->clerk)->postJson(route('ledger.draft.save', $this->project), ['header' => ['expense_date' => '2026-11-12'], 'rows' => [$typed]])->assertJson(['saved' => true]);

        $this->artisan('app:sheets-sync', ['--with-drafts' => true])->expectsOutputToContain('Written into the sheet: 2 line(s).')->assertSuccessful();

        $this->assertSame(['Saved '.$saved->request_code, 46329, 'Materials', 'Concrete & Cement', 'Cement 42.5', '', '', 10.0, 'BAG', 18500.0, 185000.0, 'Preliminaries', 'Site A'],
            array_slice($this->sheets->rows[0], 1));
        $this->assertSame(['Draft', 'Nails', 'KG'], [$this->sheets->cell('B4'), $this->sheets->cell('F4'), $this->sheets->cell('J4')]);
        $key = ExpenseDraft::for($this->clerk, $this->project)->data['rows'][0]['sheet_key'];
        $this->assertSame($this->sheets->cell('A4'), $key);

        // Reading it back changes nothing, and the 5-minute run does not write the lines again.
        $this->artisan('app:sheets-sync')->expectsOutputToContain('Added 0, updated 0, removed 0')->doesntExpectOutputToContain('Written into the sheet')->assertSuccessful();

        // The page opened before the fill sends the line without its key: it keeps the key, with no copy added.
        $this->actingAs($this->clerk)->postJson(route('ledger.draft.save', $this->project), ['header' => ['expense_date' => '2026-11-12'], 'rows' => [$typed], 'known_keys' => []])->assertJson(['arrived' => 0]);
        $this->assertSame([$key], array_column(ExpenseDraft::for($this->clerk, $this->project)->data['rows'], 'sheet_key'));

        // Saved from that page, the sheet line becomes Saved and is not written again.
        $this->actingAs($this->clerk)->post(route('ledger.expenses.store', $this->project), ['expense_date' => '2026-11-12', 'status' => 'to_confirm', 'rows_json' => json_encode([$typed])])->assertSessionHasNoErrors();
        $this->assertNull(ExpenseDraft::for($this->clerk, $this->project));
        $this->artisan('app:sheets-sync')->doesntExpectOutputToContain('Written into the sheet')->assertSuccessful();
        $this->assertStringStartsWith('Saved KND-RES-TXN-0002', $this->sheets->cell('B4'));
        $this->assertCount(2, $this->sheets->rows);
    }

    /** @return list<mixed> columns A to N of the project tab */
    private function sheetRow(string $key, mixed $date, string $category, string $subcategory, string $item, mixed $qty, string $unit, mixed $cost, mixed $total, string $phase): array
    {
        return [$key, '', $date, $category, $subcategory, $item, 'ABC Hardware', '', $qty, $unit, $cost, $total, $phase, ''];
    }
}

/** Stands in for Google: one project tab held as a grid from row 3, recording what the sync writes back. */
class FakeSheetGrid extends SheetsClient
{
    /** @var list<list<mixed>> */
    public array $rows = [];

    /** @param  list<array{id: int, title: string}>  $tabList */
    public function __construct(private array $tabList) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function tabs(): array
    {
        return $this->tabList;
    }

    public function readValues(array $ranges): array
    {
        return array_map(fn () => array_values($this->rows), $ranges);
    }

    public function writeValues(array $data): void
    {
        $this->rows = array_values($this->rows);
        foreach ($data as $range => $values) {
            preg_match('/!([A-Z])(\d+)(?::[A-Z]\d+)?$/', $range, $match);
            foreach ($values as $r => $cells) {
                foreach ($cells as $c => $value) {
                    $this->rows[(int) $match[2] - 3 + $r][ord($match[1]) - ord('A') + $c] = $value;
                }
                ksort($this->rows[(int) $match[2] - 3 + $r]);
            }
        }
    }

    public function cell(string $cell): mixed
    {
        return array_values($this->rows)[(int) substr($cell, 1) - 3][ord($cell[0]) - ord('A')] ?? null;
    }
}
