<?php

namespace App\Services\Broker;

class DealNormalizer
{
    /**
     * Normalize a cTrader deal into the import row format.
     * Returns null for opening deals (no closePositionDetail).
     */
    public function normalizeCtraderDeal(array $deal): ?array
    {
        if (!isset($deal['closePositionDetail'])) {
            return null;
        }

        $close = $deal['closePositionDetail'];
        $volume = ($deal['volume'] ?? 0) / 100000; // cTrader volume is in cents of lots

        return [
            'symbol' => $deal['symbolName'] ?? null,
            'direction' => $deal['tradeSide'] ?? null,
            'entry_price' => (float) ($close['entryPrice'] ?? 0),
            'exit_price' => (float) ($deal['executionPrice'] ?? 0),
            'size' => round($volume, 5),
            'pnl' => round(($close['grossProfit'] ?? 0) / 100, 2), // cents → units
            'opened_at' => $this->msTimestampToDatetime($deal['createTimestamp'] ?? 0),
            'closed_at' => $this->msTimestampToDatetime($deal['executionTimestamp'] ?? 0),
            'external_id' => 'ctrader_' . ($deal['positionId'] ?? $deal['dealId']),
            'pips' => null,
            'comment' => null,
        ];
    }

    /**
     * Normalize a MetaApi deal into the import row format.
     * Returns null for opening deals (entryType = DEAL_ENTRY_IN).
     */
    public function normalizeMetaApiDeal(array $deal): ?array
    {
        $entryType = $deal['entryType'] ?? '';
        if ($entryType !== 'DEAL_ENTRY_OUT') {
            return null;
        }

        // Closing deal direction is the exit side.
        // The position direction is the OPPOSITE of the closing deal.
        $exitSide = $this->extractMetaApiDirection($deal['type'] ?? '');
        $positionDirection = $exitSide === 'BUY' ? 'SELL' : 'BUY';

        return [
            'symbol' => $deal['symbol'] ?? null,
            'direction' => $positionDirection,
            'entry_price' => null, // MetaApi closing deals don't include entry price
            'exit_price' => (float) ($deal['price'] ?? 0),
            'size' => (float) ($deal['volume'] ?? 0),
            'pnl' => round((float) ($deal['profit'] ?? 0), 2),
            'opened_at' => null, // not available on closing deal
            'closed_at' => $this->isoToDatetime($deal['time'] ?? ''),
            'external_id' => 'metaapi_' . ($deal['positionId'] ?? $deal['id']),
            'pips' => null,
            'comment' => null,
        ];
    }

    /**
     * Normalize an Ouinex closed_margin_position into the import row format.
     * Returns null when the position lacks `end_ts` or `exit_price` — should
     * never happen in practice for closed_margin_positions, but defensive
     * against API anomalies. Unlike MetaApi, the `side` field already
     * represents the position direction (not the closing-deal direction),
     * so we map it 1:1.
     */
    public function normalizeOuinexMarginPosition(array $position): ?array
    {
        if (empty($position['end_ts']) || $position['exit_price'] === null) {
            return null;
        }

        return [
            'symbol' => $position['instrument_id'] ?? null,
            'direction' => $position['side'] ?? null,
            'entry_price' => (float) ($position['entry_price'] ?? 0),
            'exit_price' => (float) $position['exit_price'],
            'size' => (float) ($position['amount'] ?? 0),
            'pnl' => round((float) ($position['pnl'] ?? 0), 2),
            'opened_at' => $this->isoToDatetime($position['start_ts'] ?? ''),
            'closed_at' => $this->isoToDatetime($position['end_ts']),
            'external_id' => 'ouinex_' . ($position['margin_position_id'] ?? ''),
            'pips' => null,
            'comment' => null,
        ];
    }

    /**
     * Normalize an Ouinex open_margin_position (live position) into the
     * journal-side OPEN row format. Returns null if the entry price is
     * absent — without entry, the row is unusable for the position model.
     *
     * Critical invariant: external_id MUST match what normalizeOuinexMarginPosition
     * produces for the same margin_position_id, so the OPEN→CLOSED transition
     * downstream can re-target the same row instead of duplicating.
     *
     * No closed_at key is emitted: ImportService::isOpenPosition checks for
     * its absence to drive the OPEN trade insert.
     */
    public function normalizeOuinexOpenMarginPosition(array $position): ?array
    {
        if (!isset($position['entry_price']) || $position['entry_price'] === null) {
            return null;
        }

        return [
            'symbol' => $position['instrument_id'] ?? null,
            'direction' => $position['side'] ?? null,
            'entry_price' => (float) $position['entry_price'],
            'size' => (float) ($position['amount'] ?? 0),
            'sl_price' => isset($position['stop_loss']) ? (float) $position['stop_loss'] : null,
            'tp_price' => isset($position['take_profit']) ? (float) $position['take_profit'] : null,
            'opened_at' => $this->isoToDatetime($position['start_ts'] ?? ''),
            'external_id' => 'ouinex_' . ($position['margin_position_id'] ?? ''),
            'pnl' => null,
            'comment' => null,
        ];
    }

