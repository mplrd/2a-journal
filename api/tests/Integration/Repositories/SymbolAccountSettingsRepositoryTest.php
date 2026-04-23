<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\SymbolAccountSettingsRepository;
use App\Repositories\SymbolRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class SymbolAccountSettingsRepositoryTest extends TestCase
{
    private SymbolAccountSettingsRepository $repo;
    private SymbolRepository $symbolRepo;
    private AccountRepository $accountRepo;
    private PDO $pdo;
    private int $userId;
    private int $otherUserId;

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
        $this->repo = new SymbolAccountSettingsRepository($this->pdo);
        $this->symbolRepo = new SymbolRepository($this->pdo);
        $this->accountRepo = new AccountRepository($this->pdo);

        $this->cleanup();

        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('sas@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('sas-other@test.com', 'hashed')");
        $this->otherUserId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM symbol_account_settings');
        $this->pdo->exec('DELETE FROM symbols');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');
    }

    private function createSymbol(int $userId = null, string $code = 'US100.CASH', float $pointValue = 20.0): array
    {
        return $this->symbolRepo->create([
            'user_id' => $userId ?? $this->userId,
            'code' => $code,
            'name' => $code,
            'type' => 'INDEX',
            'point_value' => $pointValue,
            'currency' => 'USD',
        ]);
    }

    private function createAccount(int $userId = null, string $name = 'Acc'): array
    {
        return $this->accountRepo->create([
            'user_id' => $userId ?? $this->userId,
            'name' => $name,
            'account_type' => 'BROKER_DEMO',
        ]);
    }

    public function testUpsertInsertsNewRow(): void
    {
        $sym = $this->createSymbol();
        $acc = $this->createAccount();

        $this->repo->upsert((int) $sym['id'], (int) $acc['id'], 2.5);

        $found = $this->repo->findBySymbolAndAccount((int) $sym['id'], (int) $acc['id']);
        $this->assertNotNull($found);
        $this->assertEquals(2.5, (float) $found['point_value']);
    }

    public function testUpsertUpdatesExistingRow(): void
    {
        $sym = $this->createSymbol();
        $acc = $this->createAccount();

        $this->repo->upsert((int) $sym['id'], (int) $acc['id'], 2.5);
        $this->repo->upsert((int) $sym['id'], (int) $acc['id'], 20.0);

        $found = $this->repo->findBySymbolAndAccount((int) $sym['id'], (int) $acc['id']);
        $this->assertEquals(20.0, (float) $found['point_value']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM symbol_account_settings')->fetchColumn();
        $this->assertSame(1, $count, 'upsert must not duplicate rows');
    }

    public function testDeleteRemovesRow(): void
    {
        $sym = $this->createSymbol();
        $acc = $this->createAccount();

        $this->repo->upsert((int) $sym['id'], (int) $acc['id'], 2.5);
        $this->repo->delete((int) $sym['id'], (int) $acc['id']);

        $this->assertNull($this->repo->findBySymbolAndAccount((int) $sym['id'], (int) $acc['id']));
    }

    public function testDeleteIsIdempotent(): void
    {
        $sym = $this->createSymbol();
        $acc = $this->createAccount();

        $this->repo->delete((int) $sym['id'], (int) $acc['id']);
        $this->assertTrue(true);
    }

    public function testFindAllByUserIdReturnsOnlyOwnerRows(): void
    {
        $sym1 = $this->createSymbol($this->userId, 'US100.CASH');
        $sym2 = $this->createSymbol($this->otherUserId, 'DAX');
        $acc1 = $this->createAccount($this->userId, 'Mine');
        $acc2 = $this->createAccount($this->otherUserId, 'Theirs');

        $this->repo->upsert((int) $sym1['id'], (int) $acc1['id'], 5.0);
        $this->repo->upsert((int) $sym2['id'], (int) $acc2['id'], 9.0);

        $rows = $this->repo->findAllByUserId($this->userId);
        $this->assertCount(1, $rows);
        $this->assertEquals(5.0, (float) $rows[0]['point_value']);
    }

    public function testAutoMaterializeCreatesOneRowPerPairWithInheritedPointValue(): void
    {
        $sym1 = $this->createSymbol($this->userId, 'NASDAQ', 20.0);
        $sym2 = $this->createSymbol($this->userId, 'DAX', 25.0);
        $acc1 = $this->createAccount($this->userId, 'FTMO 10k');
        $acc2 = $this->createAccount($this->userId, 'FTMO 100k');

        $created = $this->repo->autoMaterializeForUser($this->userId);
        $this->assertSame(4, $created);

        $rows = $this->repo->findAllByUserId($this->userId);
        $this->assertCount(4, $rows);

        $map = [];
        foreach ($rows as $r) $map[$r['symbol_id'] . ':' . $r['account_id']] = (float) $r['point_value'];

        $this->assertEquals(20.0, $map[$sym1['id'] . ':' . $acc1['id']]);
        $this->assertEquals(20.0, $map[$sym1['id'] . ':' . $acc2['id']]);
        $this->assertEquals(25.0, $map[$sym2['id'] . ':' . $acc1['id']]);
        $this->assertEquals(25.0, $map[$sym2['id'] . ':' . $acc2['id']]);
    }

    public function testAutoMaterializeIsIdempotent(): void
    {
        $this->createSymbol();
        $this->createAccount();

        $first = $this->repo->autoMaterializeForUser($this->userId);
        $second = $this->repo->autoMaterializeForUser($this->userId);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
    }

    public function testAutoMaterializeDoesNotOverwriteExistingRows(): void
    {
        $sym = $this->createSymbol($this->userId, 'NASDAQ', 20.0);
        $acc = $this->createAccount();

        // User already customised the point value
        $this->repo->upsert((int) $sym['id'], (int) $acc['id'], 2.0);

        $this->repo->autoMaterializeForUser($this->userId);

        $row = $this->repo->findBySymbolAndAccount((int) $sym['id'], (int) $acc['id']);
        $this->assertEquals(2.0, (float) $row['point_value'], 'user-customised value must not be overwritten');
    }

    public function testCascadeOnSymbolHardDelete(): void
    {
        $sym = $this->createSymbol();
        $acc = $this->createAccount();
        $this->repo->upsert((int) $sym['id'], (int) $acc['id'], 5.0);

        $this->symbolRepo->hardDelete((int) $sym['id']);

        $this->assertNull($this->repo->findBySymbolAndAccount((int) $sym['id'], (int) $acc['id']));
    }

    public function testCascadeOnAccountDelete(): void
    {
        $sym = $this->createSymbol();
        $acc = $this->createAccount();
        $this->repo->upsert((int) $sym['id'], (int) $acc['id'], 5.0);

        $this->pdo->prepare('DELETE FROM accounts WHERE id = :id')->execute(['id' => $acc['id']]);

        $this->assertNull($this->repo->findBySymbolAndAccount((int) $sym['id'], (int) $acc['id']));
    }
}
