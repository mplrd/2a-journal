<?php

namespace App\Services\Broker;

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
 * Scope is enforced by the prefix 'ouinex_order_'. Manual orders (no
 * external_id) and other-provider orders (different prefix) are invisible
 * to this service.
 *
 * Note on the position parent of an order: when a pending order executes
 * on Ouinex, it leaves open_orders and the resulting margin position
 * appears in open_margin_positions under a DIFFERENT external_id
 * ('ouinex_<margin_position_id>'). The order's position row and the
 * eventual trade's position row therefore stay distinct — we don't try to
 * glue them at this layer.
 */
class BrokerOrderSyncService
{
    public const OUINEX_ORDER_PREFIX = 'ouinex_order_';

    public function __construct(
        private OrderRepository $orderRepo,
        private PositionRepository $positionRepo,
    ) {}

    /**
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
        int $userId,
        int $accountId,
        int $batchId,
        array $openOrdersSnapshot,
        array $closedOrdersSnapshot,
    ): array {
        $existing = $this->orderRepo->findPendingByExternalIdPrefixInAccount(
            $accountId,
            self::OUINEX_ORDER_PREFIX,
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
            'external_id' => $row['external_id'],
            'import_batch_id' => $batchId,
            'position_type' => PositionType::ORDER->value,
        ]);

        $this->orderRepo->create([
            'position_id' => $position['id'],
            'expires_at' => $row['expires_at'] ?? null,
            'status' => OrderStatus::PENDING->value,
        ]);
    }

    /**
     * Refresh the columns Ouinex owns. setup/notes/custom_field_values are
     * the user's — never touched here.
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
    }
}
