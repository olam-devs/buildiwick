<?php

namespace App\Services\GoogleSheets;

use App\Http\Controllers\Web\ProjectSetupController;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\SheetTabRequest;
use App\Support\PhaseTree;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keeps one tab per open project in the shared entry sheet: creates missing tabs, follows project
 * renames and refreshes the dropdown lists. Never deletes a tab or touches the lines typed in it.
 *
 * Data Entry may also start a project Buildiwick does not have yet by duplicating the "+ New project" tab: such a
 * tab becomes a SheetTabRequest the Director approves (creating the project, whose tab it then is) or rejects.
 */
class ProjectSheetTabs
{
    /** Tab ids are chosen by us so a tab can be created and formatted in one call. */
    public const TAB_ID_OFFSET = 100000;

    public function __construct(private SheetsClient $client, private ProjectSheetLayout $layout) {}

    /** Projects that get a tab: open project ledgers, not company overheads. */
    public function projects(): Collection
    {
        return Project::query()->where('is_company_overheads', false)->where('status', '!=', 'completed')
            ->with('phases')->orderBy('name')->get();
    }

    /**
     * @param  list<int>|null  $onlyProjectIds
     * @return array{created: list<string>, renamed: list<string>, refreshed: list<string>, guide: bool}
     */
    public function sync(?array $onlyProjectIds = null): array
    {
        $report = ['created' => [], 'renamed' => [], 'refreshed' => [], 'guide' => false];
        $tabs = collect($this->client->tabs());
        $titles = $tabs->pluck('title')->all();
        $robot = $this->client->serviceAccountEmail();
        $shared = $this->sharedLists();

        if (! in_array('How to use', $titles, true) && ($starter = $tabs->firstWhere('title', 'Sheet1'))) {
            $this->client->batchUpdate($this->layout->guide($starter['id']));
            $titles = array_map(fn ($title) => $title === 'Sheet1' ? 'How to use' : $title, $titles);
            $report['guide'] = true;
        }
        if ($this->ensureTemplate($tabs->all())) {
            $titles[] = ProjectSheetLayout::TEMPLATE_TITLE;
        }

        foreach ($this->projects() as $project) {
            if ($onlyProjectIds !== null && ! in_array($project->id, $onlyProjectIds, true)) {
                continue;
            }
            $lists = $shared + ['phases' => (new PhaseTree($project->phases))->flatten()->pluck('path')->all()];
            $existing = $tabs->firstWhere('id', $project->sheet_tab_id ?? self::TAB_ID_OFFSET + $project->id);
            $wanted = ProjectSheetLayout::tabTitle($project);

            if ($existing) {
                $requests = $this->layout->validation($existing['id'], $lists);
                if ($existing['title'] !== $wanted) {
                    $title = $this->unique($wanted, $project, array_diff($titles, [$existing['title']]));
                    $requests = [...$this->layout->rename($existing['id'], $title), ...$requests];
                    $titles = [...array_diff($titles, [$existing['title']]), $title];
                    $report['renamed'][] = "{$existing['title']} → {$title}";
                } else {
                    $report['refreshed'][] = $existing['title'];
                }
                $this->client->batchUpdate($requests);
                $this->remember($project, $existing['id']);

                continue;
            }

            $sheetId = self::TAB_ID_OFFSET + $project->id;
            $title = $this->unique($wanted, $project, $titles);
            $this->client->batchUpdate($this->layout->create($project, $sheetId, $title, $lists, $robot));
            $titles[] = $title;
            $this->remember($project, $sheetId);
            $report['created'][] = $title;
        }

        return $report;
    }

