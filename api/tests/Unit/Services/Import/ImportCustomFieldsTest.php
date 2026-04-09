<?php

namespace Tests\Unit\Services\Import;

use App\Repositories\AccountRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\TradeRepository;
use App\Services\Import\ColumnMapperService;
use App\Services\Import\FileParserService;
use App\Services\Import\ImportService;
use App\Services\Import\RowGroupingService;
use PDO;
use PHPUnit\Framework\TestCase;

class ImportCustomFieldsTest extends TestCase
{
    private ImportService $service;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/../../../fixtures';

        $this->service = new ImportService(
            new FileParserService(),
            new ColumnMapperService(),
            new RowGroupingService(),
            $this->createMock(ImportBatchRepository::class),
            $this->createMock(SymbolAliasRepository::class),
            $this->createMock(SymbolRepository::class),
            $this->createMock(PositionRepository::class),
            $this->createMock(TradeRepository::class),
            $this->createMock(AccountRepository::class),
            $this->createMock(PDO::class)
        );
    }

    public function testPreviewWithCustomFieldsMappingAttachesValuesToPositions(): void
    {
        $template = $this->buildCustomTemplate();
        $customFieldsMapping = ['42' => 'Trend'];

        $result = $this->service->preview(
            $this->fixturesPath . '/custom_fields_sample.csv',
            $template,
            'custom_fields_sample.csv',
            $customFieldsMapping
        );

        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(2, $result['total_positions']);

        // EURUSD position (grouped from 2 rows) should have custom field value
        $eurusd = $this->findPosition($result['positions'], 'EURUSD');
        $this->assertNotNull($eurusd);
        $this->assertArrayHasKey('custom_fields', $eurusd);
        $this->assertCount(1, $eurusd['custom_fields']);
        $this->assertSame(42, $eurusd['custom_fields'][0]['field_id']);
        $this->assertSame('Bullish', $eurusd['custom_fields'][0]['value']);

        // GBPUSD position should also have custom field value
        $gbpusd = $this->findPosition($result['positions'], 'GBPUSD');
        $this->assertNotNull($gbpusd);
        $this->assertCount(1, $gbpusd['custom_fields']);
        $this->assertSame('Bearish', $gbpusd['custom_fields'][0]['value']);
    }

    public function testPreviewWithMultipleCustomFieldsMappings(): void
    {
        $template = $this->buildCustomTemplate();
        $customFieldsMapping = [
            '42' => 'Trend',
            '43' => 'Score',
        ];

        $result = $this->service->preview(
            $this->fixturesPath . '/custom_fields_sample.csv',
            $template,
            'custom_fields_sample.csv',
            $customFieldsMapping
        );

        $eurusd = $this->findPosition($result['positions'], 'EURUSD');
        $this->assertCount(2, $eurusd['custom_fields']);

        $fieldIds = array_column($eurusd['custom_fields'], 'field_id');
        $this->assertContains(42, $fieldIds);
        $this->assertContains(43, $fieldIds);
    }

    public function testPreviewWithoutCustomFieldsMappingHasEmptyCustomFields(): void
    {
        $template = $this->buildCustomTemplate();

        $result = $this->service->preview(
            $this->fixturesPath . '/custom_fields_sample.csv',
            $template,
            'custom_fields_sample.csv'
        );

        foreach ($result['positions'] as $position) {
            $this->assertArrayHasKey('custom_fields', $position);
            $this->assertEmpty($position['custom_fields']);
        }
    }

    public function testPreviewCustomFieldsUsesFirstRowValueForGroupedPositions(): void
    {
        $template = $this->buildCustomTemplate();
        $customFieldsMapping = ['43' => 'Score'];

        $result = $this->service->preview(
            $this->fixturesPath . '/custom_fields_sample.csv',
            $template,
            'custom_fields_sample.csv',
            $customFieldsMapping
        );

        // EURUSD has 2 rows: Score=8 and Score=7. Should use first row's value.
        $eurusd = $this->findPosition($result['positions'], 'EURUSD');
        $this->assertSame('8', $eurusd['custom_fields'][0]['value']);
    }

    public function testPreviewSkipsEmptyCustomFieldValues(): void
    {
        // Create CSV with an empty Trend value
        $csvContent = "Symbol,Direction,Close Date,Entry,Exit,Size,PnL,Trend\n"
            . "EURUSD,Buy,2024-01-15 10:00:00,1.1000,1.1050,1.0,50.00,\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'import_test_');
        file_put_contents($tmpFile, $csvContent);

        try {
            $template = $this->buildCustomTemplate();
            $customFieldsMapping = ['42' => 'Trend'];

            $result = $this->service->preview($tmpFile, $template, 'test.csv', $customFieldsMapping);

            $eurusd = $this->findPosition($result['positions'], 'EURUSD');
            $this->assertEmpty($eurusd['custom_fields']);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testPreviewIgnoresUnmatchedCustomFieldColumns(): void
    {
        $template = $this->buildCustomTemplate();
        // Map to a column that doesn't exist in the file
        $customFieldsMapping = ['42' => 'NonExistentColumn'];

        $result = $this->service->preview(
            $this->fixturesPath . '/custom_fields_sample.csv',
            $template,
            'custom_fields_sample.csv',
            $customFieldsMapping
        );

        foreach ($result['positions'] as $position) {
            $this->assertEmpty($position['custom_fields']);
        }
    }

    private function buildCustomTemplate(): array
    {
        return $this->service->buildCustomTemplate(
            [
                'symbol' => 'Symbol',
                'direction' => 'Direction',
                'closed_at' => 'Close Date',
                'entry_price' => 'Entry',
                'exit_price' => 'Exit',
                'size' => 'Size',
                'pnl' => 'PnL',
            ],
            [
                'date_format' => 'Y-m-d H:i:s',
                'direction_buy' => 'Buy',
                'direction_sell' => 'Sell',
            ]
        );
    }

    private function findPosition(array $positions, string $symbol): ?array
    {
        foreach ($positions as $pos) {
            if ($pos['symbol'] === $symbol) {
                return $pos;
            }
        }
        return null;
    }
}
