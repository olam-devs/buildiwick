<?php

namespace App\Services;

use App\Models\EstimateEntry;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Excel round-trip for estimate lines: a downloadable template (pre-filled with the current lines when
 * there are any) and an upload parser that resolves labels to codes and validates every row before
 * anything is written.
 */
class EstimateTemplateService
{
    public const HEADERS = [
        'phase' => 'Phase',
        'work_section' => 'Section',
        'item_reference' => 'Item Ref.',
        'item_description' => 'Description',
        'main_category' => 'Category',
        'subcategory' => 'Subcategory',
        'quantity' => 'Quantity',
        'unit' => 'Unit',
        'base_unit_cost' => 'Base Rate (TSh)',
        'markup_percent' => 'Markup %',
        'notes' => 'Notes',
    ];

    private const HEADER_ROW = 4;

    private const MAX_REPORTED_ERRORS = 25;

    public function download(Project $project, ?ProjectEstimate $estimate): BinaryFileResponse
    {
        $project->loadMissing(['phases' => fn ($query) => $query->orderBy('phase_order')]);
        $lists = $this->lists();
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator('Buildiwick Group')->setTitle('Estimate Template - '.$project->name);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Estimate Lines');
        $lastColumn = $sheet->getCell([count(self::HEADERS), 1])->getColumn();
        $sheet->setCellValue('A1', 'Estimate Template - '.$project->name.' ('.$project->project_code.')');
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F6FB2']],
        ]);
        $sheet->setCellValue('A2', 'One row per estimate line. Phase, Category and Unit must match the lists on the "Lists" sheet. Base Rate is before markup. Uploading replaces every line in the working estimate.');
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('5D6A73');
        $sheet->getStyle('A2')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(2)->setRowHeight(30);

        foreach (array_values(self::HEADERS) as $index => $header) {
            $sheet->setCellValue([$index + 1, self::HEADER_ROW], $header);
        }
        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27333D']],
        ]);

        $row = self::HEADER_ROW + 1;
        $entries = $estimate?->entries()->with('phase')->get()
            ->sortBy(fn (EstimateEntry $entry) => [$entry->phase?->phase_order ?? PHP_INT_MAX, $entry->id]) ?? collect();
        foreach ($entries as $entry) {
            $values = [
                $entry->phase?->stage_label,
                $entry->work_section,
                $entry->item_reference,
                $entry->item_description,
                $lists['categories']->get($entry->main_category, $entry->main_category),
                $entry->subcategory ? $lists['subcategories']->firstWhere('code', $entry->subcategory)?->label ?? $entry->subcategory : null,
                $entry->quantity === null ? null : (float) $entry->quantity,
                $entry->unit ? $lists['units']->get($entry->unit, $entry->unit) : null,
                (float) $entry->base_unit_cost,
                (float) $entry->markup_percent,
                $entry->notes,
            ];
            foreach ($values as $index => $value) {
                if (is_float($value)) {
                    $sheet->setCellValue([$index + 1, $row], $value);
                } else {
                    $sheet->setCellValueExplicit([$index + 1, $row], (string) $value, DataType::TYPE_STRING);
                }
            }
            $row++;
        }

        $this->writeListsSheet($spreadsheet, $project, $lists);
        $lastDataRow = max($row + 200, 300);
        $this->addDropdown($sheet, 'A', $lastDataRow, '=Lists!$A$2:$A$'.max(2, $project->phases->count() + 1));
        $this->addDropdown($sheet, 'E', $lastDataRow, '=Lists!$B$2:$B$'.max(2, $lists['categories']->count() + 1));
        $this->addDropdown($sheet, 'F', $lastDataRow, '=Lists!$C$2:$C$'.max(2, $lists['subcategories']->count() + 1));
        $this->addDropdown($sheet, 'H', $lastDataRow, '=Lists!$E$2:$E$'.max(2, $lists['units']->count() + 1));
        $sheet->getStyle("G5:G{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("I5:I{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("J5:J{$lastDataRow}")->getNumberFormat()->setFormatCode('0.00##');
        foreach (['A' => 22, 'B' => 24, 'C' => 10, 'D' => 48, 'E' => 26, 'F' => 26, 'G' => 11, 'H' => 12, 'I' => 16, 'J' => 10, 'K' => 30] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A5');
        $spreadsheet->setActiveSheetIndex(0);

        $filename = Str::of($project->project_code.'-estimate-template')->replaceMatches('/[^A-Za-z0-9._-]/', '-')->finish('.xlsx')->toString();
        $path = tempnam(sys_get_temp_dir(), 'buildiwick-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Reads an uploaded template into validated entry rows. Nothing is saved here.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function parse(Project $project, UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::createReader('Xlsx')->setReadDataOnly(false)->load($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['estimate_file' => 'The file could not be read as an Excel (.xlsx) workbook.']);
        }
        $sheet = $spreadsheet->getSheetByName('Estimate Lines') ?? $spreadsheet->getSheet(0);
        $columns = $this->locateHeader($sheet);
        if ($columns === null) {
            $spreadsheet->disconnectWorksheets();
            throw ValidationException::withMessages(['estimate_file' => 'No header row with a "Description" column was found. Download the template and keep its header row.']);
        }

        $project->loadMissing('phases');
        $lists = $this->lists();
        $rows = [];
        $errors = [];
        for ($rowNumber = $columns['header_row'] + 1; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
            $raw = [];
            foreach (array_keys(self::HEADERS) as $field) {
                $raw[$field] = isset($columns[$field]) ? $this->cellValue($sheet, $columns[$field], $rowNumber, $field) : null;
            }
            if (collect($raw)->every(fn ($value) => $value === null || $value === '')) {
                continue;
            }

            [$row, $rowErrors] = $this->resolveRow($raw, $project, $lists);
            if ($rowErrors) {
                foreach ($rowErrors as $message) {
                    $errors[] = 'Row '.$rowNumber.': '.$message;
                }
            } else {
                $rows[] = $row;
            }
        }
        $spreadsheet->disconnectWorksheets();

        if ($errors) {
            $shown = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);
            if (count($errors) > self::MAX_REPORTED_ERRORS) {
                $shown[] = (count($errors) - self::MAX_REPORTED_ERRORS).' more problem(s) not shown.';
            }
            throw ValidationException::withMessages(['estimate_file' => $shown]);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['estimate_file' => 'The uploaded sheet has no estimate lines.']);
        }

        return $rows;
    }

    /** @return array{0: array<string, mixed>, 1: array<int, string>} */
    private function resolveRow(array $raw, Project $project, array $lists): array
    {
        $errors = [];
        $phase = $this->matchPhase($project->phases, $raw['phase']);
        if (! $phase) {
            $errors[] = $raw['phase'] === null || $raw['phase'] === ''
                ? 'Phase is required.'
                : 'Phase "'.$raw['phase'].'" is not one of this project\'s phases.';
        }
        $category = $this->matchCode($lists['categories'], $raw['main_category']);
        if (! $category) {
            $errors[] = $raw['main_category'] === null || $raw['main_category'] === ''
                ? 'Category is required.'
                : 'Category "'.$raw['main_category'].'" is not recognised.';
        }
        $subcategory = null;
        if ($raw['subcategory'] !== null && $raw['subcategory'] !== '') {
            $options = $lists['subcategories']->where('parent_code', $category)->pluck('label', 'code');
            $subcategory = $this->matchCode($options, $raw['subcategory']);
            if (! $subcategory) {
                $errors[] = 'Subcategory "'.$raw['subcategory'].'" does not belong to the selected category.';
            }
        }
        $unit = null;
        if ($raw['unit'] !== null && $raw['unit'] !== '') {
            $unit = $this->matchCode($lists['units'], $raw['unit']);
            if (! $unit) {
                $errors[] = 'Unit "'.$raw['unit'].'" is not recognised.';
            }
        }

        $row = [
            'project_phase_id' => $phase?->id,
            'work_area' => null,
            'work_section' => $this->text($raw['work_section']),
            'item_reference' => $this->text($raw['item_reference']),
            'main_category' => $category,
            'subcategory' => $subcategory,
            'item_description' => $this->text($raw['item_description']),
            'quantity' => $this->number($raw['quantity'], 2),
            'unit' => $unit,
            'base_unit_cost' => $this->number($raw['base_unit_cost'], 2),
            'markup_percent' => $this->number($raw['markup_percent'], 4) ?? '0',
            'notes' => $this->text($raw['notes']),
        ];
        $validator = Validator::make($row, [
            'work_section' => ['nullable', 'string', 'max:255'],
            'item_reference' => ['nullable', 'string', 'max:100'],
            'item_description' => ['required', 'string', 'max:1000'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'base_unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'item_description' => 'Description', 'base_unit_cost' => 'Base Rate', 'markup_percent' => 'Markup %',
            'quantity' => 'Quantity', 'work_section' => 'Section', 'item_reference' => 'Item Ref.', 'notes' => 'Notes',
        ]);
        foreach ($validator->errors()->all() as $message) {
            $errors[] = $message;
        }

        return [$row, $errors];
    }

    /** @return array{categories: Collection, subcategories: Collection, units: Collection} */
    private function lists(): array
    {
        return [
            'categories' => Lookup::ofType('main_category')->get()->pluck('label', 'code'),
            'subcategories' => Lookup::ofType('subcategory')->get(['code', 'label', 'parent_code']),
            'units' => Lookup::ofType('unit')->get()->pluck('label', 'code'),
        ];
    }

    private function writeListsSheet(Spreadsheet $spreadsheet, Project $project, array $lists): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Lists');
        foreach (['Phases', 'Categories', 'Subcategories', 'Subcategory Category', 'Units'] as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        foreach ($project->phases->values() as $index => $phase) {
            $sheet->setCellValueExplicit([1, $index + 2], $phase->stage_label, DataType::TYPE_STRING);
        }
        foreach ($lists['categories']->values() as $index => $label) {
            $sheet->setCellValueExplicit([2, $index + 2], $label, DataType::TYPE_STRING);
        }
        foreach ($lists['subcategories']->values() as $index => $subcategory) {
            $sheet->setCellValueExplicit([3, $index + 2], $subcategory->label, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $index + 2], (string) $lists['categories']->get($subcategory->parent_code, $subcategory->parent_code), DataType::TYPE_STRING);
        }
        foreach ($lists['units']->values() as $index => $label) {
            $sheet->setCellValueExplicit([5, $index + 2], $label, DataType::TYPE_STRING);
        }
        foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function addDropdown($sheet, string $column, int $lastRow, string $formula): void
    {
        $validation = $sheet->getCell($column.'5')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_WARNING)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Not in list')
            ->setError('Choose a value from the list on the Lists sheet.')
            ->setFormula1($formula);
        $sheet->setDataValidation("{$column}5:{$column}{$lastRow}", $validation);
    }

    /** @return array<string, int|string>|null Column indexes keyed by field, plus `header_row`. */
    private function locateHeader($sheet): ?array
    {
        $aliases = [];
        foreach (self::HEADERS as $field => $header) {
            $aliases[$this->normalise($header)] = $field;
        }
        $aliases += ['subphase' => 'work_section', 'subphasesection' => 'work_section', 'itemref' => 'item_reference', 'item' => 'item_reference',
            'baserate' => 'base_unit_cost', 'rate' => 'base_unit_cost', 'markup' => 'markup_percent', 'qty' => 'quantity'];
        $highestColumn = min(40, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
        for ($row = 1; $row <= min(15, $sheet->getHighestDataRow()); $row++) {
            $found = [];
            for ($column = 1; $column <= $highestColumn; $column++) {
                $key = $this->normalise((string) $sheet->getCell([$column, $row])->getValue());
                $field = $aliases[$key] ?? $aliases[preg_replace('/tsh$/', '', $key)] ?? null;
                if ($field && ! isset($found[$field])) {
                    $found[$field] = $column;
                }
            }
            if (isset($found['item_description'])) {
                return $found + ['header_row' => $row];
            }
        }

        return null;
    }

    private function cellValue($sheet, int $column, int $row, string $field): mixed
    {
        $cell = $sheet->getCell([$column, $row]);
        $value = $cell->getCalculatedValue();
        if ($field === 'markup_percent' && is_numeric($value) && str_contains($cell->getStyle()->getNumberFormat()->getFormatCode(), '%')) {
            $value = $value * 100; // A cell formatted as 15% stores 0.15.
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function matchPhase(Collection $phases, mixed $value): ?ProjectPhase
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return $phases->firstWhere('phase_order', (int) $value);
        }
        $needle = $this->normalise((string) $value);

        return $phases->first(fn (ProjectPhase $phase) => in_array($needle, [
            $this->normalise((string) $phase->stage_label), $this->normalise((string) $phase->phase_name), $this->normalise((string) $phase->stage_code),
        ], true));
    }

    /** Matches a label or code (case/spacing-insensitive) to its lookup code. */
    private function matchCode(Collection $labelsByCode, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $needle = $this->normalise((string) $value);
        foreach ($labelsByCode as $code => $label) {
            if ($this->normalise((string) $code) === $needle || $this->normalise((string) $label) === $needle) {
                return (string) $code;
            }
        }

        return null;
    }

    private function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9²³]/u', '', mb_strtolower(trim($value)));
    }

    private function text(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function number(mixed $value, int $decimals): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([',', ' '], '', $value);
        }

        return is_numeric($value) ? number_format(round((float) $value, $decimals), $decimals, '.', '') : $value;
    }
}