    /**
     * Tabs added by hand (not a project's, the guide or the template) are new projects waiting for the Director: each
     * gets a request, a blank one is laid out, and row 1 says it is waiting (or rejected). Approved requests have their
     * tab renamed to the project, given its heading and phase list. Lines of a waiting tab are not read until approval.
     *
     * @return array{template: bool, new: list<string>, waiting: int, ready: list<string>}
     */
    public function newProjectTabs(): array
    {
        $tabs = $this->client->tabs();
        $report = ['template' => $this->ensureTemplate($tabs), 'new' => [], 'waiting' => 0, 'ready' => []];
        $this->finishApproved($tabs, $report);

        $known = Project::query()->whereNotNull('sheet_tab_id')->pluck('sheet_tab_id')->map(fn ($id) => (int) $id)->all();
        $unknown = collect($tabs)->reject(fn (array $tab) => in_array($tab['id'], $known, true) || $tab['id'] === ProjectSheetLayout::TEMPLATE_TAB_ID
            || in_array($tab['title'], ['How to use', 'Sheet1'], true)
            || ($tab['id'] > self::TAB_ID_OFFSET && Project::query()->whereKey($tab['id'] - self::TAB_ID_OFFSET)->exists()))->values();
        // A waiting or rejected tab that was deleted from the sheet is forgotten.
        SheetTabRequest::query()->where('status', '!=', 'approved')->whereNotIn('sheet_tab_id', array_column($tabs, 'id'))->delete();
        if ($unknown->isEmpty()) {
            return $report;
        }

        $last = chr(ord('A') + count(ProjectSheetLayout::COLUMNS) - 1);
        $grids = $this->client->readValues($unknown->map(fn (array $tab) => self::quote($tab['title']).'!A1:'.$last.max(3, (int) ($tab['rows'] ?? ProjectSheetLayout::ROWS)))->all());
        $headers = array_column(ProjectSheetLayout::COLUMNS, 'header');
        $filled = fn (array $cells) => collect($cells)->contains(fn ($value) => trim((string) $value) !== '');
        $requests = [];
        foreach ($unknown as $index => $tab) {
            $grid = $grids[$index] ?? [];
            $blank = ! collect($grid)->contains(fn ($cells) => $filled((array) $cells));
            $layoutOk = $blank || array_map(fn ($value) => trim((string) $value), array_pad(array_slice($grid[1] ?? [], 0, count($headers)), count($headers), '')) === $headers;
            $lines = collect(array_slice($grid, 2))->filter(fn ($cells) => $filled($layoutOk ? array_slice((array) $cells, 2) : (array) $cells))->count();

            $request = SheetTabRequest::query()->firstOrNew(['sheet_tab_id' => $tab['id']]);
            if (! $request->exists) {
                $report['new'][] = $tab['title'];
            }
            $code = strtoupper(trim((string) ($grid[0][ProjectSheetLayout::CODE_COLUMN] ?? '')));
            $location = trim((string) ($grid[0][ProjectSheetLayout::LOCATION_COLUMN] ?? ''));
            $request->fill(['title' => $tab['title'], 'line_count' => $lines, 'layout_ok' => $layoutOk, 'status' => $request->status ?? 'pending',
                'project_code' => $code !== '' ? mb_substr($code, 0, 50) : null, 'location' => $location !== '' ? mb_substr($location, 0, 150) : null])->save();
            if ($request->status === 'pending') {
                $report['waiting']++;
            }

            $heading = $request->status === 'rejected' ? ProjectSheetLayout::rejectedHeading() : ProjectSheetLayout::waitingHeading($request->detailsProblem());
            if ($blank) {
                $requests = [...$requests, ...$this->layout->setUp($tab['id'], $heading, $this->sharedLists() + ['phases' => self::standardPhases()], $this->client->serviceAccountEmail(), true)];
            } elseif ($layoutOk && trim((string) ($grid[0][ProjectSheetLayout::NEW_HEADING_COLUMN] ?? '')) !== $heading) {
                $requests = [...$requests, ...$this->layout->headingRequest($tab['id'], $heading, ProjectSheetLayout::NEW_HEADING_COLUMN)];
            }
        }
        $this->client->batchUpdate($requests);

        return $report;
    }

