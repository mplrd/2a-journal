<?php

namespace App\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\Direction;
use App\Enums\ExitType;
use App\Enums\PositionType;
use App\Enums\TradeStatus;
use App\Repositories\PartialExitRepository;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;

/**
 * Reconciles the live OPEN snapshot returned by a broker connector against
 * the journal's current state. Unlike the closed-deals import path (which
 * only inserts new rows), this service mutates existing rows in place:
 *
 *  - INSERT  : snapshot row not yet known to the journal
 *  - UPDATE  : known position still open → refresh broker-driven fields
 *              (entry_price, size, SL/TP) but PRESERVE user metadata
 *              (setup, notes, custom_fields)
 *  - TRANSITION : known open position now appears in closed snapshot →
 *              flip the trade OPEN → CLOSED in place, keeping the position
 *              row and its user metadata intact
 *  - SKIP    : known open position absent from BOTH open and closed snapshots
 *              → leave it untouched (could be an API gap; the next sync
 *              will reconcile if it's a real anomaly)
 *
 * Scoping: the BrokerProvider passed to apply() drives the external_id
 * prefix used for the diff (cf. BrokerProvider::externalIdPrefix). Rows
 * whose external_id doesn't start with it are never read or touched.
 * Manually-entered positions (no external_id) and file-imported positions
 * (different prefixes, e.g. 'ftmo_') stay invisible. The same service
 * instance handles every provider — adding a new one means just plugging
 * a new normalizer + connector, no diff-service duplication.
 */
class BrokerOpenSyncService
{
    public function __construct(
        private PositionRepository $positionRepo,
        private TradeRepository $tradeRepo,
        private ?PartialExitRepository $partialExitRepo = null,
    ) {}

    /**
     * @param BrokerProvider $provider Provider whose external_id prefix scopes the diff.
     * @param int $userId Owner of the connection (used for new position creation).
     * @param int $accountId Account scope for the diff.
     * @param int $batchId Import batch ID to tag new positions with — same
     *                    batch as the closed-deal import for traceability.
     * @param array $openSnapshot Normalized open positions from the connector.
     * @param array $closedSnapshot Normalized closed positions from the connector
     *                              (already fetched in the same sync run; used
     *                              for OPEN→CLOSED transitions).
     * @return array{inserted: int, updated: int, transitioned: int, skipped_orphans: int}
     */
    public function apply(
        BrokerProvider $provider,
        int $userId,
        int $accountId,
        int $batchId,
        array $openSnapshot,
        array $closedSnapshot,
    ): array {
        $existing = $this->positionRepo->findOpenByExternalIdPrefixInAccount(
            $accountId,
            $provider->externalIdPrefix(),
        );

        $closedByExternalId = $this->indexByExternalId($closedSnapshot);
        $seenInOpen = [];

        $stats = ['inserted' => 0, 'updated' => 0, 'transitioned' => 0, 'skipped_orphans' => 0];

        // 1. INSERT or UPDATE for each row currently open on the broker.
        foreach ($openSnapshot as $row) {
            $externalId = $row['external_id'];
            $seenInOpen[$externalId] = true;

            if (isset($existing[$externalId])) {
                $this->updateBrokerFields($existing[$externalId], $row);
                $stats['updated']++;
            } else {
                $this->insertNewOpen($userId, $accountId, $batchId, $row);
                $stats['inserted']++;
            }
        }

        // 2. For rows known open in our DB but no longer in the broker open
        //    snapshot: check if they closed (appear in closed snapshot) and
        //    transition in place, or leave them alone defensively.
        foreach ($existing as $externalId => $row) {
            if (isset($seenInOpen[$externalId])) {
                continue;
            }

            if (isset($closedByExternalId[$externalId])) {
                $this->transitionToClosed($row, $closedByExternalId[$externalId]);
                $stats['transitioned']++;
            } else {
                $stats['skipped_orphans']++;
            }
        }

        return $stats;
    }

