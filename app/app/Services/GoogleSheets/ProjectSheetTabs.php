<?php

namespace App\Services\GoogleSheets;

use App\Models\Lookup;
use App\Models\Project;
use App\Support\PhaseTree;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keeps one tab per open project in the shared entry sheet: creates missing tabs, follows project
 * renames and refreshes the dropdown lists. Never deletes a tab or touches the lines typed in it.
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
