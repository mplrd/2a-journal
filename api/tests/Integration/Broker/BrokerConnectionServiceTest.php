<?php

namespace Tests\Integration\Broker;

use App\Core\Database;
use App\Enums\ConnectionStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\BrokerConnectionRepository;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Broker\BrokerCredentialMapper;
use App\Services\Broker\ConnectorRegistry;
use App\Services\Broker\CredentialEncryptionService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Reconfiguring a broker connection in place (docs/85-broker-connection-reconfigure.md).
 *
 * Before this, the API only had create/delete: a connection whose credentials
 * went stale (rotated cTrader clientSecret → CH_CLIENT_AUTH_FAILURE) flipped to
 * status ERROR, and sync() refuses anything but ACTIVE — so the only way out
 * was deleting the connection, losing its sync cursor and its whole log
 * history. updateCredentials() replaces the credentials on the existing row and
 * un-sticks the status, keeping the cursor and the id.
 */
class BrokerConnectionServiceTest extends TestCase
{
    private PDO $pdo;
    private BrokerConnectionRepository $repo;
    private CredentialEncryptionService $crypto;
    private BrokerConnectionService $service;
    private int $userId;
    private int $otherUserId;
    private int $accountId;

    protected function setUp(): void
    {
        $this->loadEnv();
        Database::reset();
        $this->pdo = Database::getConnection();
        $this->wipeTables();

        $this->repo = new BrokerConnectionRepository($this->pdo);
        $this->crypto = new CredentialEncryptionService(str_repeat('k', 32));
        $this->service = new BrokerConnectionService(
            $this->repo,
            $this->crypto,
            new BrokerCredentialMapper(),
            new ConnectorRegistry(
                new FakeConnector(),
                new FakeConnector(),
                new FakeConnector(),
                new FakeConnector(),
            ),
        );

        $this->userId = $this->seedUser('broker-owner@test.com');
        $this->otherUserId = $this->seedUser('broker-intruder@test.com');
        $this->accountId = $this->seedAccount($this->userId);
    }

    protected function tearDown(): void
    {
        $this->wipeTables();
    }

    // ── Reconfigure ─────────────────────────────────────────────────

    public function testUpdateReplacesOnlyTheSuppliedCredential(): void
    {
        $id = $this->seedConnection('CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'stale-secret',
            'access_token' => 'tok',
            'ctid_trader_account_id' => 12345678,
            'environment' => 'LIVE',
        ]);

        $this->service->updateCredentials($id, $this->userId, ['client_secret' => 'fresh-secret']);

