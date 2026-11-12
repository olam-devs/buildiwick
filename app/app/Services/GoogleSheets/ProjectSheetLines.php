<?php

namespace App\Services\GoogleSheets;

use App\Models\ExpenseDraft;
use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\SheetLine;
use App\Models\User;
use App\Services\ExpenseLedgerService;
use App\Support\Money;
use App\Support\PhaseTree;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Keeps each project tab of the entry sheet and Buildiwick in step, both ways.
 *
 * - Sheet → draft: a new sheet line goes into the Data Entry draft, a changed one is updated there, one deleted
 *   from the sheet leaves the draft. Nothing is saved to the ledger here; she still checks and saves in Buildiwick.
 * - Draft → sheet: a draft line changed in the Buildiwick form is written back to its sheet row.
 * - Saved lines: every saved line of the project is in the sheet. While Data Entry may still change it in
 *   Buildiwick (the day it was entered, unconfirmed) its Status is "Saved … · can be changed today" (blue);
 *   afterwards "Locked …" (grey) and the row is hard-protected: only the owner, the service account and users
 *   with "Edit locked sheet lines" can change it. A change typed in the sheet to a saved line is never applied
 *   quietly: it waits in the Review for Apply or Undo. A change made in Buildiwick is written to the sheet.
 *
 * Lines are matched by a row key in the hidden column A, so sorting or inserting rows is safe.
 */
class ProjectSheetLines
{
    public const LOCK_DESCRIPTION = 'Buildiwick locked lines';

    /** @var array<string, string> lowercase label or code => code */
    private array $categories = [];

    /** @var Collection<int, Lookup> */
    private Collection $subcategories;

    /** @var array<string, string> */
    private array $units = [];

    /** @var array{main_category: array<string, string>, subcategory: array<string, string>, unit: array<string, string>} code => label */
    private array $labels = ['main_category' => [], 'subcategory' => [], 'unit' => []];

    private ?User $clerk = null;

    public function __construct(private SheetsClient $client, private ProjectSheetTabs $tabs, private ExpenseLedgerService $ledger) {}

    /**
     * @param  list<int>|null  $onlyProjectIds
     * @param  bool  $withDrafts  also put draft lines typed in Buildiwick into the sheet (one-time fill; not on the 5-minute run,
     *                            because a line still being typed would change under its key)
     * @return array{added: int, updated: int, removed: int, needs_check: int, appended: int, written_back: int, waiting: int, locked: int, projects: list<string>, missing_tabs: list<string>}
     */
    public function sync(?array $onlyProjectIds = null, bool $withDrafts = false): array
    {
        $user = $this->clerk();
        $this->loadLookups();
        $report = ['added' => 0, 'updated' => 0, 'removed' => 0, 'needs_check' => 0, 'appended' => 0, 'written_back' => 0, 'waiting' => 0, 'locked' => 0, 'projects' => [], 'missing_tabs' => []];
        $tabList = collect($this->client->tabs())->keyBy('id');
        $titles = $tabList->map(fn (array $tab) => $tab['title']);
        $projects = $this->tabs->projects()->filter(fn (Project $project) => $onlyProjectIds === null || in_array($project->id, $onlyProjectIds, true))->values();
        foreach ($projects as $project) {
            if (! $project->sheet_tab_id || ! $titles->has($project->sheet_tab_id)) {
                $report['missing_tabs'][] = $project->name;
            }
        }
        $projects = $projects->filter(fn (Project $project) => $project->sheet_tab_id && $titles->has($project->sheet_tab_id))->values();
        $last = self::lastColumn();
        $ranges = $projects->map(fn (Project $project) => self::quote($titles[$project->sheet_tab_id]).'!A'.(ProjectSheetLayout::FIRST_LINE + 1).':'.$last.($tabList[$project->sheet_tab_id]['rows'] ?? ProjectSheetLayout::ROWS))->all();
        $grids = $this->client->readValues($ranges);

        $writes = [];
        $grow = [];
        $lockedRows = [];
        foreach ($projects as $index => $project) {
            $tab = $tabList[$project->sheet_tab_id];
            $counts = DB::transaction(function () use ($project, $user, $grids, $index, $tab, $withDrafts, &$writes, &$grow, &$lockedRows) {
                return $this->syncProject($project, $user, $grids[$index] ?? [], $tab, $withDrafts, $writes, $grow, $lockedRows);
            });
            foreach (['added', 'updated', 'removed', 'needs_check', 'appended', 'written_back', 'waiting', 'locked'] as $key) {
                $report[$key] += $counts[$key];
            }
            if ($counts['added'] + $counts['updated'] + $counts['removed'] + $counts['appended'] + $counts['written_back'] > 0) {
                $report['projects'][] = $project->name;
            }
        }
        // Tabs that need more rows for the lines added at the bottom get them first.
        $this->client->batchUpdate(array_map(fn (int $sheetId, int $rows) => ['appendDimension' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'length' => $rows]], array_keys($grow), $grow));
        $this->client->writeValues($writes);
        $this->client->batchUpdate($this->lockRequests($lockedRows));

