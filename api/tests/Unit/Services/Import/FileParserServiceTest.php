<?php

namespace Tests\Unit\Services\Import;

use App\Exceptions\ValidationException;
use App\Services\Import\FileParserService;
use PHPUnit\Framework\TestCase;

class FileParserServiceTest extends TestCase
{
    private FileParserService $parser;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->parser = new FileParserService();
        $this->fixturesPath = __DIR__ . '/../../../fixtures';
    }

    public function testParseXlsxReturnsRowsWithHeaders(): void
    {
        $result = $this->parser->parse($this->fixturesPath . '/ctrader_sample.xlsx');

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
        $this->assertArrayHasKey('Symbol', $result[0]);
        $this->assertArrayHasKey('Direction', $result[0]);
        $this->assertArrayHasKey('Entry Price', $result[0]);
    }

    public function testParseXlsxReturnsCorrectValues(): void
    {
        $result = $this->parser->parse($this->fixturesPath . '/ctrader_sample.xlsx');

        $this->assertSame('GER40.cash', $result[0]['Symbol']);
        $this->assertSame('Buy', $result[0]['Direction']);
        $this->assertEquals(23400.00, $result[0]['Entry Price']);
        $this->assertEquals(23450.00, $result[0]['Closing Price']);
        $this->assertEquals(0.5, $result[0]['Closing Quantity']);
        $this->assertEquals(25.00, $result[0]['EUR nets']);
    }

    public function testParseReturnsHeaders(): void
    {
        [$rows, $headers] = $this->parser->parseWithHeaders($this->fixturesPath . '/ctrader_sample.xlsx');

        $this->assertContains('Symbol', $headers);
        $this->assertContains('Direction', $headers);
        $this->assertContains('EUR nets', $headers);
        $this->assertCount(11, $headers);
    }

    public function testRejectsUnsupportedFileType(): void
    {
        $this->expectException(ValidationException::class);
        $this->parser->parse($this->fixturesPath . '/invalid.txt');
    }

    public function testRejectsNonExistentFile(): void
    {
        $this->expectException(ValidationException::class);
        $this->parser->parse($this->fixturesPath . '/does_not_exist.xlsx');
    }

    public function testParseCsvReturnsRows(): void
    {
        // Create a temp CSV fixture
        $csvPath = $this->fixturesPath . '/test_temp.csv';
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, ['Symbol', 'Direction', 'Closing Time', 'Entry Price', 'Closing Price', 'Closing Quantity', 'Closing Volume', 'EUR nets', 'Balance EUR', 'Pips', 'Comment'], ';');
        fputcsv($fp, ['GER40.cash', 'Buy', '15/01/2026 10:30:00.000', '23400', '23450', '0.5', '0.5', '25', '10025', '50', ''], ';');
        fclose($fp);

        try {
            $result = $this->parser->parse($csvPath);
            $this->assertCount(1, $result);
            $this->assertSame('GER40.cash', $result[0]['Symbol']);
        } finally {
            unlink($csvPath);
        }
    }
}
