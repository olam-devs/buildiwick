<?php

namespace Tests\Feature;

use App\Models\ExpenseDraft;
use App\Models\Project;
use App\Models\Role;
use App\Models\SheetTabRequest;
use App\Models\User;
use App\Services\GoogleSheets\ProjectSheetLayout;
use App\Services\GoogleSheets\ProjectSheetTabs;
use App\Services\GoogleSheets\SheetsClient;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SheetTabRequestsTest extends TestCase
{
    use RefreshDatabase;

    private TabbedSheet $sheets;

    private User $clerk;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        config(['buildiwick.mode' => 'recording', 'buildiwick.features' => ['client_billing' => false, 'estimates' => false, 'site_operator' => false, 'approvals' => false]]);
        $this->seed(LookupSeeder::class);
        $this->clerk = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'data_entry'], ['description' => 'data entry'])->id, 'username' => 'user', 'is_active' => true, 'all_projects' => false]);
        $this->director = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'admin'], ['description' => 'admin'])->id, 'is_active' => true]);
        $this->sheets = new TabbedSheet;
        $this->sheets->add(0, 'How to use');
        $this->app->instance(SheetsClient::class, $this->sheets);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_tab_she_adds_waits_for_approval_and_its_lines_reach_the_draft_once_the_project_is_created(): void
    {
        // First run: the "+ New project" template appears, with the standard phases in its Phase dropdown.
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $this->assertContains(ProjectSheetLayout::TEMPLATE_TITLE, $this->sheets->titles());
        $this->assertSame(ProjectSheetTabs::standardPhases(), $this->sheets->dropdown(ProjectSheetLayout::TEMPLATE_TAB_ID, 12));
        // Row 1 is protected except the code (C1) and location (E1) cells; no protection uses open cells inside it.
        $this->assertSame([[0, 1, 0, 2], [0, 1, 3, 4], [0, 1, 5, 14]], $this->sheets->protectedRow1(ProjectSheetLayout::TEMPLATE_TAB_ID));

        // She duplicates it, renames it, types the code and location in row 1 and two lines, before anyone approves it.
        $headers = array_column(ProjectSheetLayout::COLUMNS, 'header');
        $this->sheets->add(555, 'Msasani villa', [
            ['', 'Project code →', 'bwg-msv-2026-001 ', 'Location →', '', ProjectSheetLayout::templateHeading()], $headers,
            ['', '', 46337, 'Materials', '', 'Cement 42.5', '', '', 10, 'BAG', 18500, 185000, 'Substructure (Foundation)', ''],
            ['', '', 46337, 'Materials', '', 'Sand', '', '', 1, '', '', 350000, 'Walling', ''],
        ]);
        $this->artisan('app:sheets-sync')->expectsOutputToContain('New project tabs waiting for approval: Msasani villa')->assertSuccessful();

        $tab = SheetTabRequest::sole();
        $this->assertSame(['pending', 2, true, 'BWG-MSV-2026-001', null], [$tab->status, $tab->line_count, $tab->layout_ok, $tab->project_code, $tab->location]);
        // Row 1 tells her what is still missing; nothing reaches the draft yet.
        $this->assertSame(ProjectSheetLayout::waitingHeading('Type the location in the yellow cell E1.'), $this->sheets->cell(555, 'F1'));
        $this->assertSame(0, ExpenseDraft::query()->count());
        $this->actingAs($this->director)->get(route('admin.sheet-tabs.index'))->assertSee('Fill in details')->assertDontSee(route('admin.sheet-tabs.approve', $tab));

        $this->sheets->put(555, 'E1', 'Msasani');
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $this->assertSame(ProjectSheetLayout::waitingHeading(), $this->sheets->cell(555, 'F1'));

        // The Director sees it and approves it in one click.
        $this->actingAs($this->director)->get(route('director.index'))->assertSee('waiting for your approval');
        $this->actingAs($this->director)->get(route('admin.sheet-tabs.index'))->assertOk()->assertSee('Msasani villa')->assertSee('BWG-MSV-2026-001')->assertSee(route('admin.sheet-tabs.approve', $tab));
        $this->actingAs($this->director)->get(route('admin.projects.create', ['sheet_tab' => $tab->id]))->assertOk()->assertSee('value="BWG-MSV-2026-001"', false)->assertSee('value="Msasani"', false);
        $this->actingAs($this->director)->post(route('admin.sheet-tabs.approve', $tab))->assertRedirect();

        $project = Project::where('project_code', 'BWG-MSV-2026-001')->sole();
        $this->assertSame([555, 'Msasani villa', 'Msasani', 'active'], [(int) $project->sheet_tab_id, $project->name, $project->location, $project->status]);
        $this->assertSame(ProjectSheetTabs::standardPhases(), $project->phases()->orderBy('phase_order')->pluck('phase_name')->all());
        $this->assertSame(['approved', $project->id], [$tab->fresh()->status, $tab->fresh()->project_id]);
        $this->assertTrue($project->assignedUsers()->whereKey($this->clerk->id)->exists());
        $this->assertSame(ProjectSheetLayout::heading($project), $this->sheets->cell(555, 'B1'));

        // The next sync treats it like every other project tab: both lines go into her draft with their phases.
        $this->artisan('app:sheets-sync')->expectsOutputToContain('Added 2')->assertSuccessful();
        $rows = ExpenseDraft::for($this->clerk, $project)->data['rows'];
        $this->assertSame(['Cement 42.5', 'Sand'], array_column($rows, 'item_description'));
        $this->assertSame($project->phases()->whereIn('phase_name', ['Substructure (Foundation)', 'Walling'])->orderBy('phase_order')->pluck('id')->map(fn ($id) => (string) $id)->all(),
            array_map('strval', array_column($rows, 'project_phase_id')));
        $this->assertCount(3, $this->sheets->titles());
    }

    public function test_the_director_can_correct_the_details_in_the_form_before_approving(): void
    {
        $this->sheets->add(556, 'Mbezi beach', [['', 'Project code →', 'BWG KND', 'Location →', 'Mbezi'], array_column(ProjectSheetLayout::COLUMNS, 'header')]);
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $tab = SheetTabRequest::sole();
        $this->assertStringContainsString('capital letters, numbers and dashes', $tab->detailsProblem());

        $this->actingAs($this->director)->post(route('admin.sheet-tabs.approve', $tab))->assertRedirect(route('admin.projects.create', ['sheet_tab' => $tab->id]));
        $this->assertSame(0, Project::query()->count());
        $this->actingAs($this->director)->post(route('admin.projects.store'), [
            'project_code' => 'BWG-MBZ-2026-001', 'name' => 'Mbezi beach house', 'status' => 'active', 'location' => 'Mbezi', 'standard_stages' => '1',
            'sheet_tab_request_id' => $tab->id,
        ])->assertRedirect();
        $this->assertSame(556, (int) Project::sole()->sheet_tab_id);
        $this->assertSame('approved', $tab->fresh()->status);
    }

    public function test_a_tab_marked_as_not_a_project_is_never_read_and_only_the_director_decides(): void
    {
        $this->sheets->add(777, 'Scratch');
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $tab = SheetTabRequest::sole();
        // A blank tab is laid out like a project tab so she can type in it.
        $this->assertSame('Item / Description', $this->sheets->cell(777, 'F2'));

        $this->actingAs($this->clerk)->get(route('admin.sheet-tabs.index'))->assertForbidden();
        $this->actingAs($this->clerk)->post(route('admin.sheet-tabs.reject', $tab))->assertForbidden();

        $this->actingAs($this->director)->post(route('admin.sheet-tabs.reject', $tab))->assertRedirect();
        $this->assertSame('rejected', $tab->fresh()->status);
        $this->assertSame(ProjectSheetLayout::rejectedHeading(), $this->sheets->cell(777, 'F1'));
        $this->actingAs($this->director)->get(route('director.index'))->assertDontSee('waiting for your approval');

        // Deleting the tab from the sheet forgets it.
        $this->sheets->remove(777);
        $this->artisan('app:sheets-sync')->assertSuccessful();
        $this->assertSame(0, SheetTabRequest::query()->count());
    }
}

