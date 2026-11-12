<?php

namespace App\Services\GoogleSheets;

use App\Models\ExpenseDraft;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\SheetLine;
use App\Models\User;
use App\Support\PhaseTree;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads every project tab of the entry sheet and keeps the Data Entry draft of that project in step with it:
 * a new sheet line is added to the draft, a changed one is updated there, and one deleted from the sheet is
 * taken out of the draft. Nothing is ever saved to the ledger here; she still checks and saves in Buildiwick.
 *
 * Lines are matched by a row key the system writes in the hidden column A, so sorting or inserting rows is
 * safe. Lines already saved, or removed from the draft in Buildiwick, are never put back into the draft.
 * The Status column shows where each line is.
 */
class ProjectSheetLines
{
    private const LAST_ROW = 1000;

    /** @var array<string, string> lowercase label or code => code */
    private array $categories = [];

    /** @var Collection<int, Lookup> */
    private Collection $subcategories;

    /** @var array<string, string> */
    private array $units = [];

    public function __construct(private SheetsClient $client, private ProjectSheetTabs $tabs) {}

    /**
     * @param  list<int>|null  $onlyProjectIds
     * @return array{added: int, updated: int, removed: int, needs_check: int, projects: list<string>, missing_tabs: list<string>}
     */
    public function sync(?array $onlyProjectIds = null): array
    {
        $username = (string) config('services.google_sheets.draft_username');
        $user = User::query()->where('username', $username)->first();
        if (! $user) {
            throw new RuntimeException("No Data Entry account with username \"{$username}\" (GOOGLE_SHEETS_DRAFT_USERNAME).");
        }
        $this->loadLookups();
        $report = ['added' => 0, 'updated' => 0, 'removed' => 0, 'needs_check' => 0, 'projects' => [], 'missing_tabs' => []];
        $titles = collect($this->client->tabs())->pluck('title', 'id');
        $projects = $this->tabs->projects()->filter(fn (Project $project) => $onlyProjectIds === null || in_array($project->id, $onlyProjectIds, true))->values();
        foreach ($projects as $project) {
            if (! $project->sheet_tab_id || ! $titles->has($project->sheet_tab_id)) {
                $report['missing_tabs'][] = $project->name;
            }
        }
        $projects = $projects->filter(fn (Project $project) => $project->sheet_tab_id && $titles->has($project->sheet_tab_id))->values();
        $last = chr(ord('A') + count(ProjectSheetLayout::COLUMNS) - 1);
        $ranges = $projects->map(fn (Project $project) => self::quote($titles[$project->sheet_tab_id]).'!A'.(ProjectSheetLayout::FIRST_LINE + 1).':'.$last.self::LAST_ROW)->all();
        $grids = $this->client->readValues($ranges);

        $writes = [];
        foreach ($projects as $index => $project) {
            $counts = DB::transaction(function () use ($project, $user, $grids, $index, $titles, &$writes) {
                return $this->syncProject($project, $user, $grids[$index] ?? [], self::quote($titles[$project->sheet_tab_id]), $writes);
            });
            foreach (['added', 'updated', 'removed', 'needs_check'] as $key) {
                $report[$key] += $counts[$key];
            }
            if ($counts['added'] + $counts['updated'] + $counts['removed'] > 0) {
                $report['projects'][] = $project->name;
            }
        }
        $this->client->writeValues($writes);

        return $report;
    }

    /**
     * @param  list<list<mixed>>  $grid  sheet rows from row 3, columns A onwards
     * @param  array<string, list<list<mixed>>>  $writes  cells to write back (row keys and Status), filled in here
     * @return array{added: int, updated: int, removed: int, needs_check: int}
     */
    private function syncProject(Project $project, User $user, array $grid, string $tab, array &$writes): array
    {
        $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'needs_check' => 0];
        $draft = ExpenseDraft::for($user, $project);
        $rows = array_values($draft?->data['rows'] ?? []);
        $changed = false;
        $lines = SheetLine::query()->where('project_id', $project->id)->with('expenseRequest:id,request_code')->get()->keyBy('row_key');
        $phases = (new PhaseTree($project->phases))->flatten();
        $tree = new PhaseTree($project->phases);
        $rev = (string) now()->timestamp;
        $seen = [];

