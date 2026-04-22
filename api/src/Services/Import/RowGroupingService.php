<?php

namespace App\Services\Import;

class RowGroupingService
{
    /**
     * Group normalized rows by composite key into position groups.
     * Each group represents one position with N partial exits.
     *
     * @param array $rows Normalized rows from ColumnMapperService
     * @param string[] $keyFields Fields to group by (e.g. ['symbol', 'direction', 'entry_price'])
     * @return array[] Position groups with aggregated data
     */
    public function group(array $rows, array $keyFields): array
    {
        if (empty($rows)) {
            return [];
        }

        // Group rows by composite key
        $groups = [];
        foreach ($rows as $row) {
            $key = $this->buildKey($row, $keyFields);
            $groups[$key][] = $row;
        }

        // Aggregate each group into a position
        $positions = [];
        foreach ($groups as $groupRows) {
            $positions[] = $this->aggregateGroup($groupRows, $keyFields);
        }

        return $positions;
    }

    private function buildKey(array $row, array $keyFields): string
    {
        $parts = [];
        foreach ($keyFields as $field) {
            $parts[] = (string) ($row[$field] ?? '');
        }
        return implode('|', $parts);
    }

    private function aggregateGroup(array $rows, array $keyFields): array
    {
        $first = $rows[0];
        $totalSize = 0.0;
        $totalPnl = 0.0;
        $weightedExitPrice = 0.0;
        $earliestClose = null;
        $latestClose = null;
        $comment = null;
        $exits = [];

        foreach ($rows as $row) {
            $size = (float) ($row['size'] ?? 0);
            $pnl = (float) ($row['pnl'] ?? 0);
            $exitPrice = (float) ($row['exit_price'] ?? 0);
            $closedAt = $row['closed_at'] ?? null;

            $totalSize += $size;
            $totalPnl += $pnl;
            $weightedExitPrice += $exitPrice * $size;

            if ($closedAt !== null) {
                if ($earliestClose === null || $closedAt < $earliestClose) {
                    $earliestClose = $closedAt;
                }
                if ($latestClose === null || $closedAt > $latestClose) {
                    $latestClose = $closedAt;
                }
            }

            if ($comment === null && !empty($row['comment'])) {
                $comment = $row['comment'];
            }

            $exits[] = [
                'exit_price' => $exitPrice,
                'size' => $size,
                'pnl' => $pnl,
                'closed_at' => $closedAt,
                'pips' => $row['pips'] ?? null,
            ];
        }

        $avgExitPrice = $totalSize > 0 ? round($weightedExitPrice / $totalSize, 5) : 0;

        // Generate deterministic external_id from position key data
        $hashInput = implode(':', [
            $first['symbol'] ?? '',
            $first['direction'] ?? '',
            $first['entry_price'] ?? '',
            $earliestClose ?? '',
            $latestClose ?? '',
            (string) $totalSize,
        ]);
        $externalId = hash('sha256', $hashInput);

        return [
            'symbol' => $first['symbol'] ?? null,
            'direction' => $first['direction'] ?? null,
            'entry_price' => (float) ($first['entry_price'] ?? 0),
            'total_size' => round($totalSize, 5),
            'total_pnl' => round($totalPnl, 2),
            'avg_exit_price' => $avgExitPrice,
            'opened_at' => $first['opened_at'] ?? $earliestClose, // use explicit opened_at if available
            'closed_at' => $latestClose,
            'comment' => $comment,
            'external_id' => $externalId,
            'exits' => $exits,
        ];
    }
}
