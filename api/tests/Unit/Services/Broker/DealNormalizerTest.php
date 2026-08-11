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

    // ── Timezone of the datetimes written to the journal ────────────

    public function testTimestampsLandInTheUsersTimezoneNotUtc(): void
    {
        // The DATETIME columns hold LOCAL wall-clock time: that is what the
        // manual trade form writes (a PrimeVue DatePicker value formatted as
        // typed). Broker connectors were writing UTC into the same columns, so
        // a trade opened at 07:29 in Paris was journalled as 05:29 and sat two
        // hours off from every hand-entered trade beside it.
        $normalizer = new DealNormalizer('Europe/Paris');

        $row = $normalizer->normalizeCtraderDeal([
            'positionId' => 331,
            'volume' => 100,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY',
            'positionOpenTimestamp' => 1785907740000, // 05:29:00 UTC
            'executionTimestamp' => 1785916872000,    // 08:01:12 UTC
            'executionPrice' => 26283.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 10327],
        ]);

        // CEST in August: UTC+2.
        $this->assertSame('2026-08-05 07:29:00', $row['opened_at']);
        $this->assertSame('2026-08-05 10:01:12', $row['closed_at']);
    }

    public function testTimestampsHonourDaylightSavingTime(): void
    {
        // A fixed +2 offset would be wrong for half the year. January is CET
        // (UTC+1), so the same conversion has to shift by one hour only.
        $normalizer = new DealNormalizer('Europe/Paris');

        $row = $normalizer->normalizeCtraderOpenPosition([
            'positionId' => 1,
            'price' => 100.0,
            'symbolName' => 'X',
            'lotSize' => 100,
            'tradeData' => [
                'symbolId' => 1, 'volume' => 100, 'tradeSide' => 'BUY',
                'openTimestamp' => 1767261600000, // 2026-01-01 10:00:00 UTC
            ],
        ]);

        $this->assertSame('2026-01-01 11:00:00', $row['opened_at']);
    }

    public function testIsoTimestampsAreConvertedToo(): void
    {
        // Ouinex/MetaApi hand over ISO-8601 strings rather than epoch ms.
        // new DateTime() keeps the offset carried by the string, so formatting
        // it straight back out preserved UTC just as gmdate() did.
        $normalizer = new DealNormalizer('Europe/Paris');

        $row = $normalizer->normalizeOuinexMarginPosition([
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'entry_price' => 60000.0,
            'exit_price' => 61000.0,
            'amount' => 0.5,
            'pnl' => 500.0,
            'start_ts' => '2026-08-05T05:29:00Z',
            'end_ts' => '2026-08-05T08:01:12Z',
            'margin_position_id' => 'mp-1',
        ]);

        $this->assertSame('2026-08-05 07:29:00', $row['opened_at']);
        $this->assertSame('2026-08-05 10:01:12', $row['closed_at']);
    }

    public function testDefaultsToUtcWhenNoTimezoneIsGiven(): void
    {
        // No timezone → unchanged behaviour, so a connector that never learns
        // the user's timezone is no worse off than before.
        $row = (new DealNormalizer())->normalizeCtraderOpenPosition([
            'positionId' => 1,
            'price' => 100.0,
            'symbolName' => 'X',
            'lotSize' => 100,
            'tradeData' => ['symbolId' => 1, 'volume' => 100, 'tradeSide' => 'BUY', 'openTimestamp' => 1785907740000],
        ]);

        $this->assertSame('2026-08-05 05:29:00', $row['opened_at']);
    }

    public function testAnUnknownTimezoneFallsBackToUtcInsteadOfThrowing(): void
    {
        // users.timezone is free text. A typo must not abort the whole sync.
        $row = (new DealNormalizer('Mars/Olympus_Mons'))->normalizeCtraderOpenPosition([
            'positionId' => 1,
            'price' => 100.0,
            'symbolName' => 'X',
            'lotSize' => 100,
            'tradeData' => ['symbolId' => 1, 'volume' => 100, 'tradeSide' => 'BUY', 'openTimestamp' => 1785907740000],
        ]);

        $this->assertSame('2026-08-05 05:29:00', $row['opened_at']);
    }

    // ── cTrader deals ───────────────────────────────────────────────

    public function testNormalizeCtraderClosingDeal(): void
    {
        $deal = [
            'dealId' => 12345,
            'orderId' => 111,
            'positionId' => 999,
            'volume' => 50000, // cents; lotSize 100000 cents → 0.5 lots
            'lotSize' => 100000, // injected by the connector from ProtoOASymbol
            'filledVolume' => 50000,
            'symbolId' => 22,
            'symbolName' => 'GER40',
            'createTimestamp' => 1700000000000, // ms
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 19226.05,
            'tradeSide' => 'BUY',
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
        // tradeSide BUY on a CLOSING deal → the position itself was a SELL.
        $this->assertSame('SELL', $normalized['direction']);
        $this->assertEquals(19200.00, $normalized['entry_price']);
        $this->assertEquals(19226.05, $normalized['exit_price']);
        $this->assertEquals(0.5, $normalized['size']);
        // Net, not gross: 26.05 of price difference less the 0.50 commission
        // the same message reports.
        $this->assertEquals(25.55, $normalized['pnl']);
        $this->assertSame('ctrader_999', $normalized['external_id']);
        $this->assertNotNull($normalized['closed_at']);
    }

    public function testNormalizeCtraderDealInvertsTheClosingDealSide(): void
    {
        // ProtoOADeal.tradeSide is the side of THIS deal. On a deal carrying a
        // closePositionDetail that deal is the one closing the position, so its
        // side is the opposite of the position's own direction: a long is
        // closed by a SELL, a short by a BUY. Copying it verbatim turned every
        // synced cTrader trade upside down — a short taking profit showed up as
        // a winning long.
        $short = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 1,
            'volume' => 100,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY', // buying back → the position was SHORT
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 26300.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 8634],
        ]);

        $long = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 2,
            'volume' => 100,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 'SELL', // selling out → the position was LONG
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 26400.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 1366],
        ]);

        $this->assertSame('SELL', $short['direction']);
        $this->assertSame('BUY', $long['direction']);
    }

    public function testNormalizeCtraderDealAcceptsANumericClosingSide(): void
    {
        // Same tolerance as the open-position path: the JSON API may serialize
        // tradeSide as its integer code (BUY=1, SELL=2).
        $row = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 3,
            'volume' => 100,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 1,
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 26300.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 100],
        ]);

        $this->assertSame('SELL', $row['direction']);
    }

    public function testNormalizeCtraderDealConvertsVolumeWithTheSymbolLotSize(): void
    {
        // Both ProtoOADeal.volume and ProtoOASymbol.lotSize are expressed in
        // cents, so volume / lotSize is the lot count cTrader itself displays.
        // The old hardcoded /100000 was a pure invention: on a DAX CFD holding
        // 1.5 contracts it reported 0.0015.
        $row = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 331,
            'volume' => 150,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 26300.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 12960],
        ]);

        $this->assertEquals(1.5, $row['size']);
    }

    public function testNormalizeCtraderDealFallsBackToUnitsWithoutALotSize(): void
    {
        // When the symbol lookup failed we can still honour the documented
        // meaning of the field — "volume in cents", i.e. hundredths of a unit —
        // rather than the /100000 that matched no unit at all.
        $row = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 4,
            'volume' => 150,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 26300.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 100],
        ]);

        $this->assertEquals(1.5, $row['size']);
    }

    public function testNormalizeCtraderDealPrefersTheClosedVolumeOverTheDealVolume(): void
    {
        // closedVolume is what this deal actually took off the position; the
        // deal volume is the size of the closing order. They match on a full
        // close, and closedVolume is the authority on a partial one.
        $row = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 5,
            'volume' => 250,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 26300.0,
            'closePositionDetail' => [
                'entryPrice' => 26386.34,
                'grossProfit' => 100,
                'closedVolume' => 100,
            ],
        ]);

        $this->assertEquals(1.0, $row['size']);
    }

    public function testNormalizeCtraderDealReportsNetProfitNotGross(): void
    {
        // grossProfit is the raw price difference — cTrader states it plainly:
        // "Amount of realized gross profit". The costs sit beside it in the
        // same message, on the same moneyDigits scale: swap ("realized swap
        // related to closed volume"), commission ("realized commission related
        // to closed volume") and the conversion fee charged when the symbol is
        // quoted in something other than the deposit currency. Importing the
        // gross alone left every trade looking better than the broker
        // statement, by the exact amount of its costs.
        $row = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 7,
            'volume' => 500,
            'lotSize' => 100,
            'symbolName' => 'US100.cash',
            'tradeSide' => 'BUY',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 20000.0,
            'closePositionDetail' => [
                'entryPrice' => 20100.0,
                'grossProfit' => 50000,   // +500.00
                'swap' => -350,           //   -3.50
                'commission' => -1200,    //  -12.00
                'pnlConversionFee' => -75, //  -0.75
            ],
        ]);

        $this->assertEquals(483.75, $row['pnl']);
    }

    public function testNormalizeCtraderDealScalesCostsOnTheSameMoneyDigits(): void
    {
        // The costs share grossProfit's exponent — applying it to the profit
        // alone would make them a million times too heavy on a broker
        // reporting 8.
        $row = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 8,
            'volume' => 100,
            'lotSize' => 100,
            'symbolName' => 'US100.cash',
            'tradeSide' => 'BUY',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 20000.0,
            'closePositionDetail' => [
                'entryPrice' => 20100.0,
                'grossProfit' => 50000000000, // +500.00
                'commission' => -1200000000,  //  -12.00
                'moneyDigits' => 8,
            ],
        ]);

        $this->assertEquals(488.0, $row['pnl']);
    }

    public function testNormalizeCtraderDealToleratesAbsentCosts(): void
    {
        // A payload without swap/commission keeps behaving exactly as before —
        // no cost invented, no crash.
        $row = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 9,
            'volume' => 100,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 26300.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 10327],
        ]);

        $this->assertEquals(103.27, $row['pnl']);
    }

    public function testNormalizeCtraderDealCarriesAPerDealExitId(): void
    {
        // external_id identifies the POSITION and is shared by every closing
        // deal taken against it, so it cannot dedup individual exits. The
        // per-deal id is what the partial-exit dedup runs on, and it has to
        // match the one used while the position was still open — otherwise the
        // TP1 gets written a second time when the position finally closes.
        $row = $this->normalizer->normalizeCtraderDeal([
            'dealId' => 11,
            'positionId' => 331,
            'volume' => 100,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 26300.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 10327],
        ]);

        $this->assertSame('ctrader_331', $row['external_id']);
        $this->assertSame('ctrader_deal_11', $row['exit_external_id']);
    }

    public function testNormalizeCtraderDealUsesThePositionOpenTimestampWhenKnown(): void
    {
        // createTimestamp belongs to the CLOSING deal, so using it as opened_at
        // makes every trade look like it opened at the moment it closed. The
        // connector injects the opening deal's execution timestamp under
        // positionOpenTimestamp when it is inside the sync window.
        $row = $this->normalizer->normalizeCtraderDeal([
            'positionId' => 6,
            'volume' => 100,
            'lotSize' => 100,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY',
            'positionOpenTimestamp' => 1785907740000, // 05/08/2026 05:29:00 UTC
            'createTimestamp' => 1785916872000,       // 08:01:12 — the TP1 fill
            'executionTimestamp' => 1785916872000,
            'executionPrice' => 26300.0,
            'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 10327],
        ]);

        $this->assertSame('2026-08-05 05:29:00', $row['opened_at']);
        $this->assertSame('2026-08-05 08:01:12', $row['closed_at']);
    }

    public function testNormalizeCtraderDealScalesProfitByMoneyDigits(): void
    {
        // cTrader does not express money in cents: moneyDigits is the exponent,
        // and its own docs say it "affects grossProfit, swap, commission,
        // balance, pnlConversionFee". Dividing by a hardcoded 100 is only right
        // when moneyDigits happens to be 2 — on a broker reporting 8 every
        // imported P&L is out by a factor of a million.
        $deal = [
            'positionId' => 999,
            'volume' => 50000,
            'symbolName' => 'GER40',
            'tradeSide' => 'SELL',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 19226.05,
            'closePositionDetail' => [
                'entryPrice' => 19200.00,
                'grossProfit' => 2605000000,
                'moneyDigits' => 8,
            ],
        ];

        $normalized = $this->normalizer->normalizeCtraderDeal($deal);

        $this->assertEquals(26.05, $normalized['pnl']);
    }

    public function testNormalizeCtraderDealFallsBackToTheDealsOwnMoneyDigits(): void
    {
        // ProtoOADeal carries moneyDigits too. Prefer the closing detail's own
        // value, but a payload that only sets it on the deal must still scale.
        $deal = [
            'positionId' => 999,
            'volume' => 50000,
            'symbolName' => 'GER40',
            'tradeSide' => 'BUY',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 19226.05,
            'moneyDigits' => 3,
            'closePositionDetail' => [
                'entryPrice' => 19200.00,
                'grossProfit' => 26050,
            ],
        ];

        $normalized = $this->normalizer->normalizeCtraderDeal($deal);

        $this->assertEquals(26.05, $normalized['pnl']);
    }

    public function testNormalizeCtraderDealDefaultsToTwoDigitsWhenUnstated(): void
    {
        // Guards the common case: nothing changes for a broker that omits the
        // field or reports 2, which is what every account seen so far does.
        $deal = [
            'positionId' => 999,
            'volume' => 50000,
            'symbolName' => 'GER40',
            'tradeSide' => 'SELL',
            'createTimestamp' => 1700000000000,
            'executionTimestamp' => 1700003600000,
            'executionPrice' => 19226.05,
            'closePositionDetail' => ['entryPrice' => 19200.00, 'grossProfit' => 2605],
        ];

        $normalized = $this->normalizer->normalizeCtraderDeal($deal);

        $this->assertEquals(26.05, $normalized['pnl']);
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

    // ── cTrader open positions (ProtoOAReconcileRes → position[]) ───

    public function testNormalizeCtraderOpenPosition(): void
    {
        // A live position from ProtoOAReconcileRes. symbolName and lotSize are
        // injected by the connector after resolving symbolId. Volume uses the
        // same volume/lotSize convention as normalizeCtraderDeal, and the entry
        // price is the position's `price` field.
        $position = [
            'positionId' => 999,
            'positionStatus' => 'POSITION_STATUS_OPEN',
            'price' => 19200.0,
            'stopLoss' => 19000.0,
            'takeProfit' => 19500.0,
            'symbolName' => 'GER40',
            'lotSize' => 100000,
            'tradeData' => [
                'symbolId' => 22,
                'volume' => 50000, // /100000 cents → 0.5 lots
                'tradeSide' => 'BUY',
                'openTimestamp' => 1700000000000,
            ],
        ];

        $row = $this->normalizer->normalizeCtraderOpenPosition($position);

        $this->assertSame('GER40', $row['symbol']);
        $this->assertSame('BUY', $row['direction']);
        $this->assertEquals(19200.0, $row['entry_price']);
        $this->assertEquals(0.5, $row['size']);
        $this->assertEquals(19000.0, $row['sl_price']);
        $this->assertEquals(19500.0, $row['tp_price']);
        $this->assertSame('ctrader_999', $row['external_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['opened_at']);
        // No closed_at: this is an OPEN row.
        $this->assertArrayNotHasKey('closed_at', $row);
        $this->assertNull($row['pnl']);
    }

    public function testNormalizeCtraderOpenPositionExternalIdMatchesClosedDeal(): void
    {
        // Load-bearing invariant: the same positionId must yield the same
        // external_id whether seen live (reconcile) or closed (deal list), so
        // the OPEN→CLOSED transition re-targets the same journal row instead
        // of duplicating it.
        $open = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 4242,
            'price' => 100.0,
            'symbolName' => 'EURUSD',
            'tradeData' => ['symbolId' => 1, 'volume' => 100000, 'tradeSide' => 'BUY', 'openTimestamp' => 1700000000000],
        ]);

        $closed = $this->normalizer->normalizeCtraderDeal([
            'dealId' => 1, 'positionId' => 4242, 'volume' => 100000, 'symbolName' => 'EURUSD',
            'createTimestamp' => 1700000000000, 'executionTimestamp' => 1700003600000,
            'executionPrice' => 101.0, 'tradeSide' => 'SELL', 'dealStatus' => 'FILLED',
            'commission' => 0, 'swap' => 0,
            'closePositionDetail' => ['entryPrice' => 100.0, 'grossProfit' => 100, 'closedVolume' => 100000],
        ]);

        $this->assertSame($open['external_id'], $closed['external_id']);
    }

    public function testNormalizeCtraderOpenPositionRebuildsTheOriginalSizeFromItsExits(): void
    {
        // ProtoOAPosition.tradeData.volume is what is LEFT on the position, not
        // what was originally opened — it shrinks on every partial close. The
        // journal wants the original size on the position row and the leftover
        // on the trade, so the size has to be rebuilt by adding back whatever
        // the partial closes took off.
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 331,
            'price' => 26386.34,
            'symbolName' => 'GER40',
            'lotSize' => 100,
            'tradeData' => [
                'symbolId' => 331,
                'volume' => 150, // 1.5 contracts still open
                'tradeSide' => 'SELL',
                'openTimestamp' => 1785907740000,
            ],
            'partialExits' => [
                ['exit_price' => 26300.0, 'size' => 1.0, 'pnl' => 103.27, 'closed_at' => '2026-08-05 08:01:12', 'external_id' => 'ctrader_deal_77'],
            ],
        ]);

        $this->assertEquals(2.5, $row['size']);           // 1.5 left + 1.0 taken
        $this->assertEquals(1.5, $row['remaining_size']);
        $this->assertCount(1, $row['exits']);
        $this->assertSame('ctrader_deal_77', $row['exits'][0]['external_id']);
        $this->assertEquals(103.27, $row['exits'][0]['pnl']);
    }

    public function testNormalizeCtraderOpenPositionWithoutExitsKeepsSizeAndRemainingEqual(): void
    {
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 12,
            'price' => 100.0,
            'symbolName' => 'X',
            'lotSize' => 100,
            'tradeData' => ['symbolId' => 1, 'volume' => 250, 'tradeSide' => 'BUY', 'openTimestamp' => 1700000000000],
        ]);

        $this->assertEquals(2.5, $row['size']);
        $this->assertEquals(2.5, $row['remaining_size']);
        $this->assertSame([], $row['exits']);
    }

    public function testNormalizeCtraderOpenPositionBuildsOneTargetPerStagedTakeProfit(): void
    {
        // ProtoOAPosition.takeProfit is a single double — a position cannot
        // express a staged exit plan. Server-side partial take profits are
        // separate LIMIT closing orders, each carrying its own volume, which is
        // richer than the position's lone level: it says how much comes off at
        // each step. The connector hands them over; here they become targets,
        // nearest first.
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 331,
            'price' => 26386.34,
            'symbolName' => 'GER40',
            'lotSize' => 100,
            'tradeData' => [
                'symbolId' => 331, 'volume' => 250, 'tradeSide' => 'BUY',
                'openTimestamp' => 1785907740000,
            ],
            'takeProfitOrders' => [
                ['price' => 26600.0, 'volume' => 100],
                ['price' => 26450.0, 'volume' => 100], // nearest, must come first
                ['price' => 26900.0, 'volume' => 50],
            ],
        ]);

        $this->assertCount(3, $row['targets']);
        $this->assertSame(
            [26450.0, 26600.0, 26900.0],
            array_map(fn($t) => $t['price'], $row['targets']),
        );
        // Volume converted with the symbol lot size, like every other size.
        $this->assertSame([1.0, 1.0, 0.5], array_map(fn($t) => $t['size'], $row['targets']));
    }

    public function testNormalizeCtraderOpenPositionOrdersAShortsTargetsDownwards(): void
    {
        // A short takes profit BELOW its entry, so "nearest first" means
        // descending price. Sorting on the distance to entry covers both.
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 332,
            'price' => 26386.34,
            'symbolName' => 'GER40',
            'lotSize' => 100,
            'tradeData' => ['symbolId' => 331, 'volume' => 250, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
            'takeProfitOrders' => [
                ['price' => 26000.0, 'volume' => 100],
                ['price' => 26300.0, 'volume' => 150],
            ],
        ]);

        $this->assertSame([26300.0, 26000.0], array_map(fn($t) => $t['price'], $row['targets']));
    }

    public function testNormalizeCtraderOpenPositionFallsBackToThePositionsOwnTakeProfit(): void
    {
        // No staged orders: the single level on the position is the objective.
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 333,
            'price' => 26386.34,
            'takeProfit' => 26600.0,
            'symbolName' => 'GER40',
            'lotSize' => 100,
            'tradeData' => ['symbolId' => 331, 'volume' => 250, 'tradeSide' => 'BUY', 'openTimestamp' => 1785907740000],
        ]);

        $this->assertCount(1, $row['targets']);
        $this->assertSame(26600.0, $row['targets'][0]['price']);
        // Nothing staged and nothing taken off yet, so the objective covers the
        // whole position — here the remaining volume IS the whole position.
        $this->assertSame(2.5, $row['targets'][0]['size']);
    }

    public function testNormalizeCtraderOpenPositionSizesTheOwnTakeProfitOnWhatRemains(): void
    {
        // A take profit closes what is still open, not what the position was
        // worth before it was trimmed. The size was computed against the
        // REBUILT original — remaining plus every partial exit — so a position
        // already reduced advertised an objective larger than itself.
        //
        // Observed on the test environment on 2026-08-11: a GER40 short down to
        // 1 lot from 2.5 displayed a take profit for 2.5.
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 334,
            'price' => 26415.24,
            'takeProfit' => 22000.0,
            'symbolName' => 'GER40',
            'lotSize' => 100,
            'tradeData' => ['symbolId' => 331, 'volume' => 100, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
            'partialExits' => [['size' => 1.5]],
        ]);

        // The original is still rebuilt for the position itself — that part is
        // right and other code depends on it.
        $this->assertSame(2.5, $row['size']);
        $this->assertSame(1.0, $row['remaining_size']);

        $this->assertCount(1, $row['targets']);
        $this->assertSame(1.0, $row['targets'][0]['size']);
    }

    public function testNormalizeCtraderOpenPositionLeavesTheOwnTakeProfitOnlyWhatTheStagedLevelsSpare(): void
    {
        // The position's own level is the last step of the plan: it takes off
        // whatever the staged ones leave. That remainder is measured against
        // the remaining volume too, not the original.
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 335,
            'price' => 26415.24,
            'takeProfit' => 22000.0,
            'symbolName' => 'GER40',
            'lotSize' => 100,
            'tradeData' => ['symbolId' => 331, 'volume' => 200, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
            'partialExits' => [['size' => 0.5]],
            'takeProfitOrders' => [
                ['price' => 26000.0, 'volume' => 50],
            ],
        ]);

        $this->assertSame(2.5, $row['size']);
        $this->assertSame(2.0, $row['remaining_size']);

        // Nearest first: the staged level at 26000 comes before the far 22000.
        $this->assertSame([26000.0, 22000.0], array_map(fn($t) => $t['price'], $row['targets']));
        // 0.5 staged, so 1.5 of the 2.0 still open is left for the own level.
        $this->assertSame([0.5, 1.5], array_map(fn($t) => $t['size'], $row['targets']));
    }

    public function testNormalizeCtraderOpenPositionHasNoTargetsWithoutAnyTakeProfit(): void
    {
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 334,
            'price' => 26386.34,
            'symbolName' => 'GER40',
            'lotSize' => 100,
            'tradeData' => ['symbolId' => 331, 'volume' => 250, 'tradeSide' => 'BUY', 'openTimestamp' => 1785907740000],
        ]);

        $this->assertSame([], $row['targets']);
    }

    public function testNormalizeCtraderOpenPositionAcceptsNumericTradeSide(): void
    {
        // cTrader's JSON serialization may emit enum fields as their integer
        // code (tradeSide: 2) rather than the name ('SELL'). Tolerate both.
        $row = $this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 5,
            'price' => 50.0,
            'symbolName' => 'X',
            'tradeData' => ['symbolId' => 1, 'volume' => 100000, 'tradeSide' => 2, 'openTimestamp' => 1700000000000],
        ]);

        $this->assertSame('SELL', $row['direction']);
    }

    public function testNormalizeCtraderOpenPositionSkipsWithoutEntryPrice(): void
    {
        $this->assertNull($this->normalizer->normalizeCtraderOpenPosition([
            'positionId' => 6,
            'symbolName' => 'X',
            'tradeData' => ['symbolId' => 1, 'volume' => 100000, 'tradeSide' => 1],
            // no 'price'
        ]));
    }

    // ── cTrader open orders (ProtoOAReconcileRes → order[]) ─────────

    public function testNormalizeCtraderOpenOrder(): void
    {
        $order = [
            'orderId' => 555,
            'orderType' => 'LIMIT',
            'orderStatus' => 'ORDER_STATUS_ACCEPTED',
            'limitPrice' => 18000.0,
            'stopLoss' => 17500.0,
            'takeProfit' => 18500.0,
            'expirationTimestamp' => 1700100000000,
            'symbolName' => 'GER40',
            'lotSize' => 100000,
            'tradeData' => ['symbolId' => 22, 'volume' => 10000, 'tradeSide' => 'BUY', 'openTimestamp' => 1700000000000],
        ];

        $row = $this->normalizer->normalizeCtraderOpenOrder($order);

        $this->assertSame('GER40', $row['symbol']);
        $this->assertSame('BUY', $row['direction']);
        $this->assertEquals(18000.0, $row['entry_price']);
        $this->assertEquals(0.1, $row['size']);
        $this->assertEquals(17500.0, $row['sl_price']);
        $this->assertEquals(18500.0, $row['tp_price']);
        $this->assertSame('ctrader_order_555', $row['external_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['expires_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at']);
    }

    public function testNormalizeCtraderOpenOrderFallsBackToStopPrice(): void
    {
        // A STOP order carries stopPrice, not limitPrice — that's the entry.
        $row = $this->normalizer->normalizeCtraderOpenOrder([
            'orderId' => 556,
            'orderType' => 'STOP',
            'stopPrice' => 19000.0,
            'symbolName' => 'GER40',
            'tradeData' => ['symbolId' => 22, 'volume' => 10000, 'tradeSide' => 'SELL', 'openTimestamp' => 1700000000000],
        ]);

        $this->assertEquals(19000.0, $row['entry_price']);
        $this->assertSame('SELL', $row['direction']);
    }

    public function testNormalizeCtraderOpenOrderSkipsWithoutTriggerPrice(): void
    {
        $this->assertNull($this->normalizer->normalizeCtraderOpenOrder([
            'orderId' => 557,
            'symbolName' => 'X',
            'tradeData' => ['symbolId' => 1, 'volume' => 10000, 'tradeSide' => 1],
            // no limitPrice, no stopPrice
        ]));
    }

    public function testNormalizeCtraderOpenOrderLeavesOptionalsNullWhenAbsent(): void
    {
        $row = $this->normalizer->normalizeCtraderOpenOrder([
            'orderId' => 558,
            'limitPrice' => 100.0,
            'symbolName' => 'X',
            'tradeData' => ['symbolId' => 1, 'volume' => 10000, 'tradeSide' => 1, 'openTimestamp' => 1700000000000],
        ]);

        $this->assertNull($row['expires_at']);
        $this->assertNull($row['sl_price']);
        $this->assertNull($row['tp_price']);
    }

    // ── cTrader closed orders (ProtoOAOrderListRes → terminal states) ─

    public function testNormalizeCtraderClosedOrderMapsFilledToExecuted(): void
    {
        $row = $this->normalizer->normalizeCtraderClosedOrder([
            'orderId' => 700,
            'orderStatus' => 'ORDER_STATUS_FILLED',
        ]);

        $this->assertSame('ctrader_order_700', $row['external_id']);
        $this->assertSame('EXECUTED', $row['final_status']);
    }

    public function testNormalizeCtraderClosedOrderMapsCancelledAndExpired(): void
    {
        $this->assertSame('CANCELLED', $this->normalizer->normalizeCtraderClosedOrder(
            ['orderId' => 1, 'orderStatus' => 'ORDER_STATUS_CANCELLED'])['final_status']);
        $this->assertSame('EXPIRED', $this->normalizer->normalizeCtraderClosedOrder(
            ['orderId' => 2, 'orderStatus' => 'ORDER_STATUS_EXPIRED'])['final_status']);
    }

    public function testNormalizeCtraderClosedOrderAcceptsNumericStatus(): void
    {
        // ORDER_STATUS_FILLED = 2 in the proto enum.
        $row = $this->normalizer->normalizeCtraderClosedOrder(['orderId' => 701, 'orderStatus' => 2]);

        $this->assertSame('EXECUTED', $row['final_status']);
    }

    public function testNormalizeCtraderClosedOrderSkipsNonTerminal(): void
    {
        // ACCEPTED is still live — skip it so the order diff keeps the row
        // pending rather than misclassifying it.
        $this->assertNull($this->normalizer->normalizeCtraderClosedOrder(
            ['orderId' => 702, 'orderStatus' => 'ORDER_STATUS_ACCEPTED']));
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
