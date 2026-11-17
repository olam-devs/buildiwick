<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\GoogleSheets\ProjectSheetTabs;
use App\Services\GoogleSheets\SheetsClient;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSheetTabsTest extends TestCase
{
    use RefreshDatabase;

    private FakeSheetsClient $sheets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        $this->sheets = new FakeSheetsClient;
        $this->app->instance(SheetsClient::class, $this->sheets);
    }

    public function test_each_open_project_gets_one_set_up_tab_and_a_second_run_adds_nothing(): void
    {
        $kinondoni = $this->project('BWG-KND-RES-2026-001', 'Kinondoni residence', ['Preliminaries', 'Superstructure']);
        $this->project('BWG-OVH-2026-001', 'Company overheads', [], ['is_company_overheads' => true]);
        $this->project('BWG-OLD-2026-001', 'Finished house', [], ['status' => 'completed']);

        $this->artisan('app:sheets-tabs')->expectsOutputToContain('Created (1): Kinondoni residence')->assertSuccessful();

        // The "+ New project" template is made once, for tabs Data Entry adds herself.
        $this->assertSame(['How to use', '+ New project', 'Kinondoni residence'], array_column($this->sheets->tabs(), 'title'));
        $this->assertSame(ProjectSheetTabs::TAB_ID_OFFSET + $kinondoni->id, $kinondoni->fresh()->sheet_tab_id);

        $requests = $this->sheets->requests;
        $this->assertSame(['Preliminaries', 'Superstructure'], $this->dropdown($requests, 12));
        $this->assertContains('GAL', $this->dropdown($requests, 9));
        $this->assertContains('Materials', $this->dropdown($requests, 3));
        $protections = array_values(array_filter($requests, fn ($r) => isset($r['addProtectedRange']) && $r['addProtectedRange']['protectedRange']['range']['sheetId'] === $kinondoni->fresh()->sheet_tab_id));
        $this->assertCount(2, $protections);
        $this->assertFalse($protections[0]['addProtectedRange']['protectedRange']['warningOnly']);
        $this->assertSame(['robot@example.test'], $protections[0]['addProtectedRange']['protectedRange']['editors']['users']);

        $this->sheets->requests = [];
        $this->artisan('app:sheets-tabs')->expectsOutputToContain('Created (0)')->expectsOutputToContain('Lists refreshed (1): Kinondoni residence')->assertSuccessful();
        $this->assertEmpty(array_filter($this->sheets->requests, fn ($r) => isset($r['addSheet']) || isset($r['addProtectedRange'])));
    }

    public function test_a_renamed_project_renames_its_tab_and_new_phases_reach_the_dropdown(): void
    {
        $project = $this->project('BWG-MBO-2026-001', 'Galco Mbozi', ['Roofing']);
        $this->artisan('app:sheets-tabs')->assertSuccessful();

        $project->update(['name' => 'Galco Mbozi gate']);
        $project->phases()->create(['stage_code' => 'finishing', 'phase_name' => 'Finishing', 'phase_order' => 2, 'status' => 'not_started']);
        $this->sheets->requests = [];

        $this->artisan('app:sheets-tabs')->expectsOutputToContain('Renamed (1): Galco Mbozi → Galco Mbozi gate')->assertSuccessful();
        $this->assertSame(['How to use', '+ New project', 'Galco Mbozi gate'], array_column($this->sheets->tabs(), 'title'));
        $this->assertSame(['Roofing', 'Finishing'], $this->dropdown($this->sheets->requests, 12));
    }

    public function test_a_tab_renamed_in_the_sheet_renames_the_project_but_not_its_code(): void
    {
        $project = $this->project('BWG-MBO-2026-001', 'Galco Mbozi', ['Roofing']);
        $this->project('BWG-KND-RES-2026-001', 'Kinondoni residence', ['Roofing']);
        $this->artisan('app:sheets-tabs')->assertSuccessful();
        $tab = $project->fresh()->sheet_tab_id;

        // Data Entry renames the tab: the project takes the name (spaces tidied), the code stays, the heading follows.
        $this->sheets->retitle($tab, '  Galco   Mbozi  gate ');
        $this->sheets->requests = [];
        $this->artisan('app:sheets-tabs')->expectsOutputToContain('Project renamed from the sheet (1): Galco Mbozi → Galco Mbozi gate')->assertSuccessful();
        $this->assertSame(['Galco Mbozi gate', 'BWG-MBO-2026-001'], [$project->fresh()->name, $project->fresh()->project_code]);
        $this->assertContains('  Galco   Mbozi  gate ', array_column($this->sheets->tabs(), 'title'));
        $headings = array_column(array_column(array_filter($this->sheets->requests, fn ($r) => isset($r['updateCells'])), 'updateCells'), 'rows');
        $this->assertStringContainsString('Galco Mbozi gate · BWG-MBO-2026-001', json_encode($headings, JSON_UNESCAPED_UNICODE));
        $this->artisan('app:sheets-tabs')->expectsOutputToContain('Lists refreshed (2)')->assertSuccessful();

        // A name another project has, or a default Google name, is not taken: the tab is renamed back.
        $this->sheets->retitle($tab, 'kinondoni Residence');
        $this->artisan('app:sheets-tabs')->expectsOutputToContain('Renamed (1): kinondoni Residence → Galco Mbozi gate')->assertSuccessful();
        $this->sheets->retitle($tab, 'Sheet7');
        $this->artisan('app:sheets-tabs')->expectsOutputToContain('Renamed (1): Sheet7 → Galco Mbozi gate')->assertSuccessful();
        $this->assertSame('Galco Mbozi gate', $project->fresh()->name);

        // Renamed in Buildiwick and in the sheet before the next sync: Buildiwick's name wins.
        $project->update(['name' => 'Galco Mbozi depot']);
        $this->sheets->retitle($tab, 'Mbozi something');
        $this->artisan('app:sheets-tabs')->expectsOutputToContain('Renamed (1): Mbozi something → Galco Mbozi depot')->assertSuccessful();
        $this->assertSame('Galco Mbozi depot', $project->fresh()->name);
    }

    /** @return list<string> */
    private function dropdown(array $requests, int $column): array
    {
        foreach (array_reverse($requests) as $request) {
            $rule = $request['setDataValidation'] ?? null;
            if ($rule && $rule['range']['startColumnIndex'] === $column && ($rule['rule']['condition']['type'] ?? null) === 'ONE_OF_LIST') {
                return array_column($rule['rule']['condition']['values'], 'userEnteredValue');
            }
        }

        return [];
    }

    private function project(string $code, string $name, array $phases, array $extra = []): Project
    {
        $project = Project::create($extra + ['project_code' => $code, 'name' => $name, 'status' => 'active', 'location' => 'Dar es Salaam']);
        foreach ($phases as $index => $phase) {
            $project->phases()->create(['stage_code' => strtolower($phase), 'phase_name' => $phase, 'phase_order' => $index + 1, 'status' => 'not_started']);
        }

        return $project;
    }
}

