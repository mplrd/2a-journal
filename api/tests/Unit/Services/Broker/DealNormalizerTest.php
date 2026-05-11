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

    // ── Ouinex margin positions ────────────────────────────────────

    public function testNormalizeOuinexMarginPositionMapsRoundTripFields(): void
    {
        // closed_margin_positions returns round-trip data: entry + exit + pnl
        // already aggregated by Ouinex, no leg-pairing needed.
        $position = [
            'margin_position_id' => 'mp-100',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'leverage' => 10,
            'amount' => 0.5,
            'entry_price' => 60000.0,
            'exit_price' => 61500.0,
            'pnl' => 750.0,
            'start_ts' => '2026-01-15T10:00:00Z',
            'end_ts' => '2026-01-15T14:30:00Z',
            'stop_loss' => 59500.0,
            'take_profit' => 62000.0,
            'close_reason' => 'TP_HIT',
        ];

        $normalized = $this->normalizer->normalizeOuinexMarginPosition($position);

        $this->assertSame('BTCUSDT', $normalized['symbol']);
        $this->assertSame('BUY', $normalized['direction']);
        $this->assertEquals(60000.0, $normalized['entry_price']);
        $this->assertEquals(61500.0, $normalized['exit_price']);
        $this->assertEquals(0.5, $normalized['size']);
        $this->assertEquals(750.0, $normalized['pnl']);
        $this->assertSame('ouinex_mp-100', $normalized['external_id']);
        $this->assertSame('2026-01-15 10:00:00', $normalized['opened_at']);
        $this->assertSame('2026-01-15 14:30:00', $normalized['closed_at']);
    }

    public function testNormalizeOuinexMarginPositionPreservesShortDirection(): void
    {
        $position = [
            'margin_position_id' => 'mp-200',
            'instrument_id' => 'ETHUSDT',
            'side' => 'SELL',
            'amount' => 1.0,
            'entry_price' => 4000.0,
            'exit_price' => 3800.0,
            'pnl' => 200.0, // SELL profitable when exit < entry
            'start_ts' => '2026-02-01T00:00:00Z',
            'end_ts' => '2026-02-01T05:00:00Z',
        ];

        $normalized = $this->normalizer->normalizeOuinexMarginPosition($position);

        // Unlike MetaApi (where the closing-deal side is the OPPOSITE of the
        // position direction), Ouinex's `side` already represents the
        // position itself, so we map it 1:1.
        $this->assertSame('SELL', $normalized['direction']);
        $this->assertEquals(200.0, $normalized['pnl']);
    }

    public function testNormalizeOuinexMarginPositionSkipsPositionWithoutEnd(): void
    {
        // Defensive: an open margin position should never appear in
        // closed_margin_positions, but if it did (server-side glitch), we
        // skip it rather than ingest a half-baked trade.
        $position = [
            'margin_position_id' => 'mp-300',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'amount' => 0.1,
            'entry_price' => 60000.0,
            'exit_price' => null,
            'pnl' => null,
            'start_ts' => '2026-03-01T00:00:00Z',
            'end_ts' => null,
        ];

        $normalized = $this->normalizer->normalizeOuinexMarginPosition($position);

        $this->assertNull($normalized);
    }

    // ── Ouinex open margin positions ───────────────────────────────

    public function testNormalizeOuinexOpenMarginPositionMapsLiveFields(): void
    {
        // open_margin_positions is the snapshot of currently-active positions:
        // entry + size + SL/TP, but NO exit_price, NO pnl, NO end_ts — the
        // position isn't closed yet. ImportService::isOpenPosition uses the
        // absence of closed_at to decide OPEN vs CLOSED.
        $position = [
            'margin_position_id' => 'mp-live-7',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'leverage' => 10,
            'amount' => 0.25,
            'entry_price' => 60500.0,
            'stop_loss' => 59500.0,
            'take_profit' => 63000.0,
            'start_ts' => '2026-05-07T08:00:00Z',
        ];

        $normalized = $this->normalizer->normalizeOuinexOpenMarginPosition($position);

        $this->assertSame('BTCUSDT', $normalized['symbol']);
        $this->assertSame('BUY', $normalized['direction']);
        $this->assertEquals(60500.0, $normalized['entry_price']);
        $this->assertEquals(0.25, $normalized['size']);
        $this->assertEquals(59500.0, $normalized['sl_price']);
        $this->assertEquals(63000.0, $normalized['tp_price']);
        // Same external_id format as closed — so transitions OPEN→CLOSED
        // re-target the same row instead of duplicating.
        $this->assertSame('ouinex_mp-live-7', $normalized['external_id']);
        $this->assertSame('2026-05-07 08:00:00', $normalized['opened_at']);
        $this->assertArrayNotHasKey('closed_at', $normalized);
        $this->assertNull($normalized['pnl']);
    }

    public function testNormalizeOuinexOpenMarginPositionExternalIdMatchesClosed(): void
    {
        // The same margin_position_id from Ouinex must produce the same
        // external_id whether seen via open_margin_positions or
        // closed_margin_positions — this is the load-bearing invariant for
        // the OPEN→CLOSED transition logic.
        $open = $this->normalizer->normalizeOuinexOpenMarginPosition([
            'margin_position_id' => 'mp-42',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'amount' => 1,
            'entry_price' => 60000,
            'start_ts' => '2026-05-07T08:00:00Z',
        ]);

        $closed = $this->normalizer->normalizeOuinexMarginPosition([
            'margin_position_id' => 'mp-42',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'amount' => 1,
            'entry_price' => 60000,
            'exit_price' => 61000,
            'pnl' => 1000,
            'start_ts' => '2026-05-07T08:00:00Z',
            'end_ts' => '2026-05-07T12:00:00Z',
        ]);

        $this->assertSame($open['external_id'], $closed['external_id']);
    }

    public function testNormalizeOuinexOpenMarginPositionSkipsIfMissingEntryPrice(): void
    {
        // Defensive against API anomalies. A position without entry is
        // unusable for the journal model.
        $position = [
            'margin_position_id' => 'mp-bogus',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'amount' => 0.1,
            'entry_price' => null,
            'start_ts' => '2026-05-07T08:00:00Z',
        ];

        $this->assertNull($this->normalizer->normalizeOuinexOpenMarginPosition($position));
    }

    public function testNormalizeOuinexOpenMarginPositionLeavesSlTpNullWhenAbsent(): void
    {
        $position = [
            'margin_position_id' => 'mp-no-sl',
            'instrument_id' => 'ETHUSDT',
            'side' => 'SELL',
            'amount' => 1.0,
            'entry_price' => 4000.0,
            'start_ts' => '2026-05-07T08:00:00Z',
            // no stop_loss, no take_profit
        ];

        $normalized = $this->normalizer->normalizeOuinexOpenMarginPosition($position);

        $this->assertNull($normalized['sl_price']);
        $this->assertNull($normalized['tp_price']);
    }

    // ── Ouinex open orders ──────────────────────────────────────────

    public function testNormalizeOuinexOpenOrderMapsPendingFields(): void
    {
        // open_orders returns pending margin orders (limit/stop/conditional)
        // that haven't been triggered yet. price is the limit/trigger price —
        // the journal stores it as entry_price (the level the user wants to
        // enter at).
        $order = [
            'order_id' => 'ord-7',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'order_type' => 'LIMIT',
            'amount' => 0.5,
            'price' => 58000.0,
            'stop_loss' => 57000.0,
            'take_profit' => 62000.0,
            'expires_at' => '2026-06-01T00:00:00Z',
            'created_at' => '2026-05-07T08:00:00Z',
        ];

        $normalized = $this->normalizer->normalizeOuinexOpenOrder($order);

        $this->assertSame('BTCUSDT', $normalized['symbol']);
        $this->assertSame('BUY', $normalized['direction']);
        $this->assertEquals(58000.0, $normalized['entry_price']);
        $this->assertEquals(0.5, $normalized['size']);
        $this->assertEquals(57000.0, $normalized['sl_price']);
        $this->assertEquals(62000.0, $normalized['tp_price']);
        // Distinct prefix from margin positions: ouinex_order_ vs ouinex_.
        // Prevents scope collisions in the diff services.
        $this->assertSame('ouinex_order_ord-7', $normalized['external_id']);
        $this->assertSame('2026-06-01 00:00:00', $normalized['expires_at']);
        $this->assertSame('2026-05-07 08:00:00', $normalized['created_at']);
    }

    public function testNormalizeOuinexOpenOrderSkipsIfMissingPrice(): void
    {
        $order = [
            'order_id' => 'ord-bogus',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'amount' => 0.1,
            'price' => null,
            'created_at' => '2026-05-07T08:00:00Z',
        ];

        $this->assertNull($this->normalizer->normalizeOuinexOpenOrder($order));
    }

    public function testNormalizeOuinexOpenOrderLeavesOptionalsNullWhenAbsent(): void
    {
        // No stop_loss / take_profit / expires_at — order types like a bare
        // market limit don't carry them.
        $order = [
            'order_id' => 'ord-bare',
            'instrument_id' => 'ETHUSDT',
            'side' => 'SELL',
            'amount' => 1.0,
            'price' => 4500.0,
            'created_at' => '2026-05-07T08:00:00Z',
        ];

        $normalized = $this->normalizer->normalizeOuinexOpenOrder($order);

        $this->assertNull($normalized['sl_price']);
        $this->assertNull($normalized['tp_price']);
        $this->assertNull($normalized['expires_at']);
    }

    // ── Ouinex closed orders ───────────────────────────────────────

    public function testNormalizeOuinexClosedOrderMapsExecutedStatus(): void
    {
        $order = [
            'order_id' => 'ord-100',
            'status' => 'EXECUTED',
            'updated_at' => '2026-05-08T12:00:00Z',
        ];

        $normalized = $this->normalizer->normalizeOuinexClosedOrder($order);

        $this->assertSame('ouinex_order_ord-100', $normalized['external_id']);
        $this->assertSame('EXECUTED', $normalized['final_status']);
    }

    public function testNormalizeOuinexClosedOrderMapsCancelledStatus(): void
    {
        $order = [
            'order_id' => 'ord-101',
            'status' => 'CANCELLED',
            'updated_at' => '2026-05-08T12:00:00Z',
        ];

        $normalized = $this->normalizer->normalizeOuinexClosedOrder($order);

        $this->assertSame('CANCELLED', $normalized['final_status']);
    }

    public function testNormalizeOuinexClosedOrderMapsExpiredStatus(): void
    {
        $order = [
            'order_id' => 'ord-102',
            'status' => 'EXPIRED',
            'updated_at' => '2026-05-08T12:00:00Z',
        ];

        $normalized = $this->normalizer->normalizeOuinexClosedOrder($order);

        $this->assertSame('EXPIRED', $normalized['final_status']);
    }

    public function testNormalizeOuinexClosedOrderMapsFilledAsExecuted(): void
    {
        // Some broker APIs use FILLED instead of EXECUTED — normalize defensively
        // so the journal sees only one terminology.
        $order = [
            'order_id' => 'ord-fill',
            'status' => 'FILLED',
            'updated_at' => '2026-05-08T12:00:00Z',
        ];

        $normalized = $this->normalizer->normalizeOuinexClosedOrder($order);

        $this->assertSame('EXECUTED', $normalized['final_status']);
    }

    public function testNormalizeOuinexClosedOrderSkipsUnknownStatus(): void
    {
        // If Ouinex ever returns a status we don't model, skip the row
        // rather than guess. The diff service falls back to its default
        // policy for unseen orders.
        $order = [
            'order_id' => 'ord-weird',
            'status' => 'IN_FLIGHT',
            'updated_at' => '2026-05-08T12:00:00Z',
        ];

        $this->assertNull($this->normalizer->normalizeOuinexClosedOrder($order));
    }
}
