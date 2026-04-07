<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Exceptions\ValidationException;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\SyncLogRepository;
use App\Services\Broker\BrokerSyncService;
use App\Services\Broker\CredentialEncryptionService;

class BrokerSyncController extends Controller
{
    public function __construct(
        private BrokerSyncService $syncService,
        private BrokerConnectionRepository $connectionRepo,
        private SyncLogRepository $syncLogRepo,
        private CredentialEncryptionService $crypto,
    ) {}

    /**
     * Create a MetaApi connection (credentials provided in body).
     */
    public function createConnection(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = $request->getBody();

        $provider = $body['provider'] ?? '';
        $accountId = (int) ($body['account_id'] ?? 0);

        if (!$accountId) {
            throw new ValidationException('broker.error.account_required', 'account_id');
        }

        // Check no existing connection for this account
        $existing = $this->connectionRepo->findByAccountId($accountId);
        if ($existing) {
            throw new ValidationException('broker.error.already_connected', 'account_id');
        }

        if ($provider === BrokerProvider::METAAPI->value) {
            return $this->createMetaApiConnection($userId, $accountId, $body);
        }

        if ($provider === BrokerProvider::CTRADER->value) {
            return $this->createCtraderConnection($userId, $accountId, $body);
        }

        throw new ValidationException('broker.error.unsupported_provider', 'provider');
    }

    /**
     * Get connection for an account.
     */
    public function connections(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) ($request->getQuery()['account_id'] ?? 0);

        if ($accountId) {
            $connection = $this->connectionRepo->findByAccountId($accountId);
            if ($connection && (int) $connection['user_id'] === $userId) {
                return $this->jsonSuccess($this->sanitizeConnection($connection));
            }
            return $this->jsonSuccess(null);
        }

        $connections = $this->connectionRepo->findAllByUserId($userId);
        return $this->jsonSuccess(array_map([$this, 'sanitizeConnection'], $connections));
    }

    /**
     * Trigger a sync for a connection.
     */
    public function sync(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $connectionId = (int) $request->getAttribute('id');

        $result = $this->syncService->sync($connectionId, $userId);

        return $this->jsonSuccess($result);
    }

    /**
     * Delete a connection.
     */
    public function deleteConnection(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $connectionId = (int) $request->getAttribute('id');

        $connection = $this->connectionRepo->findById($connectionId);
        if (!$connection || (int) $connection['user_id'] !== $userId) {
            throw new ValidationException('broker.error.connection_not_found', 'id');
        }

        $this->connectionRepo->delete($connectionId);

        return $this->jsonSuccess(['message_key' => 'broker.success.disconnected']);
    }

    /**
     * Get sync logs for a connection.
     */
    public function syncLogs(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $connectionId = (int) $request->getAttribute('id');

        $connection = $this->connectionRepo->findById($connectionId);
        if (!$connection || (int) $connection['user_id'] !== $userId) {
            throw new ValidationException('broker.error.connection_not_found', 'id');
        }

        $logs = $this->syncLogRepo->findByConnectionId($connectionId);

        return $this->jsonSuccess($logs);
    }

    private function createCtraderConnection(int $userId, int $accountId, array $body): Response
    {
        $clientId = $body['client_id'] ?? '';
        $clientSecret = $body['client_secret'] ?? '';
        $accessToken = $body['access_token'] ?? '';
        $accountNumber = $body['account_id_ctrader'] ?? '';

        if (!$clientId || !$clientSecret || !$accessToken || !$accountNumber) {
            throw new ValidationException('broker.error.credentials_required', 'access_token');
        }

        $credentials = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'access_token' => $accessToken,
            'ctid_trader_account_id' => (int) $accountNumber,
        ];

        $encrypted = $this->crypto->encrypt($credentials);

        $connection = $this->connectionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'provider' => BrokerProvider::CTRADER->value,
            'status' => ConnectionStatus::ACTIVE->value,
            'credentials_encrypted' => $encrypted['ciphertext'],
            'credentials_iv' => $encrypted['iv'],
        ]);

        return $this->jsonSuccess($this->sanitizeConnection($connection));
    }

    private function createMetaApiConnection(int $userId, int $accountId, array $body): Response
    {
        $apiToken = $body['api_token'] ?? '';
        $metaApiAccountId = $body['metaapi_account_id'] ?? '';

        if (!$apiToken || !$metaApiAccountId) {
            throw new ValidationException('broker.error.credentials_required', 'api_token');
        }

        $credentials = [
            'api_token' => $apiToken,
            'metaapi_account_id' => $metaApiAccountId,
        ];

        $encrypted = $this->crypto->encrypt($credentials);

        $connection = $this->connectionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'provider' => BrokerProvider::METAAPI->value,
            'status' => ConnectionStatus::ACTIVE->value,
            'credentials_encrypted' => $encrypted['ciphertext'],
            'credentials_iv' => $encrypted['iv'],
        ]);

        return $this->jsonSuccess($this->sanitizeConnection($connection));
    }

    /**
     * Remove sensitive fields from connection before returning to client.
     */
    private function sanitizeConnection(array $connection): array
    {
        unset($connection['credentials_encrypted'], $connection['credentials_iv']);
        return $connection;
    }
}
