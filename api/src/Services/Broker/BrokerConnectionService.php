<?php

namespace App\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\BrokerConnectionRepository;

/**
 * Lifecycle of a broker connection: create, reconfigure, read.
 * (docs/85-broker-connection-reconfigure.md)
 *
 * Reconfiguring exists because credentials go stale — a rotated cTrader
 * clientSecret makes every sync fail with CH_CLIENT_AUTH_FAILURE, which flips
 * the connection to ERROR, and BrokerSyncService refuses to sync anything but
 * ACTIVE. Without an update path the only escape was deleting the connection,
 * which drops its sync cursor (so the next sync re-walks 90 days of history)
 * and cascades away its whole sync_logs trail.
 *
 * updateCredentials() therefore edits in place: same row, same id, same
 * cursor, same logs — only the credentials change, and the status is reset so
 * the connection can sync again.
 */
class BrokerConnectionService
{
    public function __construct(
        private BrokerConnectionRepository $connectionRepo,
        private CredentialEncryptionService $crypto,
        private BrokerCredentialMapper $mapper,
        private ConnectorRegistry $connectors,
    ) {}

    /**
     * @return array{connection: array, connection_test: array{success: bool, error: ?string}}
     */
    public function createConnection(int $userId, int $accountId, string $provider, array $body): array
    {
        if (!$accountId) {
            throw new ValidationException('broker.error.account_required', 'account_id');
        }
        if (BrokerProvider::tryFrom($provider) === null) {
            throw new ValidationException('broker.error.unsupported_provider', 'provider');
        }
        if ($this->connectionRepo->findByAccountId($accountId)) {
            throw new ValidationException('broker.error.already_connected', 'account_id');
        }

        $credentials = $this->mapper->build($provider, $body);
        $encrypted = $this->crypto->encrypt($credentials);

        $connection = $this->connectionRepo->create([
            'user_id' => $userId,
            'account_id' => $accountId,
            'provider' => $provider,
            'status' => ConnectionStatus::ACTIVE->value,
            'credentials_encrypted' => $encrypted['ciphertext'],
            'credentials_iv' => $encrypted['iv'],
        ]);

        return [
            'connection' => $this->present($connection, $credentials),
            'connection_test' => $this->testCredentials($provider, $credentials),
        ];
    }

    /**
     * Replace some or all credentials on an existing connection. Fields left
     * blank keep their stored value — secrets are never sent back to the
     * client, so an untouched password input arrives empty and must not wipe
     * the stored secret.
     *
     * @return array{connection: array, connection_test: array{success: bool, error: ?string}}
     */
    public function updateCredentials(int $connectionId, int $userId, array $body): array
    {
        $connection = $this->requireOwnedConnection($connectionId, $userId);
        $provider = $connection['provider'];

        if (!$this->mapper->hasAnyField($provider, $body)) {
            // An empty submit is a user error, not a silent status reset.
            throw new ValidationException('broker.error.no_credential_change', 'credentials');
        }

        $existing = $this->decryptOrEmpty($connection);
        $credentials = $this->mapper->merge($provider, $existing, $body);
        $encrypted = $this->crypto->encrypt($credentials);

        // Reset the failure state in the same write: fresh credentials mean the
        // previous error no longer describes the connection, and leaving it on
        // ERROR would keep sync() refusing to run.
        $this->connectionRepo->update($connectionId, [
            'credentials_encrypted' => $encrypted['ciphertext'],
            'credentials_iv' => $encrypted['iv'],
            'status' => ConnectionStatus::ACTIVE->value,
            'last_sync_error' => null,
        ]);
        $this->connectionRepo->resetFailures($connectionId);

        return [
            'connection' => $this->present($this->connectionRepo->findById($connectionId), $credentials),
            'connection_test' => $this->testCredentials($provider, $credentials),
        ];
    }

    /**
     * The connection attached to an account, as the client may see it: no
     * ciphertext, no secrets — just the non-secret identifiers (to prefill the
     * reconfigure dialog) and a set/unset flag per secret.
     */
    public function findForAccount(int $accountId, int $userId): ?array
    {
        $connection = $this->connectionRepo->findByAccountId($accountId);
        if (!$connection || (int) $connection['user_id'] !== $userId) {
            return null;
        }

        return $this->present($connection, $this->decryptOrEmpty($connection));
    }

    /** @return array<int, array> */
    public function findAllForUser(int $userId): array
    {
        return array_map(
            fn(array $c) => $this->present($c, $this->decryptOrEmpty($c)),
            $this->connectionRepo->findAllByUserId($userId),
        );
    }

