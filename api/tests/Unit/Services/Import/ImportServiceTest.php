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
        $this->assertEquals(65.00, $eurusd['total_pnl']);
        $this->assertEquals(1.5, $eurusd['total_size']);
    }

    public function testGenericTemplateMatchesCommonDirectionValues(): void
    {
        // Test that Long/Short, Achat/Vente are properly mapped
        $template = require __DIR__ . '/../../../../config/import_templates/generic.php';

        $csvContent = "Symbol,Direction,Close Date,Entry Price,Exit Price,Size,PnL\n"
            . "EURUSD,Long,2024-01-15 10:00:00,1.1000,1.1050,1.0,50.00\n"
            . "GBPUSD,Short,2024-01-15 11:00:00,1.2700,1.2650,0.5,25.00\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'generic_test_');
        file_put_contents($tmpFile, $csvContent);

        try {
            $result = $this->service->preview($tmpFile, $template, 'test.csv');
            $this->assertSame(2, $result['total_positions']);

            $directions = array_column($result['positions'], 'direction');
            $this->assertContains('BUY', $directions);
            $this->assertContains('SELL', $directions);
        } finally {
            unlink($tmpFile);
        }
    }
}