        return $report;
    }

    /**
     * Sheet changes to saved lines of a project that wait for a decision, with what changed.
     *
     * @return list<array{id: int, code: string, item: string, changes: list<array{field: string, from: string, to: string}>, problems: list<string>, can_act: bool, at: string}>
     */
    public function waitingChanges(Project $project, User $viewer): array
    {
        $this->loadLookups();
        $tree = new PhaseTree($project->phases);
        $headers = array_column(array_slice(ProjectSheetLayout::COLUMNS, 2), 'header');

        return SheetLine::query()->where('project_id', $project->id)->where('state', 'saved')->whereNotNull('pending')->with('expenseRequest')->get()
            ->filter(fn (SheetLine $line) => $line->expenseRequest !== null)
            ->map(function (SheetLine $line) use ($tree, $headers, $viewer) {
                $before = $this->savedValues($line->expenseRequest, $tree);
                $after = $line->pending['values'] ?? [];
                $changes = [];
                foreach ($headers as $index => $header) {
                    [$from, $to] = [self::show($before[$index] ?? '', $index), self::show($after[$index] ?? '', $index)];
                    if (self::normal($from) !== self::normal($to)) {
                        $changes[] = ['field' => $header, 'from' => $from, 'to' => $to];
                    }
                }

                return ['id' => $line->id, 'code' => $line->expenseRequest->request_code, 'item' => (string) $line->expenseRequest->item_description,
                    'changes' => $changes, 'problems' => $line->pending['problems'] ?? [],
                    'can_act' => Gate::forUser($viewer)->allows('edit-ledger-line', $line->expenseRequest), 'at' => $line->pending_at?->format('d M Y H:i') ?? ''];
            })->values()->all();
    }

    /** Applies a waiting sheet change to the saved line, through the normal edit (permission check and edit log). */
    public function apply(SheetLine $line, User $actor): ExpenseRequest
    {
        $saved = $line->expenseRequest;
        if (! $saved || ! $line->pending) {
            throw ValidationException::withMessages(['line' => 'This change is no longer waiting.']);
        }
        if (($line->pending['problems'] ?? []) !== []) {
            throw ValidationException::withMessages(['line' => 'Fix the line in the sheet first: '.implode('; ', $line->pending['problems']).'.']);
        }
        $this->loadLookups();
        $row = $line->pending['row'];
        $updated = $this->ledger->update($saved, [
            'expense_date' => $row['expense_date'] ?: CarbonImmutable::parse($saved->requested_at)->toDateString(),
            'main_category' => $row['main_category'] ?: $saved->main_category, 'subcategory' => $row['subcategory'] ?: null,
            'item_description' => $row['item_description'], 'supplier' => $row['supplier'] ?: null, 'invoice_receipt_no' => $row['invoice_receipt_no'] ?: null,
            'quantity' => $row['quantity'], 'unit' => $row['unit'] ?: null, 'unit_cost' => $row['unit_cost'], 'total_amount' => $row['total_amount'],
            'project_phase_id' => $row['project_phase_id'], 'details' => $row['details'] ?: null, 'labour_agreement_id' => $saved->labour_agreement_id,
        ], $actor);
        $line->update(['sheet_hash' => $line->pending_hash, 'system_hash' => self::hash($this->savedValues($updated->fresh(), new PhaseTree($updated->project->phases))),
            'pending' => null, 'pending_hash' => null, 'pending_at' => null]);

        return $updated;
    }

    /** Drops a waiting sheet change; the next sync writes the saved values back into the sheet row. */
    public function undo(SheetLine $line): void
    {
        $line->update(['sheet_hash' => $line->pending_hash ?? $line->sheet_hash, 'system_hash' => 'undo', 'pending' => null, 'pending_hash' => null, 'pending_at' => null]);
    }

    /**
     * @param  list<list<mixed>>  $grid  sheet rows from row 3, columns A onwards
     * @param  array{id: int, title: string, rows?: int}  $tabInfo
     * @param  array<string, list<list<mixed>>>  $writes  cells to write (row keys, Status, changed lines, lines added at the bottom), filled in here
     * @param  array<int, int>  $grow  rows to add per tab id, filled in here
     * @param  array<int, list<int>>  $lockedRows  sheet rows to protect per tab id, filled in here
     * @return array{added: int, updated: int, removed: int, needs_check: int, appended: int, written_back: int, waiting: int, locked: int}
     */
    private function syncProject(Project $project, User $user, array $grid, array $tabInfo, bool $withDrafts, array &$writes, array &$grow, array &$lockedRows): array
    {
        $tab = self::quote($tabInfo['title']);
        $last = self::lastColumn();
        $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'needs_check' => 0, 'appended' => 0, 'written_back' => 0, 'waiting' => 0, 'locked' => 0];
        $draft = ExpenseDraft::for($user, $project);
        $rows = array_values($draft?->data['rows'] ?? []);
        $changed = false;
        $lines = SheetLine::query()->where('project_id', $project->id)->with('expenseRequest')->get()->keyBy('row_key');
        $phases = (new PhaseTree($project->phases))->flatten();
        $tree = new PhaseTree($project->phases);
        $rev = (string) now()->timestamp;
        $seen = [];
        $lockedRows[$tabInfo['id']] = [];

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
            $hash = self::hash($values);
            [$row, $problems] = $this->draftRow($cells, $project, $phases, $tree);
            $row += ['sheet_key' => $key, 'sheet_rev' => $rev];
            $line = $lines->get($key);
            // A line the system wrote itself has no reading yet: this first reading is its starting point.
            if ($line && $line->sheet_hash === '') {
                $line->sheet_hash = $hash;
            }

            if (! $line) {
                $rows[] = $row;
                SheetLine::create(['project_id' => $project->id, 'row_key' => $key, 'sheet_hash' => $hash, 'draft_hash' => self::hash($this->sheetValues($row, $tree)), 'state' => 'draft']);
                $changed = true;
                $counts['added']++;
                $wanted = $this->draftStatus($problems);
            } elseif ($line->state === 'draft') {
                $at = $this->indexOf($rows, $key);
                if ($at === null) {
                    // Taken out of the draft in Buildiwick (removed, not saved): not put back.
                    $line->fill(['state' => 'removed'])->save();
                    $wanted = 'Removed from the draft in Buildiwick';
                } else {
                    $draftValues = $this->sheetValues($rows[$at], $tree);
                    $draftHash = self::hash($draftValues);
                    if ($line->sheet_hash !== $hash) {
                        // Changed in the sheet: the draft follows (the sheet wins if both changed).
                        $rows[$at] = $row + array_intersect_key($rows[$at], ['labour_agreement_id' => true]);
                        $line->fill(['sheet_hash' => $hash, 'draft_hash' => self::hash($this->sheetValues($rows[$at], $tree))])->save();
                        $changed = true;
                        $counts['updated']++;
                    } elseif ($line->draft_hash !== null && $line->draft_hash !== $draftHash) {
                        // Changed in the Buildiwick form: the sheet row follows.
                        $writes["{$tab}!C{$sheetRow}:{$last}{$sheetRow}"] = [$draftValues];
                        [, $problems] = $this->draftRow([$key, $status, ...$draftValues], $project, $phases, $tree);
                        $line->fill(['sheet_hash' => $draftHash, 'draft_hash' => $draftHash])->save();
                        $counts['written_back']++;
                    } else {
                        $line->fill(['draft_hash' => $draftHash])->save();
                    }
                    $wanted = $this->draftStatus($problems);
                }
            } elseif ($line->state === 'saved') {
                $wanted = $this->syncSaved($line, $hash, $values, $row, $problems, $tree, "{$tab}!C{$sheetRow}:{$last}{$sheetRow}", $writes, $counts);
                if (str_starts_with($wanted, 'Locked')) {
                    $lockedRows[$tabInfo['id']][] = $sheetRow;
                }
            } else {
                $line->save();
                $wanted = 'Removed from the draft in Buildiwick';
            }
            if (str_starts_with($wanted, 'Needs check')) {
                $counts['needs_check']++;
            }
            if ($wanted !== $status) {
                $writes["{$tab}!B{$sheetRow}"] = [[$wanted]];
            }
        }

        // Draft lines no longer in the sheet (row deleted or emptied) leave the draft; "removed" lines are forgotten.
        foreach ($lines as $key => $line) {
            if (isset($seen[$key])) {
                continue;
            }
            if ($line->state === 'draft') {
                $at = $this->indexOf($rows, $key);
                if ($at !== null) {
                    array_splice($rows, $at, 1);
                    $changed = true;
                    $counts['removed']++;
                }
                $line->delete();
            } elseif ($line->state === 'removed') {
                $line->delete();
            }
        }

        // Lines the sheet does not have go at the bottom: saved lines (typed in the Buildiwick form, saved before the
        // sheet existed, or a row deleted in the sheet) and, on the one-time fill, draft lines typed in Buildiwick.
        $append = [];
        $first = ProjectSheetLayout::FIRST_LINE + 1 + count($grid);
        $missing = $lines->filter(fn (SheetLine $line, string $key) => $line->state === 'saved' && ! isset($seen[$key]) && $line->expenseRequest);
        $linked = SheetLine::query()->where('project_id', $project->id)->whereNotNull('expense_request_id')->pluck('expense_request_id')->all();
        $unlinked = ExpenseRequest::query()->where('project_id', $project->id)->where('status', '!=', 'rejected')->whereNotIn('id', $linked)->get()
            ->map(fn (ExpenseRequest $saved) => SheetLine::create(['project_id' => $project->id, 'row_key' => $this->newKey(), 'sheet_hash' => '', 'state' => 'saved', 'expense_request_id' => $saved->id])->setRelation('expenseRequest', $saved));
        $toAdd = $missing->values()->concat($unlinked)->sortBy(fn (SheetLine $line) => [CarbonImmutable::parse($line->expenseRequest->requested_at)->toDateString(), $line->expenseRequest->id])->values();
        foreach ($toAdd as $line) {
            $values = $this->savedValues($line->expenseRequest, $tree);
            $line->fill(['sheet_hash' => self::hash($values), 'system_hash' => self::hash($values), 'pending' => null, 'pending_hash' => null, 'pending_at' => null])->save();
            $status = $this->savedStatus($line);
            if (str_starts_with($status, 'Locked')) {
                $lockedRows[$tabInfo['id']][] = $first + count($append);
            }
            $append[] = [$line->row_key, $status, ...$values];
        }
        if ($withDrafts) {
            foreach ($rows as $index => $row) {
                if (! is_array($row) || filled($row['sheet_key'] ?? null)
                    || collect($row)->only(['item_description', 'unit_cost', 'total_amount'])->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
                    continue;
                }
                $key = $this->newKey();
                $values = $this->sheetValues($row, $tree);
                SheetLine::create(['project_id' => $project->id, 'row_key' => $key, 'sheet_hash' => self::hash($values), 'draft_hash' => self::hash($values), 'state' => 'draft']);
                $rows[$index]['sheet_key'] = $key;
                $rows[$index]['sheet_rev'] = $rev;
                $changed = true;
                $append[] = [$key, 'Draft', ...$values];
            }
        }
        if ($append !== []) {
            $end = $first + count($append) - 1;
            $writes["{$tab}!A{$first}:{$last}{$end}"] = $append;
            $capacity = $tabInfo['rows'] ?? ProjectSheetLayout::ROWS;
            if ($end > $capacity) {
                $grow[$tabInfo['id']] = $end - $capacity + 200;
            }
            $counts['appended'] = count($append);
        }
        $counts['locked'] = count($lockedRows[$tabInfo['id']]);

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
     * One saved line's row: in step, changed in Buildiwick (the row is rewritten) or changed in the sheet (waits for
     * a decision). Returns the Status to show.
     *
     * @param  list<mixed>  $values  sheet cells C to N
     * @param  array<string, string>  $row  the sheet cells read as a form line
     * @param  list<string>  $problems
     * @param  array<string, list<list<mixed>>>  $writes
     * @param  array<string, int>  $counts
     */
    private function syncSaved(SheetLine $line, string $hash, array $values, array $row, array $problems, PhaseTree $tree, string $range, array &$writes, array &$counts): string
    {
        $saved = $line->expenseRequest;
        if (! $saved) {
            $line->save();

            return 'Locked · deleted in Buildiwick';
        }
        $systemValues = $this->savedValues($saved, $tree);
        $systemHash = self::hash($systemValues);
        $line->system_hash ??= $systemHash;
        if ($hash === $systemHash) {
            $line->fill(['sheet_hash' => $hash, 'system_hash' => $hash, 'pending' => null, 'pending_hash' => null, 'pending_at' => null]);
        } elseif ($hash !== $line->sheet_hash) {
            // Typed in the sheet: never applied quietly.
            if ($line->pending_hash !== $hash) {
                $line->fill(['pending' => ['row' => $row, 'problems' => $problems, 'values' => $values], 'pending_hash' => $hash, 'pending_at' => now()]);
            }
            $line->system_hash = $systemHash;
        } elseif ($line->system_hash !== $systemHash) {
            // Changed in Buildiwick: the sheet row follows.
            $writes[$range] = [$systemValues];
            $line->fill(['sheet_hash' => $systemHash, 'system_hash' => $systemHash]);
            $counts['written_back']++;
        }
        $line->save();
        if ($line->pending) {
            $counts['waiting']++;
        }

        return $this->savedStatus($line);
    }

    private function savedStatus(SheetLine $line): string
    {
        $saved = $line->expenseRequest;
        $status = match (true) {
            ! $saved => 'Locked · deleted in Buildiwick',
            $saved->status === 'rejected' => 'Locked '.$saved->request_code.' · denied in Buildiwick',
            $this->editableByClerk($saved) => 'Saved '.$saved->request_code.' · can be changed today',
            default => 'Locked '.$saved->request_code,
        };

        return $status.($line->pending ? ' · sheet change waiting for approval in Buildiwick' : '');
    }

    /** Data Entry may still change the line in Buildiwick (the day it was entered, unconfirmed, not changed by others). */
    private function editableByClerk(ExpenseRequest $saved): bool
    {
        return $saved->created_at?->isToday() && Gate::forUser($this->clerk())->allows('edit-ledger-line', $saved);
    }

    /**
     * Row protections for locked lines: contiguous rows become one protected range; the editors are the service
     * account and users given "Edit locked sheet lines" (with an email). Ranges are only rebuilt when they changed.
     *
     * @param  array<int, list<int>>  $lockedRows
     * @return list<array<string, mixed>>
     */
    private function lockRequests(array $lockedRows): array
    {
        if ($lockedRows === []) {
            return [];
        }
        $editors = collect([$this->client->serviceAccountEmail()])
            ->concat(User::query()->where('is_active', true)->whereNotNull('permissions')->whereNotNull('email')->get()
                ->filter(fn (User $user) => in_array('edit_locked_sheet_lines', $user->permissions ?? [], true))->pluck('email'))
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))->filter()->unique()->sort()->values()->all();
        $existing = $this->client->protectedRanges(self::LOCK_DESCRIPTION);
        $requests = [];
        foreach ($lockedRows as $sheetId => $rowNumbers) {
            sort($rowNumbers);
            $runs = [];
            foreach ($rowNumbers as $number) {
                $lastRun = array_key_last($runs);
                if ($lastRun !== null && $runs[$lastRun][1] === $number - 1) {
                    $runs[$lastRun][1] = $number;
                } else {
                    $runs[] = [$number, $number];
                }
            }
            $wanted = array_map(fn (array $run) => ($run[0] - 1).'-'.$run[1], $runs);
            $have = $existing[$sheetId] ?? [];
            $haveRuns = array_map(fn (array $range) => $range['start'].'-'.$range['end'], $have);
            sort($haveRuns);
            $sortedWanted = $wanted;
            sort($sortedWanted);
            $editorsMatch = collect($have)->every(fn (array $range) => array_diff($editors, array_map('mb_strtolower', $range['editors'])) === []
                && count(array_diff(array_map('mb_strtolower', $range['editors']), $editors)) <= 1);
            if ($haveRuns === $sortedWanted && $editorsMatch) {
                continue;
            }
            foreach ($have as $range) {
                $requests[] = ['deleteProtectedRange' => ['protectedRangeId' => $range['id']]];
            }
            foreach ($runs as [$from, $to]) {
                $requests[] = ['addProtectedRange' => ['protectedRange' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => $from - 1, 'endRowIndex' => $to, 'startColumnIndex' => 0, 'endColumnIndex' => count(ProjectSheetLayout::COLUMNS)],
                    'description' => self::LOCK_DESCRIPTION, 'warningOnly' => false, 'editors' => ['users' => $editors],
                ]]];
            }
        }

        return $requests;
    }

    /** @return list<mixed> a saved line as sheet cells, columns C to N */
    private function savedValues(ExpenseRequest $saved, PhaseTree $tree): array
    {
        return $this->sheetValues([
            'expense_date' => CarbonImmutable::parse($saved->requested_at)->toDateString(), 'main_category' => $saved->main_category, 'subcategory' => $saved->subcategory,
            'item_description' => $saved->item_description, 'supplier' => $saved->supplier_fundi_client, 'invoice_receipt_no' => $saved->invoice_receipt_no,
            'quantity' => $saved->quantity, 'unit' => $saved->unit, 'unit_cost' => $saved->unit_cost, 'total_amount' => $saved->total_amount,
            'project_phase_id' => $saved->project_phase_id, 'details' => $saved->notes,
        ], $tree);
    }

    /**
     * A fingerprint of a row's cells C to N, the same whether read from the sheet or built from Buildiwick
     * (numbers compared by value, text by its words).
     *
     * @param  list<mixed>  $values
     */
    public static function hash(array $values): string
    {
        $values = array_pad(array_slice(array_values($values), 0, count(ProjectSheetLayout::COLUMNS) - 2), count(ProjectSheetLayout::COLUMNS) - 2, '');

        return sha1((string) json_encode(array_map(fn ($value) => self::normal($value), $values)));
    }

    private static function normal(mixed $value): string
    {
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    }

    /** A sheet cell as text for the Review: dates and amounts readable. */
    private static function show(mixed $value, int $index): string
    {
        if ($value === '' || $value === null) {
            return '—';
        }
        if ($index === 0 && is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->format('d M Y');
        }
        if (is_numeric($value) && in_array($index, [8, 9], true)) {
            return Money::format((string) $value);
        }

        return (string) $value;
    }

    private function clerk(): User
    {
        if ($this->clerk) {
            return $this->clerk;
        }
        $username = (string) config('services.google_sheets.draft_username');

        return $this->clerk = User::query()->where('username', $username)->first()
            ?? throw new RuntimeException("No Data Entry account with username \"{$username}\" (GOOGLE_SHEETS_DRAFT_USERNAME).");
    }

    private static function lastColumn(): string
    {
        return chr(ord('A') + count(ProjectSheetLayout::COLUMNS) - 1);
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
        if ($phaseText === '') {
            $problems[] = 'no phase';
        } elseif (! $phase) {
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

    /**
     * A line as sheet cells, columns C to N: labels instead of codes, the date as a day serial, amounts as numbers.
     *
     * @param  array<string, mixed>  $line  form/draft fields (codes, phase id)
     * @return list<mixed>
     */
    private function sheetValues(array $line, PhaseTree $tree): array
    {
        $text = fn (string $key) => trim((string) ($line[$key] ?? ''));
        $number = fn (string $key) => is_numeric(str_replace(',', '', $text($key))) ? (float) str_replace(',', '', $text($key)) : '';
        $date = $text('expense_date');
        $serial = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? (int) CarbonImmutable::create(1899, 12, 30)->diffInDays(CarbonImmutable::parse($date)) : '';
        $phase = $text('project_phase_id');

        return [
            $serial,
            $this->labels['main_category'][$text('main_category')] ?? $text('main_category'),
            $this->labels['subcategory'][$text('subcategory')] ?? $text('subcategory'),
            $text('item_description'), $text('supplier'), $text('invoice_receipt_no'),
            $number('quantity'),
            $this->labels['unit'][$text('unit')] ?? $text('unit'),
            $number('unit_cost'), $number('total_amount'),
            $phase !== '' ? $tree->path((int) $phase) : '',
            $text('details'),
        ];
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
        foreach (array_keys($this->labels) as $type) {
            $this->labels[$type] = Lookup::query()->where('type', $type)->pluck('label', 'code')->all();
        }
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