    private function insertNewOpen(int $userId, int $accountId, int $batchId, array $row): void
    {
        $position = $this->positionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'direction' => $row['direction'],
            'symbol' => $row['symbol'],
            'entry_price' => $row['entry_price'],
            'size' => $row['size'],
            'sl_price' => $row['sl_price'] ?? null,
            'targets' => BrokerTargetBuilder::fromSnapshot($row),
            'external_id' => $row['external_id'],
            'import_batch_id' => $batchId,
            'position_type' => PositionType::TRADE->value,
        ]);

        $tradeFields = [
            'position_id' => $position['id'],
            // BingX /user/positions doesn't expose an open time on the live
            // snapshot, so normalizeBingxOpenPosition returns null and we
            // fall back to "now". It's at worst the moment we discovered the
            // position, slightly later than the real open — acceptable for
            // an OPEN row whose lifecycle is being reconciled live anyway.
            // Ouinex/cTrader/MetaApi provide opened_at when they support
            // open snapshots, so this fallback only kicks in for connectors
            // that genuinely don't expose it.
            'opened_at' => $row['opened_at'] ?? date('Y-m-d H:i:s'),
            'remaining_size' => $row['remaining_size'] ?? $row['size'],
            'status' => TradeStatus::OPEN->value,
        ];

        // A position can already be protected the first time we see it, and
        // the same rule has to apply here as on the update path. Left out, the
        // status depended on something arbitrary: a position the closed-deals
        // import had materialised earlier in the same run was found existing
        // and promoted, one arriving only in the open snapshot was filed OPEN
        // and stayed wrong until the next pass.
        if ($this->stopProtectsEntry($row, (float) ($row['entry_price'] ?? 0))) {
            $tradeFields['status'] = TradeStatus::SECURED->value;
            $tradeFields['be_reached'] = 1;
        }

        $trade = $this->tradeRepo->create($tradeFields);

        // BingX (and any connector that reconstructs from fills) may emit
        // an exits[] array on still-open positions — partial closes that
        // happened before the position fully closes. Persist them as
        // partial_exits rows so the journal reflects the real activity.
        if ($this->insertPartialExits((int) $trade['id'], $row['exits'] ?? []) > 0) {
            $this->bankRealizedFromExits((int) $trade['id']);
        }
    }

    /**
     * Refresh the columns the broker owns (entry_price, size, SL/TP,
     * direction, symbol). Setup, notes, and custom_field_values belong to
     * the user — they MUST NOT be touched by this service.
     *
     * Also reconciles partial_exits when the snapshot carries an exits[]
     * array (broker connectors that walk fills): inserts any exit whose
     * external_id isn't already on the trade. Idempotent across sync ticks.
     */
    private function updateBrokerFields(array $existing, array $snapshot): void
    {
        $positionFields = [
            'entry_price' => $snapshot['entry_price'],
            'size' => $snapshot['size'],
            'sl_price' => $snapshot['sl_price'] ?? null,
            'direction' => $snapshot['direction'],
            'symbol' => $snapshot['symbol'],
        ];

        // Objectives are the user's the moment they type one, same contract as
        // setup and notes — but an objective the SYNC wrote belongs to the
        // broker and has to follow it. The old rule ("only fill an empty
        // slot") could not tell the two apart, so it froze both: a take profit
        // moved on the platform never reached the journal, and one removed
        // there stayed on file for good. Ownership is now carried in the JSON
        // itself, and a broker-owned set is rewritten from the snapshot —
        // including to null when the broker no longer has an objective.
        if (BrokerTargetBuilder::isBrokerOwned($existing['targets'] ?? null)) {
            $positionFields['targets'] = BrokerTargetBuilder::fromSnapshot($snapshot);
        }

        $this->positionRepo->update((int) $existing['position_id'], $positionFields);

        $tradeFields = ['remaining_size' => $snapshot['remaining_size'] ?? $snapshot['size']];

        // A stop moved to the entry — or past it — is what actually takes the
        // risk off, and the broker reports it as a level we already sync.
        // Promotion only: a trade can also have been secured by hand through a
        // BE exit, and pulling the stop back on the platform must not erase
        // that decision.
        if (
            $existing['trade_status'] === TradeStatus::OPEN->value
            && $this->stopProtectsEntry($snapshot, (float) $existing['entry_price'])
        ) {
            $tradeFields['status'] = TradeStatus::SECURED->value;
            $tradeFields['be_reached'] = 1;
        }

        // opened_at is broker-driven like every field above, and was the one
        // this path never rewrote — so a position already on file kept its
        // original timestamp forever. That is what left positions two hours off
        // once the connectors switched from UTC to the user's own timezone:
        // corrected on every column but the one that had changed.
        // Only when the snapshot actually carries one: BingX's live snapshot
        // has no open time, and writing null would erase what we already hold.
        if (!empty($snapshot['opened_at'])) {
            $tradeFields['opened_at'] = $snapshot['opened_at'];
        }

        $this->tradeRepo->update((int) $existing['trade_id'], $tradeFields);

        // Only when a leg actually landed: the scheduler ticks every minute and
        // re-reports the same exits, so an unconditional rollup would be one
        // write per tick per position, for nothing.
        if ($this->insertPartialExits((int) $existing['trade_id'], $snapshot['exits'] ?? []) > 0) {
            $this->bankRealizedFromExits((int) $existing['trade_id']);
        }
    }

    /**
     * Whether the stop loss has reached the entry, or gone past it into
     * profit — both count as "no risk left", per the product call. Direction
     * inverts the comparison: a long is protected once its stop rises TO the
     * entry, a short once its stop drops to it.
     */
    private function stopProtectsEntry(array $snapshot, float $entryPrice): bool
    {
        $stop = $snapshot['sl_price'] ?? null;
        if ($stop === null || $entryPrice <= 0) {
            return false;
        }

        return ($snapshot['direction'] ?? null) === Direction::SELL->value
            ? (float) $stop <= $entryPrice
            : (float) $stop >= $entryPrice;
    }

    /**
     * Insert exits[] coming from a connector reconstruction, dedup'd by
     * external_id against what's already attached to the trade. No-op when
     * the partial-exit repository wasn't injected (legacy connectors).
     */
    private function insertPartialExits(int $tradeId, array $exits): int
    {
        if (empty($exits) || $this->partialExitRepo === null) {
            return 0;
        }
        $inserted = 0;
        $existing = $this->partialExitRepo->existingExternalIdsForTrade($tradeId);
        foreach ($exits as $exit) {
            $externalId = $exit['external_id'] ?? null;
            if ($externalId !== null && isset($existing[$externalId])) {
                continue;
            }
            $inserted++;
            $this->partialExitRepo->create([
                'trade_id' => $tradeId,
                'exited_at' => $exit['closed_at'] ?? $exit['exited_at'] ?? date('Y-m-d H:i:s'),
                'exit_price' => $exit['exit_price'],
                'size' => $exit['size'],
                'exit_type' => $exit['exit_type'] ?? ExitType::MANUAL->value,
                'pnl' => $exit['pnl'] ?? 0,
                'external_id' => $externalId,
            ]);
        }

        return $inserted;
    }

    /**
     * Bank on the trade what its legs have realized so far.
     *
     * A partial exit the sync discovered was written to partial_exits and
     * stopped there: `trades.pnl` stayed NULL until the position eventually
     * closed. Every aggregate filtering on `pnl IS NOT NULL` — the KPI cards,
     * the P&L calendar — was therefore blind to money already banked. Seen on
     * 2026-08-11: a take profit of 406.13 taken at 20:29 appeared nowhere on
     * that day's figures.
     *
     * The manual path has always kept this running total — "realized metrics
     * are computed on EVERY exit so the running P&L is visible immediately for
     * swing trades" (TradeService::close). The sync was simply inconsistent
     * with it.
     *
     * Deliberately NOT called when a position closes: there the broker states
     * the position's own total, which accounts for swap and commission the
     * individual legs may not carry, and that figure is authoritative.
     */
    private function bankRealizedFromExits(int $tradeId): void
    {
        if ($this->partialExitRepo === null) {
            return;
        }

        $realized = 0.0;
        foreach ($this->partialExitRepo->findByTradeId($tradeId) as $exit) {
            $realized += (float) ($exit['pnl'] ?? 0);
        }

        $this->tradeRepo->update($tradeId, ['pnl' => round($realized, 2)]);
    }

    /**
     * In-place flip OPEN → CLOSED for an existing trade that just moved out
     * of the broker's live set. The position row and its user metadata
     * (setup, notes, custom_fields) are deliberately left alone.
     *
     * Every closing leg is also recorded as a partial exit, mirroring the
     * manual close path (TradeService::exit writes one per exit, final leg
     * included). Dedup on external_id means a leg already banked while the
     * position was still open is not written twice.
     */
    private function transitionToClosed(array $existing, array $closed): void
    {
        $this->tradeRepo->update((int) $existing['trade_id'], [
            'status' => TradeStatus::CLOSED->value,
            'closed_at' => $closed['closed_at'],
            'avg_exit_price' => $closed['exit_price'] ?? $closed['avg_exit_price'] ?? null,
            'pnl' => $closed['pnl'] ?? null,
            'remaining_size' => 0.0,
            'exit_type' => ExitType::MANUAL->value,
        ]);

        $this->insertPartialExits((int) $existing['trade_id'], $closed['exits'] ?? []);
    }

    /**
     * Index closed rows by external_id, MERGING rows that share one instead of
     * letting the last overwrite the rest.
     *
     * A position closed in several legs (a TP1 then the remainder) is reported
     * as one row per leg, all carrying the position's external_id. Keeping only
     * the last one credited the trade with the final leg's P&L alone and
     * dropped everything banked earlier.
     */
    private function indexByExternalId(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (!isset($row['external_id'])) {
                continue;
            }
            $externalId = $row['external_id'];
            $indexed[$externalId] = isset($indexed[$externalId])
                ? $this->mergeClosedRows($indexed[$externalId], $row)
                : $this->withOwnExit($row);
        }
        return $indexed;
    }

    /**
     * Seed a closed row's exits[] with itself so a single-leg close carries the
     * same shape as a merged multi-leg one. Connectors that already reconstruct
     * their own exits (BingX) keep theirs untouched.
     */
    private function withOwnExit(array $row): array
    {
        if (isset($row['exits'])) {
            return $row;
        }
        $row['exits'] = [$this->rowToExit($row)];

        return $row;
    }

    /**
     * Fold a second closing leg into the first: P&L and size add up, the exit
     * price is re-averaged by size, and the position is closed at the LAST
     * fill.
     */
    private function mergeClosedRows(array $merged, array $row): array
    {
        $mergedSize = (float) ($merged['size'] ?? 0);
        $rowSize = (float) ($row['size'] ?? 0);
        $totalSize = $mergedSize + $rowSize;

        $mergedPrice = (float) ($merged['exit_price'] ?? $merged['avg_exit_price'] ?? 0);
        $rowPrice = (float) ($row['exit_price'] ?? $row['avg_exit_price'] ?? 0);

        $merged['size'] = $totalSize;
        $merged['pnl'] = (float) ($merged['pnl'] ?? 0) + (float) ($row['pnl'] ?? 0);
        $merged['exit_price'] = $totalSize > 0
            ? ($mergedPrice * $mergedSize + $rowPrice * $rowSize) / $totalSize
            : $rowPrice;

        $rowClosedAt = $row['closed_at'] ?? null;
        if ($rowClosedAt !== null && $rowClosedAt > ($merged['closed_at'] ?? '')) {
            $merged['closed_at'] = $rowClosedAt;
        }

        $merged['exits'] = array_merge($merged['exits'] ?? [], $row['exits'] ?? [$this->rowToExit($row)]);

        return $merged;
    }

    /**
     * A closing row seen as one exit. `exit_external_id` is the per-leg id
     * (a deal, a fill) — distinct from `external_id`, which identifies the
     * position and is shared by every leg, so it is the only one usable for
     * partial-exit dedup.
     */
    private function rowToExit(array $row): array
    {
        return [
            'exit_price' => $row['exit_price'] ?? $row['avg_exit_price'] ?? null,
            'size' => $row['size'] ?? 0,
            'pnl' => $row['pnl'] ?? 0,
            'closed_at' => $row['closed_at'] ?? null,
            'external_id' => $row['exit_external_id'] ?? null,
        ];
    }
}
