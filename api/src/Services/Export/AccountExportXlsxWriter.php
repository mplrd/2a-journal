<?php

namespace App\Services\Export;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Turns the export sheets into .xlsx bytes: one tab per sheet, its header rows
 * in bold (the first one frozen), columns sized to their content.
 *
 * Every cell is written with an explicit type. A string is always a string,
 * so a note or setup typed as "=…", "+…", "-…" or "@…" can never become a
 * formula Excel evaluates when the file is opened (formula injection).
 */
class AccountExportXlsxWriter
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const DATE_FORMAT = 'yyyy-mm-dd hh:mm:ss';

    /**
     * @param list<array{title: string, header_rows?: list<int>, rows: list<list<mixed>>}> $sheets
     */
    public function write(array $sheets): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        foreach ($sheets as $sheet) {
            $this->fillSheet($book->createSheet(), $sheet['title'], $sheet['rows'], $sheet['header_rows'] ?? [1]);
        }
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');
        $bytes = (string) ob_get_clean();
        $book->disconnectWorksheets();

        return $bytes;
    }

    /** @param list<int> $headerRows 1-based row numbers */
    private function fillSheet(Worksheet $worksheet, string $title, array $rows, array $headerRows): void
    {
        $worksheet->setTitle($title);

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $this->setCell($worksheet, Coordinate::stringFromColumnIndex($c + 1) . ($r + 1), $value);
            }
        }

        $columnCount = max(array_map('count', $rows ?: [[]]));
        if ($columnCount === 0) {
            return;
        }
        foreach ($headerRows as $rowNumber) {
            $width = count($rows[$rowNumber - 1] ?? []);
            if ($width > 0) {
                $lastColumn = Coordinate::stringFromColumnIndex($width);
                $worksheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFont()->setBold(true);
            }
        }
        $worksheet->freezePane('A2');
        for ($c = 1; $c <= $columnCount; $c++) {
            $worksheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }

    private function setCell(Worksheet $worksheet, string $coordinate, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value instanceof DateTimeInterface) {
            $worksheet->setCellValueExplicit($coordinate, Date::PHPToExcel($value), DataType::TYPE_NUMERIC);
            $worksheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            return;
        }
        if (is_int($value) || is_float($value)) {
            $worksheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
            return;
        }
        $worksheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
    }
}
