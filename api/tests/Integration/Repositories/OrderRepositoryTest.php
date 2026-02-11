<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PositionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class OrderRepositoryTest extends TestCase
{
    private OrderRepository $repo;
    private PositionRepository $positionRepo;
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
        $this->repo = new OrderRepository($this->pdo);
        $this->positionRepo = new PositionRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');

        // Create a test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('order-test@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();

        // Create a test account
        $accountRepo = new AccountRepository($this->pdo);
        $account = $accountRepo->create([
            'user_id' => $this->userId,
            'name' => 'Test Account',
            'account_type' => 'BROKER',
            'mode' => 'DEMO',
        ]);
        $this->accountId = (int) $account['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');
    }

    private function createPosition(array $overrides = []): array
    {
        return $this->positionRepo->create(array_merge([
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
        ], $overrides));
    }

    public function testCreateInsertsOrder(): void
    {
        $position = $this->createPosition();

        $order = $this->repo->create([
            'position_id' => (int) $position['id'],
            'status' => 'PENDING',
        ]);

        $this->assertNotNull($order);
        $this->assertSame('PENDING', $order['status']);
        $this->assertSame('NASDAQ', $order['symbol']);
        $this->assertSame('BUY', $order['direction']);
        $this->assertEquals($position['id'], $order['position_id']);
    }

    public function testFindByIdReturnsOrderWithPositionData(): void
    {
        $position = $this->createPosition(['symbol' => 'DAX', 'direction' => 'SELL']);
        $order = $this->repo->create(['position_id' => (int) $position['id']]);

        $found = $this->repo->findById((int) $order['id']);

        $this->assertNotNull($found);
        $this->assertSame('DAX', $found['symbol']);
        $this->assertSame('SELL', $found['direction']);
        $this->assertSame('PENDING', $found['status']);
        $this->assertArrayHasKey('entry_price', $found);
        $this->assertArrayHasKey('sl_price', $found);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(99999));
    }

    public function testFindAllByUserIdReturnsOrders(): void
    {
        $pos1 = $this->createPosition(['symbol' => 'NASDAQ']);
        $pos2 = $this->createPosition(['symbol' => 'DAX']);
        $this->repo->create(['position_id' => (int) $pos1['id']]);
        $this->repo->create(['position_id' => (int) $pos2['id']]);

        $result = $this->repo->findAllByUserId($this->userId);

        $this->assertCount(2, $result['items']);
        $this->assertSame(2, $result['total']);
    }

    public function testFindAllByUserIdWithFilters(): void
    {
        $pos1 = $this->createPosition(['symbol' => 'NASDAQ']);
        $pos2 = $this->createPosition(['symbol' => 'DAX']);
        $this->repo->create(['position_id' => (int) $pos1['id'], 'status' => 'PENDING']);
        $order2 = $this->repo->create(['position_id' => (int) $pos2['id'], 'status' => 'PENDING']);
        $this->repo->updateStatus((int) $order2['id'], 'CANCELLED');

        // Filter by status
        $pending = $this->repo->findAllByUserId($this->userId, ['status' => 'PENDING']);
        $this->assertCount(1, $pending['items']);
        $this->assertSame('NASDAQ', $pending['items'][0]['symbol']);

        // Filter by symbol
        $dax = $this->repo->findAllByUserId($this->userId, ['symbol' => 'DAX']);
        $this->assertCount(1, $dax['items']);
        $this->assertSame('CANCELLED', $dax['items'][0]['status']);
    }

    public function testUpdateStatusChangesStatus(): void
    {
        $position = $this->createPosition();
        $order = $this->repo->create(['position_id' => (int) $position['id']]);

        $updated = $this->repo->updateStatus((int) $order['id'], 'EXECUTED');

        $this->assertNotNull($updated);
        $this->assertSame('EXECUTED', $updated['status']);

        // Verify in DB
        $found = $this->repo->findById((int) $order['id']);
        $this->assertSame('EXECUTED', $found['status']);
    }
}