    /**
     * Normalize an Ouinex open_order (pending limit/stop/conditional) into a
     * journal-side ORDER row. Returns null if price is missing — without a
     * trigger level, the order is unusable.
     *
     * Critical: uses a DISTINCT external_id prefix ('ouinex_order_') vs
     * normalizeOuinexOpenMarginPosition / normalizeOuinexMarginPosition
     * ('ouinex_'). Two reasons:
     *   1. Ouinex's order_id and margin_position_id are separate identifier
     *      spaces — they could collide if we used the same prefix.
     *   2. The diff services scope themselves by prefix, so the order diff
     *      and the position diff never confuse each other's rows.
     */
    public function normalizeOuinexOpenOrder(array $order): ?array
    {
        if (!isset($order['price']) || $order['price'] === null) {
            return null;
        }

        return [
            'symbol' => $order['instrument_id'] ?? null,
            'direction' => $order['side'] ?? null,
            'entry_price' => (float) $order['price'],
            'size' => (float) ($order['amount'] ?? 0),
            'sl_price' => isset($order['stop_loss']) ? (float) $order['stop_loss'] : null,
            'tp_price' => isset($order['take_profit']) ? (float) $order['take_profit'] : null,
            'expires_at' => !empty($order['expires_at']) ? $this->isoToDatetime($order['expires_at']) : null,
            'created_at' => $this->isoToDatetime($order['created_at'] ?? ''),
            'external_id' => 'ouinex_order_' . ($order['order_id'] ?? ''),
        ];
    }

    /**
     * Normalize a BingX closed USDT-M perpetual position (from
     * positionHistory.list[]) into the journal-side closed-deal format.
     * Returns null on missing entry/exit prices. Maps positionSide
     * (LONG/SHORT) to direction (BUY/SELL) by convention.
     *
     * Field names are inferred from BingX's open-position schema +
     * standard conventions. If BingX ever drifts, the `?? null` defaults
     * keep the normalizer non-fatal — the row is just skipped.
     */
    public function normalizeBingxClosedPosition(array $position): ?array
    {
        $entry = $position['avgPrice'] ?? $position['entryPrice'] ?? null;
        $exit = $position['closeAvgPrice'] ?? $position['exitPrice'] ?? $position['closePrice'] ?? null;
        if ($entry === null || $exit === null) {
            return null;
        }

        return [
            'symbol' => $position['symbol'] ?? null,
            'direction' => $this->bingxPositionSideToDirection($position['positionSide'] ?? ''),
            'entry_price' => (float) $entry,
            'exit_price' => (float) $exit,
            'size' => (float) ($position['positionAmt'] ?? $position['volume'] ?? 0),
            'pnl' => round((float) ($position['realisedProfit'] ?? $position['realizedPnl'] ?? 0), 2),
            'opened_at' => $this->msTimestampToDatetime((int) ($position['openTime'] ?? 0)),
            'closed_at' => $this->msTimestampToDatetime((int) ($position['closeTime'] ?? $position['updateTime'] ?? 0)),
            'external_id' => 'bingx_' . ($position['positionId'] ?? ''),
            'pips' => null,
            'comment' => null,
        ];
    }

    /**
     * Normalize a BingX live position (from /user/positions) into the
     * journal-side OPEN row format. Same external_id format as the closed
     * variant — invariant for OPEN→CLOSED transition matching downstream.
     */
    public function normalizeBingxOpenPosition(array $position): ?array
    {
        $entry = $position['avgPrice'] ?? null;
        if ($entry === null) {
            return null;
        }

        return [
            'symbol' => $position['symbol'] ?? null,
            'direction' => $this->bingxPositionSideToDirection($position['positionSide'] ?? ''),
            'entry_price' => (float) $entry,
            'size' => (float) ($position['positionAmt'] ?? 0),
            'sl_price' => null, // BingX doesn't return SL/TP on /user/positions
            'tp_price' => null,
            'opened_at' => null, // not provided by the live snapshot
            'external_id' => 'bingx_' . ($position['positionId'] ?? ''),
            'pnl' => null,
            'comment' => null,
        ];
    }

    /**
     * Normalize a BingX pending order (from /trade/openOrders) into the
     * journal-side ORDER PENDING row format.
     */
    public function normalizeBingxOpenOrder(array $order): ?array
    {
        $price = $order['price'] ?? null;
        if ($price === null) {
            return null;
        }

        return [
            'symbol' => $order['symbol'] ?? null,
            'direction' => $order['side'] ?? null, // BingX side is BUY/SELL directly
            'entry_price' => (float) $price,
            'size' => (float) ($order['origQty'] ?? 0),
            'sl_price' => null,
            'tp_price' => null,
            'expires_at' => null, // BingX doesn't expose TTL on openOrders
            'created_at' => $this->msTimestampToDatetime((int) ($order['time'] ?? 0)),
            'external_id' => 'bingx_order_' . ($order['orderId'] ?? ''),
        ];
    }

