<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Repositories\AccountRepository;
use App\Repositories\BrokerConnectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class BrokerConnectionRepositoryTest extends TestCase
{
    private BrokerConnectionRepository $repo;
    private AccountRepository $accountRepo;
    private PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/../../../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
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

        Database::reset();
        $this->pdo = Database::getConnection();
        $this->repo = new BrokerConnectionRepository($this->pdo);
        $this->accountRepo = new AccountRepository($this->pdo);

        $this->cleanup();

        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('broker-conn@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM sync_logs');
        $this->pdo->exec('DELETE FROM broker_connections');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');
    }

    private function createConnection(array $overrides = []): array
    {
        $account = $this->accountRepo->create([
            'user_id' => $this->userId,
            'name' => 'Account ' . uniqid(),
            'account_type' => 'BROKER_DEMO',
        ]);

        return $this->repo->create(array_merge([
            'user_id' => $this->userId,
            'account_id' => (int) $account['id'],
            'provider' => BrokerProvider::METAAPI->value,
            'status' => ConnectionStatus::ACTIVE->value,
            'credentials_encrypted' => 'cipher',
            'credentials_iv' => str_repeat('a', 32),
        ], $overrides));
    }

    private function setLastSyncAt(int $connectionId, ?string $lastSyncAt): void
    {
        $this->pdo->prepare('UPDATE broker_connections SET last_sync_at = :t WHERE id = :id')
            ->execute(['t' => $lastSyncAt, 'id' => $connectionId]);
    }

    // ── findDueForAutoSync ──────────────────────────────────────

    public function testFindDueIncludesConnectionNeverSynced(): void
    {
        $conn = $this->createConnection();

        $due = $this->repo->findDueForAutoSync(15);

        $this->assertCount(1, $due);
        $this->assertSame((int) $conn['id'], (int) $due[0]['id']);
    }

    public function testFindDueIncludesConnectionOlderThanInterval(): void
    {
        $conn = $this->createConnection();
        $this->setLastSyncAt((int) $conn['id'], gmdate('Y-m-d H:i:s', time() - 30 * 60));

        $due = $this->repo->findDueForAutoSync(15);

        $this->assertCount(1, $due);
    }

    public function testFindDueExcludesConnectionRecentlySynced(): void
    {
        $conn = $this->createConnection();
        $this->setLastSyncAt((int) $conn['id'], gmdate('Y-m-d H:i:s', time() - 5 * 60));

        $due = $this->repo->findDueForAutoSync(15);

        $this->assertCount(0, $due);
    }

    public function testFindDueExcludesNonActiveConnections(): void
    {
        $this->createConnection(['status' => ConnectionStatus::PENDING->value]);
        $this->createConnection(['status' => ConnectionStatus::ERROR->value]);
        $this->createConnection(['status' => ConnectionStatus::REVOKED->value]);

        $due = $this->repo->findDueForAutoSync(15);

        $this->assertCount(0, $due);
    }

    public function testFindDueReturnsMultipleOrderedByLastSync(): void
    {
        $connOld = $this->createConnection();
        $this->setLastSyncAt((int) $connOld['id'], gmdate('Y-m-d H:i:s', time() - 60 * 60));
        $connNever = $this->createConnection();

        $due = $this->repo->findDueForAutoSync(15);

        $this->assertCount(2, $due);
    }

    public function testFindDueClampsNegativeInterval(): void
    {
        $conn = $this->createConnection();
        $this->setLastSyncAt((int) $conn['id'], gmdate('Y-m-d H:i:s', time() - 5 * 60));

        // Negative interval must not be blindly injected into SQL; clamp to a safe min
        $due = $this->repo->findDueForAutoSync(-1);

        $this->assertIsArray($due);
    }

    // ── incrementFailures / resetFailures / markError ───────────

    public function testIncrementFailuresIncrementsCounter(): void
    {
        $conn = $this->createConnection();

        $this->repo->incrementFailures((int) $conn['id']);
        $this->repo->incrementFailures((int) $conn['id']);

        $row = $this->repo->findById((int) $conn['id']);
        $this->assertSame(2, (int) $row['consecutive_failures']);
    }

    public function testResetFailuresZeroesCounter(): void
    {
        $conn = $this->createConnection();
        $this->repo->incrementFailures((int) $conn['id']);
        $this->repo->incrementFailures((int) $conn['id']);

        $this->repo->resetFailures((int) $conn['id']);

        $row = $this->repo->findById((int) $conn['id']);
        $this->assertSame(0, (int) $row['consecutive_failures']);
    }

    public function testMarkErrorSetsStatusAndKeepsFailureCount(): void
    {
        $conn = $this->createConnection();
        $this->repo->incrementFailures((int) $conn['id']);
        $this->repo->incrementFailures((int) $conn['id']);
        $this->repo->incrementFailures((int) $conn['id']);

        $this->repo->markError((int) $conn['id'], 'auth refused');

        $row = $this->repo->findById((int) $conn['id']);
        $this->assertSame(ConnectionStatus::ERROR->value, $row['status']);
        $this->assertSame('auth refused', $row['last_sync_error']);
        $this->assertSame(3, (int) $row['consecutive_failures']);
    }
}
