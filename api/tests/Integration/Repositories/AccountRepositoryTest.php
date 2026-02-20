<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\AccountRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class AccountRepositoryTest extends TestCase
{
    private AccountRepository $repo;
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
        $this->repo = new AccountRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');

        // Create a test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('test@test.com', 'hashed')");
        $this->userId = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->userId,
            'name' => 'Test Account',
            'account_type' => 'BROKER_DEMO',
        ], $overrides);
    }

    public function testCreateReturnsAccount(): void
    {
        $account = $this->repo->create($this->validData());

        $this->assertIsArray($account);
        $this->assertSame('Test Account', $account['name']);
        $this->assertSame('BROKER_DEMO', $account['account_type']);
        $this->assertNull($account['stage']);
        $this->assertSame('EUR', $account['currency']);
        $this->assertEquals(0, $account['initial_capital']);
        $this->assertEquals(0, $account['current_capital']);
        $this->assertEquals($this->userId, $account['user_id']);
    }

    public function testCreateWithAllFields(): void
    {
        $account = $this->repo->create($this->validData([
            'currency' => 'USD',
            'initial_capital' => 10000,
            'broker' => 'FTMO',
            'max_drawdown' => 5000,
            'daily_drawdown' => 500,
            'profit_target' => 10000,
            'profit_split' => 80,
        ]));

        $this->assertSame('USD', $account['currency']);
        $this->assertEquals(10000, $account['initial_capital']);
        $this->assertEquals(10000, $account['current_capital']);
        $this->assertSame('FTMO', $account['broker']);
        $this->assertEquals(5000, $account['max_drawdown']);
        $this->assertEquals(500, $account['daily_drawdown']);
        $this->assertEquals(10000, $account['profit_target']);
        $this->assertEquals(80, $account['profit_split']);
    }

    public function testFindByIdReturnsAccount(): void
    {
        $created = $this->repo->create($this->validData());
        $found = $this->repo->findById((int)$created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame('Test Account', $found['name']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $found = $this->repo->findById(99999);
        $this->assertNull($found);
    }

    public function testFindAllByUserIdReturnsUserAccounts(): void
    {
        $this->repo->create($this->validData(['name' => 'Account 1']));
        $this->repo->create($this->validData(['name' => 'Account 2']));

        // Create another user's account
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('other@test.com', 'hashed')");
        $otherUserId = (int)$this->pdo->lastInsertId();
        $this->repo->create($this->validData(['user_id' => $otherUserId, 'name' => 'Other Account']));

        $result = $this->repo->findAllByUserId($this->userId);

        $this->assertCount(2, $result['items']);
        $this->assertSame(2, $result['total']);
        $names = array_column($result['items'], 'name');
        $this->assertContains('Account 1', $names);
        $this->assertContains('Account 2', $names);
        $this->assertNotContains('Other Account', $names);
    }

    public function testUpdateModifiesFields(): void
    {
        $created = $this->repo->create($this->validData());
        $updated = $this->repo->update((int)$created['id'], [
            'name' => 'Updated Name',
            'account_type' => 'BROKER_LIVE',
            'broker' => 'IC Markets',
        ]);

        $this->assertNotNull($updated);
        $this->assertSame('Updated Name', $updated['name']);
        $this->assertSame('BROKER_LIVE', $updated['account_type']);
        $this->assertSame('IC Markets', $updated['broker']);
    }

    public function testSoftDeleteMarksAsDeleted(): void
    {
        $created = $this->repo->create($this->validData());
        $result = $this->repo->softDelete((int)$created['id']);

        $this->assertTrue($result);

        // Should not be found anymore
        $found = $this->repo->findById((int)$created['id']);
        $this->assertNull($found);
    }

    public function testSoftDeletedAccountNotInList(): void
    {
        $created = $this->repo->create($this->validData());
        $this->repo->softDelete((int)$created['id']);

        $result = $this->repo->findAllByUserId($this->userId);
        $this->assertCount(0, $result['items']);
        $this->assertSame(0, $result['total']);
    }
}
