<?php

namespace App\Services\GoogleSheets;

use App\Models\Project;

/**
 * Builds the Sheets API requests for one project's tab in the shared entry sheet: columns, dropdowns,
 * status colours and the protected system columns. Pure: it only returns request arrays.
 *
 * Layout: row 1 title, row 2 headers, lines from row 3. Column A is a hidden row key and column B the
 * system Status; both are protected so only the owner and the service account can change them.
 */
class ProjectSheetLayout
{
    public const ROWS = 1000;

    /** First line row as a 0-based index (row 3). */
    public const FIRST_LINE = 2;

    /** The blank "+ New project" tab Data Entry duplicates to start a project Buildiwick does not have yet. */
    public const TEMPLATE_TAB_ID = 99999;

    public const TEMPLATE_TITLE = '+ New project';

    public const GUIDE_TABS = 'One tab per project. For a project not in Buildiwick yet: right-click the "+ New project" tab, Duplicate, rename the copy to the project name and type in it; the owner approves it in Buildiwick.';

    /** @var list<array{key: string, header: string, width: int}> */
    public const COLUMNS = [
        ['key' => 'row_key', 'header' => 'Row key', 'width' => 60],
        ['key' => 'status', 'header' => 'Status', 'width' => 220],
        ['key' => 'expense_date', 'header' => 'Date', 'width' => 95],
        ['key' => 'main_category', 'header' => 'Main Category', 'width' => 170],
        ['key' => 'subcategory', 'header' => 'Subcategory', 'width' => 190],
        ['key' => 'item_description', 'header' => 'Item / Description', 'width' => 280],
        ['key' => 'supplier', 'header' => 'Supplier / Fundi', 'width' => 200],
        ['key' => 'invoice_receipt_no', 'header' => 'Invoice / Receipt No.', 'width' => 130],
        ['key' => 'quantity', 'header' => 'Quantity', 'width' => 80],
        ['key' => 'unit', 'header' => 'Unit', 'width' => 80],
        ['key' => 'unit_cost', 'header' => 'Unit Cost (TSh)', 'width' => 120],
        ['key' => 'total_amount', 'header' => 'Total (TSh)', 'width' => 130],
        ['key' => 'project_phase', 'header' => 'Phase / Stage', 'width' => 190],
        ['key' => 'details', 'header' => 'Notes', 'width' => 240],
    ];

    /** Status colours, matched on the start of the Status text. */
    public const STATUS_COLOURS = [
        'Locked' => ['red' => 0.85, 'green' => 0.85, 'blue' => 0.85],
        'Saved' => ['red' => 0.82, 'green' => 0.91, 'blue' => 0.99],
        'Needs check' => ['red' => 0.98, 'green' => 0.84, 'blue' => 0.84],
    ];

    public static function column(string $key): int
    {
        foreach (self::COLUMNS as $index => $column) {
            if ($column['key'] === $key) {
                return $index;
            }
        }
        throw new \InvalidArgumentException("Unknown sheet column {$key}");
    }

    /** A tab title Sheets accepts: the project name, without characters that break sheet references. */
    public static function tabTitle(Project $project): string
    {
        $title = trim(preg_replace('/\s+/', ' ', str_replace(['[', ']', '*', '?', '/', '\\', ':', "'"], ' ', (string) $project->name)));

        return mb_substr($title !== '' ? $title : (string) $project->project_code, 0, 90);
    }

    /**
     * Requests that create a fully set-up tab for a project.
     *
     * @param  array{main_categories: list<string>, subcategories: list<string>, units: list<string>, phases: list<string>}  $lists
     * @return list<array<string, mixed>>
     */
    public function create(Project $project, int $sheetId, string $title, array $lists, string $robotEmail): array
    {
        return [
            ['addSheet' => ['properties' => ['sheetId' => $sheetId, 'title' => $title,
                'gridProperties' => ['rowCount' => self::ROWS, 'columnCount' => count(self::COLUMNS), 'frozenRowCount' => 2]]]],
            ...$this->setUp($sheetId, self::heading($project), $lists, $robotEmail),
        ];
    }

    /** Row 1 of a project's tab. */
    public static function heading(Project $project): string
    {
        return $project->name.' · '.$project->project_code.'   —   type lines from row 3. Blue = saved, grey = locked, red = needs check.';
    }

    public static function templateHeading(): string
    {
        return 'NEW PROJECT: do not type here. Right-click this tab → Duplicate, rename the copy to the project name and type lines from row 3. The owner approves the new project in Buildiwick.';
    }

