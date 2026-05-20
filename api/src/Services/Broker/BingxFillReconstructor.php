<?php

namespace App\Services\Broker;

/**
 * Reconstructs position lifecycles from a flat list of BingX fills (executed
 * orders). Pure logic: no IO, no DB, no clock. Fed by BingxConnector after
 * the fills are pulled from /openApi/swap/v2/trade/allOrders.
 *
 * Per (symbol, positionSide) we maintain a running "current cycle". A fill
 * with reduce_only=false opens or extends the cycle (scale-in), a fill with
 * reduce_only=true reduces it (partial or full close). When cumulative
 * reduced quantity catches up to the cumulative opened quantity, the cycle
 * is closed and a fresh one can start on the next opening fill.
 *
 * The output mirrors the contract the journal-side services already
 * consume (positions with an `exits[]` array — see ImportService for closed
 * positions and BrokerOpenSyncService for open ones). external_id uses the
 * `bingx_position_<firstOrderId>` / `bingx_fill_<orderId>` prefixes so
 * downstream dedup works across syncs.
 */
class BingxFillReconstructor
{
    /**
     * Tolerance for "fully closed" check. BingX may report tiny rounding
     * residuals on the last partial close (e.g. 0.0099999 instead of 0.01);
     * treat the cycle as closed when the residual is below 0.01% of the
     * opened size.
     */
    private const CLOSE_RATIO_THRESHOLD = 0.9999;

    /**
     * @param array $fills Each entry is the normalized fill shape emitted by
     *                      DealNormalizer::normalizeBingxFill():
     *                      {orderId, symbol, positionSide, side, reduce_only,
     *                       executed_qty, avg_price, profit, time}
     *
     * @return array{closed: array<int, array>, open: array<int, array>}
     */
    public function reconstruct(array $fills): array
    {
        // Group fills by (symbol, positionSide). Sort each group by time ASC
        // so the walk applies events in chronological order regardless of the
        // order the caller pulled them in (chunks may walk back in time).
        $groups = [];
        foreach ($fills as $fill) {
            $key = ($fill['symbol'] ?? '') . '|' . ($fill['positionSide'] ?? '');
            $groups[$key][] = $fill;
        }
        foreach ($groups as &$group) {
            usort($group, fn($a, $b) => ($a['time'] ?? 0) <=> ($b['time'] ?? 0));
        }
        unset($group);

        $closed = [];
        $open = [];

        foreach ($groups as $group) {
            $current = null;
            foreach ($group as $fill) {
                $reduceOnly = (bool) ($fill['reduce_only'] ?? false);

                if (!$reduceOnly) {
                    $current = $this->applyOpenFill($current, $fill);
                } elseif ($current !== null) {
                    $current = $this->applyReduceFill($current, $fill);
                    if ($this->isFullyClosed($current)) {
                        $closed[] = $this->finalizeClosed($current);
                        $current = null;
                    }
                }
                // else: reduce-only fill without a known opening — orphan,
                // happens when the sync window misses the original open.
                // Drop it silently; reconstructor stays idempotent.
            }

            if ($current !== null) {
                $open[] = $this->finalizeOpen($current);
            }
        }

        return ['closed' => $closed, 'open' => $open];
    }

    private function applyOpenFill(?array $current, array $fill): array
    {
        $qty = (float) ($fill['executed_qty'] ?? 0);
        $price = (float) ($fill['avg_price'] ?? 0);

        if ($current === null) {
            return [
                'symbol' => (string) ($fill['symbol'] ?? ''),
                'direction' => $this->positionSideToDirection((string) ($fill['positionSide'] ?? '')),
                'entry_price' => $price,
                'size_opened' => $qty,
                'opened_at' => (int) ($fill['time'] ?? 0),
                'external_id' => 'bingx_position_' . ($fill['orderId'] ?? ''),
                'exits' => [],
                'closed_size' => 0.0,
                'pnl_running' => 0.0,
            ];
        }

        // Scale in: weighted-average entry price.
        $totalOld = $current['size_opened'] * $current['entry_price'];
        $totalNew = $qty * $price;
        $current['size_opened'] += $qty;
        $current['entry_price'] = $current['size_opened'] > 0
            ? ($totalOld + $totalNew) / $current['size_opened']
            : 0.0;
        return $current;
    }

    private function applyReduceFill(array $current, array $fill): array
    {
        $qty = (float) ($fill['executed_qty'] ?? 0);
        $price = (float) ($fill['avg_price'] ?? 0);
        $profit = (float) ($fill['profit'] ?? 0);

        $current['exits'][] = [
            'exit_price' => $price,
            'size' => $qty,
            'pnl' => $profit,
            'exited_at' => (int) ($fill['time'] ?? 0),
            'exit_type' => 'MANUAL',
            'external_id' => 'bingx_fill_' . ($fill['orderId'] ?? ''),
        ];
        $current['closed_size'] += $qty;
        $current['pnl_running'] += $profit;
        return $current;
    }

    private function isFullyClosed(array $current): bool
    {
        if ($current['size_opened'] <= 0) {
            return false;
        }
        return $current['closed_size'] / $current['size_opened'] >= self::CLOSE_RATIO_THRESHOLD;
    }

    private function finalizeClosed(array $current): array
    {
        $lastExit = end($current['exits']);
        $current['closed_at'] = $lastExit['exited_at'] ?? null;
        $current['exit_price'] = $this->weightedAvgExitPrice($current['exits']);
        $current['pnl'] = round($current['pnl_running'], 2);
        $current['size'] = $current['size_opened'];
        $current['status'] = 'closed';
        return $current;
    }

    private function finalizeOpen(array $current): array
    {
        $current['status'] = 'open';
        $current['size'] = $current['size_opened'];
        $current['remaining_size'] = $current['size_opened'] - $current['closed_size'];
        $current['pnl'] = round($current['pnl_running'], 2);
        $current['closed_at'] = null;
        $current['exit_price'] = null;
        return $current;
    }

    private function weightedAvgExitPrice(array $exits): float
    {
        $totalSize = 0.0;
        $totalNotional = 0.0;
        foreach ($exits as $exit) {
            $size = (float) $exit['size'];
            $totalSize += $size;
            $totalNotional += $size * (float) $exit['exit_price'];
        }
        return $totalSize > 0 ? round($totalNotional / $totalSize, 5) : 0.0;
    }

    /**
     * Direction in the journal model is BUY (LONG) or SELL (SHORT). BingX
     * exposes positionSide=LONG/SHORT (hedge mode) or positionSide=BOTH
     * (one-way mode). In one-way mode the side itself carries the
     * direction — but BOTH cycles are still a single time-series per symbol,
     * so we map it to BUY by convention. The caller can override via the
     * `side` field if needed downstream.
     */
    private function positionSideToDirection(string $positionSide): string
    {
        return match (strtoupper($positionSide)) {
            'LONG' => 'BUY',
            'SHORT' => 'SELL',
            default => 'BUY',
        };
    }
}
