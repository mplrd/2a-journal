<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\AccountAdjustmentRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class AccountAdjustmentRepositoryTest extends TestCase
{
    private AccountAdjustmentRepository $repo;
    private PDO $pdo;
    private int $accountId;

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
        $this->repo = new AccountAdjustmentRepository($this->pdo);

        $this->pdo->exec('DELETE FROM account_balance_adjustments');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');

        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('test@test.com', 'hashed')");
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, current_capital)
             VALUES (:uid, 'Acc', 'BROKER_DEMO', 10000, 10000)"
        )->execute(['uid' => $userId]);
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM account_balance_adjustments');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');
    }

    public function testCreateReturnsAdjustment(): void
    {
        $adj = $this->repo->create([
            'account_id' => $this->accountId,
            'amount' => 18.00,
            'reason' => 'Frais oubliés',
        ]);

        $this->assertIsArray($adj);
        $this->assertNotEmpty($adj['id']);
        $this->assertEquals($this->accountId, $adj['account_id']);
        $this->assertEquals(18.00, (float) $adj['amount']);
        $this->assertSame('Frais oubliés', $adj['reason']);
        $this->assertNotEmpty($adj['adjusted_at']);
    }

    public function testCreateAcceptsNegativeAmountAndNullReason(): void
    {
        $adj = $this->repo->create([
            'account_id' => $this->accountId,
            'amount' => -50.50,
            'reason' => null,
        ]);

        $this->assertEquals(-50.50, (float) $adj['amount']);
        $this->assertNull($adj['reason']);
    }

    public function testFindByAccountIdReturnsAllForAccountNewestFirst(): void
    {
        $this->repo->create(['account_id' => $this->accountId, 'amount' => 10, 'reason' => 'first', 'adjusted_at' => '2026-01-01 10:00:00']);
        $this->repo->create(['account_id' => $this->accountId, 'amount' => 20, 'reason' => 'second', 'adjusted_at' => '2026-02-01 10:00:00']);

        $list = $this->repo->findByAccountId($this->accountId);

        $this->assertCount(2, $list);
        // Newest first
        $this->assertSame('second', $list[0]['reason']);
        $this->assertSame('first', $list[1]['reason']);
    }

    public function testFindByAccountIdIsScopedPerAccount(): void
    {
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('other@test.com', 'hashed')");
        $otherUser = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, current_capital)
             VALUES (:uid, 'Other', 'BROKER_DEMO', 5000, 5000)"
        )->execute(['uid' => $otherUser]);
        $otherAccount = (int) $this->pdo->lastInsertId();

        $this->repo->create(['account_id' => $this->accountId, 'amount' => 10, 'reason' => 'mine']);
        $this->repo->create(['account_id' => $otherAccount, 'amount' => 99, 'reason' => 'theirs']);

        $list = $this->repo->findByAccountId($this->accountId);

        $this->assertCount(1, $list);
        $this->assertSame('mine', $list[0]['reason']);
    }

    public function testFindByIdReturnsAdjustment(): void
    {
        $created = $this->repo->create(['account_id' => $this->accountId, 'amount' => 12, 'reason' => 'x']);
        $found = $this->repo->findById((int) $created['id']);

        $this->assertNotNull($found);
        $this->assertEquals($created['id'], $found['id']);
        $this->assertEquals($this->accountId, $found['account_id']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999999));
    }

    public function testDeleteRemovesAdjustment(): void
    {
        $created = $this->repo->create(['account_id' => $this->accountId, 'amount' => 12, 'reason' => 'x']);

        $result = $this->repo->delete((int) $created['id']);

        $this->assertTrue($result);
        $this->assertNull($this->repo->findById((int) $created['id']));
    }

    public function testDeleteCascadesWhenAccountDeleted(): void
    {
        $created = $this->repo->create(['account_id' => $this->accountId, 'amount' => 12, 'reason' => 'x']);

        $this->pdo->prepare('DELETE FROM accounts WHERE id = :id')->execute(['id' => $this->accountId]);

        $this->assertNull($this->repo->findById((int) $created['id']));
    }
}
