<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelExportService
{
    /**
     * @param  iterable<array<int, mixed>>  $rows
     * @param  array<int, string>  $metadata
     * @param  array<int, int>  $moneyColumns  Zero-based column indexes.
     * @param  array<int, int>  $dateColumns  Zero-based column indexes.
     */
    /**
     * The title band every Buildiwick workbook starts with: the logo at the left of row 1, the title in
     * the logo's blue beside it, and a blue rule underneath.
     */
    public static function brandBand(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => '0B4F7A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 6],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '0081C3']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(40);
        $logo = public_path('images/buildiwick-mark-160.png');
        if (is_file($logo)) {
            (new Drawing)->setName('Buildiwick')->setDescription('Buildiwick logo')->setPath($logo)
                ->setHeight(30)->setCoordinates('A1')->setOffsetX(8)->setOffsetY(7)->setWorksheet($sheet);
        }
    }

    public function download(
        string $filename,
        string $title,
        array $headers,
        iterable $rows,
        array $metadata = [],
        array $moneyColumns = [],
        array $dateColumns = [],
    ): BinaryFileResponse {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Buildiwick Group')
            ->setCompany('Buildiwick Group')
            ->setTitle($title)
            ->setSubject('Buildiwick system export');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(Str::limit(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $title), 31, ''));
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $columnCount = max(1, count($headers));
        $lastColumn = $sheet->getCell([$columnCount, 1])->getColumn();
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', $title);
        self::brandBand($sheet, "A1:{$lastColumn}1");

        $metaText = implode('  |  ', array_filter($metadata));
        $metaText = $metaText ? $metaText.'  |  Exported '.now()->format('d M Y H:i') : 'Exported '.now()->format('d M Y H:i');
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', $metaText);
        $sheet->getStyle("A2:{$lastColumn}2")->getFont()->setItalic(true)->getColor()->setRGB('5D6A73');
        $sheet->getStyle("A2:{$lastColumn}2")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(30);

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 4], $header);
        }
        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27333D']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $rowNumber = 5;
        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $value) {
                $coordinate = [$index + 1, $rowNumber];
                if ($value instanceof DateTimeInterface) {
                    $sheet->setCellValue($coordinate, Date::PHPToExcel($value));
                } elseif (in_array($index, $moneyColumns, true) && is_numeric($value)) {
                    $sheet->setCellValue($coordinate, (float) $value);
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValue($coordinate, $value);
                } else {
                    $sheet->setCellValueExplicit($coordinate, $value === null ? '' : (string) $value, DataType::TYPE_STRING);
                }
            }
            $rowNumber++;
        }

        $lastRow = max(4, $rowNumber - 1);
        if ($lastRow >= 5) {
            $sheet->getStyle("A5:{$lastColumn}{$lastRow}")->applyFromArray([
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'D8DEE3']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            ]);
            $sheet->setAutoFilter("A4:{$lastColumn}{$lastRow}");
            for ($index = 5; $index <= $lastRow; $index++) {
                $values = $sheet->rangeToArray("A{$index}:{$lastColumn}{$index}", null, true, true, false)[0];
                if (collect($values)->contains(fn ($value) => is_string($value) && str_contains($value, 'TOTAL'))) {
                    $sheet->getStyle("A{$index}:{$lastColumn}{$index}")->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FE']],
                        'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '1F6FB2']]],
                    ]);
                }
            }
        }
        foreach ($moneyColumns as $index) {
            $column = $sheet->getCell([$index + 1, 4])->getColumn();
            $sheet->getStyle("{$column}5:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00;[Red](#,##0.00);-');
        }
        foreach ($dateColumns as $index) {
            $column = $sheet->getCell([$index + 1, 4])->getColumn();
            $sheet->getStyle("{$column}5:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('dd mmm yyyy');
        }
        foreach (range(1, $columnCount) as $index) {
            $column = $sheet->getCell([$index, 4])->getColumn();
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A5');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 4);
        $sheet->getPageMargins()->setTop(.4)->setBottom(.4)->setLeft(.35)->setRight(.35);
        $sheet->getHeaderFooter()
            ->setOddHeader('&LBuildiwick Group&C'.$title.'&R&D')
            ->setOddFooter('&LConfidential internal record&CPage &P of &N&RBuildiwick Group');
        $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}{$lastRow}");

        $safeFilename = Str::of($filename)->replaceMatches('/[^A-Za-z0-9._-]/', '-')->finish('.xlsx')->toString();
        $path = tempnam(sys_get_temp_dir(), 'buildiwick-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $safeFilename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