    public static function waitingHeading(): string
    {
        return 'New project, waiting for approval in Buildiwick. Keep typing lines from row 3; they go into your Buildiwick draft once the project is approved.';
    }

    public static function rejectedHeading(): string
    {
        return 'Not a Buildiwick project (marked by the owner in Buildiwick). Lines in this tab are not read.';
    }

    /** @return list<array<string, mixed>> the request that writes row 1's text */
    public function headingRequest(int $sheetId, string $heading): array
    {
        return [['updateCells' => [
            'start' => ['sheetId' => $sheetId, 'rowIndex' => 0, 'columnIndex' => 1],
            'rows' => [['values' => [$this->text($heading, ['bold' => true, 'fontSize' => 12])]]],
            'fields' => 'userEnteredValue,userEnteredFormat',
        ]]];
    }

    /**
     * Requests that lay out an existing tab: heading, headers, formats, colours, protected system columns, dropdowns.
     *
     * @param  array{main_categories: list<string>, subcategories: list<string>, units: list<string>, phases: list<string>}  $lists
     * @return list<array<string, mixed>>
     */
    public function setUp(int $sheetId, string $heading, array $lists, string $robotEmail): array
    {
        $last = count(self::COLUMNS);
        $requests = [
            ['updateSheetProperties' => ['properties' => ['sheetId' => $sheetId, 'gridProperties' => ['frozenRowCount' => 2]], 'fields' => 'gridProperties.frozenRowCount']],
            ['mergeCells' => ['range' => $this->range($sheetId, 0, 1, 1, $last), 'mergeType' => 'MERGE_ALL']],
            ...$this->headingRequest($sheetId, $heading),
            ['updateCells' => [
                'start' => ['sheetId' => $sheetId, 'rowIndex' => 1, 'columnIndex' => 0],
                'rows' => [['values' => array_map(fn (array $column) => $this->text($column['header'], ['bold' => true], ['red' => 0.93, 'green' => 0.94, 'blue' => 0.96]), self::COLUMNS)]],
                'fields' => 'userEnteredValue,userEnteredFormat',
            ]],
            $this->format($sheetId, 'expense_date', ['numberFormat' => ['type' => 'DATE', 'pattern' => 'dd/mm/yyyy']]),
            $this->format($sheetId, 'quantity', ['numberFormat' => ['type' => 'NUMBER', 'pattern' => '#,##0.###']]),
            $this->format($sheetId, 'unit_cost', ['numberFormat' => ['type' => 'NUMBER', 'pattern' => '#,##0.00']]),
            $this->format($sheetId, 'total_amount', ['numberFormat' => ['type' => 'NUMBER', 'pattern' => '#,##0.00']]),
        ];
        foreach (self::COLUMNS as $index => $column) {
            $requests[] = ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => $index, 'endIndex' => $index + 1],
                'properties' => ['pixelSize' => $column['width']], 'fields' => 'pixelSize',
            ]];
        }
        $requests[] = ['updateDimensionProperties' => [
            'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 1],
            'properties' => ['hiddenByUser' => true], 'fields' => 'hiddenByUser',
        ]];
        $index = 0;
        foreach (self::STATUS_COLOURS as $status => $colour) {
            $requests[] = ['addConditionalFormatRule' => ['index' => $index++, 'rule' => [
                'ranges' => [$this->range($sheetId, self::FIRST_LINE, self::ROWS, 1, $last)],
                'booleanRule' => [
                    'condition' => ['type' => 'CUSTOM_FORMULA', 'values' => [['userEnteredValue' => '=REGEXMATCH($B'.(self::FIRST_LINE + 1).',"^'.$status.'")']]],
                    'format' => ['backgroundColor' => $colour],
                ],
            ]]];
        }
        foreach ([[$this->range($sheetId, 0, self::ROWS, 0, 2), 'Buildiwick system columns (row key and status)'],
            [$this->range($sheetId, 0, 2, 0, $last), 'Buildiwick title and headers']] as [$range, $description]) {
            $requests[] = ['addProtectedRange' => ['protectedRange' => [
                'range' => $range, 'description' => $description, 'warningOnly' => false, 'editors' => ['users' => [$robotEmail]],
            ]]];
        }

        return [...$requests, ...$this->validation($sheetId, $lists)];
    }

    /**
     * Dropdowns and value checks for the line rows; re-sent on every run so lists follow the system.
     *
     * @param  array{main_categories: list<string>, subcategories: list<string>, units: list<string>, phases: list<string>}  $lists
     * @return list<array<string, mixed>>
     */
    public function validation(int $sheetId, array $lists): array
    {
        $requests = [];
        foreach (['main_category' => 'main_categories', 'subcategory' => 'subcategories', 'unit' => 'units', 'project_phase' => 'phases'] as $column => $list) {
            $values = array_values(array_unique(array_filter($lists[$list], fn ($value) => trim((string) $value) !== '')));
            $requests[] = $this->rule($sheetId, $column, $values === [] ? null : [
                'condition' => ['type' => 'ONE_OF_LIST', 'values' => array_map(fn ($value) => ['userEnteredValue' => (string) $value], $values)],
                'strict' => true, 'showCustomUi' => true,
            ]);
        }
        $requests[] = $this->rule($sheetId, 'expense_date', ['condition' => ['type' => 'DATE_IS_VALID'], 'strict' => true,
            'inputMessage' => 'Pick the date from the calendar (double-click the cell).']);
        foreach (['quantity', 'unit_cost', 'total_amount'] as $column) {
            $requests[] = $this->rule($sheetId, $column, ['condition' => ['type' => 'NUMBER_GREATER_THAN_EQ', 'values' => [['userEnteredValue' => '0']]], 'strict' => true]);
        }

        return $requests;
    }

    /** @return list<array<string, mixed>> */
    public function rename(int $sheetId, string $title): array
    {
        return [['updateSheetProperties' => ['properties' => ['sheetId' => $sheetId, 'title' => $title], 'fields' => 'title']]];
    }

    /**
     * Turns the spreadsheet's starting "Sheet1" into a short guide.
     *
     * @return list<array<string, mixed>>
     */
    public function guide(int $sheetId): array
    {
        $lines = [
            ['Buildiwick Project Entries', ['bold' => true, 'fontSize' => 14]],
            [self::GUIDE_TABS, []],
            ['Type each expense on its own row, from row 3. Pick Main Category, Subcategory, Unit and Phase from the dropdowns, and the date from the calendar.', []],
            ['New lines go into your Record Expense draft in Buildiwick. Nothing is saved until you check and save it there.', []],
            ['Colours: white = draft, blue = saved (can still be changed today), grey = locked, red = needs checking.', []],
            ['Locked lines cannot be changed. Ask the owner if a locked line is wrong.', []],
            ['Do not type in the Status column; the system fills it.', []],
        ];

        return [
            ['updateSheetProperties' => ['properties' => ['sheetId' => $sheetId, 'title' => 'How to use', 'index' => 0], 'fields' => 'title,index']],
            ['updateCells' => [
                'start' => ['sheetId' => $sheetId, 'rowIndex' => 0, 'columnIndex' => 0],
                'rows' => array_map(fn (array $line) => ['values' => [$this->text($line[0], $line[1])]], $lines),
                'fields' => 'userEnteredValue,userEnteredFormat',
            ]],
            ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 1],
                'properties' => ['pixelSize' => 900], 'fields' => 'pixelSize',
            ]],
        ];
    }

    /** @return array<string, int> */
    private function range(int $sheetId, int $startRow, int $endRow, int $startColumn, int $endColumn): array
    {
        return ['sheetId' => $sheetId, 'startRowIndex' => $startRow, 'endRowIndex' => $endRow, 'startColumnIndex' => $startColumn, 'endColumnIndex' => $endColumn];
    }

    /**
     * @param  array<string, mixed>  $textFormat
     * @param  array<string, float>|null  $background
     * @return array<string, mixed>
     */
    private function text(string $value, array $textFormat = [], ?array $background = null): array
    {
        $format = ['textFormat' => $textFormat, 'wrapStrategy' => 'CLIP'];
        if ($background) {
            $format['backgroundColor'] = $background;
        }

        return ['userEnteredValue' => ['stringValue' => $value], 'userEnteredFormat' => $format];
    }

    /**
     * @param  array<string, mixed>  $format
     * @return array<string, mixed>
     */
    private function format(int $sheetId, string $column, array $format): array
    {
        $index = self::column($column);

        return ['repeatCell' => [
            'range' => $this->range($sheetId, self::FIRST_LINE, self::ROWS, $index, $index + 1),
            'cell' => ['userEnteredFormat' => $format], 'fields' => 'userEnteredFormat.numberFormat',
        ]];
    }

    /**
     * @param  array<string, mixed>|null  $rule  null clears the column's rule
     * @return array<string, mixed>
     */
    private function rule(int $sheetId, string $column, ?array $rule): array
    {
        $index = self::column($column);
        $request = ['range' => $this->range($sheetId, self::FIRST_LINE, self::ROWS, $index, $index + 1)];
        if ($rule) {
            $request['rule'] = $rule;
        }

        return ['setDataValidation' => $request];
    }
}
