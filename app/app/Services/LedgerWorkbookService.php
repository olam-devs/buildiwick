<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\User;
use App\Support\PhaseTree;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Formatted Excel workbooks of the recording-mode ledger: one project (summary + expenditure) or the
 * whole company (projects, categories, months and every line). Rows are written in bulk with fixed
 * column widths so a full export of every project stays quick.
 */
class LedgerWorkbookService
{
    private const MONEY = '#,##0.00;[Red]-#,##0.00;-';

    private const DATE = 'dd mmm yyyy';

    private const LINE_HEADERS = ['Transaction ID', 'Date', 'Main Category', 'Subcategory', 'Item / Description', 'Worker', 'Invoice / Receipt No.',
        'Quantity', 'Unit', 'Unit Cost (TSh)', 'Total Amount (TSh)', 'Amount Paid (TSh)', 'Payment Status', 'Payment Method', 'Paid On', 'Phase', 'Recorded By', 'Notes'];

    private const LINE_WIDTHS = [17, 12, 20, 22, 40, 20, 16, 10, 8, 15, 17, 17, 13, 15, 12, 30, 18, 40];

    /** @var array<string, array<string, string>> */
    private array $labels = [];

    public function __construct(private readonly LedgerAnalyticsService $analytics) {}

    /**
     * One project: a Summary sheet (only for users who may see totals) and the Expenditure register.
     *
     * @param  Collection<int, ExpenseRequest>  $lines  already filtered and sorted
     * @param  array<int, string>  $notes  what the export was filtered by
     */
    public function project(Project $project, Collection $lines, PhaseTree $tree, bool $withSummary, array $notes, User $user): BinaryFileResponse
    {
        $book = $this->book($project->name);
        $info = [
            'Project ID' => $project->project_code, 'Project' => $project->name, 'Location' => $project->location,
            'Status' => ucfirst(str_replace('_', ' ', (string) $project->status)),
            'Start – End' => $this->dates($project),
            'Exported' => now()->format('d M Y H:i').' by '.$user->name,
        ] + ($notes ? ['Filters' => implode(' · ', $notes)] : []);

        if ($withSummary) {
            $this->projectSummary($book->getActiveSheet(), $project, $info);
            $sheet = $book->createSheet();
        } else {
            $sheet = $book->getActiveSheet();
        }
        $sheet->setTitle('Expenditure');
        $rows = $lines->map(fn (ExpenseRequest $line) => $this->lineRow($line, $tree))->all();
        $this->table($sheet, 'Expenditure · '.$project->name, $info, self::LINE_HEADERS, self::LINE_WIDTHS, $rows,
            money: [9, 10, 11], dates: [1, 14], totals: $withSummary ? [10, 11] : [], categoryColumn: 2, statusColumn: 12);
        $book->setActiveSheetIndex(0);

        return $this->send($book, $project->project_code.'-expenditure-'.now()->format('Ymd'));
    }

