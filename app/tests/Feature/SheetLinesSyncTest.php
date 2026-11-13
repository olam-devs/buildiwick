<?php

namespace Tests\Feature;

use App\Models\ExpenseDraft;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\SheetLine;
use App\Models\User;
use App\Services\ExpenseLedgerService;
use App\Services\GoogleSheets\ProjectSheetLayout;
use App\Services\GoogleSheets\ProjectSheetLines;
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
        $this->assertSame('Saved '.$saved->request_code.' · can be changed until confirmed', $this->sheets->cell('B3'));
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

        $this->assertSame(['Saved '.$saved->request_code.' · can be changed until confirmed', 46329, 'Materials', 'Concrete & Cement', 'Cement 42.5', '', '', 10.0, 'BAG', 18500.0, 185000.0, 'Preliminaries', 'Site A'],
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

    public function test_saved_lines_lock_once_data_entry_can_no_longer_change_them_with_hard_row_protection(): void
    {
        $phase = (string) $this->project->phases()->orderBy('phase_order')->value('id');
        $ledger = app(ExpenseLedgerService::class);
        // Recorded by the Director (paid at once): not Data Entry's to change, so locked in the sheet.
        $director = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'admin'], ['description' => 'admin'])->id, 'is_active' => true]);
        $old = $ledger->record($this->project, ['expense_date' => '2026-11-10', 'main_category' => 'materials', 'project_phase_id' => $phase, 'item_description' => 'Sand', 'unit_cost' => '300000'], $director);
        $ledger->record($this->project, ['expense_date' => '2026-11-12', 'main_category' => 'materials', 'project_phase_id' => $phase, 'item_description' => 'Cement', 'unit_cost' => '18500'], $this->clerk);
        $boss = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'accounts'], ['description' => 'accounts'])->id, 'email' => 'Boss@Example.test', 'is_active' => true,
            'permissions' => ['mark_paid', 'edit_locked_sheet_lines']]);

        $this->artisan('app:sheets-sync')->expectsOutputToContain('Locked lines: 1.')->assertSuccessful();

        $this->assertSame(['Locked '.$old->request_code, 'Saved KND-RES-TXN-0002 · can be changed until confirmed'], [$this->sheets->cell('B3'), $this->sheets->cell('B4')]);
        $locks = array_values(array_filter($this->sheets->requests, fn ($r) => ($r['addProtectedRange']['protectedRange']['description'] ?? null) === ProjectSheetLines::LOCK_DESCRIPTION));
        $this->assertCount(1, $locks);
        $range = $locks[0]['addProtectedRange']['protectedRange'];
        $this->assertSame([2, 3, false, ['boss@example.test', 'robot@example.test']], [$range['range']['startRowIndex'], $range['range']['endRowIndex'], $range['warningOnly'], $range['editors']['users']]);

        // Unchanged: the protection is not rebuilt. Taking the permission away rebuilds it without that editor.
        $this->sheets->requests = [];
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $this->assertSame([], $this->sheets->requests);
        $boss->update(['permissions' => ['mark_paid']]);
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $this->assertSame(['deleteProtectedRange', 'addProtectedRange'], array_map(fn ($r) => array_key_first($r), $this->sheets->requests));
        $this->assertSame(['robot@example.test'], $this->sheets->requests[1]['addProtectedRange']['protectedRange']['editors']['users']);
    }

    public function test_a_sheet_change_to_a_saved_line_waits_for_apply_or_undo_and_buildiwick_edits_reach_the_sheet(): void
    {
        $phase = (string) $this->project->phases()->orderBy('phase_order')->value('id');
        $saved = app(ExpenseLedgerService::class)->record($this->project, ['expense_date' => '2026-11-12', 'main_category' => 'materials', 'project_phase_id' => $phase,
            'item_description' => 'Cement', 'quantity' => '10', 'unit' => 'bag', 'unit_cost' => '18500'], $this->clerk);
        $this->artisan('app:sheets-sync')->assertSuccessful();

        // Typed in the sheet: not applied, shown as waiting.
        $this->sheets->rows[0][8] = 12;
        $this->sheets->rows[0][11] = 222000;
        $this->artisan('app:sheets-sync')->expectsOutputToContain('waiting for approval: 1')->assertSuccessful();
        $this->assertSame('10.00', $saved->fresh()->quantity);
        $this->assertStringEndsWith('sheet change waiting for approval in Buildiwick', $this->sheets->cell('B3'));
        $changes = $this->actingAs($this->clerk)->postJson(route('ledger.draft.review', $this->project), ['rows' => []])->json('sheet_changes');
        $this->assertSame([['field' => 'Quantity', 'from' => '10', 'to' => '12'], ['field' => 'Total (TSh)', 'from' => '185,000.00', 'to' => '222,000.00']], $changes[0]['changes']);
        $this->assertTrue($changes[0]['can_act']);

        // Apply goes through the normal edit.
        $this->actingAs($this->clerk)->postJson(route('ledger.sheet-changes.apply', [$this->project, $changes[0]['id']]))->assertOk();
        $this->assertSame(['12.00', '222000.00'], [$saved->fresh()->quantity, $saved->fresh()->total_amount]);
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $this->assertSame('Saved '.$saved->request_code.' · can be changed until confirmed', $this->sheets->cell('B3'));

        // Undo puts the saved values back into the sheet. (The Director may still act on a line Data Entry edited.)
        $director = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'admin'], ['description' => 'admin'])->id, 'is_active' => true]);
        $this->sheets->rows[0][5] = 'Cement 42.5R';
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $id = $this->actingAs($director)->postJson(route('ledger.draft.review', $this->project), ['rows' => []])->json('sheet_changes.0.id');
        $this->actingAs($director)->postJson(route('ledger.sheet-changes.undo', [$this->project, $id]))->assertOk();
        $this->artisan('app:sheets-sync')->expectsOutputToContain('written to the sheet: 1')->assertSuccessful();
        $this->assertSame('Cement', $this->sheets->cell('F3'));

        // Edited in Buildiwick: the sheet row follows.
        app(ExpenseLedgerService::class)->update($saved->fresh(), ['expense_date' => '2026-11-12', 'main_category' => 'materials', 'project_phase_id' => $phase,
            'item_description' => 'Cement bags', 'quantity' => '12', 'unit' => 'bag', 'unit_cost' => '18500', 'total_amount' => '222000'], $director);
        $this->artisan('app:sheets-sync')->expectsOutputToContain('written to the sheet: 1')->assertSuccessful();
        $this->assertSame('Cement bags', $this->sheets->cell('F3'));
    }

    public function test_a_draft_line_changed_in_the_buildiwick_form_is_written_to_the_sheet(): void
    {
        $this->sheets->rows = [$this->sheetRow('', 46331, 'Materials', '', 'Cement 42.5', 50, 'BAG', 18500, 925000, 'Preliminaries')];
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $row = ExpenseDraft::for($this->clerk, $this->project)->data['rows'][0];

        $this->actingAs($this->clerk)->postJson(route('ledger.draft.save', $this->project), ['header' => ['expense_date' => '2026-11-12'],
            'rows' => [array_merge($row, ['quantity' => '40', 'total_amount' => '740000'])], 'known_keys' => [$row['sheet_key']]])->assertOk();
        $this->artisan('app:sheets-sync')->expectsOutputToContain('written to the sheet: 1')->assertSuccessful();

        $this->assertSame([40.0, 740000.0, 'Draft'], [$this->sheets->cell('I3'), $this->sheets->cell('L3'), $this->sheets->cell('B3')]);
        $this->artisan('app:sheets-sync')->expectsOutputToContain('Added 0, updated 0, removed 0')->doesntExpectOutputToContain('written to the sheet')->assertSuccessful();
    }

    public function test_the_total_becomes_quantity_times_unit_cost_in_the_sheet(): void
    {
        $this->sheets->rows = [
            $this->sheetRow('', 46331, 'Materials', '', 'Cement', 50, 'BAG', 18500, '', 'Preliminaries'),
            $this->sheetRow('', 46331, 'Materials', '', 'Sand', 2, 'TRIP', 1000, 2000, 'Preliminaries'),
            $this->sheetRow('', 46331, 'Materials', '', 'Lump sum', '', '', '', 50000, 'Preliminaries'),
        ];

        // A line typed without a total gets the formula; one with its own total keeps it on the 5-minute run.
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $this->assertSame(['L3' => '=IF(K3="","",ROUND(IF(I3="",1,I3)*K3,2))'], $this->sheets->formulas);

        // The one-time run turns matching totals into formulas too, never a total typed on its own.
        $this->sheets->formulas = [];
        $this->artisan('app:sheets-sync', ['--total-formulas' => true])->assertSuccessful();
        $this->assertSame(['L3', 'L4'], array_keys($this->sheets->formulas));
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
        // The "+ New project" template only has its row 1; every other range is the project tab.
        return array_map(fn (string $range) => str_contains($range, ProjectSheetLayout::TEMPLATE_TITLE)
            ? [['', ProjectSheetLayout::NAME_LABEL]] : array_values($this->rows), $ranges);
    }

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var array<int, list<array{id: int, start: int, end: int, editors: list<string>}>> */
    private array $protections = [];

    private int $nextProtection = 1;

    public function batchUpdate(array $requests): void
    {
        foreach ($requests as $request) {
            $this->requests[] = $request;
            if (isset($request['addSheet'])) {
                $this->tabList[] = ['id' => $request['addSheet']['properties']['sheetId'], 'title' => $request['addSheet']['properties']['title']];
            }
            if (isset($request['deleteProtectedRange'])) {
                foreach ($this->protections as $sheet => $ranges) {
                    $this->protections[$sheet] = array_values(array_filter($ranges, fn ($r) => $r['id'] !== $request['deleteProtectedRange']['protectedRangeId']));
                }
            }
            if (($request['addProtectedRange']['protectedRange']['description'] ?? null) === ProjectSheetLines::LOCK_DESCRIPTION) {
                $range = $request['addProtectedRange']['protectedRange'];
                $this->protections[$range['range']['sheetId']][] = ['id' => $this->nextProtection++, 'start' => $range['range']['startRowIndex'],
                    'end' => $range['range']['endRowIndex'], 'editors' => [...$range['editors']['users'], 'owner@example.test']];
            }
        }
    }

    public function protectedRanges(string $description): array
    {
        return $this->protections;
    }

    public function serviceAccountEmail(): string
    {
        return 'robot@example.test';
    }

    /** @var array<string, string> formulas written, by cell */
    public array $formulas = [];

    public function writeValues(array $data, string $input = 'RAW'): void
    {
        if ($input === 'USER_ENTERED') {
            foreach ($data as $range => $values) {
                $this->formulas[substr($range, strrpos($range, '!') + 1)] = $values[0][0];
            }

            return;
        }
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
