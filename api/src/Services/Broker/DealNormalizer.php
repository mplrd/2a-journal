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