    /**
     * Creates the "+ New project" tab when it is missing: laid out like a project tab, with the standard phases (every new
     * project starts with them) in the Phase dropdown and cells for the project code and location in row 1.
     *
     * @param  list<array{id: int, title: string, rows?: int}>  $tabs
     */
    public function ensureTemplate(array $tabs): bool
    {
        if (collect($tabs)->contains('id', ProjectSheetLayout::TEMPLATE_TAB_ID)) {
            return false;
        }
        $title = collect($tabs)->contains('title', ProjectSheetLayout::TEMPLATE_TITLE) ? ProjectSheetLayout::TEMPLATE_TITLE.' (template)' : ProjectSheetLayout::TEMPLATE_TITLE;
        $id = ProjectSheetLayout::TEMPLATE_TAB_ID;
        $this->client->batchUpdate([
            ['addSheet' => ['properties' => ['sheetId' => $id, 'title' => $title,
                'gridProperties' => ['rowCount' => ProjectSheetLayout::ROWS, 'columnCount' => count(ProjectSheetLayout::COLUMNS), 'frozenRowCount' => 2]]]],
            ...$this->layout->setUp($id, ProjectSheetLayout::templateHeading(), $this->sharedLists() + ['phases' => self::standardPhases()], $this->client->serviceAccountEmail(), true),
        ]);
        if (collect($tabs)->contains('title', 'How to use')) {
            $this->client->writeValues(["'How to use'!A2" => [[ProjectSheetLayout::GUIDE_TABS]]]);
        }

        return true;
    }

    /**
     * @param  list<array{id: int, title: string, rows?: int}>  $tabs
     * @param  array{template: bool, new: list<string>, waiting: int, ready: list<string>}  $report
     */
    private function finishApproved(array $tabs, array &$report): void
    {
        $requests = SheetTabRequest::query()->where('status', 'approved')->whereNull('tab_ready_at')->with('project.phases')->get();
        foreach ($requests as $request) {
            $tab = collect($tabs)->firstWhere('id', $request->sheet_tab_id);
            $project = $request->project;
            if (! $tab || ! $project) {
                $request->update(['tab_ready_at' => now()]);

                continue;
            }
            $title = $this->unique(ProjectSheetLayout::tabTitle($project), $project, array_values(array_diff(array_column($tabs, 'title'), [$tab['title']])));
            $lists = $this->sharedLists() + ['phases' => (new PhaseTree($project->phases))->flatten()->pluck('path')->all()];
            $this->client->batchUpdate([
                ...($title !== $tab['title'] ? $this->layout->rename($tab['id'], $title) : []),
                ...$this->layout->projectRow($tab['id'], $project),
                ...$this->layout->validation($tab['id'], $lists),
            ]);
            $request->update(['tab_ready_at' => now()]);
            $report['ready'][] = $title;
        }
    }

    /** @return list<string> the phases every new project starts with */
    public static function standardPhases(): array
    {
        return ProjectSetupController::startingPhases(false);
    }

    private static function quote(string $title): string
    {
        return "'".str_replace("'", "''", $title)."'";
    }

    /** @return array{main_categories: list<string>, subcategories: list<string>, units: list<string>} */
    private function sharedLists(): array
    {
        return [
            'main_categories' => Lookup::ofType('main_category')->pluck('label')->all(),
            'subcategories' => Lookup::ofType('subcategory')->orderBy('label')->pluck('label')->all(),
            'units' => Lookup::ofType('unit')->pluck('label')->all(),
        ];
    }

    /** @param  list<string>  $taken */
    private function unique(string $title, Project $project, array $taken): string
    {
        return in_array($title, $taken, true) ? mb_substr($title, 0, 70).' ('.$project->project_code.')' : $title;
    }

    private function remember(Project $project, int $sheetId): void
    {
        if ((int) $project->sheet_tab_id !== $sheetId) {
            $project->forceFill(['sheet_tab_id' => $sheetId])->save();
        }
    }
}
