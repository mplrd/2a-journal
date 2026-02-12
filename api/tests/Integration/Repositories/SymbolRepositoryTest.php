<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\SymbolRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class SymbolRepositoryTest extends TestCase
{
    private SymbolRepository $repo;
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
        $this->repo = new SymbolRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM symbols');
        $this->pdo->exec('DELETE FROM users');

        // Create a test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('test@test.com', 'hashed')");
        $this->userId = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM symbols');
        $this->pdo->exec('DELETE FROM users');
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->userId,
            'code' => 'US100.CASH',
            'name' => 'NASDAQ 100',
            'type' => 'INDEX',
            'point_value' => 20.0,
            'currency' => 'USD',
        ], $overrides);
    }

    public function testCreateReturnsSymbol(): void
    {
        $symbol = $this->repo->create($this->validData());

        $this->assertIsArray($symbol);
        $this->assertSame('US100.CASH', $symbol['code']);
        $this->assertSame('NASDAQ 100', $symbol['name']);
        $this->assertSame('INDEX', $symbol['type']);
        $this->assertEquals(20.0, $symbol['point_value']);
        $this->assertSame('USD', $symbol['currency']);
        $this->assertEquals($this->userId, $symbol['user_id']);
    }

    public function testFindByIdReturnsSymbol(): void
    {
        $created = $this->repo->create($this->validData());
        $found = $this->repo->findById((int)$created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame('US100.CASH', $found['code']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $found = $this->repo->findById(99999);
        $this->assertNull($found);
    }

    public function testFindAllByUserIdReturnsUserSymbols(): void
    {
        $this->repo->create($this->validData(['code' => 'US100.CASH', 'name' => 'NASDAQ 100']));
        $this->repo->create($this->validData(['code' => 'DE40.CASH', 'name' => 'DAX 40']));

        // Create another user's symbol
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('other@test.com', 'hashed')");
        $otherUserId = (int)$this->pdo->lastInsertId();
        $this->repo->create($this->validData(['user_id' => $otherUserId, 'code' => 'US500.CASH', 'name' => 'S&P 500']));

        $result = $this->repo->findAllByUserId($this->userId);

        $this->assertCount(2, $result['items']);
        $this->assertSame(2, $result['total']);
        $codes = array_column($result['items'], 'code');
        $this->assertContains('US100.CASH', $codes);
        $this->assertContains('DE40.CASH', $codes);
        $this->assertNotContains('US500.CASH', $codes);
    }

    public function testFindByUserAndCodeReturnsSymbol(): void
    {
        $this->repo->create($this->validData());

        $found = $this->repo->findByUserAndCode($this->userId, 'US100.CASH');
        $this->assertNotNull($found);
        $this->assertSame('US100.CASH', $found['code']);
    }

    public function testFindByUserAndCodeReturnsNullWhenNotFound(): void
    {
        $found = $this->repo->findByUserAndCode($this->userId, 'NONEXISTENT');
        $this->assertNull($found);
    }

    public function testUpdateModifiesFields(): void
    {
        $created = $this->repo->create($this->validData());
        $updated = $this->repo->update((int)$created['id'], [
            'name' => 'Updated Name',
            'point_value' => 25.0,
        ]);

        $this->assertNotNull($updated);
        $this->assertSame('Updated Name', $updated['name']);
        $this->assertEquals(25.0, $updated['point_value']);
        $this->assertSame('US100.CASH', $updated['code']);
    }

    public function testSoftDeleteMarksAsDeleted(): void
    {
        $created = $this->repo->create($this->validData());
        $result = $this->repo->softDelete((int)$created['id']);

        $this->assertTrue($result);

        $found = $this->repo->findById((int)$created['id']);
        $this->assertNull($found);
    }

    public function testSoftDeletedSymbolNotInList(): void
    {
        $created = $this->repo->create($this->validData());
        $this->repo->softDelete((int)$created['id']);

        $result = $this->repo->findAllByUserId($this->userId);
        $this->assertCount(0, $result['items']);
        $this->assertSame(0, $result['total']);
    }

    public function testSoftDeletedSymbolNotFoundByCode(): void
    {
        $created = $this->repo->create($this->validData());
        $this->repo->softDelete((int)$created['id']);

        $found = $this->repo->findByUserAndCode($this->userId, 'US100.CASH');
        $this->assertNull($found);
    }

    public function testSeedForUserCreatesDefaultSymbols(): void
    {
        $this->repo->seedForUser($this->userId);

        $result = $this->repo->findAllByUserId($this->userId, 50, 0);
        $this->assertSame(6, $result['total']);

        $codes = array_column($result['items'], 'code');
        $this->assertContains('US100.CASH', $codes);
        $this->assertContains('DE40.CASH', $codes);
        $this->assertContains('US500.CASH', $codes);
        $this->assertContains('FRA40.CASH', $codes);
        $this->assertContains('EURUSD', $codes);
        $this->assertContains('BTCUSD', $codes);
    }

    public function testPaginationWorksCorrectly(): void
    {
        $this->repo->seedForUser($this->userId);

        $result = $this->repo->findAllByUserId($this->userId, 2, 0);
        $this->assertCount(2, $result['items']);
        $this->assertSame(6, $result['total']);

        $result2 = $this->repo->findAllByUserId($this->userId, 2, 2);
        $this->assertCount(2, $result2['items']);
    }
}
