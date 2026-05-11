<?php

namespace App\Services\Broker;

use App\Enums\ExitType;
use App\Enums\TradeStatus;
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
 * The OUINEX_PREFIX is what scopes the reconciliation: rows whose
 * external_id doesn't start with it are never read or touched by this
 * service. Manually-entered positions (no external_id) and file-imported
 * positions (other prefixes) are therefore safe.
 */
class BrokerOpenSyncService
{
    public const OUINEX_PREFIX = 'ouinex_';

    public function __construct(
        private PositionRepository $positionRepo,
        private TradeRepository $tradeRepo,
    ) {}

    /**
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
        int $userId,
        int $accountId,
        int $batchId,
        array $openSnapshot,
        array $closedSnapshot,
    ): array {
        $existing = $this->positionRepo->findOpenByExternalIdPrefixInAccount(
            $accountId,
            self::OUINEX_PREFIX,
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
            'external_id' => $row['external_id'],
            'import_batch_id' => $batchId,
            'position_type' => 'TRADE',
        ]);

        $this->tradeRepo->create([
            'position_id' => $position['id'],
            'opened_at' => $row['opened_at'],
            'remaining_size' => $row['size'],
            'status' => TradeStatus::OPEN->value,
        ]);
    }

    /**
     * Refresh the columns that Ouinex owns (entry_price, size, SL/TP,
     * direction, symbol). Setup, notes, and custom_field_values belong to
     * the user — they MUST NOT be touched by this service.
     */
    private function updateBrokerFields(array $existing, array $snapshot): void
    {
        $this->positionRepo->update((int) $existing['position_id'], [
            'entry_price' => $snapshot['entry_price'],
            'size' => $snapshot['size'],
            'sl_price' => $snapshot['sl_price'] ?? null,
            'direction' => $snapshot['direction'],
            'symbol' => $snapshot['symbol'],
        ]);

        $this->tradeRepo->update((int) $existing['trade_id'], [
            'remaining_size' => $snapshot['size'],
        ]);
    }

    /**
     * In-place flip OPEN → CLOSED for an existing trade that just moved out
     * of the broker's live set. The position row and its user metadata
     * (setup, notes, custom_fields) are deliberately left alone.
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
    }

    private function indexByExternalId(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (!isset($row['external_id'])) {
                continue;
            }
            $indexed[$row['external_id']] = $row;
        }
        return $indexed;
    }
}