/** Stands in for Google: keeps the tab list in memory and records every request. */
class FakeSheetsClient extends SheetsClient
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var list<array{id: int, title: string}> */
    private array $tabList = [['id' => 0, 'title' => 'Sheet1']];

    public function isConfigured(): bool
    {
        return true;
    }

    public function tabs(): array
    {
        return $this->tabList;
    }

    public function batchUpdate(array $requests): void
    {
        foreach ($requests as $request) {
            $this->requests[] = $request;
            if (isset($request['addSheet'])) {
                $this->tabList[] = ['id' => $request['addSheet']['properties']['sheetId'], 'title' => $request['addSheet']['properties']['title']];
            }
            if (isset($request['updateSheetProperties']['properties']['title'])) {
                $properties = $request['updateSheetProperties']['properties'];
                foreach ($this->tabList as &$tab) {
                    if ($tab['id'] === $properties['sheetId']) {
                        $tab['title'] = $properties['title'];
                    }
                }
                unset($tab);
            }
        }
    }

    public function serviceAccountEmail(): string
    {
        return 'robot@example.test';
    }

    /** A person renaming a tab in Google Sheets. */
    public function retitle(int $id, string $title): void
    {
        foreach ($this->tabList as &$tab) {
            if ($tab['id'] === $id) {
                $tab['title'] = $title;
            }
        }
        unset($tab);
    }
}
