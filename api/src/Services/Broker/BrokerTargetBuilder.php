<?php

namespace App\Services\Broker;

/**
 * The broker's objectives as a positions.targets payload, or null when there
 * is none.
 *
 * There is no tp_price column — objectives live in that JSON — so the entry
 * mirrors what the trade form writes (id, label, points, price, size) and a
 * synced objective renders like any other. `points` is the distance from
 * entry, which is the unit the form edits in.
 *
 * Shared by the open-position diff and the pending-order diff. It lived
 * private to the former, which is why a pending order's take profit was
 * normalized by the connectors and then dropped on the floor: nothing on the
 * order path knew how to write one.
 */
final class BrokerTargetBuilder
{
    public static function fromSnapshot(array $row): ?string
    {
        // An objective closes the row as it stands NOW, so sizes are measured
        // on what is still open and never on a rebuilt original. cTrader
        // shrinks a position on every partial close and the normalizer adds
        // the exits back to recover the original — using that figure made a
        // position trimmed to 1 lot advertise an objective for the 2.5 it once
        // was. A pending order has no partial exit, so the two coincide there.
        $rowSize = (float) ($row['remaining_size'] ?? $row['size'] ?? 0);

        // A connector that resolves a staged exit plan hands over `targets`,
        // one entry per level with its own size — cTrader's server-side partial
        // take profits, for instance. Connectors reporting a single level fall
        // back to tp_price, which then covers whatever is still open.
        $levels = $row['targets'] ?? [];
        if (empty($levels)) {
            $takeProfit = $row['tp_price'] ?? null;
            if ($takeProfit === null || (float) $takeProfit <= 0) {
                return null;
            }
            $levels = [['price' => (float) $takeProfit, 'size' => $rowSize]];
        }

        $entryPrice = (float) ($row['entry_price'] ?? 0);
        $targets = [];
        foreach ($levels as $index => $level) {
            $rank = $index + 1;
            $targets[] = [
                'id' => 'tp' . $rank,
                'label' => 'TP' . $rank,
                'points' => round(abs((float) $level['price'] - $entryPrice), 5),
                'price' => (float) $level['price'],
                'size' => (float) ($level['size'] ?? $rowSize),
            ];
        }

        return json_encode($targets);
    }
}
