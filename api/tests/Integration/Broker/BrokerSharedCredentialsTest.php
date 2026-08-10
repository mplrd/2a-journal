<?php

namespace Tests\Integration\Broker;

use App\Core\Database;
use App\Exceptions\ValidationException;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\BrokerCredentialRepository;
use App\Services\Broker\BrokerConnectionService;
use App\Services\Broker\BrokerCredentialMapper;
use App\Services\Broker\BrokerCredentialStore;
use App\Services\Broker\ConnectorRegistry;
use App\Services\Broker\CredentialEncryptionService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * App credentials shared across a provider's connections
 * (docs/91-broker-shared-credentials.md).
 *
 * A user with two cTrader accounts used to type client_id, client_secret,
 * access_token and refresh_token twice, when only the ctidTraderAccountId
 * differs. Beyond the double entry it meant replaying every secret rotation by
 * hand, and one OAuth refresh per connection where one would do — straight onto
 * evolution #22's request budget.
 *
 * The split is declared per provider in BrokerCredentialMapper::SPEC, not
 * special-cased for cTrader: what carries `shared` lives on the user
 * (broker_credentials, one row per provider), the rest stays on the connection.
 * Ouinex and BingX declare nothing and must come out of this behaving exactly as
 * they did — that is the test of whether the abstraction holds.
 */
class BrokerSharedCredentialsTest extends TestCase
{
    private PDO $pdo;
    private BrokerConnectionRepository $connectionRepo;
    private BrokerCredentialRepository $credentialRepo;
    private CredentialEncryptionService $crypto;
    private BrokerCredentialStore $store;
    private BrokerConnectionService $service;
    private int $userId;
    private int $otherUserId;
    private int $accountId;
    private int $secondAccountId;

    protected function setUp(): void
    {
        $this->loadEnv();
        Database::reset();
        $this->pdo = Database::getConnection();
        $this->wipeTables();

        $this->connectionRepo = new BrokerConnectionRepository($this->pdo);
        $this->credentialRepo = new BrokerCredentialRepository($this->pdo);
        $this->crypto = new CredentialEncryptionService(str_repeat('k', 32));
        $this->store = new BrokerCredentialStore(
            $this->credentialRepo,
            $this->crypto,
            new BrokerCredentialMapper(),
        );
        $this->service = new BrokerConnectionService(
            $this->connectionRepo,
            $this->store,
            new BrokerCredentialMapper(),
            new ConnectorRegistry(
                new FakeConnector(),
                new FakeConnector(),
                new FakeConnector(),
                new FakeConnector(),
            ),
        );

        $this->userId = $this->seedUser('shared-owner@test.com');
        $this->otherUserId = $this->seedUser('shared-other@test.com');
        $this->accountId = $this->seedAccount($this->userId);
        $this->secondAccountId = $this->seedAccount($this->userId);
    }

    protected function tearDown(): void
    {
        $this->wipeTables();
    }

    // ── The point of the feature: no second entry ───────────────────

    public function testASecondCtraderConnectionNeedsOnlyItsAccountId(): void
    {
        $this->createCtrader($this->accountId, 7589848);

        // Everything the dialog would leave blank on the second connection.
        $second = $this->service->createConnection($this->userId, $this->secondAccountId, 'CTRADER', [
            'account_id_ctrader' => 7589849,
        ]);

        $credentials = $this->store->forConnection(
            $this->connectionRepo->findById((int) $second['connection']['id']),
        );
        $this->assertSame('sEcReT', $credentials['client_secret']);
        $this->assertSame('tok', $credentials['access_token']);
        $this->assertSame('refresh', $credentials['refresh_token']);
        // ...and its own account, not the first one's.
        $this->assertSame(7589849, $credentials['ctid_trader_account_id']);
    }

