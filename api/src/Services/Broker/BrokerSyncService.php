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
    /**
     * Au-delà de ce délai, la réservation d'une connexion est considérée
     * abandonnée et reprise par l'appelant suivant. Assez large pour ne jamais
     * doubler une synchro lente encore vivante, assez court pour qu'un worker
     * tué ne bloque pas la connexion au-delà d'un tour de cron.
     */
    public const SYNC_CLAIM_TTL_SECONDS = 900;

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
        private ?\App\Repositories\UserRepository $userRepo = null,
    ) {}

    /**
     * Queue a sync instead of running it inside the HTTP request.
     *
     * A cTrader pass opens four to five WebSocket sessions in a row: run inline,
     * the user waits in front of a spinner and a proxy timeout can cut it in
     * half, leaving them with no idea whether anything was imported. Flagging
     * the connection hands the work to the scheduler, which ticks every minute.
     *
     * Deliberately still queued when a run is already in flight: that run took
     * its reservation before this flag was set, so it will not consume it, and
     * the user gets the fresh pass they asked for on the following tick.
     */
    public function requestSync(int $connectionId, int $userId): array
    {
        $connection = $this->requireSyncableConnection($connectionId, $userId);

        $this->connectionRepo->requestSync($connectionId);

        return [
            'status' => SyncStatus::QUEUED->value,
            'syncing' => ($connection['syncing_since'] ?? null) !== null,
        ];
    }

    /**
     * The connection, or the reason it cannot be synced. Shared by the queueing
     * path and the run itself so the two never diverge on what is syncable.
     */
    private function requireSyncableConnection(int $connectionId, int $userId): array
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

        return $connection;
    }

    /**
     * Synchronize trades from broker API.
     */
    public function sync(int $connectionId, int $userId): array
    {
        $connection = $this->requireSyncableConnection($connectionId, $userId);

        // One sync at a time per connection. Nothing else serialises the manual
        // click against the scheduled run, and two concurrent runs on the same
        // connection import the same deals twice — the dedup is per-batch, not
        // cross-batch. The reservation is also what lets the scheduler fan out
        // across several workers without splitting the work up front.
        if (!$this->connectionRepo->claimForSync($connectionId, self::SYNC_CLAIM_TTL_SECONDS)) {
            return $this->alreadySyncingResult();
        }

        $syncLog = null;

        try {
            // Create sync log entry
            $syncLog = $this->syncLogRepo->create([
                'broker_connection_id' => $connectionId,
                'user_id' => $userId,
                'status' => SyncStatus::STARTED->value,
            ]);

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

            // The journal's DATETIME columns hold local wall-clock time — that
            // is what the trade form writes. Brokers report instants, so
            // without this the synced rows land in UTC and sit an hour or two
            // away from the trades the user typed in by hand.
            if (method_exists($connector, 'setTimezone')) {
                $connector->setTimezone($this->resolveUserTimezone($userId));
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

            // Null when the failure happened while opening the log itself.
            if ($syncLog !== null) {
                $this->syncLogRepo->update($syncLog['id'], [
                    'status' => SyncStatus::FAILED->value,
                    'error_message' => $e->getMessage(),
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
            }

            throw $e;
        } finally {
            // In a finally, never at the end of the happy path: a crash that
            // left the reservation in place would lock the connection out of
            // every sync until the staleness window expires.
            $this->connectionRepo->releaseSync($connectionId);

            // What the run cost at the broker, before the session is torn down.
            // Brokers cap requests per day — FTMO disables a trading account
            // past 2 000 — and a budget nobody can measure is one nobody
            // notices going over. Logged even when the run failed: a crashing
            // run still spent its requests, and a crash loop is exactly how a
            // quota gets burnt.
            if (isset($connector) && method_exists($connector, 'getRequestCounts')) {
                $spent = $connector->getRequestCounts();
                if (($spent['total'] ?? 0) > 0) {
                    BrokerLogger::event('ctrader', 'sync_request_budget', [
                        'connection_id' => $connectionId,
                        'requests' => $spent['total'],
                        'by_type' => $spent['by_type'] ?? [],
                    ]);
                }
            }

            // Connectors that hold one socket open for the whole run (cTrader)
            // hang up here. Without it a crashed run leaks its socket, and the
            // scheduler runs thousands of them a day.
            if (isset($connector) && method_exists($connector, 'closeSession')) {
                $connector->closeSession();
            }
        }
    }

    /**
     * Résultat d'une synchro qui n'a pas eu lieu : une autre la tenait déjà.
     * Même forme que le succès — l'appelant lit des compteurs, il ne doit pas
     * avoir à distinguer deux structures — mais tout à zéro et un statut à part.
     */
    private function alreadySyncingResult(): array
    {
        return [
            'status' => SyncStatus::SKIPPED->value,
            'deals_fetched' => 0,
            'imported_positions' => 0,
            'imported_trades' => 0,
            'skipped_duplicates' => 0,
            'batch_id' => null,
            'live_inserted' => 0,
            'live_updated' => 0,
            'live_transitioned' => 0,
            'pending_inserted' => 0,
            'pending_updated' => 0,
            'pending_executed' => 0,
            'pending_expired' => 0,
            'pending_cancelled' => 0,
        ];
    }

    /**
     * The timezone the journal's datetimes are written in for this user.
     * Null — no repository injected, unknown user, blank column — leaves the
     * connector on UTC, i.e. the behaviour that predates this.
     */
    private function resolveUserTimezone(int $userId): ?string
    {
        $timezone = $this->userRepo?->findById($userId)['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
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
