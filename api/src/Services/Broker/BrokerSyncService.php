<?php

namespace App\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\SyncLogRepository;
use App\Services\Import\ImportService;
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

            // Update connection state
            $updateData = [
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_sync_status' => SyncStatus::SUCCESS->value,
                'last_sync_error' => null,
            ];
            if ($result['cursor']) {
                $updateData['sync_cursor'] = $result['cursor'];
            }
            $this->connectionRepo->update($connectionId, $updateData);

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
        };
    }
}
