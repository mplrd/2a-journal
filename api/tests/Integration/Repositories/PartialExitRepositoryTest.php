<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\PartialExitRepository;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class PartialExitRepositoryTest extends TestCase
{
    private PartialExitRepository $repo;
    private TradeRepository $tradeRepo;
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
        $this->repo = new PartialExitRepository($this->pdo);
        $this->tradeRepo = new TradeRepository($this->pdo);
        $this->positionRepo = new PositionRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');

        // Create a test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('partial-exit-test@test.com', 'hashed')");
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

    private function createTrade(): array
    {
        $position = $this->positionRepo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => '18500.00000',
            'size' => '2.0000',
            'setup' => 'Breakout',
            'sl_points' => '50.00',
            'sl_price' => '18450.00000',
            'position_type' => 'TRADE',
        ]);

        return $this->tradeRepo->create([
            'position_id' => (int) $position['id'],
            'opened_at' => '2026-01-15 10:00:00',
            'remaining_size' => 2.0,
        ]);
    }

    public function testCreateInsertsPartialExit(): void
    {
        $trade = $this->createTrade();

        $exit = $this->repo->create([
            'trade_id' => (int) $trade['id'],
            'exited_at' => '2026-01-15 12:00:00',
            'exit_price' => 18600.0,
            'size' => 1.0,
            'exit_type' => 'TP',
            'pnl' => 100.0,
        ]);

        $this->assertNotNull($exit);
        $this->assertEquals($trade['id'], $exit['trade_id']);
        $this->assertEquals(18600.0, (float) $exit['exit_price']);
        $this->assertEquals(1.0, (float) $exit['size']);
        $this->assertSame('TP', $exit['exit_type']);
        $this->assertEquals(100.0, (float) $exit['pnl']);
    }

    public function testFindByTradeIdReturnsExitsOrdered(): void
    {
        $trade = $this->createTrade();

        $this->repo->create([
            'trade_id' => (int) $trade['id'],
            'exited_at' => '2026-01-15 14:00:00',
            'exit_price' => 18700.0,
            'size' => 0.5,
            'exit_type' => 'TP',
            'pnl' => 100.0,
        ]);

        $this->repo->create([
            'trade_id' => (int) $trade['id'],
            'exited_at' => '2026-01-15 12:00:00',
            'exit_price' => 18600.0,
            'size' => 1.0,
            'exit_type' => 'BE',
            'pnl' => 100.0,
        ]);

        $exits = $this->repo->findByTradeId((int) $trade['id']);

        $this->assertCount(2, $exits);
        // Ordered by exited_at ASC → first should be 12:00
        $this->assertSame('BE', $exits[0]['exit_type']);
        $this->assertSame('TP', $exits[1]['exit_type']);
    }

    public function testFindByTradeIdReturnsEmptyWhenNone(): void
    {
        $trade = $this->createTrade();

        $exits = $this->repo->findByTradeId((int) $trade['id']);

        $this->assertCount(0, $exits);
    }
}
