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
        private array $brokerConfig,
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

    /**
     * Redirect to cTrader OAuth authorization page.
     */
    public function ctraderAuthorize(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) ($request->getQuery()['account_id'] ?? 0);

        if (!$accountId) {
            throw new ValidationException('broker.error.account_required', 'account_id');
        }

        $existing = $this->connectionRepo->findByAccountId($accountId);
        if ($existing) {
            throw new ValidationException('broker.error.already_connected', 'account_id');
        }

        $config = $this->brokerConfig['ctrader'];
        $state = base64_encode(json_encode([
            'user_id' => $userId,
            'account_id' => $accountId,
            'nonce' => bin2hex(random_bytes(16)),
            'exp' => time() + 300, // 5 min expiry
        ]));

        $url = $config['oauth_authorize_url'] . '?' . http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'scope' => 'trading',
            'response_type' => 'code',
            'state' => $state,
        ]);

        return $this->jsonSuccess(['authorize_url' => $url]);
    }

    /**
     * Handle cTrader OAuth callback.
     */
    public function ctraderCallback(Request $request): Response
    {
        $query = $request->getQuery();
        $code = $query['code'] ?? '';
        $stateRaw = $query['state'] ?? '';

        if (!$code || !$stateRaw) {
            throw new ValidationException('broker.error.oauth_failed', 'code');
        }

        $state = json_decode(base64_decode($stateRaw), true);
        if (!$state || ($state['exp'] ?? 0) < time()) {
            throw new ValidationException('broker.error.oauth_expired', 'state');
        }

        $userId = (int) ($state['user_id'] ?? 0);
        $accountId = (int) ($state['account_id'] ?? 0);

        // Exchange code for tokens
        $config = $this->brokerConfig['ctrader'];
        $client = new \GuzzleHttp\Client();

        $response = $client->get($config['oauth_token_url'], [
            'query' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $config['redirect_uri'],
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ],
        ]);

        $tokens = json_decode($response->getBody()->getContents(), true);
        if (!isset($tokens['accessToken'])) {
            throw new ValidationException('broker.error.oauth_failed', 'token');
        }

        // Store connection with encrypted credentials
        $credentials = [
            'access_token' => $tokens['accessToken'],
            'refresh_token' => $tokens['refreshToken'] ?? null,
            'ctid_trader_account_id' => $tokens['ctidTraderAccountId'] ?? null,
        ];

        $encrypted = $this->crypto->encrypt($credentials);

        $this->connectionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'provider' => BrokerProvider::CTRADER->value,
            'status' => ConnectionStatus::ACTIVE->value,
            'credentials_encrypted' => $encrypted['ciphertext'],
            'credentials_iv' => $encrypted['iv'],
        ]);

        // Redirect to frontend
        return Response::redirect('http://localhost:5173/#/accounts?connection=success');
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
