<?php

namespace App\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\SyncLogRepository;
use App\Services\Broker\BrokerOpenSyncService;
use App\Services\Import\ImportService;
use App\Repositories\AccountRepository;
use App\Services\Import\RowGroupingService;

class BrokerSyncService
{
    public function __construct(
        private BrokerConnectionRepository $connectionRepo,
        private SyncLogRepository $syncLogRepo,
        private ImportService $importService,
        private RowGroupingService $grouper,
        private CredentialEncryptionService $crypto,
        private ConnectorInterface $ctraderConnector,
        private ConnectorInterface $metaApiConnector,
        private ConnectorInterface $ouinexConnector,
        private ConnectorInterface $bingxConnector,
        private BrokerOpenSyncService $openSyncService,
        private BrokerOrderSyncService $orderSyncService,
        private ?AccountRepository $accountRepo = null,
    ) {}

    /**
     * Synchronize trades from broker API.
     */
    public function sync(int $connectionId, int $userId): array
    {
        $connection = $this->connectionRepo->findById($connectionId);
        if (!$connection) {
            throw new ValidationException('broker.error.connection_not_found', 'id');
        }

        if ((int) $connection['user_id'] !== $userId) {
            throw new ForbiddenException('broker.error.forbidden');
        }

        if ($connection['status'] !== ConnectionStatus::ACTIVE->value) {
            throw new ValidationException('broker.error.connection_not_active', 'status');
        }

        // Create sync log entry
        $syncLog = $this->syncLogRepo->create([
            'broker_connection_id' => $connectionId,
            'user_id' => $userId,
            'status' => SyncStatus::STARTED->value,
        ]);

        try {
            // Decrypt credentials
            $credentials = $this->crypto->decrypt(
                $connection['credentials_encrypted'],
                $connection['credentials_iv']
            );

            // Select connector
            $connector = $this->getConnector($connection['provider']);

            // Refresh credentials if needed
            $refreshed = $connector->refreshCredentials($credentials);
            if ($refreshed !== $credentials) {
                $encrypted = $this->crypto->encrypt($refreshed);
                $this->connectionRepo->update($connectionId, [
                    'credentials_encrypted' => $encrypted['ciphertext'],
                    'credentials_iv' => $encrypted['iv'],
                ]);
                $credentials = $refreshed;
            }

            // Hand the connector the symbols we've persisted from previous
            // syncs so it can scan history on symbols the user has fully
            // closed (no longer in /user/positions). Only BingX supports
            // this today; other connectors silently no-op.
            if (method_exists($connector, 'setKnownSymbols')) {
                $persistedSymbols = $this->decodeSymbolsSeen($connection['symbols_seen'] ?? null);
                $connector->setKnownSymbols($persistedSymbols);
            }
            if (method_exists($connector, 'resetSyncCache')) {
                $connector->resetSyncCache();
            }

            // Fetch deals from broker
            $result = $connector->fetchDeals($credentials, $connection['sync_cursor']);
            $deals = $result['deals'];

            // Group deals into positions (by external_id)
            $positions = $this->grouper->group($deals, ['external_id']);

            // Import via the shared pipeline
            $importResult = $this->importService->importNormalizedPositions(
                $userId,
                (int) $connection['account_id'],
                $positions,
                'api-sync-' . strtolower($connection['provider']),
                strtolower($connection['provider']),
            );

            // Reconcile the live OPEN snapshot. fetchOpenPositions is best-
            // effort (cTrader/MetaApi return empty for now) — we still call
            // the diff service so OPEN→CLOSED transitions of previously-known
            // Ouinex positions get a chance to run via the closed deals we
            // just fetched.
            $openResult = $connector->fetchOpenPositions($credentials);
            $liveStats = $this->openSyncService->apply(
                BrokerProvider::from($connection['provider']),
                $userId,
                (int) $connection['account_id'],
                (int) $importResult['batch_id'],
                $openResult['positions'],
                $deals,
            );

            // Reconcile pending orders. Same pattern as open positions but
            // on the ORDER lifecycle. closed_orders is consumed alongside
            // open_orders so disappearances can be tagged EXECUTED,
            // EXPIRED, or CANCELLED accurately rather than always defaulting
            // to CANCELLED.
            $openOrdersResult = $connector->fetchOpenOrders($credentials);
            $closedOrdersResult = $connector->fetchClosedOrders($credentials, $connection['sync_cursor']);
            $orderStats = $this->orderSyncService->apply(
                BrokerProvider::from($connection['provider']),
                $userId,
                (int) $connection['account_id'],
                (int) $importResult['batch_id'],
                $openOrdersResult['orders'],
                $closedOrdersResult['orders'],
            );

            // Update connection state
            $updateData = [
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_sync_status' => SyncStatus::SUCCESS->value,
                'last_sync_error' => null,
            ];
            if ($result['cursor']) {
                $updateData['sync_cursor'] = $result['cursor'];
            }

            // Persist the symbols the connector reports having observed
            // during this run. Union with what was previously stored — we
            // never lose a symbol once seen.
            if (method_exists($connector, 'getSeenSymbols')) {
                $previouslySeen = $this->decodeSymbolsSeen($connection['symbols_seen'] ?? null);
                $newlySeen = $connector->getSeenSymbols();
                $union = array_values(array_unique(array_merge($previouslySeen, $newlySeen)));
                sort($union);
                if (!empty($union)) {
                    $updateData['symbols_seen'] = json_encode($union);
                }
            }

            $this->connectionRepo->update($connectionId, $updateData);

            // Pull the broker-reported balance and persist it on the account
            // row. Failure-tolerant: a connector that doesn't expose balance
            // returns null and we leave the previous value alone. Wrapped in
            // a try/catch so a balance hiccup never aborts an otherwise
            // successful sync.
            try {
                $balance = $connector->fetchBalance($credentials);
                if ($balance !== null && $this->accountRepo !== null) {
                    // Persist the currency the balance is denominated in (when
                    // the connector reports it) so the UI can flag a mismatch
                    // with the account's own currency.
                    $currency = method_exists($connector, 'getBalanceCurrency')
                        ? $connector->getBalanceCurrency()
                        : null;
                    $this->accountRepo->updateBrokerBalance((int) $connection['account_id'], $balance, $currency);
                }
            } catch (\Throwable $e) {
                // Logged via BrokerLogger? Keep silent here — the sync logs
                // status SUCCESS regardless; the balance is "best effort".
                BrokerLogger::failure(strtolower($connection['provider']), 'balance_fetch_failed', [
                    'msg' => $e->getMessage(),
                    'account_id' => (int) $connection['account_id'],
                ]);
            }

            // Update sync log
            $this->syncLogRepo->update($syncLog['id'], [
                'status' => SyncStatus::SUCCESS->value,
                'deals_fetched' => $result['raw_count'],
                'deals_imported' => $importResult['imported_positions'],
                'deals_skipped' => $importResult['skipped_duplicates'],
                'import_batch_id' => $importResult['batch_id'],
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'status' => SyncStatus::SUCCESS->value,
                'deals_fetched' => $result['raw_count'],
                'imported_positions' => $importResult['imported_positions'],
                'imported_trades' => $importResult['imported_trades'],
                'skipped_duplicates' => $importResult['skipped_duplicates'],
                'batch_id' => $importResult['batch_id'],
                'live_inserted' => $liveStats['inserted'],
                'live_updated' => $liveStats['updated'],
                'live_transitioned' => $liveStats['transitioned'],
                'pending_inserted' => $orderStats['inserted'],
                'pending_updated' => $orderStats['updated'],
                'pending_executed' => $orderStats['executed'],
                'pending_expired' => $orderStats['expired'],
                'pending_cancelled' => $orderStats['cancelled'],
            ];
        } catch (\Throwable $e) {
            // Update connection and log on failure
            $this->connectionRepo->update($connectionId, [
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_sync_status' => SyncStatus::FAILED->value,
                'last_sync_error' => $e->getMessage(),
            ]);

            $this->syncLogRepo->update($syncLog['id'], [
                'status' => SyncStatus::FAILED->value,
                'error_message' => $e->getMessage(),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

            throw $e;
        }
    }

    private function getConnector(string $provider): ConnectorInterface
    {
        return match (BrokerProvider::from($provider)) {
            BrokerProvider::CTRADER => $this->ctraderConnector,
            BrokerProvider::METAAPI => $this->metaApiConnector,
            BrokerProvider::OUINEX => $this->ouinexConnector,
            BrokerProvider::BINGX => $this->bingxConnector,
        };
    }

    /**
     * Decode the JSON-encoded symbols_seen blob stored on broker_connections.
     * Tolerates null, empty string and malformed JSON — the union we re-write
     * at the end of the sync uses the connector's report as the new source
     * of truth anyway. Returns a plain string[] of unique symbols.
     *
     * @return string[]
     */
    private function decodeSymbolsSeen(mixed $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }
        $decoded = is_array($stored) ? $stored : json_decode((string) $stored, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map('strval', $decoded),
            fn($v) => $v !== ''
        )));
    }
}