    /**
     * Every project the user may see: Projects, By Category, Monthly and All Expenditure sheets.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array{name: string, label: string, from: CarbonImmutable, to: CarbonImmutable}|null  $range
     */
    public function portfolio(Collection $projects, ?array $range, User $user): BinaryFileResponse
    {
        $from = $range['from'] ?? null;
        $to = $range['to'] ?? null;
        $portfolio = $this->analytics->portfolio($projects, $from, $to);
        $book = $this->book('All projects');
        $info = ['Period' => $range['label'] ?? 'All time', 'Projects' => (string) $projects->count(), 'Exported' => now()->format('d M Y H:i').' by '.$user->name];
        $categories = LedgerAnalyticsService::CATEGORIES;

        $sheet = $book->getActiveSheet()->setTitle('Projects');
        $rows = $portfolio['rows']->values()->map(fn (array $row, int $index) => [
            $index + 1, $row['project']->project_code, $row['project']->name, $row['project']->location, ucfirst(str_replace('_', ' ', (string) $row['project']->status)),
            $row['project']->planned_start_date, $row['project']->expected_completion_date, $row['lines'],
            (float) $row['recorded'], (float) $row['to_confirm'], (float) $row['budget'],
            $row['used'] === null ? null : round((float) $row['used'], 2), $row['last_entry'] ? CarbonImmutable::parse($row['last_entry']) : null,
        ])->all();
        $this->table($sheet, 'Projects · '.($range['label'] ?? 'All time'), $info,
            ['#', 'Project ID', 'Project', 'Location', 'Status', 'Start', 'End', 'Lines', 'Confirmed Spend (TSh)', 'Awaiting Confirmation (TSh)', 'Budget (TSh)', 'Budget Used (to date)', 'Last Entry'],
            [5, 24, 36, 20, 12, 12, 12, 8, 19, 19, 18, 12, 12], $rows, money: [8, 9, 10], dates: [5, 6, 12], totals: [7, 8, 9, 10], percent: [11]);

        $sheet = $book->createSheet()->setTitle('By Category');
        $rows = $portfolio['rows']->values()->map(fn (array $row) => [$row['project']->project_code, $row['project']->name,
            ...array_map(fn ($code) => (float) $row['by_category'][$code], array_keys($categories)), (float) $row['recorded']])->all();
        $this->table($sheet, 'Confirmed spend by category · '.($range['label'] ?? 'All time'), $info, ['Project ID', 'Project', ...array_values($categories), 'Total (TSh)'],
            [24, 36, 18, 18, 18, 22, 18], $rows, money: [2, 3, 4, 5, 6], totals: [2, 3, 4, 5, 6]);

        $sheet = $book->createSheet()->setTitle('Monthly');
        $rows = collect($portfolio['monthly'])->map(fn (array $month) => [$month['label'],
            ...array_map(fn ($code) => (float) $month['categories'][$code], array_keys($categories)), (float) $month['total']])->all();
        $this->table($sheet, 'Monthly confirmed spend · all projects', $info, ['Month', ...array_values($categories), 'Total (TSh)'],
            [12, 18, 18, 18, 22, 18], $rows, money: [1, 2, 3, 4, 5], totals: [1, 2, 3, 4, 5]);

        $sheet = $book->createSheet()->setTitle('All Expenditure');
        $rows = [];
        foreach ($projects as $project) {
            $tree = new PhaseTree($project->phases()->get());
            $lines = $this->analytics->lines($project);
            if ($lines instanceof \Illuminate\Database\Eloquent\Collection) {
                $lines->loadMissing('labourAgreement');
            }
            foreach ($lines as $line) {
                if (($from && $line->requested_at?->lt($from)) || ($to && $line->requested_at?->gt($to->endOfDay()))) {
                    continue;
                }
                $rows[] = [$project->project_code, $project->name, ...$this->lineRow($line, $tree)];
            }
        }
        $this->table($sheet, 'All expenditure · '.($range['label'] ?? 'All time'), $info, ['Project ID', 'Project', ...self::LINE_HEADERS],
            [24, 28, ...self::LINE_WIDTHS], $rows, money: [11, 12, 13], dates: [3, 16], totals: [12, 13], categoryColumn: 4, statusColumn: 14);
        $book->setActiveSheetIndex(0);

        return $this->send($book, 'buildiwick-all-projects-'.now()->format('Ymd'));
    }