    /**
     * List the cTrader accounts reachable with a set of app credentials, so the
     * user picks one instead of typing `ctidTraderAccountId` by hand — a number
     * the cTrader platform never displays (it shows `traderLogin`, a different
     * number, which is what a real user entered before getting "account not
     * found").
     *
     * Credentials come from the request body, overlaid on the stored ones when
     * `connection_id` is supplied — that way the reconfigure dialog can list
     * accounts without the user retyping a secret that is already stored.
     *
     * Broker-side failures are reported, not thrown: the reason ("wrong
     * clientSecret") is the actionable part and must reach the user, so it
     * comes back in `error` — redacted — alongside an empty list. Only a
     * caller mistake (missing credential, someone else's connection) throws.
     *
     * @return array{accounts: list<array{ctid_trader_account_id: int, trader_login: string, is_live: bool, broker_name: ?string, balance: ?float, currency: ?string, is_disabled: bool, disabled_reason: ?string, details: array<string, string|int|float|bool>}>, error: ?string}
     */
    public function discoverCtraderAccounts(int $userId, array $body): array
    {
        $existing = [];
        if (!empty($body['connection_id'])) {
            $connection = $this->requireOwnedConnection((int) $body['connection_id'], $userId);
            $existing = $this->decryptOrEmpty($connection);
        }

        // The account list is keyed by access token, not by account, so the
        // account id is not required here — merge() would demand it.
        $credentials = [];
        foreach (['client_id', 'client_secret', 'access_token'] as $field) {
            $value = trim((string) ($body[$field] ?? ''));
            $credentials[$field] = $value !== '' ? $value : ($existing[$field] ?? '');
            if ($credentials[$field] === '') {
                throw new ValidationException('broker.error.credentials_required', $field);
            }
        }
        if (!empty($body['environment'])) {
            $credentials['environment'] = strtoupper((string) $body['environment']);
        } elseif (!empty($existing['environment'])) {
            $credentials['environment'] = $existing['environment'];
        }

        $connector = $this->connectors->get(BrokerProvider::CTRADER->value);
        if (!method_exists($connector, 'fetchAccounts')) {
            throw new ValidationException('broker.error.account_discovery_failed', 'provider');
        }

        try {
            return ['accounts' => $connector->fetchAccounts($credentials), 'error' => null];
        } catch (\Throwable $e) {
            return ['accounts' => [], 'error' => $this->sanitizeTestError($e->getMessage())];
        }
    }

    public function deleteConnection(int $connectionId): void
    {
        $this->connectionRepo->delete($connectionId);
    }

    public function requireOwnedConnection(int $connectionId, int $userId): array
    {
        $connection = $this->connectionRepo->findById($connectionId);
        if (!$connection) {
            throw new ValidationException('broker.error.connection_not_found', 'id');
        }
        if ((int) $connection['user_id'] !== $userId) {
            throw new ForbiddenException('broker.error.forbidden');
        }
        return $connection;
    }

    /**
     * Run the provider's own credential check. Never throws: the result is
     * advisory (the caller has already persisted the credentials), so a broker
     * outage reports a failed test instead of blocking the save.
     *
     * @return array{success: bool, error: ?string}
     */
    public function testCredentials(string $provider, array $credentials): array
    {
        try {
            $connector = $this->connectors->get($provider);
            if ($connector->testConnection($credentials)) {
                return ['success' => true, 'error' => null];
            }
            return ['success' => false, 'error' => $this->sanitizeTestError($connector->getLastTestError())];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $this->sanitizeTestError($e->getMessage())];
        }
    }

    /** Max length of a broker error handed back to the client. */
    private const MAX_TEST_ERROR_LENGTH = 300;

    /**
     * Make a raw connector error safe to return to the client.
     *
     * The broker's own wording is the whole point ("wrong clientSecret" names
     * the field to fix), but the raw message is not always just that: BingX
     * signs in the query string, and a Guzzle transport error embeds the full
     * request URI — so the message can carry an HMAC signature and the internal
     * endpoint. Redact the credential-bearing parameters and any long hex run,
     * then cap the length. A short broker message passes through untouched.
     */
    private function sanitizeTestError(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        // Query/JSON parameters that carry a credential or a signature.
        $message = preg_replace(
            '/\b(signature|api[_-]?key|apikey|secret|access[_-]?token|token|password)\b(\s*[=:]\s*"?)[^\s&"\',]+/i',
            '$1$2[redacted]',
            $message,
        );

        // Any remaining long hex run is an HMAC/token by construction.
        $message = preg_replace('/\b[0-9a-f]{32,}\b/i', '[redacted]', $message);

        if (mb_strlen($message) > self::MAX_TEST_ERROR_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_TEST_ERROR_LENGTH) . '...';
        }

        return $message;
    }

    /**
     * Strip the ciphertext and attach the safe credential view.
     */
    private function present(array $connection, array $credentials): array
    {
        unset($connection['credentials_encrypted'], $connection['credentials_iv']);

        // last_sync_error is a raw connector message too — same redaction as
        // the save-time test result. The full text stays in the DB and in
        // sync_logs for debugging; only what crosses to the client is scrubbed.
        if (!empty($connection['last_sync_error'])) {
            $connection['last_sync_error'] = $this->sanitizeTestError($connection['last_sync_error']);
        }

        return $connection + $this->mapper->publicView($connection['provider'], $credentials);
    }

    /**
     * Decrypt a connection's credentials, tolerating failure.
     *
     * A row encrypted under a rotated BROKER_ENCRYPTION_KEY must stay listable
     * — and therefore reconfigurable or deletable — rather than fataling the
     * whole accounts screen. An empty array simply means "nothing to prefill,
     * every field must be retyped", which merge() then enforces.
     */
    private function decryptOrEmpty(array $connection): array
    {
        try {
            return $this->crypto->decrypt(
                $connection['credentials_encrypted'],
                $connection['credentials_iv'],
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