/** A spreadsheet with real tabs and cells, enough for tab detection and the line sync. */
class TabbedSheet extends SheetsClient
{
    /** @var array<int, array{id: int, title: string, rows: int}> */
    private array $tabList = [];

    /** @var array<int, array<int, array<int, mixed>>> cells per tab id: row index => column index => value */
    private array $grids = [];

    public function add(int $id, string $title, array $rows = []): void
    {
        $this->tabList[$id] = ['id' => $id, 'title' => $title, 'rows' => 1000];
        $this->grids[$id] = $rows;
    }

    public function remove(int $id): void
    {
        unset($this->tabList[$id], $this->grids[$id]);
    }

    /** @return list<string> */
    public function titles(): array
    {
        return array_values(array_column($this->tabList, 'title'));
    }

    public function cell(int $id, string $a1): mixed
    {
        preg_match('/^([A-Z])(\d+)$/', $a1, $m);

        return $this->grids[$id][(int) $m[2] - 1][ord($m[1]) - 65] ?? null;
    }

    public function put(int $id, string $a1, mixed $value): void
    {
        preg_match('/^([A-Z])(\d+)$/', $a1, $m);
        $this->grids[$id][(int) $m[2] - 1][ord($m[1]) - 65] = $value;
    }

    /** @var array<int, list<array<string, int>>> protected ranges per tab id */
    private array $protections = [];

