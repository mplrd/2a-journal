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