    /**
     * Normalize a BingX raw order from /trade/allOrders into a "fill" — the
     * unit BingxFillReconstructor consumes to rebuild position lifecycles.
     *
     * Only FILLED / PARTIALLY_FILLED orders carry useful fill data; CANCELED
     * / NEW / EXPIRED orders are skipped (returns null). BingX has variant
     * field names across endpoints and account types (futures spot, hedge
     * mode, etc.) — we tolerate the documented aliases via `??` fallback.
     *
     * Output shape (consumed by BingxFillReconstructor):
     *   orderId, symbol, positionSide, side, reduce_only (bool),
     *   executed_qty, avg_price, profit, time (ms)
     */
    public function normalizeBingxFill(array $order): ?array
    {
        $status = strtoupper((string) ($order['status'] ?? ''));
        if (!in_array($status, ['FILLED', 'PARTIALLY_FILLED'], true)) {
            return null;
        }

        $executedQty = (float) ($order['executedQty'] ?? $order['cumQuote'] ?? $order['quantity'] ?? 0);
        if ($executedQty <= 0) {
            return null;
        }

        $avgPrice = (float) ($order['avgPrice'] ?? $order['averagePrice'] ?? $order['price'] ?? 0);
        if ($avgPrice <= 0) {
            return null;
        }

        // reduceOnly may come as bool true/false, or string "true"/"false", or
        // be absent altogether (older payloads). Treat truthy variants as true.
        $rawReduce = $order['reduceOnly'] ?? $order['reduce_only'] ?? false;
        $reduceOnly = is_string($rawReduce)
            ? in_array(strtolower($rawReduce), ['true', '1', 'yes'], true)
            : (bool) $rawReduce;

        return [
            'orderId' => (string) ($order['orderId'] ?? ''),
            'symbol' => (string) ($order['symbol'] ?? ''),
            'positionSide' => strtoupper((string) ($order['positionSide'] ?? 'BOTH')),
            'side' => strtoupper((string) ($order['side'] ?? '')),
            'reduce_only' => $reduceOnly,
            'executed_qty' => $executedQty,
            'avg_price' => $avgPrice,
            'profit' => (float) ($order['profit'] ?? $order['realisedProfit'] ?? 0),
            'time' => (int) ($order['updateTime'] ?? $order['time'] ?? 0),
        ];
    }

    /**
     * Normalize a BingX closed/cancelled/expired order (from /trade/allOrders)
     * into the lifecycle-only shape consumed by the order diff to
     * disambiguate EXECUTED vs CANCELLED vs EXPIRED. Returns null on
     * unmappable statuses so the diff falls back to its default policy.
     */
    public function normalizeBingxClosedOrder(array $order): ?array
    {
        $finalStatus = $this->mapClosedOrderStatus($order['status'] ?? '');
        if ($finalStatus === null) {
            return null;
        }

        return [
            'external_id' => 'bingx_order_' . ($order['orderId'] ?? ''),
            'final_status' => $finalStatus,
        ];
    }

    /**
     * BingX uses LONG/SHORT for positionSide. Our model uses BUY/SELL on
     * direction (per Direction enum). Conventional mapping.
     */
    private function bingxPositionSideToDirection(string $positionSide): string
    {
        return match (strtoupper($positionSide)) {
            'LONG' => 'BUY',
            'SHORT' => 'SELL',
            default => 'BUY',
        };
    }

    /**
     * Normalize an Ouinex closed_order into a tiny row carrying the
     * external_id (so it matches a PENDING order in the journal by
     * order_id) and the final lifecycle status mapped to our OrderStatus
     * enum vocabulary. Returns null for unknown statuses — the diff
     * service falls back to its default policy rather than misclassify.
     */
    public function normalizeOuinexClosedOrder(array $order): ?array
    {
        $finalStatus = $this->mapClosedOrderStatus($order['status'] ?? '');
        if ($finalStatus === null) {
            return null;
        }

        return [
            'external_id' => 'ouinex_order_' . ($order['order_id'] ?? ''),
            'final_status' => $finalStatus,
        ];
    }

    /**
     * Map an Ouinex closed_order.status string to one of our OrderStatus
     * values. Defensive against vocabulary drift: FILLED is treated as
     * EXECUTED (some APIs alternate). Anything not on the allow-list
     * returns null so the diff service can fall back to its default.
     */
    private function mapClosedOrderStatus(string $raw): ?string
    {
        return match (strtoupper($raw)) {
            'EXECUTED', 'FILLED', 'COMPLETED' => 'EXECUTED',
            'CANCELLED', 'CANCELED' => 'CANCELLED',
            'EXPIRED' => 'EXPIRED',
            default => null,
        };
    }

    private function msTimestampToDatetime(int $ms): string
    {
        return gmdate('Y-m-d H:i:s', (int) ($ms / 1000));
    }

    private function isoToDatetime(string $iso): string
    {
        $dt = new \DateTime($iso);
        return $dt->format('Y-m-d H:i:s');
    }

    private function extractMetaApiDirection(string $dealType): string
    {
        return match (true) {
            str_contains($dealType, 'BUY') => 'BUY',
            str_contains($dealType, 'SELL') => 'SELL',
            default => 'BUY',
        };
    }
}