    /** @return list<list<int>> protected ranges touching row 1: start row, end row, start column, end column */
    public function protectedRow1(int $id): array
    {
        $ranges = array_filter($this->protections[$id] ?? [], fn (array $r) => $r['startRowIndex'] === 0 && $r['endRowIndex'] <= 1 || ($r['startRowIndex'] === 0 && $r['startColumnIndex'] === 0 && $r['endColumnIndex'] === 2));
        $rows = array_map(fn (array $r) => [$r['startRowIndex'], min(1, $r['endRowIndex']), $r['startColumnIndex'], $r['endColumnIndex']], array_values($ranges));
        sort($rows);

        return $rows;
    }

    /** @var array<int, array<int, list<string>>> dropdown values per tab id and column */
    private array $dropdowns = [];

    /** @return list<string> */
    public function dropdown(int $id, int $column): array
    {
        return $this->dropdowns[$id][$column] ?? [];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function serviceAccountEmail(): string
    {
        return 'robot@example.test';
    }

    public function tabs(): array
    {
        return array_values($this->tabList);
    }

    public function protectedRanges(string $description): array
    {
        return [];
    }

    public function readValues(array $ranges): array
    {
        return array_map(function (string $range) {
            [$id, $row] = $this->locate($range);
            $grid = $this->grids[$id] ?? [];
            ksort($grid);
            $out = [];
            for ($r = $row; $r <= (array_key_last($grid) ?? -1); $r++) {
                $cells = $grid[$r] ?? [];
                $line = [];
                for ($c = 0; $c <= (empty($cells) ? -1 : max(array_keys($cells))); $c++) {
                    $line[] = $cells[$c] ?? '';
                }
                $out[] = $line;
            }

            return $out;
        }, $ranges);
    }

    public function writeValues(array $data, string $input = 'RAW'): void
    {
        foreach ($data as $range => $values) {
            [$id, $row, $column] = $this->locate($range);
            foreach ($values as $r => $cells) {
                foreach ($cells as $c => $value) {
                    $this->grids[$id][$row + $r][$column + $c] = $value;
                }
            }
        }
    }

    public function batchUpdate(array $requests): void
    {
        foreach ($requests as $request) {
            if (isset($request['addSheet'])) {
                $this->add($request['addSheet']['properties']['sheetId'], $request['addSheet']['properties']['title']);
            }
            if (isset($request['updateSheetProperties']['properties']['title'])) {
                $this->tabList[$request['updateSheetProperties']['properties']['sheetId']]['title'] = $request['updateSheetProperties']['properties']['title'];
            }
            if (isset($request['addProtectedRange'])) {
                $protected = $request['addProtectedRange']['protectedRange'];
                if (isset($protected['unprotectedRanges'])) {
                    throw new \RuntimeException('unprotectedRanges are only allowed on protections covering a whole sheet');
                }
                $this->protections[$protected['range']['sheetId']][] = $protected['range'];
            }
            if (isset($request['setDataValidation'])) {
                $range = $request['setDataValidation']['range'];
                $this->dropdowns[$range['sheetId']][$range['startColumnIndex']] = array_column($request['setDataValidation']['rule']['condition']['values'] ?? [], 'userEnteredValue');
            }
            if (isset($request['updateCells']['start'])) {
                $start = $request['updateCells']['start'];
                foreach ($request['updateCells']['rows'] as $r => $row) {
                    foreach ($row['values'] as $c => $value) {
                        $this->grids[$start['sheetId']][$start['rowIndex'] + $r][$start['columnIndex'] + $c] = $value['userEnteredValue']['stringValue'] ?? '';
                    }
                }
            }
        }
    }

    /** @return array{0: int, 1: int, 2: int} tab id, first row index, first column index */
    private function locate(string $range): array
    {
        preg_match("/^'?(.*?)'?!([A-Z])(\\d+)/", $range, $m);
        $title = str_replace("''", "'", $m[1]);
        $id = collect($this->tabList)->firstWhere('title', $title)['id'] ?? -1;

        return [$id, (int) $m[3] - 1, ord($m[2]) - 65];
    }
}
