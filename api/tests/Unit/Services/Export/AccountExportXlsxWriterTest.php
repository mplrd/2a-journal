<?php

namespace Tests\Unit\Services\Export;

use App\Services\Export\AccountExportXlsxWriter;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class AccountExportXlsxWriterTest extends TestCase
{
    private function sheets(array $tradeRows = []): array
    {
        return [
            ['title' => 'Compte', 'header_rows' => [1, 6], 'rows' => [
                ['Champ', 'Valeur'], ['Nom', 'FTMO'], ['Solde', 9868.55], ['Phase', null], [],
                ['Date', 'Montant', 'Motif'], [new DateTimeImmutable('2026-06-03 12:00:00'), -2.0, 'Frais'],
            ]],
            ['title' => 'Trades', 'header_rows' => [1], 'rows' => array_merge([['Ouverture', 'Taille', 'P&L', 'Notes']], $tradeRows)],
        ];
    }

    /** Write, then read back the bytes the way a user's Excel would get them. */
    private function roundTrip(array $sheets): Spreadsheet
    {
        $bytes = (new AccountExportXlsxWriter())->write($sheets);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testWrite_returnsAnXlsxPackage(): void
    {
        $bytes = (new AccountExportXlsxWriter())->write($this->sheets());

        $this->assertStringStartsWith('PK', $bytes);
    }

    public function testWrite_createsOneTabPerSheetInOrder(): void
    {
        $book = $this->roundTrip($this->sheets());

        $this->assertSame(['Compte', 'Trades'], $book->getSheetNames());
        $this->assertSame(0, $book->getActiveSheetIndex());
    }

    public function testWrite_putsHeadersInBoldOnTheFirstRow(): void
    {
        $sheet = $this->roundTrip($this->sheets())->getSheetByName('Trades');

        $this->assertSame('Ouverture', $sheet->getCell('A1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A2', $sheet->getFreezePane());
    }

    public function testWrite_boldsEveryHeaderRowOfASheet(): void
    {
        $sheet = $this->roundTrip($this->sheets())->getSheetByName('Compte');

        $this->assertTrue($sheet->getStyle('A6')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('C6')->getFont()->getBold());
        $this->assertFalse($sheet->getStyle('A7')->getFont()->getBold());
        $this->assertNull($sheet->getCell('A5')->getValue());
        $this->assertSame('Frais', $sheet->getCell('C7')->getValue());
    }

    public function testWrite_keepsNumbersAsNumbers(): void
    {
        $sheet = $this->roundTrip($this->sheets([[null, 0.1, -13.1, null]]))->getSheetByName('Trades');

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertEqualsWithDelta(-13.1, $sheet->getCell('C2')->getValue(), 1e-9);
    }

    public function testWrite_writesDatesAsRealSpreadsheetDates(): void
    {
        $opened = new DateTimeImmutable('2026-09-24 10:22:40');
        $sheet = $this->roundTrip($this->sheets([[$opened, 0.1, 4.49, null]]))->getSheetByName('Trades');

        $cell = $sheet->getCell('A2');
        $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
        $this->assertTrue(Date::isDateTime($cell));
        $this->assertSame('2026-09-24 10:22:40', Date::excelToDateTimeObject($cell->getValue())->format('Y-m-d H:i:s'));
    }

    public function testWrite_leavesNullCellsEmpty(): void
    {
        $sheet = $this->roundTrip($this->sheets())->getSheetByName('Compte');

        $this->assertNull($sheet->getCell('B4')->getValue());
    }

    public function testWrite_neverTurnsUserTextIntoAFormula(): void
    {
        // Formula injection: a note or setup typed as "=..." must stay text in
        // the file, never a formula Excel would evaluate on open.
        $payloads = ['=HYPERLINK("http://evil","x")', '+1+1', '-2+3', '@SUM(A1)'];
        $rows = array_map(fn ($p) => [null, null, null, $p], $payloads);

        $sheet = $this->roundTrip($this->sheets($rows))->getSheetByName('Trades');

        foreach ($payloads as $i => $payload) {
            $cell = $sheet->getCell('D' . ($i + 2));
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), $payload);
            $this->assertSame($payload, $cell->getValue());
        }
    }
}
