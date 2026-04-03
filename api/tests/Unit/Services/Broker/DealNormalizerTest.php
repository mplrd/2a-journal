<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\DealNormalizer;
use PHPUnit\Framework\TestCase;

class DealNormalizerTest extends TestCase
{
    private DealNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new DealNormalizer();
    }

    // ── cTrader deals ───────────────────────────────────────────────

    public function testNormalizeCtraderClosingDeal(): void
    {
        $deal = [
            'dealId' => 12345,
            'orderId' => 111,
            'positionId' => 999,
            'volume' => 50000, // in cents → 500.00 units → 0.5 lots (volume/100000)
            'filledVolume' => 50000,
            'symbolId' => 22,
            'symbolName' => 'GER40',
            'createTimestamp' => 1700000000000, // ms
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 19226.05,
            'tradeSide' => 'SELL',
            'dealStatus' => 'FILLED',
            'commission' => -50, // cents
            'swap' => 0,
            'closePositionDetail' => [
                'entryPrice' => 19200.00,
                'grossProfit' => 2605,  // cents
                'swap' => 0,
                'commission' => -50,
                'balance' => 1002605,
                'closedVolume' => 50000,
            ],
        ];

        $normalized = $this->normalizer->normalizeCtraderDeal($deal);

        $this->assertSame('GER40', $normalized['symbol']);
        $this->assertSame('SELL', $normalized['direction']);
        $this->assertEquals(19200.00, $normalized['entry_price']);
        $this->assertEquals(19226.05, $normalized['exit_price']);
        $this->assertEquals(0.5, $normalized['size']);
        $this->assertEquals(26.05, $normalized['pnl']); // grossProfit/100
        $this->assertSame('ctrader_999', $normalized['external_id']);
        $this->assertNotNull($normalized['closed_at']);
    }

    public function testNormalizeCtraderDealConvertsTimestamps(): void
    {
        $deal = [
            'dealId' => 1,
            'positionId' => 2,
            'volume' => 100000,
            'symbolName' => 'EURUSD',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 1.0950,
            'tradeSide' => 'BUY',
            'dealStatus' => 'FILLED',
            'commission' => 0,
            'swap' => 0,
            'closePositionDetail' => [
                'entryPrice' => 1.0900,
                'grossProfit' => 500,
                'swap' => -10,
                'commission' => -20,
                'closedVolume' => 100000,
            ],
        ];

        $normalized = $this->normalizer->normalizeCtraderDeal($deal);

        // Timestamps should be Y-m-d H:i:s format
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalized['closed_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalized['opened_at']);
    }

    public function testNormalizeCtraderDealSkipsNonClosing(): void
    {
        $deal = [
            'dealId' => 1,
            'positionId' => 2,
            'volume' => 100000,
            'symbolName' => 'EURUSD',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700000000000,
            'executionPrice' => 1.0900,
            'tradeSide' => 'BUY',
            'dealStatus' => 'FILLED',
            'commission' => 0,
            'swap' => 0,
            // No closePositionDetail = opening deal
        ];

        $normalized = $this->normalizer->normalizeCtraderDeal($deal);

        $this->assertNull($normalized);
    }

    // ── MetaApi deals ───────────────────────────────────────────────

    public function testNormalizeMetaApiClosingDeal(): void
    {
        $deal = [
            'id' => 'deal-123',
            'type' => 'DEAL_TYPE_SELL',
            'time' => '2024-11-22T07:44:00.000Z',
            'symbol' => 'GER40.cash',
            'volume' => 0.5,
            'price' => 19226.05,
            'profit' => 26.05,
            'commission' => -0.50,
            'swap' => 0.00,
            'positionId' => 'pos-456',
            'entryType' => 'DEAL_ENTRY_OUT',
            'accountCurrencyExchangeRate' => 1.0,
        ];

        $normalized = $this->normalizer->normalizeMetaApiDeal($deal);

        $this->assertSame('GER40.cash', $normalized['symbol']);
        // Closing deal is SELL → position was opened as BUY
        $this->assertSame('BUY', $normalized['direction']);
        $this->assertEquals(19226.05, $normalized['exit_price']);
        $this->assertEquals(0.5, $normalized['size']);
        $this->assertEquals(26.05, $normalized['pnl']);
        $this->assertSame('metaapi_pos-456', $normalized['external_id']);
        $this->assertSame('2024-11-22 07:44:00', $normalized['closed_at']);
    }

    public function testNormalizeMetaApiDealSkipsNonClosing(): void
    {
        $deal = [
            'id' => 'deal-100',
            'type' => 'DEAL_TYPE_BUY',
            'time' => '2024-11-22T07:43:00.000Z',
            'symbol' => 'GER40.cash',
            'volume' => 0.5,
            'price' => 19200.00,
            'profit' => 0,
            'positionId' => 'pos-456',
            'entryType' => 'DEAL_ENTRY_IN', // opening deal
        ];

        $normalized = $this->normalizer->normalizeMetaApiDeal($deal);

        $this->assertNull($normalized);
    }

    public function testNormalizeMetaApiDealExtractsDirection(): void
    {
        $buyDeal = [
            'id' => '1', 'type' => 'DEAL_TYPE_BUY', 'time' => '2024-01-01T00:00:00Z',
            'symbol' => 'EURUSD', 'volume' => 1.0, 'price' => 1.09, 'profit' => 10,
            'positionId' => 'p1', 'entryType' => 'DEAL_ENTRY_OUT',
        ];

        $sellDeal = [
            'id' => '2', 'type' => 'DEAL_TYPE_SELL', 'time' => '2024-01-01T00:00:00Z',
            'symbol' => 'EURUSD', 'volume' => 1.0, 'price' => 1.10, 'profit' => -10,
            'positionId' => 'p2', 'entryType' => 'DEAL_ENTRY_OUT',
        ];

        // Closing deal direction is the EXIT direction, but position direction is opposite
        // A BUY closing deal means the position was SHORT (SELL)
        $this->assertSame('SELL', $this->normalizer->normalizeMetaApiDeal($buyDeal)['direction']);
        $this->assertSame('BUY', $this->normalizer->normalizeMetaApiDeal($sellDeal)['direction']);
    }
}