        foreach ($grid as $offset => $cells) {
            $sheetRow = ProjectSheetLayout::FIRST_LINE + 1 + $offset;
            $key = trim((string) ($cells[0] ?? ''));
            $status = (string) ($cells[ProjectSheetLayout::column('status')] ?? '');
            $values = array_slice(array_pad($cells, count(ProjectSheetLayout::COLUMNS), ''), 2);
            if ($this->isBlank($cells)) {
                if ($status !== '') {
                    $writes["{$tab}!B{$sheetRow}"] = [['']];
                }

                continue;
            }
            // A row without a key, a copied row (key seen above) or a key from another project is a new line.
            if ($key === '' || isset($seen[$key]) || (! $lines->has($key) && SheetLine::query()->where('row_key', $key)->exists())) {
                $key = $this->newKey();
                $writes["{$tab}!A{$sheetRow}"] = [[$key]];
            }
            $seen[$key] = true;
            $hash = sha1((string) json_encode($values));
            [$row, $problems] = $this->draftRow($cells, $project, $phases, $tree);
            $row += ['sheet_key' => $key, 'sheet_rev' => $rev];
            $line = $lines->get($key);

            if (! $line) {
                SheetLine::create(['project_id' => $project->id, 'row_key' => $key, 'sheet_hash' => $hash, 'state' => 'draft']);
                $rows[] = $row;
                $changed = true;
                $counts['added']++;
                $wanted = $this->draftStatus($problems);
            } elseif ($line->state === 'draft') {
                $at = $this->indexOf($rows, $key);
                if ($at === null) {
                    // Taken out of the draft in Buildiwick (removed, not saved): not put back.
                    $line->update(['state' => 'removed']);
                    $wanted = 'Removed from the draft in Buildiwick';
                } else {
                    if ($line->sheet_hash !== $hash) {
                        $rows[$at] = $row + array_intersect_key($rows[$at], ['labour_agreement_id' => true]);
                        $line->update(['sheet_hash' => $hash]);
                        $changed = true;
                        $counts['updated']++;
                    }
                    $wanted = $this->draftStatus($problems);
                }
            } elseif ($line->state === 'saved') {
                $wanted = 'Saved '.($line->expenseRequest?->request_code ?? '').($line->sheet_hash !== $hash ? ' · later change in the sheet not applied' : '');
            } else {
                $wanted = 'Removed from the draft in Buildiwick';
            }
            if (str_starts_with($wanted, 'Needs check')) {
                $counts['needs_check']++;
            }
            if ($wanted !== $status) {
                $writes["{$tab}!B{$sheetRow}"] = [[$wanted]];
            }
        }

        // Draft lines no longer in the sheet (row deleted or emptied) leave the draft.
        foreach ($lines as $key => $line) {
            if ($line->state === 'draft' && ! isset($seen[$key])) {
                $at = $this->indexOf($rows, $key);
                if ($at !== null) {
                    array_splice($rows, $at, 1);
                    $changed = true;
                    $counts['removed']++;
                }
                $line->delete();
            }
        }

        if ($changed) {
            $rows = array_values(array_filter($rows, fn ($row) => is_array($row)));
            if ($rows === []) {
                ExpenseDraft::clear($user, $project);
            } else {
                ExpenseDraft::updateOrCreate(['user_id' => $user->id, 'project_id' => $project->id], [
                    'data' => ['header' => ($draft?->data['header'] ?? []) ?: ['expense_date' => now()->toDateString()], 'rows' => $rows],
                    'item_count' => count($rows),
                ]);
            }
        }

