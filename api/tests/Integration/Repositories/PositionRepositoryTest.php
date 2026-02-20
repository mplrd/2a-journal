<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\PositionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class PositionRepositoryTest extends TestCase
{
    private PositionRepository $repo;
    private PDO $pdo;
    private int $userId;
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
        $this->repo = new PositionRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');

        // Create a test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('test@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();

        // Create a test account
        $accountRepo = new AccountRepository($this->pdo);
        $account = $accountRepo->create([
            'user_id' => $this->userId,
            'name' => 'Test Account',
            'account_type' => 'BROKER_DEMO',
        ]);
        $this->accountId = (int) $account['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');
    }

    private function insertPosition(array $overrides = []): array
    {
        $data = array_merge([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => '18500.00000',
            'size' => '1.0000',
            'setup' => 'Breakout',
            'sl_points' => '50.00',
            'sl_price' => '18450.00000',
            'position_type' => 'TRADE',
        ], $overrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, sl_price, be_points, be_price, be_size, targets, notes, position_type)
             VALUES (:user_id, :account_id, :direction, :symbol, :entry_price, :size, :setup, :sl_points, :sl_price, :be_points, :be_price, :be_size, :targets, :notes, :position_type)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'direction' => $data['direction'],
            'symbol' => $data['symbol'],
            'entry_price' => $data['entry_price'],
            'size' => $data['size'],
            'setup' => $data['setup'],
            'sl_points' => $data['sl_points'],
            'sl_price' => $data['sl_price'],
            'be_points' => $data['be_points'] ?? null,
            'be_price' => $data['be_price'] ?? null,
            'be_size' => $data['be_size'] ?? null,
            'targets' => $data['targets'] ?? null,
            'notes' => $data['notes'] ?? null,
            'position_type' => $data['position_type'],
        ]);

        return $this->repo->findById((int) $this->pdo->lastInsertId());
    }

    public function testCreateInsertsAndReturnsPosition(): void
    {
        $position = $this->repo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => '18500.00000',
            'size' => '1.0000',
            'setup' => 'Breakout',
            'sl_points' => '50.00',
            'sl_price' => '18450.00000',
            'position_type' => 'ORDER',
        ]);

        $this->assertNotNull($position);
        $this->assertArrayHasKey('id', $position);
        $this->assertSame('NASDAQ', $position['symbol']);
        $this->assertSame('BUY', $position['direction']);
        $this->assertSame('ORDER', $position['position_type']);

        // Verify it's persisted
        $found = $this->repo->findById((int) $position['id']);
        $this->assertNotNull($found);
        $this->assertSame('NASDAQ', $found['symbol']);
    }

    public function testFindByIdReturnsPosition(): void
    {
        $created = $this->insertPosition();

        $found = $this->repo->findById((int) $created['id']);

        $this->assertNotNull($found);
        $this->assertSame('NASDAQ', $found['symbol']);
        $this->assertSame('BUY', $found['direction']);
        $this->assertSame('TRADE', $found['position_type']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(99999));
    }

    public function testFindAllByUserIdReturnsPositions(): void
    {
        $this->insertPosition(['symbol' => 'NASDAQ']);
        $this->insertPosition(['symbol' => 'DAX']);

        $result = $this->repo->findAllByUserId($this->userId);

        $this->assertCount(2, $result['items']);
        $this->assertSame(2, $result['total']);
    }

    public function testFindAllByUserIdWithFilters(): void
    {
        $this->insertPosition(['symbol' => 'NASDAQ', 'position_type' => 'TRADE']);
        $this->insertPosition(['symbol' => 'DAX', 'position_type' => 'ORDER']);
        $this->insertPosition(['symbol' => 'NASDAQ', 'position_type' => 'ORDER']);

        // Filter by position_type
        $trades = $this->repo->findAllByUserId($this->userId, ['position_type' => 'TRADE']);
        $this->assertCount(1, $trades['items']);

        // Filter by symbol
        $nasdaq = $this->repo->findAllByUserId($this->userId, ['symbol' => 'NASDAQ']);
        $this->assertCount(2, $nasdaq['items']);

        // Filter by both
        $nasdaqOrders = $this->repo->findAllByUserId($this->userId, ['symbol' => 'NASDAQ', 'position_type' => 'ORDER']);
        $this->assertCount(1, $nasdaqOrders['items']);
    }

    public function testUpdateModifiesFields(): void
    {
        $created = $this->insertPosition();

        $updated = $this->repo->update((int) $created['id'], [
            'entry_price' => '19000.00000',
            'notes' => 'Updated note',
        ]);

        $this->assertNotNull($updated);
        $this->assertEquals(19000, (float) $updated['entry_price']);
        $this->assertSame('Updated note', $updated['notes']);
        $this->assertSame('NASDAQ', $updated['symbol']); // unchanged
    }

    public function testDeleteRemovesPosition(): void
    {
        $created = $this->insertPosition();

        $result = $this->repo->delete((int) $created['id']);

        $this->assertTrue($result);
        $this->assertNull($this->repo->findById((int) $created['id']));
    }

    public function testDeleteReturnsFalseWhenNotFound(): void
    {
        $this->assertFalse($this->repo->delete(99999));
    }

    public function testTransferChangesAccount(): void
    {
        $created = $this->insertPosition();

        // Create second account
        $accountRepo = new AccountRepository($this->pdo);
        $newAccount = $accountRepo->create([
            'user_id' => $this->userId,
            'name' => 'New Account',
            'account_type' => 'BROKER_LIVE',
        ]);

        $transferred = $this->repo->transfer((int) $created['id'], (int) $newAccount['id']);

        $this->assertNotNull($transferred);
        $this->assertEquals($newAccount['id'], $transferred['account_id']);
    }
}