        $stored = $this->decryptStored($id);
        $this->assertSame('fresh-secret', $stored['client_secret']);
        $this->assertSame('tok', $stored['access_token']);
        $this->assertSame('30528', $stored['client_id']);
        $this->assertSame(12345678, $stored['ctid_trader_account_id']);
    }

    public function testUpdatePreservesSyncCursorAndConnectionId(): void
    {
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());
        $this->repo->update($id, ['sync_cursor' => '2026-07-20 10:00:00']);

        $result = $this->service->updateCredentials($id, $this->userId, ['client_secret' => 'new']);

        $row = $this->repo->findById($id);
        // The whole point: no delete/recreate, so history is not re-imported.
        $this->assertSame($id, (int) $result['connection']['id']);
        $this->assertSame('2026-07-20 10:00:00', $row['sync_cursor']);
    }

    public function testUpdateUnsticksAnErroredConnection(): void
    {
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());
        $this->repo->markError($id, 'cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret');
        $this->repo->incrementFailures($id);
        $this->repo->incrementFailures($id);

        $this->service->updateCredentials($id, $this->userId, ['client_secret' => 'new']);

        $row = $this->repo->findById($id);
        $this->assertSame(ConnectionStatus::ACTIVE->value, $row['status']);
        $this->assertNull($row['last_sync_error']);
        $this->assertSame(0, (int) $row['consecutive_failures']);
    }

    public function testUpdatePreservesSyncLogHistory(): void
    {
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());
        $this->pdo->prepare(
            "INSERT INTO sync_logs (broker_connection_id, user_id, status) VALUES (:c, :u, 'FAILED')"
        )->execute(['c' => $id, 'u' => $this->userId]);

        $this->service->updateCredentials($id, $this->userId, ['client_secret' => 'new']);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM sync_logs WHERE broker_connection_id = :c");
        $count->execute(['c' => $id]);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    public function testUpdateCanSwitchCtraderEnvironmentToDemo(): void
    {
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $this->service->updateCredentials($id, $this->userId, ['environment' => 'DEMO']);

        $this->assertSame('DEMO', $this->decryptStored($id)['environment']);
    }

    public function testUpdateRejectsAnotherUsersConnection(): void
    {
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $this->expectException(ForbiddenException::class);
        $this->service->updateCredentials($id, $this->otherUserId, ['client_secret' => 'new']);
    }

    public function testUpdateRejectsUnknownConnection(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->updateCredentials(999999, $this->userId, ['client_secret' => 'new']);
    }

    public function testUpdateRejectsBlankSubmission(): void
    {
        // Submitting the dialog without touching anything must not be a silent
        // no-op that resets the status — it is a user error.
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $this->expectException(ValidationException::class);
        $this->service->updateCredentials($id, $this->userId, []);
    }

    // ── Connection test at save (non-blocking) ──────────────────────

    public function testUpdateReportsASuccessfulCredentialTest(): void
    {
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $result = $this->service->updateCredentials($id, $this->userId, ['client_secret' => 'new']);

        $this->assertTrue($result['connection_test']['success']);
        $this->assertNull($result['connection_test']['error']);
    }

    public function testUpdateStillSavesWhenTheCredentialTestFails(): void
    {
        $failing = new FakeConnector(false, 'cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret');
        $service = new BrokerConnectionService(
            $this->repo,
            $this->crypto,
            new BrokerCredentialMapper(),
            new ConnectorRegistry($failing, $failing, $failing, $failing),
        );
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $result = $service->updateCredentials($id, $this->userId, ['client_secret' => 'still-wrong']);

        // Non-blocking: the credentials are persisted even though the test
        // failed, so a broker outage never locks the user out of saving.
        $this->assertSame('still-wrong', $this->decryptStored($id)['client_secret']);
        $this->assertFalse($result['connection_test']['success']);
        $this->assertStringContainsString('CH_CLIENT_AUTH_FAILURE', $result['connection_test']['error']);
    }

    public function testUpdateRedactsSignaturesAndTokensFromTheReportedError(): void
    {
        // BingX signs in the query string, and a Guzzle transport error carries
        // the full request URI in its message — so the raw connector error can
        // contain an HMAC signature and the internal endpoint. Keep the part
        // that helps the user, drop the part that should never reach a client.
        $leaky = new FakeConnector(
            false,
            'BingX HTTP error: GET https://open-api.bingx.com/openApi/swap/v3/user/balance'
                . '?recvWindow=5000&signature=a3f1c9d84b2e6071f5a8c3b9d2e4f60718293a4b5c6d7e8f9012345678abcdef'
                . '&timestamp=1753900000000 resulted in a 401 response',
        );
        $service = new BrokerConnectionService(
            $this->repo,
            $this->crypto,
            new BrokerCredentialMapper(),
            new ConnectorRegistry($leaky, $leaky, $leaky, $leaky),
        );
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $error = $service->updateCredentials($id, $this->userId, ['client_secret' => 'x'])['connection_test']['error'];

        $this->assertStringNotContainsString('a3f1c9d84b2e6071', $error);
        $this->assertStringContainsString('[redacted]', $error);
        // The actionable part survives.
        $this->assertStringContainsString('401', $error);
    }

    public function testUpdateRedactsUnderscoredCredentialParameters(): void
    {
        // cTrader refreshes its access token with a GET whose query string
        // carries client_secret and refresh_token, so a transport error hands
        // both to the client verbatim. \bsecret\b and \btoken\b do not match
        // inside "client_secret"/"refresh_token" — the underscore is a word
        // character, so there is no boundary there — and neither value is long
        // hex, so the fallback rule misses them too.
        $leaky = new FakeConnector(
            false,
            'cTrader HTTP error: GET https://openapi.ctrader.com/apps/token'
                . '?grant_type=refresh_token&refresh_token=rt-SHOULD-NOT-LEAK'
                . '&client_id=30528&client_secret=cs-SHOULD-NOT-LEAK resulted in a 400 response',
        );
        $service = new BrokerConnectionService(
            $this->repo,
            $this->crypto,
            new BrokerCredentialMapper(),
            new ConnectorRegistry($leaky, $leaky, $leaky, $leaky),
        );
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $error = $service->updateCredentials($id, $this->userId, ['client_secret' => 'x'])['connection_test']['error'];

        $this->assertStringNotContainsString('rt-SHOULD-NOT-LEAK', $error);
        $this->assertStringNotContainsString('cs-SHOULD-NOT-LEAK', $error);
        // Still useful: the status code and the endpoint that failed remain.
        $this->assertStringContainsString('400', $error);
    }

    public function testUpdateKeepsAShortBrokerMessageIntact(): void
    {
        $failing = new FakeConnector(false, 'cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret');
        $service = new BrokerConnectionService(
            $this->repo,
            $this->crypto,
            new BrokerCredentialMapper(),
            new ConnectorRegistry($failing, $failing, $failing, $failing),
        );
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $error = $service->updateCredentials($id, $this->userId, ['client_secret' => 'x'])['connection_test']['error'];

        $this->assertSame('cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret', $error);
    }

    public function testUpdateTruncatesAnOverlongConnectorError(): void
    {
        $noisy = new FakeConnector(false, str_repeat('z', 900));
        $service = new BrokerConnectionService(
            $this->repo,
            $this->crypto,
            new BrokerCredentialMapper(),
            new ConnectorRegistry($noisy, $noisy, $noisy, $noisy),
        );
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $error = $service->updateCredentials($id, $this->userId, ['client_secret' => 'x'])['connection_test']['error'];

        $this->assertLessThanOrEqual(303, strlen($error));
    }

    public function testUpdateSurvivesAConnectorThatThrows(): void
    {
        $throwing = new FakeConnector(false, null, true);
        $service = new BrokerConnectionService(
            $this->repo,
            $this->crypto,
            new BrokerCredentialMapper(),
            new ConnectorRegistry($throwing, $throwing, $throwing, $throwing),
        );
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $result = $service->updateCredentials($id, $this->userId, ['client_secret' => 'new']);

        $this->assertFalse($result['connection_test']['success']);
        $this->assertStringContainsString('boom', $result['connection_test']['error']);
    }

    // ── Create (same pipeline) ──────────────────────────────────────

    public function testCreateStoresEncryptedCredentialsAndReportsTheTest(): void
    {
        $result = $this->service->createConnection($this->userId, $this->accountId, 'BINGX', [
            'api_key' => 'k',
            'api_secret' => 's',
        ]);

        $id = (int) $result['connection']['id'];
        $this->assertSame(['api_key' => 'k', 'api_secret' => 's'], $this->decryptStored($id));
        $this->assertTrue($result['connection_test']['success']);

        // Ciphertext must not contain the plaintext secret.
        $row = $this->repo->findById($id);
        $this->assertStringNotContainsString('api_secret', base64_decode($row['credentials_encrypted']));
    }

    public function testCreateRefusesASecondConnectionOnTheSameAccount(): void
    {
        $this->service->createConnection($this->userId, $this->accountId, 'BINGX', [
            'api_key' => 'k',
            'api_secret' => 's',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createConnection($this->userId, $this->accountId, 'BINGX', [
            'api_key' => 'k2',
            'api_secret' => 's2',
        ]);
    }

    // ── One broker account, one connection ──────────────────────────

    /** @return array<string, string|int> */
    private function ctraderBody(int $ctidTraderAccountId): array
    {
        return [
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 't',
            'account_id_ctrader' => $ctidTraderAccountId,
        ];
    }

    public function testCreateRefusesASecondConnectionOnTheSameBrokerAccount(): void
    {
        // Two journal accounts, one cTrader account behind both. Nothing stopped
        // this, and it doubles the request volume against a broker that disables
        // a trading account for going over — invisibly, since each connection
        // looks perfectly healthy on its own.
        $secondAccount = $this->seedAccount($this->userId);
        $this->service->createConnection($this->userId, $this->accountId, 'CTRADER', $this->ctraderBody(7589848));

        $this->expectException(ValidationException::class);
        $this->service->createConnection($this->userId, $secondAccount, 'CTRADER', $this->ctraderBody(7589848));
    }

    public function testCreateAllowsASecondConnectionOnADifferentBrokerAccount(): void
    {
        $secondAccount = $this->seedAccount($this->userId);
        $this->service->createConnection($this->userId, $this->accountId, 'CTRADER', $this->ctraderBody(7589848));

        $result = $this->service->createConnection($this->userId, $secondAccount, 'CTRADER', $this->ctraderBody(7589849));

        $this->assertNotEmpty($result['connection']['id']);
    }

    public function testCreateIgnoresARevokedConnectionOnTheSameBrokerAccount(): void
    {
        // A revoked connection never syncs, so it spends nothing. Counting it
        // would strand the user: they could not reconnect the account they
        // just disconnected.
        $secondAccount = $this->seedAccount($this->userId);
        $first = $this->service->createConnection($this->userId, $this->accountId, 'CTRADER', $this->ctraderBody(7589848));
        $this->repo->update((int) $first['connection']['id'], ['status' => ConnectionStatus::REVOKED->value]);

        $result = $this->service->createConnection($this->userId, $secondAccount, 'CTRADER', $this->ctraderBody(7589848));

        $this->assertNotEmpty($result['connection']['id']);
    }

    public function testUpdateRefusesPointingAtAnAlreadyConnectedBrokerAccount(): void
    {
        $secondAccount = $this->seedAccount($this->userId);
        $this->service->createConnection($this->userId, $this->accountId, 'CTRADER', $this->ctraderBody(7589848));
        $second = $this->service->createConnection($this->userId, $secondAccount, 'CTRADER', $this->ctraderBody(7589849));

        $this->expectException(ValidationException::class);
        $this->service->updateCredentials((int) $second['connection']['id'], $this->userId, [
            'account_id_ctrader' => 7589848,
        ]);
    }

    public function testUpdateLetsAConnectionKeepItsOwnBrokerAccount(): void
    {
        // The obvious way to get this wrong: a reconfigure that changes only
        // the access token would find "another" connection on that broker
        // account — itself — and reject every reconfigure there is.
        $created = $this->service->createConnection($this->userId, $this->accountId, 'CTRADER', $this->ctraderBody(7589848));

        $result = $this->service->updateCredentials((int) $created['connection']['id'], $this->userId, [
            'access_token' => 'refreshed',
        ]);

        $this->assertSame('refreshed', $this->decryptStored((int) $result['connection']['id'])['access_token']);
    }

    // ── cTrader account discovery ───────────────────────────────────

    public function testDiscoverCtraderAccountsWithTypedCredentials(): void
    {
        $result = $this->service->discoverCtraderAccounts($this->userId, [
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'tok',
        ]);

        $this->assertSame([
            ['ctid_trader_account_id' => 42111, 'trader_login' => '1234567', 'is_live' => true],
        ], $result['accounts']);
        $this->assertNull($result['error']);
    }

    public function testDiscoverCtraderAccountsReusesStoredSecretsWhenReconfiguring(): void
    {
        // While reconfiguring, the user should be able to list their accounts
        // without retyping the secret — it is already stored and never echoed.
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $result = $this->service->discoverCtraderAccounts($this->userId, ['connection_id' => $id]);

        $this->assertNotEmpty($result['accounts']);
    }

    public function testDiscoverCtraderAccountsRequiresCredentialsWithoutAConnection(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->discoverCtraderAccounts($this->userId, ['client_id' => 'a']);
    }

    public function testDiscoverCtraderAccountsRejectsAnotherUsersConnection(): void
    {
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $this->expectException(ForbiddenException::class);
        $this->service->discoverCtraderAccounts($this->otherUserId, ['connection_id' => $id]);
    }

    public function testDiscoverCtraderAccountsReportsTheBrokerReasonWithoutThrowing(): void
    {
        // The reason is the actionable part — "wrong clientSecret" tells the
        // user which field to fix. Swallowing it would defeat the button.
        $failing = new FakeConnector(false, null, true);
        $service = new BrokerConnectionService(
            $this->repo,
            $this->crypto,
            new BrokerCredentialMapper(),
            new ConnectorRegistry($failing, $failing, $failing, $failing),
        );

        $result = $service->discoverCtraderAccounts($this->userId, [
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'tok',
        ]);

        $this->assertSame([], $result['accounts']);
        $this->assertStringContainsString('boom', $result['error']);
    }

    // ── Public view on read ─────────────────────────────────────────

    public function testFindForAccountExposesIdentifiersButNoSecrets(): void
    {
        $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $view = $this->service->findForAccount($this->accountId, $this->userId);

        $this->assertSame('30528', $view['credentials_public']['client_id']);
        $this->assertSame('LIVE', $view['credentials_public']['environment']);
        $this->assertTrue($view['credentials_set']['client_secret']);
        $this->assertArrayNotHasKey('credentials_encrypted', $view);
        $this->assertArrayNotHasKey('credentials_iv', $view);
        $this->assertStringNotContainsString('stale-secret', json_encode($view));
    }

    public function testFindForAccountRedactsSignaturesInTheStoredSyncError(): void
    {
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());
        $this->repo->markError(
            $id,
            'BingX HTTP error: GET https://open-api.bingx.com/openApi/swap/v3/user/balance'
                . '?signature=a3f1c9d84b2e6071f5a8c3b9d2e4f60718293a4b5c6d7e8f9012345678abcdef resulted in a 401',
        );

        $view = $this->service->findForAccount($this->accountId, $this->userId);

        $this->assertStringNotContainsString('a3f1c9d84b2e6071', $view['last_sync_error']);
        $this->assertStringContainsString('[redacted]', $view['last_sync_error']);
    }

    public function testFindForAccountReturnsNullForAnotherUser(): void
    {
        $this->seedConnection('CTRADER', $this->ctraderCredentials());

        $this->assertNull($this->service->findForAccount($this->accountId, $this->otherUserId));
    }

    public function testFindForAccountToleratesUndecryptableCredentials(): void
    {
        // A connection encrypted under a rotated BROKER_ENCRYPTION_KEY must
        // still be listable (and therefore reconfigurable/deletable) instead of
        // fataling the whole accounts screen.
        $id = $this->seedConnection('CTRADER', $this->ctraderCredentials());
        $this->pdo->prepare("UPDATE broker_connections SET credentials_encrypted = 'not-base64-cipher' WHERE id = :id")
            ->execute(['id' => $id]);

        $view = $this->service->findForAccount($this->accountId, $this->userId);

        $this->assertNotNull($view);
        $this->assertSame([], $view['credentials_public']);
        // Every secret reads as unset, which is exactly what the dialog needs
        // to know: nothing can be prefilled, retype everything.
        $this->assertSame([
            'client_secret' => false,
            'access_token' => false,
            'refresh_token' => false,
        ], $view['credentials_set']);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function ctraderCredentials(): array
    {
        return [
            'client_id' => '30528',
            'client_secret' => 'stale-secret',
            'access_token' => 'tok',
            'ctid_trader_account_id' => 12345678,
            'environment' => 'LIVE',
        ];
    }

    private function seedConnection(string $provider, array $credentials): int
    {
        $encrypted = $this->crypto->encrypt($credentials);
        $row = $this->repo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'provider' => $provider,
            'status' => ConnectionStatus::ACTIVE->value,
            'credentials_encrypted' => $encrypted['ciphertext'],
            'credentials_iv' => $encrypted['iv'],
        ]);
        return (int) $row['id'];
    }

    private function decryptStored(int $id): array
    {
        $row = $this->repo->findById($id);
        return $this->crypto->decrypt($row['credentials_encrypted'], $row['credentials_iv']);
    }

    private function seedUser(string $email): int
    {
        $this->pdo->prepare("INSERT INTO users (email, password, locale) VALUES (:email, :pw, 'fr')")
            ->execute(['email' => $email, 'pw' => password_hash('Test1234!', PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedAccount(int $userId): int
    {
        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, currency)
             VALUES (:u, 'Broker Account', 'BROKER_DEMO', 10000, 'USD')"
        )->execute(['u' => $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function wipeTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['sync_logs', 'broker_connections', 'accounts', 'users'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function loadEnv(): void
    {
        $envFile = __DIR__ . '/../../../.env';
        if (!file_exists($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (($eq = strpos($line, '=')) === false) continue;
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
                $value = substr($value, 1, -1);
            }
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

/**
 * Minimal connector double: only testConnection()/getLastTestError() matter for
 * the save-time credential check.
 */
class FakeConnector implements \App\Services\Broker\ConnectorInterface
{
    public function __construct(
        private bool $testResult = true,
        private ?string $lastError = null,
        private bool $throws = false,
    ) {}

    public function testConnection(array $credentials): bool
    {
        if ($this->throws) {
            throw new \RuntimeException('boom');
        }
        return $this->testResult;
    }

    /** cTrader-only, resolved via method_exists — not part of ConnectorInterface. */
    public function fetchAccounts(array $credentials): array
    {
        if ($this->throws) {
            throw new \RuntimeException('boom');
        }
        return [['ctid_trader_account_id' => 42111, 'trader_login' => '1234567', 'is_live' => true]];
    }

    public function getLastTestError(): ?string
    {
        return $this->lastError;
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        return ['deals' => [], 'cursor' => '', 'raw_count' => 0];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        return ['positions' => [], 'raw_count' => 0];
    }

    public function fetchOpenOrders(array $credentials): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function refreshCredentials(array $credentials): array
    {
        return $credentials;
    }

    public function fetchBalance(array $credentials): ?float
    {
        return null;
    }

    public function placeOrder(array $credentials, array $order): array
    {
        return ['external_order_id' => '1', 'status' => null, 'raw' => []];
    }

    public function cancelOrder(array $credentials, string $externalOrderId): array
    {
        return ['status' => null, 'raw' => []];
    }

    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array
    {
        return ['status' => null, 'raw' => []];
    }

    public function modifyOrder(array $credentials, array $modification): array
    {
        return ['status' => null, 'raw' => []];
    }
}
