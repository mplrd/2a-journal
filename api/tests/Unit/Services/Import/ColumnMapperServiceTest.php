<?php

namespace Tests\Unit\Services\Import;

use App\Exceptions\ValidationException;
use App\Services\Import\ColumnMapperService;
use PHPUnit\Framework\TestCase;

class ColumnMapperServiceTest extends TestCase
{
    private ColumnMapperService $mapper;
    private array $ctraderTemplate;

    protected function setUp(): void
    {
        $this->mapper = new ColumnMapperService();
        $this->ctraderTemplate = require __DIR__ . '/../../../../config/import_templates/ctrader.php';
    }

    public function testMapsColumnsWithEnglishHeaders(): void
    {
        $headers = ['Symbol', 'Direction', 'Closing Time', 'Entry Price', 'Closing Price', 'Closing Quantity', 'Closing Volume', 'EUR nets', 'Balance EUR', 'Pips', 'Comment'];

        $mapping = $this->mapper->mapColumns($headers, $this->ctraderTemplate);

        $this->assertSame('Symbol', $mapping['symbol']);
        $this->assertSame('Direction', $mapping['direction']);
        $this->assertSame('Closing Time', $mapping['closed_at']);
        $this->assertSame('Entry Price', $mapping['entry_price']);
        $this->assertSame('Closing Price', $mapping['exit_price']);
        $this->assertSame('Closing Quantity', $mapping['size']);
        $this->assertSame('EUR nets', $mapping['pnl']);
        $this->assertSame('Pips', $mapping['pips']);
        $this->assertSame('Comment', $mapping['comment']);
    }

    public function testMapsColumnsWithFrenchHeaders(): void
    {
        $headers = ['Symbole', "Sens d'ouverture", 'Heure de clôture', "Cours d'entrée", 'Price de clôture', 'Quantité de clôture', 'Volume de clôture', '€ nets', 'Solde €', 'Pips', 'Commentaire'];

        $mapping = $this->mapper->mapColumns($headers, $this->ctraderTemplate);

        $this->assertSame('Symbole', $mapping['symbol']);
        $this->assertSame("Sens d'ouverture", $mapping['direction']);
        $this->assertSame("Cours d'entrée", $mapping['entry_price']);
    }

    public function testDetectsCurrencyFromPnlColumn(): void
    {
        $headers = ['Symbol', 'Direction', 'Closing Time', 'Entry Price', 'Closing Price', 'Closing Quantity', 'Closing Volume', 'USD nets', 'Balance USD', 'Pips', 'Comment'];

        $result = $this->mapper->mapColumns($headers, $this->ctraderTemplate);
        $currency = $this->mapper->detectCurrency($headers, $this->ctraderTemplate);

        $this->assertSame('USD', $currency);
    }

    public function testDetectsCurrencyEur(): void
    {
        $headers = ['Symbol', 'Direction', 'Closing Time', 'Entry Price', 'Closing Price', 'Closing Quantity', 'Closing Volume', '€ nets', 'Solde €', 'Pips', 'Comment'];

        $currency = $this->mapper->detectCurrency($headers, $this->ctraderTemplate);

        $this->assertSame('EUR', $currency);
    }

    public function testThrowsOnMissingRequiredColumn(): void
    {
        $headers = ['Symbol', 'Direction', 'Closing Time'];

        $this->expectException(ValidationException::class);
        $this->mapper->mapColumns($headers, $this->ctraderTemplate);
    }

    public function testAppliesDirectionValueMapping(): void
    {
        $row = ['Direction' => 'Buy'];
        $mapped = $this->mapper->applyValueMappings($row, 'Direction', $this->ctraderTemplate['columns']['direction']);
        $this->assertSame('BUY', $mapped);

        $row2 = ['Direction' => 'Sell'];
        $mapped2 = $this->mapper->applyValueMappings($row2, 'Direction', $this->ctraderTemplate['columns']['direction']);
        $this->assertSame('SELL', $mapped2);
    }

    public function testParsesDateWithTemplateFormat(): void
    {
        $dateStr = '16/03/2026 11:32:57.406';
        $format = $this->ctraderTemplate['columns']['closed_at']['format'];

        $parsed = $this->mapper->parseDate($dateStr, $format);

        $this->assertSame('2026-03-16 11:32:57', $parsed);
    }

    public function testMapRowConvertsRawRowToNormalized(): void
    {
        $headers = ['Symbol', 'Direction', 'Closing Time', 'Entry Price', 'Closing Price', 'Closing Quantity', 'Closing Volume', 'EUR nets', 'Balance EUR', 'Pips', 'Comment'];
        $columnMapping = $this->mapper->mapColumns($headers, $this->ctraderTemplate);

        $rawRow = [
            'Symbol' => 'GER40.cash',
            'Direction' => 'Buy',
            'Closing Time' => '15/01/2026 10:30:00.000',
            'Entry Price' => 23400.00,
            'Closing Price' => 23450.00,
            'Closing Quantity' => 0.5,
            'Closing Volume' => 0.5,
            'EUR nets' => 25.00,
            'Balance EUR' => 10025.00,
            'Pips' => 50.00,
            'Comment' => '',
        ];

        $normalized = $this->mapper->mapRow($rawRow, $columnMapping, $this->ctraderTemplate);

        $this->assertSame('GER40.cash', $normalized['symbol']);
        $this->assertSame('BUY', $normalized['direction']);
        $this->assertSame('2026-01-15 10:30:00', $normalized['closed_at']);
        $this->assertEquals(23400.00, $normalized['entry_price']);
        $this->assertEquals(23450.00, $normalized['exit_price']);
        $this->assertEquals(0.5, $normalized['size']);
        $this->assertEquals(25.00, $normalized['pnl']);
        $this->assertEquals(50.00, $normalized['pips']);
    }
}
