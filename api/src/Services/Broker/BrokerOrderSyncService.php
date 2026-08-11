<?php

namespace App\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\OrderStatus;
use App\Enums\PositionType;
use App\Repositories\OrderRepository;
use App\Repositories\PositionRepository;

/**
 * Reconciles the broker's pending-orders snapshot against the journal's
 * current state. Mirrors BrokerOpenSyncService's pattern but operates on
 * the ORDER lifecycle (positions of type ORDER + orders.status PENDING)
 * rather than the TRADE lifecycle.
 *
 *  - INSERT   : snapshot order not yet known → create position (ORDER) +
 *               order (PENDING)
 *  - UPDATE   : known pending order → refresh broker-driven fields on the
 *               position (entry_price, size, SL) while preserving user meta
 *               (setup, notes, custom_fields)
 *  - EXECUTED : known pending order absent from open_orders AND present in
 *               closed_orders as EXECUTED/FILLED → order flipped to
 *               EXECUTED (the resulting margin position is ingested by
 *               BrokerOpenSyncService separately under its own external_id)
 *  - EXPIRED  : closed_orders confirms EXPIRED → order flipped to EXPIRED
 *  - CANCELLED: closed_orders confirms CANCELLED, OR no closed_orders signal
 *               at all → conservative default flip to CANCELLED
 *
 * Scoping: the BrokerProvider passed to apply() drives the external_id
 * prefix (cf. BrokerProvider::orderExternalIdPrefix). Manual orders (no
 * external_id) and other-provider orders (different prefix) are invisible
 * to this service. Same instance handles every provider — adding a new
 * one means just plugging a new normalizer + connector.
 *
 * Note on the position parent of an order: when a pending order executes,
 * it leaves open_orders and the resulting position appears in the
 * open-positions feed under a DIFFERENT external_id (positions prefix vs
 * orders prefix). The order's position row and the eventual trade's
 * position row therefore stay distinct — we don't try to glue them at
 * this layer.
 */
class BrokerOrderSyncService
{
    public function __construct(
        private OrderRepository $orderRepo,
        private PositionRepository $positionRepo,
    ) {}

    /**
     * @param BrokerProvider $provider Provider whose order-id prefix scopes the diff.
     * @param int $userId Owner of the connection.
     * @param int $accountId Account scope.
     * @param int $batchId Import batch used for new-order traceability.
     * @param array $openOrdersSnapshot Normalized pending orders from connector.
     * @param array $closedOrdersSnapshot Normalized closed-order final states
     *                                    (rows of {external_id, final_status}).
     *                                    Optional but recommended — without
     *                                    it, disappearances default to
     *                                    CANCELLED. May be empty for
     *                                    connectors that don't surface
     *                                    closed-order history.
     * @return array{inserted: int, updated: int, executed: int, expired: int, cancelled: int}
     */
    public function apply(
        BrokerProvider $provider,
        int $userId,
        int $accountId,
        int $batchId,
        array $openOrdersSnapshot,
        array $closedOrdersSnapshot,
    ): array {
        $existing = $this->orderRepo->findPendingByExternalIdPrefixInAccount(
            $accountId,
            $provider->orderExternalIdPrefix(),
        );

        $finalStatusByExternalId = $this->indexFinalStatus($closedOrdersSnapshot);

        $stats = ['inserted' => 0, 'updated' => 0, 'executed' => 0, 'expired' => 0, 'cancelled' => 0];
        $seen = [];

        // INSERT or UPDATE for each snapshot row.
        foreach ($openOrdersSnapshot as $row) {
            $externalId = $row['external_id'];
            $seen[$externalId] = true;

            if (isset($existing[$externalId])) {
                $this->updateBrokerFields($existing[$externalId], $row);
                $stats['updated']++;
            } else {
                $this->insertNewPending($userId, $accountId, $batchId, $row);
                $stats['inserted']++;
            }
        }

        // For anything in DB but not in open snapshot, decide the final
        // status. closed_orders takes precedence; default CANCELLED if no
        // info available (conservative).
        foreach ($existing as $externalId => $row) {
            if (isset($seen[$externalId])) {
                continue;
            }

            $finalStatus = $finalStatusByExternalId[$externalId] ?? 'CANCELLED';
            $orderStatus = $this->mapFinalStatusToEnum($finalStatus);
            $this->orderRepo->updateStatus((int) $row['order_id'], $orderStatus->value);

            $key = match ($orderStatus) {
                OrderStatus::EXECUTED => 'executed',
                OrderStatus::EXPIRED => 'expired',
                default => 'cancelled',
            };
            $stats[$key]++;
        }

        return $stats;
    }

    private function indexFinalStatus(array $closedOrdersSnapshot): array
    {
        $indexed = [];
        foreach ($closedOrdersSnapshot as $row) {
            if (!isset($row['external_id'], $row['final_status'])) {
                continue;
            }
            $indexed[$row['external_id']] = $row['final_status'];
        }
        return $indexed;
    }

    private function mapFinalStatusToEnum(string $finalStatus): OrderStatus
    {
        return match ($finalStatus) {
            'EXECUTED' => OrderStatus::EXECUTED,
            'EXPIRED' => OrderStatus::EXPIRED,
            default => OrderStatus::CANCELLED,
        };
    }

    private function insertNewPending(int $userId, int $accountId, int $batchId, array $row): void
    {
        $position = $this->positionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'direction' => $row['direction'],
            'symbol' => $row['symbol'],
            'entry_price' => $row['entry_price'],
            'size' => $row['size'],
            'sl_price' => $row['sl_price'] ?? null,
            // The connectors normalize a pending order's take profit and
            // nothing consumed it: only the open-position path knew how to
            // write positions.targets, so an order's objective was neither
            // stored nor displayed. A pending order has no partial exit, so
            // the level covers its whole size.
            'targets' => BrokerTargetBuilder::fromSnapshot($row),
            'external_id' => $row['external_id'],
            'import_batch_id' => $batchId,
            'position_type' => PositionType::ORDER->value,
        ]);

        $this->orderRepo->create([
            'position_id' => $position['id'],
            // When the broker says the order was placed. Left out, the column
            // falls back to CURRENT_TIMESTAMP and every synced order is dated
            // at the moment of the sync instead.
            'created_at' => $row['created_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'status' => OrderStatus::PENDING->value,
        ]);
    }

    /**
     * Refresh the columns the broker owns. setup/notes/custom_field_values are
     * the user's — never touched here.
     *
     * The expiry lives on the order row, not the position, and used to be left
     * out entirely: an expiry already on file was never corrected, including
     * when the connectors stopped writing UTC.
     */
    private function updateBrokerFields(array $existing, array $snapshot): void
    {
        $this->positionRepo->update((int) $existing['position_id'], [
            'entry_price' => $snapshot['entry_price'],
            'size' => $snapshot['size'],
            'sl_price' => $snapshot['sl_price'] ?? null,
            'direction' => $snapshot['direction'],
            'symbol' => $snapshot['symbol'],
            // Refreshed unconditionally, unlike the open-position path. There
            // is no user-typed objective to protect on a synced pending order:
            // the row came from the broker and every other field here is
            // already taken from the snapshot. Dropping the take profit on the
            // platform therefore clears it here too, rather than leaving a
            // stale objective behind.
            'targets' => BrokerTargetBuilder::fromSnapshot($snapshot),
        ]);

        if (array_key_exists('expires_at', $snapshot)) {
            $this->orderRepo->updateExpiry((int) $existing['order_id'], $snapshot['expires_at']);
        }
    }
}