    /** @param  array<string, ?string>  $info */
    private function projectSummary(Worksheet $sheet, Project $project, array $info): void
    {
        $sheet->setTitle('Summary');
        $report = $this->analytics->project($project);
        $t = $report['totals'];
        $this->band($sheet, $project->name, 'Project summary', 'H');
        $row = $this->info($sheet, 3, $info);

        $row += 1;
        $cards = [['Confirmed spend', (float) $t['recorded'], self::MONEY], ['Awaiting confirmation', (float) $t['to_confirm'], self::MONEY], ['Lines awaiting', $t['to_confirm_count'], '#,##0'],
            ['Budget', (float) $t['budget'], self::MONEY], ['Budget used', $t['used'] === null ? 'No budget' : round((float) $t['used'], 2) / 100, '0.00%'], ['Lines', $t['lines'], '#,##0']];
        foreach ($cards as $index => [$label, $value, $format]) {
            $column = Coordinate::stringFromColumnIndex(1 + ($index % 3) * 3);
            $at = $row + intdiv($index, 3) * 2;
            $sheet->setCellValue("{$column}{$at}", $label);
            $sheet->setCellValue($column.($at + 1), $value);
            $sheet->getStyle("{$column}{$at}")->getFont()->setSize(9)->getColor()->setRGB('5D6A73');
            $sheet->getStyle($column.($at + 1))->getFont()->setBold(true)->setSize(13);
            $sheet->getStyle($column.($at + 1))->getNumberFormat()->setFormatCode($format);
        }
        $row += 5;

        $headers = ['Category', 'Lines', 'Confirmed Spend (TSh)', 'Awaiting Confirmation (TSh)', 'Budget (TSh)', 'Used'];
        $rows = collect($report['categories'])->map(fn (array $c) => [$c['label'], $c['count'], (float) $c['recorded'], (float) $c['to_confirm'],
            (float) $c['budget'], $c['used'] === null ? null : round((float) $c['used'], 2) / 100])->values()->all();
        $row = $this->block($sheet, $row, 'By category', $headers, $rows, money: [2, 3, 4], percent: [5], totals: [1, 2, 3, 4]);

        $categories = LedgerAnalyticsService::CATEGORIES;
        $headers = ['Phase', ...array_values($categories), 'Total (TSh)', 'Budget (TSh)', 'Used'];
        $rows = $report['phases']->map(fn (array $p) => [str_repeat('    ', $p['depth'] - 1).$p['label'],
            ...array_map(fn ($code) => (float) $p['categories'][$code]['recorded'], array_keys($categories)),
            (float) $p['recorded'], (float) $p['budget'], $p['used'] === null ? null : round((float) $p['used'], 2) / 100])->all();
        $this->block($sheet, $row + 1, 'Confirmed spend by phase (each phase includes its subphases)', $headers, $rows, money: [1, 2, 3, 4, 5, 6], percent: [7],
            bold: $report['phases']->map(fn ($p) => $p['depth'] === 1)->all());

        foreach ([34, 18, 18, 18, 22, 18, 18, 10] as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        $this->page($sheet, $project->name.' · Summary', 'H', 1);
    }

    /**
     * A small titled table inside the Summary sheet. Returns the next free row.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, bool>  $bold
     */
    private function block(Worksheet $sheet, int $row, string $title, array $headers, array $rows, array $money = [], array $percent = [], array $totals = [], array $bold = []): int
    {
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;
        $sheet->fromArray($headers, null, "A{$row}");
        $this->headerStyle($sheet, "A{$row}:{$last}{$row}");
        $first = $row + 1;
        if ($rows) {
            $sheet->fromArray($rows, null, "A{$first}", true);
        }
        $end = $first + max(count($rows), 1) - 1;
        foreach ($bold as $index => $isBold) {
            if ($isBold) {
                $sheet->getStyle('A'.($first + $index).":{$last}".($first + $index))->getFont()->setBold(true);
            }
        }
        if ($totals && $rows) {
            $end++;
            $sheet->setCellValue("A{$end}", 'TOTAL');
            foreach ($totals as $index) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValue("{$column}{$end}", "=SUM({$column}{$first}:{$column}".($end - 1).')');
            }
            $this->totalStyle($sheet, "A{$end}:{$last}{$end}");
        }
        $this->formats($sheet, $first, $end, $money, [], $percent);
        $sheet->getStyle("A{$first}:{$last}{$end}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('D8DEE3');

        return $end + 2;
    }

    /**
     * A full-sheet register: title band, info block, header row, data, optional SUM row, filters and frozen header.
     *
     * @param  array<string, ?string>  $info
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function table(Worksheet $sheet, string $title, array $info, array $headers, array $widths, array $rows, array $money = [], array $dates = [],
        array $totals = [], array $percent = [], ?int $categoryColumn = null, ?int $statusColumn = null): void
    {
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $this->band($sheet, $title, '', $last);
        $headerRow = $this->info($sheet, 3, $info) + 1;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $this->headerStyle($sheet, "A{$headerRow}:{$last}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(30);
        $first = $headerRow + 1;
        $rows = array_map(fn (array $row) => array_map(fn ($value) => $value instanceof DateTimeInterface ? Date::PHPToExcel($value) : $value, $row), $rows);
        if ($rows) {
            $sheet->fromArray($rows, null, "A{$first}", true);
        }
        $end = $first + max(count($rows), 1) - 1;
        if ($rows) {
            $sheet->setAutoFilter("A{$headerRow}:{$last}{$end}");
        }
        if ($totals && $rows) {
            $total = $end + 1;
            $sheet->setCellValue("A{$total}", 'TOTAL');
            foreach ($totals as $index) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->setCellValue("{$column}{$total}", "=SUBTOTAL(9,{$column}{$first}:{$column}{$end})");
            }
            $this->totalStyle($sheet, "A{$total}:{$last}{$total}");
            $end = $total;
        }
        // Register percent columns hold whole numbers (85.5 means 85.5%).
        $this->formats($sheet, $first, $end, $money, $dates, $percent, '0.00"%"');
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        if ($categoryColumn !== null && $rows) {
            $this->highlight($sheet, Coordinate::stringFromColumnIndex($categoryColumn + 1), $first, $end, collect(LedgerAnalyticsService::CATEGORIES)
                ->mapWithKeys(fn ($label, $code) => [$label => [ltrim(LedgerAnalyticsService::CATEGORY_COLORS[$code], '#'), null]])->all());
        }
        if ($statusColumn !== null && $rows) {
            $this->highlight($sheet, Coordinate::stringFromColumnIndex($statusColumn + 1), $first, $end,
                ['To confirm' => ['9A5B00', 'FEF3E0'], 'Part paid' => ['1B75C4', 'EAF4FE'], 'Paid' => ['1E7B4F', 'E5F6EE']]);
        }
        $sheet->freezePane('A'.$first);
        $this->page($sheet, $title, $last, $headerRow);
    }

    private function book(string $title): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Buildiwick Group')->setCompany('Buildiwick Group')->setTitle($title)->setSubject('Buildiwick expenditure export');
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $book->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        return $book;
    }

    private function band(Worksheet $sheet, string $title, string $subtitle, string $last): void
    {
        $sheet->mergeCells("A1:{$last}1");
        $sheet->setCellValue('A1', $subtitle ? $title.'  ·  '.$subtitle : $title);
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F6FB2']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
    }

    /**
     * Label/value pairs under the title. Returns the last row used.
     *
     * @param  array<string, ?string>  $info
     */
    private function info(Worksheet $sheet, int $row, array $info): int
    {
        foreach ($info as $label => $value) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValueExplicit("B{$row}", (string) $value, DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setRGB('5D6A73');
            $row++;
        }

        return $row;
    }

    private function headerStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27333D']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function totalStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FE']],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '1F6FB2']]],
        ]);
    }

    /** Percent cells hold a fraction (0.855) unless $percentFormat says otherwise. */
    private function formats(Worksheet $sheet, int $first, int $end, array $money, array $dates, array $percent, string $percentFormat = '0.00%'): void
    {
        foreach ([[$money, self::MONEY], [$dates, self::DATE], [$percent, $percentFormat]] as [$columns, $format]) {
            foreach ($columns as $index) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getStyle("{$column}{$first}:{$column}{$end}")->getNumberFormat()->setFormatCode($format);
            }
        }
    }

    /**
     * Colours a column's cells by their exact text with conditional formats (cheap even for thousands of rows).
     *
     * @param  array<string, array{0: string, 1: ?string}>  $styles  text => [font colour, fill colour]
     */
    private function highlight(Worksheet $sheet, string $column, int $first, int $end, array $styles): void
    {
        $conditions = [];
        foreach ($styles as $text => [$font, $fill]) {
            $condition = (new Conditional)->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_EQUAL)
                ->addCondition('"'.str_replace('"', '""', $text).'"');
            $condition->getStyle()->getFont()->setBold(true)->getColor()->setRGB($font);
            if ($fill) {
                $condition->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB($fill);
            }
            $conditions[] = $condition;
        }
        $sheet->getStyle("{$column}{$first}:{$column}{$end}")->setConditionalStyles($conditions);
    }

    private function page(Worksheet $sheet, string $title, string $last, int $repeatTo): void
    {
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, $repeatTo);
        $sheet->getPageMargins()->setTop(.4)->setBottom(.4)->setLeft(.3)->setRight(.3);
        $sheet->getHeaderFooter()->setOddHeader('&LBuildiwick Group&C'.str_replace('&', '&&', $title).'&R&D')
            ->setOddFooter('&LConfidential internal record&CPage &P of &N&RBuildiwick Group');
    }

    /** @return array<int, mixed> */
    private function lineRow(ExpenseRequest $line, PhaseTree $tree): array
    {
        $lastPayment = $line->payments->sortBy('payment_date')->last();
        $status = $line->ledger_status ?? $line->payment_status;

        return [
            $line->request_code, $line->requested_at, LedgerAnalyticsService::CATEGORIES[$line->main_category] ?? $line->main_category,
            $this->label('subcategory', $line->subcategory), $line->item_description, $line->labourAgreement?->fundi_subcontractor, $line->invoice_receipt_no,
            $line->quantity === null ? null : (float) $line->quantity, $this->label('unit', $line->unit),
            $line->unit_cost === null ? null : (float) $line->unit_cost, (float) $line->total_amount, (float) $line->paid_to_date,
            ['paid' => 'Paid', 'part_paid' => 'Part paid', 'unpaid' => 'To confirm'][$status] ?? 'To confirm',
            $this->label('payment_method', $lastPayment?->payment_method), $lastPayment?->payment_date,
            $tree->path($line->project_phase_id), $line->requestedBy?->name, Str::after((string) $line->notes, 'Evidence: ') ?: null,
        ];
    }

    private function label(string $type, ?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $this->labels[$type] ??= $type === 'unit' ? ExpenseLedgerService::units() : Lookup::ofType($type)->pluck('label', 'code')->all();

        return $this->labels[$type][$code] ?? $code;
    }

    private function dates(Project $project): string
    {
        if (! $project->planned_start_date && ! $project->expected_completion_date) {
            return 'Not set';
        }

        return ($project->planned_start_date?->format('d M Y') ?? '…').' – '.($project->expected_completion_date?->format('d M Y') ?? '…');
    }

    private function send(Spreadsheet $book, string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'buildiwick-xlsx-');
        (new Xlsx($book))->setPreCalculateFormulas(false)->save($path);
        $book->disconnectWorksheets();

        return response()->download($path, Str::of($filename)->replaceMatches('/[^A-Za-z0-9._-]/', '-')->finish('.xlsx')->toString(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
