<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class TradeRepositoryTest extends TestCase
{
    private TradeRepository $repo;
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
        $this->repo = new TradeRepository($this->pdo);
        $this->positionRepo = new PositionRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');

        // Create a test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('trade-test@test.com', 'hashed')");
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
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
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
            'position_type' => 'TRADE',
        ], $overrides));
    }

    public function testCreateInsertsTrade(): void
    {
        $position = $this->createPosition();

        $trade = $this->repo->create([
            'position_id' => (int) $position['id'],
            'opened_at' => '2026-01-15 10:00:00',
            'remaining_size' => 1.0,
            'status' => 'OPEN',
        ]);

        $this->assertNotNull($trade);
        $this->assertSame('OPEN', $trade['status']);
        $this->assertSame('NASDAQ', $trade['symbol']);
        $this->assertSame('BUY', $trade['direction']);
        $this->assertEquals($position['id'], $trade['position_id']);
        $this->assertEquals(1.0, (float) $trade['remaining_size']);
    }

    public function testFindByIdReturnsTradeWithPositionData(): void
    {
        $position = $this->createPosition(['symbol' => 'DAX', 'direction' => 'SELL']);
        $trade = $this->repo->create([
            'position_id' => (int) $position['id'],
            'opened_at' => '2026-01-15 10:00:00',
            'remaining_size' => 1.0,
        ]);

        $found = $this->repo->findById((int) $trade['id']);

        $this->assertNotNull($found);
        $this->assertSame('DAX', $found['symbol']);
        $this->assertSame('SELL', $found['direction']);
        $this->assertSame('OPEN', $found['status']);
        $this->assertArrayHasKey('entry_price', $found);
        $this->assertArrayHasKey('sl_price', $found);
        $this->assertArrayHasKey('opened_at', $found);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(99999));
    }

    public function testFindAllByUserIdReturnsTrades(): void
    {
        $pos1 = $this->createPosition(['symbol' => 'NASDAQ']);
        $pos2 = $this->createPosition(['symbol' => 'DAX']);
        $this->repo->create(['position_id' => (int) $pos1['id'], 'opened_at' => '2026-01-15 10:00:00', 'remaining_size' => 1.0]);
        $this->repo->create(['position_id' => (int) $pos2['id'], 'opened_at' => '2026-01-15 11:00:00', 'remaining_size' => 1.0]);

        $result = $this->repo->findAllByUserId($this->userId);

        $this->assertCount(2, $result['items']);
        $this->assertSame(2, $result['total']);
    }

    public function testFindAllByUserIdWithFilters(): void
    {
        $pos1 = $this->createPosition(['symbol' => 'NASDAQ']);
        $pos2 = $this->createPosition(['symbol' => 'DAX']);
        $this->repo->create(['position_id' => (int) $pos1['id'], 'opened_at' => '2026-01-15 10:00:00', 'remaining_size' => 1.0]);
        $trade2 = $this->repo->create(['position_id' => (int) $pos2['id'], 'opened_at' => '2026-01-15 11:00:00', 'remaining_size' => 1.0]);
        $this->repo->update((int) $trade2['id'], ['status' => 'CLOSED']);

        // Filter by status
        $open = $this->repo->findAllByUserId($this->userId, ['status' => 'OPEN']);
        $this->assertCount(1, $open['items']);
        $this->assertSame('NASDAQ', $open['items'][0]['symbol']);

        // Filter by symbol
        $dax = $this->repo->findAllByUserId($this->userId, ['symbol' => 'DAX']);
        $this->assertCount(1, $dax['items']);
        $this->assertSame('CLOSED', $dax['items'][0]['status']);
    }

    public function testUpdateModifiesTradeFields(): void
    {
        $position = $this->createPosition();
        $trade = $this->repo->create([
            'position_id' => (int) $position['id'],
            'opened_at' => '2026-01-15 10:00:00',
            'remaining_size' => 1.0,
        ]);

        $updated = $this->repo->update((int) $trade['id'], [
            'remaining_size' => 0.5,
            'status' => 'SECURED',
            'avg_exit_price' => 18600.0,
        ]);

        $this->assertNotNull($updated);
        $this->assertEquals(0.5, (float) $updated['remaining_size']);
        $this->assertSame('SECURED', $updated['status']);
        $this->assertEquals(18600.0, (float) $updated['avg_exit_price']);
    }
}