        return $counts;
    }

    /**
     * Turns one sheet row into a draft row, with what could not be understood.
     *
     * @param  list<mixed>  $cells
     * @param  Collection<int, array{phase: ProjectPhase, path: string}>  $phases
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function draftRow(array $cells, Project $project, Collection $phases, PhaseTree $tree): array
    {
        $cell = fn (string $key) => $cells[ProjectSheetLayout::column($key)] ?? '';
        $text = fn (string $key, int $max) => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $cell($key))), 0, $max);
        $problems = [];
        $row = [
            'item_description' => $text('item_description', 255),
            'supplier' => $text('supplier', 150),
            'invoice_receipt_no' => $text('invoice_receipt_no', 255),
            'details' => $text('details', 2000),
        ];

        $date = $cell('expense_date');
        $row['expense_date'] = $this->date($date) ?? '';
        if ($row['expense_date'] === '' && trim((string) $date) !== '') {
            $problems[] = "date \"{$date}\" not understood";
        }

        $category = $text('main_category', 100);
        $row['main_category'] = $this->categories[mb_strtolower($category)] ?? '';
        if ($category !== '' && $row['main_category'] === '') {
            $problems[] = "category \"{$category}\" not in the list";
        }

        $subcategory = $text('subcategory', 150);
        $matches = $this->subcategories->filter(fn (Lookup $lookup) => in_array(mb_strtolower($subcategory), [mb_strtolower($lookup->label), $lookup->code], true));
        $row['subcategory'] = (string) ($matches->firstWhere('parent_code', $row['main_category']) ?? $matches->first())?->code;
        if ($subcategory !== '' && $row['subcategory'] === '') {
            $problems[] = "subcategory \"{$subcategory}\" not in the list";
        }

        $unit = $text('unit', 30);
        $row['unit'] = $this->units[mb_strtolower($unit)] ?? '';
        if ($unit !== '' && $row['unit'] === '') {
            $problems[] = "unit \"{$unit}\" not in the list";
        }

        $phaseText = $text('project_phase', 255);
        // The full path from the dropdown, or a phase name that only one phase of the project has.
        $byName = $phases->filter(fn (array $node) => mb_strtolower($node['phase']->phase_name) === mb_strtolower($phaseText));
        $phase = $phases->first(fn (array $node) => mb_strtolower($node['path']) === mb_strtolower($phaseText))
            ?? ($byName->count() === 1 ? $byName->first() : null);
        $row['project_phase_id'] = $phase ? (string) $phase['phase']->id : '';
        if ($phaseText !== '' && ! $phase) {
            $problems[] = "phase \"{$phaseText}\" not in this project";
        } elseif ($phase && ! $tree->isLeaf($phase['phase']->id)) {
            $problems[] = "phase \"{$phaseText}\" has sub-phases, pick one of them";
        }

        foreach (['quantity' => 'quantity', 'unit_cost' => 'unit cost', 'total_amount' => 'total'] as $key => $label) {
            $raw = $cell($key);
            $number = is_numeric($raw) ? (string) $raw : str_replace([',', ' '], '', trim((string) $raw));
            $row[$key] = is_numeric($number) ? $number : '';
            if (trim((string) $raw) !== '' && $row[$key] === '') {
                $problems[] = "{$label} \"{$raw}\" is not a number";
            }
        }
        if ($row['total_amount'] === '' && $row['unit_cost'] === '') {
            $problems[] = 'no price';
        }
        if ($row['item_description'] === '') {
            $problems[] = 'no item name';
        }

        return [$row, $problems];
    }

    /** A sheet date: a day serial number, or text such as 03/11/2026 (day first) or 2026-11-03. */
    private function date(mixed $value): ?string
    {
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value))->toDateString();
        }
        $value = trim((string) $value);
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd/m/y'] as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /** @param  list<string>  $problems */
    private function draftStatus(array $problems): string
    {
        return $problems === [] ? 'Draft' : 'Needs check: '.implode('; ', $problems);
    }

    /** @param  list<mixed>  $cells */
    private function isBlank(array $cells): bool
    {
        foreach (['item_description', 'unit_cost', 'total_amount'] as $key) {
            if (trim((string) ($cells[ProjectSheetLayout::column($key)] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function indexOf(array $rows, string $key): ?int
    {
        foreach ($rows as $index => $row) {
            if (is_array($row) && ($row['sheet_key'] ?? null) === $key) {
                return $index;
            }
        }

        return null;
    }

    private function newKey(): string
    {
        do {
            $key = strtolower(Str::random(10));
        } while (SheetLine::query()->where('row_key', $key)->exists());

        return $key;
    }

    private function loadLookups(): void
    {
        $this->categories = [];
        foreach (Lookup::ofType('main_category')->get() as $lookup) {
            $this->categories[mb_strtolower($lookup->label)] = $lookup->code;
            $this->categories[$lookup->code] = $lookup->code;
        }
        $this->subcategories = Lookup::ofType('subcategory')->get();
        $this->units = [];
        foreach (Lookup::ofType('unit')->get() as $lookup) {
            $this->units[mb_strtolower($lookup->label)] = $lookup->code;
            $this->units[mb_strtolower($lookup->code)] = $lookup->code;
        }
    }

    private static function quote(string $title): string
    {
        return "'".str_replace("'", "''", $title)."'";
    }
}