    public function testTheFirstConnectionStillRequiresEveryCredential(): void
    {
        // Nothing is stored yet, so there is nothing to fall back on: the
        // create-time validation must be exactly what it always was.
        $this->expectException(ValidationException::class);
        $this->service->createConnection($this->userId, $this->accountId, 'CTRADER', [
            'account_id_ctrader' => 7589848,
        ]);
    }

    public function testAppCredentialsAreStoredOnceForTheUser(): void
    {
        $this->createCtrader($this->accountId, 7589848);
        $this->createCtrader($this->secondAccountId, 7589849);

        $count = $this->pdo->prepare(
            "SELECT COUNT(*) FROM broker_credentials WHERE user_id = :u AND provider = 'CTRADER'"
        );
        $count->execute(['u' => $this->userId]);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    public function testTheSharedRowHoldsTheAppCredentialsAndNotTheAccount(): void
    {
        $this->createCtrader($this->accountId, 7589848);

        $shared = $this->store->sharedFor($this->userId, 'CTRADER');

        $this->assertSame(
            ['client_id', 'client_secret', 'access_token', 'refresh_token'],
            array_keys($shared),
        );
    }

    public function testTheConnectionRowKeepsOnlyWhatIdentifiesTheAccount(): void
    {
        // The secret must live in one place. Leaving a copy on the connection
        // would mean a rotation silently half-applied.
        $id = $this->createCtrader($this->accountId, 7589848);

        $own = $this->crypto->decrypt(...$this->connectionBlob($id));

        $this->assertSame(['ctid_trader_account_id', 'environment'], array_keys($own));
    }

    // ── One rotation, every connection ──────────────────────────────

    public function testRotatingASecretFromOneConnectionReachesTheOther(): void
    {
        // The whole reason sharing is worth the migration: a rotated cTrader
        // clientSecret used to have to be replayed on each connection, and any
        // one missed flips to ERROR on its next sync.
        $first = $this->createCtrader($this->accountId, 7589848);
        $second = $this->createCtrader($this->secondAccountId, 7589849);

        $this->service->updateCredentials($second, $this->userId, ['client_secret' => 'rotated']);

        $onFirst = $this->store->forConnection($this->connectionRepo->findById($first));
        $this->assertSame('rotated', $onFirst['client_secret']);
    }

    public function testRotatingASecretLeavesEachConnectionItsOwnAccount(): void
    {
        $first = $this->createCtrader($this->accountId, 7589848);
        $second = $this->createCtrader($this->secondAccountId, 7589849);

        $this->service->updateCredentials($second, $this->userId, ['client_secret' => 'rotated']);

        $this->assertSame(
            7589848,
            $this->store->forConnection($this->connectionRepo->findById($first))['ctid_trader_account_id'],
        );
        $this->assertSame(
            7589849,
            $this->store->forConnection($this->connectionRepo->findById($second))['ctid_trader_account_id'],
        );
    }

    public function testAnotherUsersCredentialsAreNeverReached(): void
    {
        $this->createCtrader($this->accountId, 7589848);
        $otherAccount = $this->seedAccount($this->otherUserId);

        // The second user has typed nothing, so there is nothing to fall back
        // on — sharing is scoped to one user, never to a provider globally.
        $this->expectException(ValidationException::class);
        $this->service->createConnection($this->otherUserId, $otherAccount, 'CTRADER', [
            'account_id_ctrader' => 7589850,
        ]);
    }

    public function testSharedCredentialsAreEncryptedAtRest(): void
    {
        $this->createCtrader($this->accountId, 7589848);

        $row = $this->pdo->query(
            "SELECT credentials_encrypted FROM broker_credentials LIMIT 1"
        )->fetchColumn();

        $this->assertStringNotContainsString('sEcReT', base64_decode((string) $row));
        $this->assertStringNotContainsString('client_secret', base64_decode((string) $row));
    }

    // ── Providers that share nothing must not change ────────────────

    public function testBingxKeepsEveryCredentialOnItsOwnConnection(): void
    {
        $result = $this->service->createConnection($this->userId, $this->accountId, 'BINGX', [
            'api_key' => 'k',
            'api_secret' => 's',
        ]);
        $id = (int) $result['connection']['id'];

        $this->assertSame(
            ['api_key' => 'k', 'api_secret' => 's'],
            $this->crypto->decrypt(...$this->connectionBlob($id)),
        );

        $count = $this->pdo->query("SELECT COUNT(*) FROM broker_credentials")->fetchColumn();
        $this->assertSame(0, (int) $count);
    }

    public function testASecondBingxConnectionStillRequiresItsOwnKey(): void
    {
        // Their API key IS the account: there is nothing to inherit, and
        // silently reusing the first key would connect the wrong account.
        $this->service->createConnection($this->userId, $this->accountId, 'BINGX', [
            'api_key' => 'k',
            'api_secret' => 's',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createConnection($this->userId, $this->secondAccountId, 'BINGX', []);
    }

    // ── Saying it out loud: the sharing banner ──────────────────────

    public function testTheConnectionViewNamesHowManyConnectionsShareTheCredentials(): void
    {
        // Without this the user editing a token from their second connection
        // would silently rewrite the first — which is the whole trap of
        // sharing, and the reason the dialog must say so.
        $this->createCtrader($this->accountId, 7589848);
        $this->createCtrader($this->secondAccountId, 7589849);

        $view = $this->service->findForAccount($this->secondAccountId, $this->userId);

        $this->assertSame(2, $view['credentials_shared_count']);
        $this->assertSame(
            ['client_id', 'client_secret', 'access_token', 'refresh_token'],
            $view['credentials_shared_fields'],
        );
    }

    public function testALoneConnectionReportsSharingWithItselfOnly(): void
    {
        $this->createCtrader($this->accountId, 7589848);

        $view = $this->service->findForAccount($this->accountId, $this->userId);

        $this->assertSame(1, $view['credentials_shared_count']);
    }

    public function testABingxConnectionReportsNoSharingAtAll(): void
    {
        $this->service->createConnection($this->userId, $this->accountId, 'BINGX', [
            'api_key' => 'k',
            'api_secret' => 's',
        ]);

        $view = $this->service->findForAccount($this->accountId, $this->userId);

        $this->assertSame([], $view['credentials_shared_fields']);
        $this->assertSame(0, $view['credentials_shared_count']);
    }

    public function testTheViewPrefillsIdentifiersHeldOnlyByTheSharedRow(): void
    {
        // client_id is not a secret and lives on the shared row now. The
        // reconfigure dialog still has to prefill it.
        $this->createCtrader($this->accountId, 7589848);
        $this->createCtrader($this->secondAccountId, 7589849);

        $view = $this->service->findForAccount($this->secondAccountId, $this->userId);

        $this->assertSame('30528', $view['credentials_public']['client_id']);
        $this->assertTrue($view['credentials_set']['client_secret']);
        $this->assertStringNotContainsString('sEcReT', json_encode($view));
    }

    // ── What the create dialog reads before anything exists ─────────

    public function testSharedCredentialsForUserDescribesWhatIsAlreadyStored(): void
    {
        // Feeds the create dialog: on a second connection the app credentials
        // arrive prefilled and folded away, leaving only the account to pick.
        $this->createCtrader($this->accountId, 7589848);

        $shared = $this->service->sharedCredentialsForUser($this->userId);

        $this->assertSame('30528', $shared['CTRADER']['credentials_public']['client_id']);
        $this->assertTrue($shared['CTRADER']['credentials_set']['client_secret']);
        $this->assertSame(1, $shared['CTRADER']['credentials_shared_count']);
        $this->assertStringNotContainsString('sEcReT', json_encode($shared));
    }

    public function testSharedCredentialsForUserIsEmptyWithoutAnyConnection(): void
    {
        $this->assertSame([], $this->service->sharedCredentialsForUser($this->userId));
    }

    public function testSharedCredentialsForUserIgnoresProvidersThatShareNothing(): void
    {
        $this->service->createConnection($this->userId, $this->accountId, 'BINGX', [
            'api_key' => 'k',
            'api_secret' => 's',
        ]);

        $this->assertSame([], $this->service->sharedCredentialsForUser($this->userId));
    }

    // ── Typing credentials is not renewing them ─────────────────────

    public function testTypingCredentialsDoesNotCountAsARenewal(): void
    {
        // The distinction the first version of the guard got wrong. Writing the
        // shared row and renewing the token are opposite situations: a token
        // the user just pasted may be months old, and the sync that follows is
        // the one that most needs a refresh.
        $this->createCtrader($this->accountId, 7589848);

        $this->assertFalse($this->store->sharedRenewedWithin($this->userId, 'CTRADER', 300));
    }

    public function testReconfiguringDoesNotCountAsARenewalEither(): void
    {
        $id = $this->createCtrader($this->accountId, 7589848);

        $this->service->updateCredentials($id, $this->userId, ['client_secret' => 'rotated']);

        $this->assertFalse($this->store->sharedRenewedWithin($this->userId, 'CTRADER', 300));
    }

    public function testARenewalIsWhatOpensTheSkipWindow(): void
    {
        $this->createCtrader($this->accountId, 7589848);

        // What BrokerSyncService does after a successful token refresh.
        $this->store->store($this->userId, 'CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'renewed',
            'refresh_token' => 'rotated',
            'ctid_trader_account_id' => 7589848,
        ], fromRefresh: true);

        $this->assertTrue($this->store->sharedRenewedWithin($this->userId, 'CTRADER', 300));
        // ...and the renewed token is what the connection now resolves to.
        $this->assertSame(
            'renewed',
            $this->store->sharedFor($this->userId, 'CTRADER')['access_token'],
        );
    }

    public function testAProviderWithoutSharedCredentialsNeverSkips(): void
    {
        $this->service->createConnection($this->userId, $this->accountId, 'BINGX', [
            'api_key' => 'k',
            'api_secret' => 's',
        ]);

        $this->assertFalse($this->store->sharedRenewedWithin($this->userId, 'BINGX', 300));
    }

    // ── Disconnecting must actually revoke ──────────────────────────

    public function testDisconnectingOneOfTwoConnectionsKeepsTheSharedCredentials(): void
    {
        $first = $this->createCtrader($this->accountId, 7589848);
        $this->createCtrader($this->secondAccountId, 7589849);

        $this->service->deleteConnection($first);

        $this->assertNotSame([], $this->store->sharedFor($this->userId, 'CTRADER'));
    }

    public function testDisconnectingTheLastConnectionDropsTheSharedCredentials(): void
    {
        // Otherwise "disconnect" would leave a live access token in the
        // database for a broker the user believes they have unplugged.
        $first = $this->createCtrader($this->accountId, 7589848);
        $second = $this->createCtrader($this->secondAccountId, 7589849);

        $this->service->deleteConnection($first);
        $this->service->deleteConnection($second);

        $this->assertSame([], $this->store->sharedFor($this->userId, 'CTRADER'));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /** Create a fully specified cTrader connection and return its id. */
    private function createCtrader(int $accountId, int $ctidTraderAccountId): int
    {
        $result = $this->service->createConnection($this->userId, $accountId, 'CTRADER', [
            'client_id' => '30528',
            'client_secret' => 'sEcReT',
            'access_token' => 'tok',
            'refresh_token' => 'refresh',
            'account_id_ctrader' => $ctidTraderAccountId,
        ]);

        return (int) $result['connection']['id'];
    }

    /** @return array{string, string} ciphertext + IV, ready to spread into decrypt() */
    private function connectionBlob(int $id): array
    {
        $row = $this->connectionRepo->findById($id);
        return [$row['credentials_encrypted'], $row['credentials_iv']];
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
        foreach (['sync_logs', 'broker_connections', 'broker_credentials', 'accounts', 'users'] as $table) {
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
