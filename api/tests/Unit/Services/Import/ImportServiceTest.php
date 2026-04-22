<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\ImportService;
use App\Services\Import\FileParserService;
use App\Services\Import\ColumnMapperService;
use App\Services\Import\RowGroupingService;
use App\Repositories\ImportBatchRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\AccountRepository;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class ImportServiceTest extends TestCase
{
    private ImportService $service;
    private FileParserService $parser;
    private ColumnMapperService $mapper;
    private RowGroupingService $grouper;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->parser = new FileParserService();
        $this->mapper = new ColumnMapperService();
        $this->grouper = new RowGroupingService();
        $this->fixturesPath = __DIR__ . '/../../../fixtures';

        // ImportService needs real parser/mapper/grouper but mocked DB deps
        $pdo = $this->createMock(PDO::class);
        $batchRepo = $this->createMock(ImportBatchRepository::class);
        $aliasRepo = $this->createMock(SymbolAliasRepository::class);
        $symbolRepo = $this->createMock(SymbolRepository::class);
        $posRepo = $this->createMock(PositionRepository::class);
        $tradeRepo = $this->createMock(TradeRepository::class);
        $accountRepo = $this->createMock(AccountRepository::class);

        $this->service = new ImportService(
            $this->parser,
            $this->mapper,
            $this->grouper,
            $batchRepo,
            $aliasRepo,
            $symbolRepo,
            $posRepo,
            $tradeRepo,
            $accountRepo,
            $pdo
        );
    }

    public function testPreviewReturnsParsedPositions(): void
    {
        $template = require __DIR__ . '/../../../../config/import_templates/ctrader.php';

        $result = $this->service->preview(
            $this->fixturesPath . '/ctrader_sample.xlsx',
            $template
        );

        $this->assertArrayHasKey('positions', $result);
        $this->assertArrayHasKey('total_rows', $result);
        $this->assertArrayHasKey('total_positions', $result);
        $this->assertArrayHasKey('unknown_symbols', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertSame(5, $result['total_rows']);
        $this->assertSame(3, $result['total_positions']);
        $this->assertSame('EUR', $result['currency']);
    }

    public function testPreviewGroupsPartialExits(): void
    {
        $template = require __DIR__ . '/../../../../config/import_templates/ctrader.php';

        $result = $this->service->preview(
            $this->fixturesPath . '/ctrader_sample.xlsx',
            $template
        );

        // Position 1: GER40 BUY @ 23400 has 3 partial exits
        $ger40Buy = null;
        foreach ($result['positions'] as $pos) {
            if ($pos['symbol'] === 'GER40.cash' && $pos['direction'] === 'BUY') {
                $ger40Buy = $pos;
                break;
            }
        }

        $this->assertNotNull($ger40Buy);
        $this->assertCount(3, $ger40Buy['exits']);
        $this->assertEquals(1.0, $ger40Buy['total_size']);
        $this->assertEquals(39.0, $ger40Buy['total_pnl']);
    }

    public function testPreviewIdentifiesUnknownSymbols(): void
    {
        $template = require __DIR__ . '/../../../../config/import_templates/ctrader.php';

        $result = $this->service->preview(
            $this->fixturesPath . '/ctrader_sample.xlsx',
            $template
        );

        // All symbols are "unknown" since no aliases exist in the mock
        $this->assertContains('GER40.cash', $result['unknown_symbols']);
        $this->assertContains('EURUSD', $result['unknown_symbols']);
    }

    public function testGetAvailableTemplates(): void
    {
        $templates = $this->service->getAvailableTemplates();

        $this->assertIsArray($templates);
        $this->assertNotEmpty($templates);

        $ctrader = null;
        foreach ($templates as $t) {
            if ($t['broker'] === 'ctrader') {
                $ctrader = $t;
                break;
            }
        }
        $this->assertNotNull($ctrader);
        $this->assertSame('cTrader', $ctrader['label']);
    }

    public function testGetAvailableTemplatesIncludesGeneric(): void
    {
        $templates = $this->service->getAvailableTemplates();

        $generic = null;
        foreach ($templates as $t) {
            if ($t['broker'] === 'generic') {
                $generic = $t;
                break;
            }
        }
        $this->assertNotNull($generic);
        $this->assertSame('Standard (CSV)', $generic['label']);
        $this->assertContains('csv', $generic['file_types']);
        $this->assertContains('xlsx', $generic['file_types']);
    }

    public function testPreviewWithGenericTemplate(): void
    {
        $template = require __DIR__ . '/../../../../config/import_templates/generic.php';

        $result = $this->service->preview(
            $this->fixturesPath . '/generic_sample.csv',
            $template
        );

        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(2, $result['total_positions']);

        // Check EURUSD grouped (2 rows → 1 position with 2 exits)
        $eurusd = null;
        foreach ($result['positions'] as $pos) {
            if ($pos['symbol'] === 'EURUSD') {
                $eurusd = $pos;
                break;
            }
        }
        $this->assertNotNull($eurusd);
        $this->assertSame('BUY', $eurusd['direction']);
        $this->assertCount(2, $eurusd['exits']);
        $this->assertEquals(1.5, $eurusd['total_size']);
    }

    public function testCustomImportWithMinimalFields(): void
    {
        // Only symbol, direction, entry_price — no exit, size, pnl, date
        $csvContent = "Symbol,Side,Entry\n"
            . "EURUSD,Buy,1.1000\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'minimal_test_');
        file_put_contents($tmpFile, $csvContent);

        try {
            $template = $this->service->buildCustomTemplate(
                ['symbol' => 'Symbol', 'direction' => 'Side', 'entry_price' => 'Entry'],
                ['direction_buy' => 'Buy', 'direction_sell' => 'Sell']
            );

            $result = $this->service->preview($tmpFile, $template, 'test.csv');
            $this->assertSame(1, $result['total_positions']);

            $pos = $result['positions'][0];
            $this->assertSame('EURUSD', $pos['symbol']);
            $this->assertSame('BUY', $pos['direction']);
            $this->assertEquals(1.0, $pos['total_size']); // default size
            $this->assertNull($pos['closed_at']); // no date = OPEN trade
        } finally {
            unlink($tmpFile);
        }
    }

    public function testCustomImportWithClosedAtCreatesClosedTrade(): void
    {
        $csvContent = "Symbol,Side,Date,Entry,Exit,PnL\n"
            . "EURUSD,Buy,2024-01-15 10:00:00,1.1000,1.1050,50.00\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'closed_test_');
        file_put_contents($tmpFile, $csvContent);

        try {
            $template = $this->service->buildCustomTemplate(
                ['symbol' => 'Symbol', 'direction' => 'Side', 'closed_at' => 'Date',
                 'entry_price' => 'Entry', 'exit_price' => 'Exit', 'pnl' => 'PnL'],
                ['date_format' => 'Y-m-d H:i:s', 'direction_buy' => 'Buy', 'direction_sell' => 'Sell']
            );

            $result = $this->service->preview($tmpFile, $template, 'test.csv');
            $pos = $result['positions'][0];

            $this->assertSame('2024-01-15 10:00:00', $pos['closed_at']);
            $this->assertEquals(50.0, $pos['total_pnl']);
            $this->assertEquals(1.1050, $pos['avg_exit_price']);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testGenericTemplateRejectsNonMatchingFile(): void
    {
        $template = require __DIR__ . '/../../../../config/import_templates/generic.php';

        // custom_fields_sample.csv has different column names
        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->service->preview(
            $this->fixturesPath . '/custom_fields_sample.csv',
            $template,
            'custom_fields_sample.csv'
        );
    }

    public function testGenericTemplateImportsStandardCsv(): void
    {
        $template = require __DIR__ . '/../../../../config/import_templates/generic.php';

        $result = $this->service->preview(
            __DIR__ . '/../../../../public/templates/import-template.csv',
            $template,
            'import-template.csv'
        );

        $this->assertSame(2, $result['total_positions']);

        // EURUSD: closed trade with exit price
        $eurusd = null;
        $nasdaq = null;
        foreach ($result['positions'] as $pos) {
            if ($pos['symbol'] === 'EURUSD') $eurusd = $pos;
            if ($pos['symbol'] === 'NASDAQ') $nasdaq = $pos;
        }
        $this->assertNotNull($eurusd['closed_at']);
        $this->assertEquals(1.1050, $eurusd['avg_exit_price']);

        // NASDAQ: open trade (no close date, no exit price)
        $this->assertNull($nasdaq['closed_at']);
    }

}
