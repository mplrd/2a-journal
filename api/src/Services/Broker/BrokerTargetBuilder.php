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
    /**
     * Stamped on every level the sync writes, so the diff can tell its own
     * objectives from one the user typed — see isBrokerOwned().
     */
    public const SOURCE = 'broker';

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
                'source' => self::SOURCE,
            ];
        }

        return json_encode($targets);
    }

    /**
     * Whether the stored objectives are the sync's to rewrite.
     *
     * True when there is nothing stored, or when EVERY level carries the
     * broker marker. The column is written whole, so ownership is
     * all-or-nothing: one hand-typed level protects the entire list.
     *
     * This replaces an "only fill an empty slot" rule that could not tell the
     * two origins apart. It froze a synced objective exactly like a typed one:
     * once written it was never read again, so moving a take profit on the
     * platform never reached the journal. Objectives the broker owns must
     * follow the broker; objectives the user typed are theirs.
     *
     * Unreadable JSON returns false on purpose — what cannot be shown to be
     * the broker's must not be overwritten.
     */
    public static function isBrokerOwned(mixed $stored): bool
    {
        if ($stored === null || $stored === '') {
            return true;
        }

        $levels = is_array($stored) ? $stored : json_decode((string) $stored, true);
        if (!is_array($levels)) {
            return false;
        }
        if ($levels === []) {
            return true;
        }

        foreach ($levels as $level) {
            if (!is_array($level) || ($level['source'] ?? null) !== self::SOURCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strip the marker from every level: saving objectives from a form is the
     * user taking ownership of them, whatever they were before.
     */
    public static function withoutSource(array $levels): array
    {
        return array_map(
            function ($level) {
                if (is_array($level)) {
                    unset($level['source']);
                }
                return $level;
            },
            $levels,
        );
    }
}
