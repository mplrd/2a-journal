<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\ColumnMapperService;
use App\Services\Import\FileParserService;
use App\Services\Import\RowGroupingService;
use PHPUnit\Framework\TestCase;

class FtmoTemplateTest extends TestCase
{
    private ColumnMapperService $mapper;
    private FileParserService $parser;
    private RowGroupingService $grouper;
    private array $ftmoTemplate;

    protected function setUp(): void
    {
        $this->mapper = new ColumnMapperService();
        $this->parser = new FileParserService();
        $this->grouper = new RowGroupingService();
        $this->ftmoTemplate = require __DIR__ . '/../../../../config/import_templates/ftmo.php';
    }

    // ── Template structure ──────────────────────────────────────────

    public function testTemplateHasBrokerKey(): void
    {
        $this->assertSame('ftmo', $this->ftmoTemplate['broker']);
    }

    public function testTemplateSupportsCsvAndXlsx(): void
    {
        $this->assertContains('csv', $this->ftmoTemplate['file_types']);
        $this->assertContains('xlsx', $this->ftmoTemplate['file_types']);
    }

    public function testTemplateHasAllRequiredColumns(): void
    {
        $required = ['symbol', 'direction', 'closed_at', 'entry_price', 'exit_price', 'size', 'pnl'];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $this->ftmoTemplate['columns'], "Missing column: $field");
        }
    }

    public function testTemplateHasOptionalColumns(): void
    {
        $this->assertArrayHasKey('pips', $this->ftmoTemplate['columns']);
        $this->assertArrayHasKey('opened_at', $this->ftmoTemplate['columns']);
    }

    // ── Duplicate header handling in FileParserService ───────────────

    public function testParserDeduplicatesDuplicateHeaders(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/ftmo_sample.csv';
        [$rows, $headers] = $this->parser->parseWithHeaders($fixturePath);

        // The two "Prix" columns should be deduplicated
        $prixCount = count(array_filter($headers, fn($h) => str_starts_with($h, 'Prix')));
        $this->assertSame(2, $prixCount);

        // Second "Prix" should be renamed to "Prix_2"
        $this->assertContains('Prix', $headers);
        $this->assertContains('Prix_2', $headers);
    }

    public function testParserKeepsAllDataWithDuplicateHeaders(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/ftmo_sample.csv';
        [$rows] = $this->parser->parseWithHeaders($fixturePath);

        // First row: entry_price = 22965.31, exit_price = 22964.20
        $this->assertEquals(22965.31, $rows[0]['Prix']);
        $this->assertEquals(22964.20, $rows[0]['Prix_2']);
    }

    // ── Column mapping ──────────────────────────────────────────────

    public function testMapsColumnsWithFtmoHeaders(): void
    {
        $headers = ['Ticket', 'Ouvrir', 'Type', 'Volume', 'Symbole', 'Prix', 'SL', 'TP', 'Fermeture', 'Prix_2', 'Swap', 'Commissions', 'Profit', 'Pips', 'Durée du trade en secondes'];

        $mapping = $this->mapper->mapColumns($headers, $this->ftmoTemplate);

        $this->assertSame('Symbole', $mapping['symbol']);
        $this->assertSame('Type', $mapping['direction']);
        $this->assertSame('Fermeture', $mapping['closed_at']);
        $this->assertSame('Ouvrir', $mapping['opened_at']);
        $this->assertSame('Prix', $mapping['entry_price']);
        $this->assertSame('Prix_2', $mapping['exit_price']);
        $this->assertSame('Volume', $mapping['size']);
        $this->assertSame('Profit', $mapping['pnl']);
        $this->assertSame('Pips', $mapping['pips']);
    }

    public function testMapsColumnsWithEnglishHeaders(): void
    {
        $headers = ['Ticket', 'Open', 'Type', 'Volume', 'Symbol', 'Price', 'SL', 'TP', 'Close', 'Price_2', 'Swap', 'Commissions', 'Profit', 'Pips', 'Trade duration in seconds'];

        $mapping = $this->mapper->mapColumns($headers, $this->ftmoTemplate);

        $this->assertSame('Symbol', $mapping['symbol']);
        $this->assertSame('Type', $mapping['direction']);
        $this->assertSame('Close', $mapping['closed_at']);
        $this->assertSame('Open', $mapping['opened_at']);
        $this->assertSame('Price', $mapping['entry_price']);
        $this->assertSame('Price_2', $mapping['exit_price']);
    }

    // ── Direction mapping ───────────────────────────────────────────

    public function testAppliesDirectionMapping(): void
    {
        $colDef = $this->ftmoTemplate['columns']['direction'];

        $row = ['Type' => 'sell'];
        $this->assertSame('SELL', $this->mapper->applyValueMappings($row, 'Type', $colDef));

        $row2 = ['Type' => 'buy'];
        $this->assertSame('BUY', $this->mapper->applyValueMappings($row2, 'Type', $colDef));
    }

    public function testAppliesDirectionMappingCaseInsensitive(): void
    {
        $colDef = $this->ftmoTemplate['columns']['direction'];

        $row = ['Type' => 'Sell'];
        $this->assertSame('SELL', $this->mapper->applyValueMappings($row, 'Type', $colDef));

        $row2 = ['Type' => 'Buy'];
        $this->assertSame('BUY', $this->mapper->applyValueMappings($row2, 'Type', $colDef));
    }

    // ── Row mapping (full pipeline) ─────────────────────────────────

    public function testMapRowConvertsRawRowToNormalized(): void
    {
        $headers = ['Ticket', 'Ouvrir', 'Type', 'Volume', 'Symbole', 'Prix', 'SL', 'TP', 'Fermeture', 'Prix_2', 'Swap', 'Commissions', 'Profit', 'Pips', 'Durée du trade en secondes'];
        $columnMapping = $this->mapper->mapColumns($headers, $this->ftmoTemplate);

        $rawRow = [
            'Ticket' => 132729766,
            'Ouvrir' => '2026-04-02 10:36:09',
            'Type' => 'sell',
            'Volume' => 0.25,
            'Symbole' => 'GER40.cash',
            'Prix' => 22965.31,
            'SL' => 22963.31,
            'TP' => 0,
            'Fermeture' => '2026-04-02 16:33:14',
            'Prix_2' => 22964.20,
            'Swap' => 0,
            'Commissions' => 0,
            'Profit' => 0.28,
            'Pips' => 1.1,
            'Durée du trade en secondes' => 21425,
        ];

        $normalized = $this->mapper->mapRow($rawRow, $columnMapping, $this->ftmoTemplate);

        $this->assertSame('GER40.cash', $normalized['symbol']);
        $this->assertSame('SELL', $normalized['direction']);
        $this->assertSame('2026-04-02 16:33:14', $normalized['closed_at']);
        $this->assertSame('2026-04-02 10:36:09', $normalized['opened_at']);
        $this->assertEquals(22965.31, $normalized['entry_price']);
        $this->assertEquals(22964.20, $normalized['exit_price']);
        $this->assertEquals(0.25, $normalized['size']);
        $this->assertEquals(0.28, $normalized['pnl']);
        $this->assertEquals(1.1, $normalized['pips']);
    }

    // ── Full pipeline: parse → map → group ──────────────────────────

    public function testFullPipelineWithFixture(): void
    {
        $fixturePath = __DIR__ . '/../../../../tests/fixtures/ftmo_sample.csv';
        [$rows, $headers] = $this->parser->parseWithHeaders($fixturePath);

        $columnMapping = $this->mapper->mapColumns($headers, $this->ftmoTemplate);

        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = $this->mapper->mapRow($row, $columnMapping, $this->ftmoTemplate);
        }

        $this->assertCount(5, $normalized);

        // First row
        $this->assertSame('GER40.cash', $normalized[0]['symbol']);
        $this->assertSame('SELL', $normalized[0]['direction']);
        $this->assertEquals(22965.31, $normalized[0]['entry_price']);
        $this->assertEquals(22964.20, $normalized[0]['exit_price']);
        $this->assertEquals(0.28, $normalized[0]['pnl']);
        $this->assertSame('2026-04-02 10:36:09', $normalized[0]['opened_at']);
        $this->assertSame('2026-04-02 16:33:14', $normalized[0]['closed_at']);

        // Grouping: rows with same (symbol, direction, entry_price, opened_at) should group
        $groupKey = $this->ftmoTemplate['grouping']['key'];
        $positions = $this->grouper->group($normalized, $groupKey);

        // 5 rows with 3 distinct (symbol, direction, entry_price, opened_at) combos:
        // (GER40.cash, SELL, 22965.31, 2026-04-02 10:36:09) → 2 trades (tickets 132729766, 132693349)
        // (GER40.cash, SELL, 22925.51, 2026-04-02 09:23:02) → 2 trades (tickets 132726793, 132688432)
        // (GER40.cash, SELL, 23285.61, 2026-04-01 09:35:21) → 1 trade
        $this->assertCount(3, $positions);
    }

    // ── Grouping uses opened_at in key ──────────────────────────────

    public function testGroupingKeyIncludesOpenedAt(): void
    {
        $this->assertContains('opened_at', $this->ftmoTemplate['grouping']['key']);
    }
}
