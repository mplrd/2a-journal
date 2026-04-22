<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\ColumnMapperService;
use App\Services\Import\FileParserService;
use App\Services\Import\RowGroupingService;
use PHPUnit\Framework\TestCase;

class FxcmTemplateTest extends TestCase
{
    private ColumnMapperService $mapper;
    private FileParserService $parser;
    private RowGroupingService $grouper;
    private array $fxcmTemplate;

    protected function setUp(): void
    {
        $this->mapper = new ColumnMapperService();
        $this->parser = new FileParserService();
        $this->grouper = new RowGroupingService();
        $this->fxcmTemplate = require __DIR__ . '/../../../../config/import_templates/fxcm.php';
    }

    // ── Template structure ──────────────────────────────────────────

    public function testTemplateHasBrokerKey(): void
    {
        $this->assertSame('fxcm', $this->fxcmTemplate['broker']);
    }

    public function testTemplateSupportsXml(): void
    {
        $this->assertContains('xml', $this->fxcmTemplate['file_types']);
    }

    public function testTemplateHasAllRequiredColumns(): void
    {
        $required = ['symbol', 'direction', 'closed_at', 'entry_price', 'exit_price', 'size', 'pnl'];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $this->fxcmTemplate['columns'], "Missing column: $field");
        }
    }

    public function testTemplateHasThousandsSeparator(): void
    {
        $this->assertSame(',', $this->fxcmTemplate['thousands_separator']);
    }

    public function testTemplateHasMultiRowConfig(): void
    {
        $this->assertSame(2, $this->fxcmTemplate['multi_row']);
    }

    // ── XML parsing ─────────────────────────────────────────────────

    public function testParserSupportsXmlExtension(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows, $headers] = $this->parser->parseWithHeaders($fixturePath);

        $this->assertNotEmpty($headers);
        $this->assertNotEmpty($rows);
    }

    public function testParserFindsHeaderRowInSpreadsheetMl(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows, $headers] = $this->parser->parseWithHeaders($fixturePath);

        // Should detect "Ticket №" as first header, skip metadata rows
        $this->assertContains('Monnaie', $headers);
        $this->assertContains('Vendu', $headers);
        $this->assertContains('Achete', $headers);
        $this->assertContains('G/P Net', $headers);
    }

    public function testParserPreservesNumberPrecisionWithThousandsSeparator(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows] = $this->parser->parseWithHeaders($fixturePath);

        // First row (trade 1 open): Vendu = "19,226.05" should be preserved as string
        $this->assertSame('19,226.05', $rows[0]['Vendu']);
    }

    public function testParserReturns6DataRows(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows] = $this->parser->parseWithHeaders($fixturePath);

        // 3 trades × 2 rows each = 6, summary row filtered out
        $this->assertCount(6, $rows);
    }

    public function testParserSkipsSummaryRows(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows] = $this->parser->parseWithHeaders($fixturePath);

        // No row should have "Total:" in any field
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $this->assertNotSame('Total:', $value);
            }
        }
    }

    // ── Multi-row merging ───────────────────────────────────────────

    public function testMultiRowMergingProducesMergedRows(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows, $headers] = $this->parser->parseWithHeaders($fixturePath);

        $merged = $this->mapper->mergeMultiRows($rows, $this->fxcmTemplate);

        // 6 rows → 3 merged trades
        $this->assertCount(3, $merged);
    }

    public function testMultiRowMergingSellTrade(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows] = $this->parser->parseWithHeaders($fixturePath);

        $merged = $this->mapper->mergeMultiRows($rows, $this->fxcmTemplate);

        // Trade 1: SELL, open has price in Vendu, close has price in Achete
        $trade = $merged[0];
        $this->assertSame('GER30', $trade['Monnaie']);
        $this->assertSame('1.00', $trade['Volume']);
        $this->assertSame('SELL', $trade['_direction']);
        $this->assertSame('22/11/2024 7:43', $trade['_opened_at']);
        $this->assertSame('22/11/2024 7:44', $trade['_closed_at']);
        $this->assertSame('19,226.05', $trade['_entry_price']);
        $this->assertSame('19,222.56', $trade['_exit_price']);
        $this->assertSame('0.35', $trade['G/P Net']);
    }

    public function testMultiRowMergingBuyTrade(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows] = $this->parser->parseWithHeaders($fixturePath);

        $merged = $this->mapper->mergeMultiRows($rows, $this->fxcmTemplate);

        // Trade 3: BUY, open has price in Achete, close has price in Vendu
        $trade = $merged[2];
        $this->assertSame('BUY', $trade['_direction']);
        $this->assertSame('16/12/2024 9:40', $trade['_opened_at']);
        $this->assertSame('16/12/2024 10:20', $trade['_closed_at']);
        $this->assertSame('20,362.29', $trade['_entry_price']);
        $this->assertSame('20,323.49', $trade['_exit_price']);
        $this->assertSame('-3.88', $trade['G/P Net']);
    }

    // ── Column mapping ──────────────────────────────────────────────

    public function testMapsColumnsWithFxcmHeaders(): void
    {
        // After merging, headers include synthetic _direction, _entry_price, etc.
        $headers = ['Ticket №', 'Monnaie', 'Volume', '_direction', '_opened_at', '_closed_at', '_entry_price', '_exit_price', 'G/P Brut', 'Marges (pips)', 'Com', 'Dividendes', 'Prorogation', 'Aju', 'G/P Net'];

        $mapping = $this->mapper->mapColumns($headers, $this->fxcmTemplate);

        $this->assertSame('Monnaie', $mapping['symbol']);
        $this->assertSame('_direction', $mapping['direction']);
        $this->assertSame('_closed_at', $mapping['closed_at']);
        $this->assertSame('_opened_at', $mapping['opened_at']);
        $this->assertSame('_entry_price', $mapping['entry_price']);
        $this->assertSame('_exit_price', $mapping['exit_price']);
        $this->assertSame('Volume', $mapping['size']);
        $this->assertSame('G/P Net', $mapping['pnl']);
        $this->assertSame('Marges (pips)', $mapping['pips']);
    }

    // ── Thousands separator in numeric cast ─────────────────────────

    public function testMapRowStripsThousandsSeparator(): void
    {
        $headers = ['Monnaie', '_direction', '_opened_at', '_closed_at', '_entry_price', '_exit_price', 'Volume', 'G/P Net', 'Marges (pips)'];
        $columnMapping = $this->mapper->mapColumns($headers, $this->fxcmTemplate);

        $rawRow = [
            'Monnaie' => 'GER30',
            '_direction' => 'SELL',
            '_opened_at' => '22/11/2024 7:43',
            '_closed_at' => '22/11/2024 7:44',
            '_entry_price' => '19,226.05',
            '_exit_price' => '19,222.56',
            'Volume' => '1.00',
            'G/P Net' => '0.35',
            'Marges (pips)' => '0.50',
        ];

        $normalized = $this->mapper->mapRow($rawRow, $columnMapping, $this->fxcmTemplate);

        $this->assertEquals(19226.05, $normalized['entry_price']);
        $this->assertEquals(19222.56, $normalized['exit_price']);
        $this->assertEquals(1.0, $normalized['size']);
        $this->assertEquals(0.35, $normalized['pnl']);
    }

    // ── Full pipeline: parse → merge → map → group ──────────────────

    public function testFullPipelineWithFixture(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/fxcm_sample.xml';
        [$rows, $headers] = $this->parser->parseWithHeaders($fixturePath);

        // Merge multi-rows
        $merged = $this->mapper->mergeMultiRows($rows, $this->fxcmTemplate);

        // Map columns on merged headers
        $mergedHeaders = array_keys($merged[0]);
        $columnMapping = $this->mapper->mapColumns($mergedHeaders, $this->fxcmTemplate);

        // Normalize
        $normalized = [];
        foreach ($merged as $row) {
            $normalized[] = $this->mapper->mapRow($row, $columnMapping, $this->fxcmTemplate);
        }

        $this->assertCount(3, $normalized);

        // Trade 1: SELL GER30
        $this->assertSame('GER30', $normalized[0]['symbol']);
        $this->assertSame('SELL', $normalized[0]['direction']);
        $this->assertEquals(19226.05, $normalized[0]['entry_price']);
        $this->assertEquals(19222.56, $normalized[0]['exit_price']);
        $this->assertEquals(0.35, $normalized[0]['pnl']);

        // Trade 3: BUY GER30
        $this->assertSame('BUY', $normalized[2]['direction']);
        $this->assertEquals(20362.29, $normalized[2]['entry_price']);
        $this->assertEquals(20323.49, $normalized[2]['exit_price']);
        $this->assertEquals(-3.88, $normalized[2]['pnl']);

        // Grouping: each trade is unique (no partial exits to group)
        $groupKey = $this->fxcmTemplate['grouping']['key'];
        $positions = $this->grouper->group($normalized, $groupKey);
        $this->assertCount(3, $positions);
    }
}
