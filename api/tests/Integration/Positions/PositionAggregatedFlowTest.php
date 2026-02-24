<?php

namespace Tests\Integration\Positions;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use PDO;
use PHPUnit\Framework\TestCase;

class PositionAggregatedFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $accessToken;
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

        // Clean tables
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');

        // Build router
        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;

        // Register a user and get access token
        $request = Request::create('POST', '/auth/register', [
            'email' => 'agg@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'agg@test.com']);
        $this->userId = (int) $stmt->fetchColumn();

        // Create an account
        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Test Account',
            'account_type' => 'BROKER_DEMO',
        ]));
        $this->accountId = (int) $response->getBody()['data']['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function authRequest(string $method, string $uri, array $body = [], array $query = []): Request
    {
        return Request::create($method, $uri, $body, $query, [
            'Authorization' => "Bearer {$this->accessToken}",
        ]);
    }

    private function insertPositionWithTrade(array $posOverrides = [], array $tradeOverrides = []): int
    {
        $posData = array_merge([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => '18500.00000',
            'size' => '1.0000',
            'setup' => '["Breakout"]',
            'sl_points' => '50.00',
            'sl_price' => '18450.00000',
            'position_type' => 'TRADE',
        ], $posOverrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, sl_price, position_type)
             VALUES (:user_id, :account_id, :direction, :symbol, :entry_price, :size, :setup, :sl_points, :sl_price, :position_type)'
        );
        $stmt->execute($posData);
        $positionId = (int) $this->pdo->lastInsertId();

        $tradeData = array_merge([
            'position_id' => $positionId,
            'opened_at' => '2025-01-15 10:00:00',
            'remaining_size' => $posData['size'],
            'status' => 'OPEN',
        ], $tradeOverrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO trades (position_id, opened_at, remaining_size, status)
             VALUES (:position_id, :opened_at, :remaining_size, :status)'
        );
        $stmt->execute($tradeData);

        return $positionId;
    }

    public function testAggregatedRequiresAuth(): void
    {
        $request = Request::create('GET', '/positions/aggregated');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (\App\Exceptions\HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testAggregatedReturnsEmptyWhenNoTrades(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/positions/aggregated'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(0, $body['data']);
    }

    public function testAggregatedReturnsGroupedResults(): void
    {
        $this->insertPositionWithTrade(
            ['symbol' => 'NASDAQ', 'entry_price' => '18500.00000', 'size' => '2.0000'],
            ['remaining_size' => '2.0000', 'opened_at' => '2025-01-15 10:00:00']
        );
        $this->insertPositionWithTrade(
            ['symbol' => 'NASDAQ', 'entry_price' => '19000.00000', 'size' => '3.0000'],
            ['remaining_size' => '3.0000', 'opened_at' => '2025-01-16 10:00:00']
        );

        $response = $this->router->dispatch($this->authRequest('GET', '/positions/aggregated'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);

        $row = $body['data'][0];
        $this->assertSame('NASDAQ', $row['symbol']);
        $this->assertEquals(5.0, (float) $row['total_size']);
        $this->assertEquals(18800.0, (float) $row['pru']);
    }

    public function testAggregatedFiltersAccountId(): void
    {
        // Create second account
        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Second Account',
            'account_type' => 'BROKER_LIVE',
        ]));
        $secondAccountId = (int) $response->getBody()['data']['id'];

        $this->insertPositionWithTrade(
            ['symbol' => 'NASDAQ', 'account_id' => $this->accountId],
            ['remaining_size' => '1.0000']
        );
        $this->insertPositionWithTrade(
            ['symbol' => 'DAX', 'account_id' => $secondAccountId],
            ['remaining_size' => '2.0000']
        );

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/positions/aggregated', [], ['account_id' => (string) $this->accountId])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('NASDAQ', $body['data'][0]['symbol']);
    }

    public function testAggregatedExcludesClosedTrades(): void
    {
        $this->insertPositionWithTrade(
            ['symbol' => 'NASDAQ', 'size' => '1.0000'],
            ['remaining_size' => '1.0000', 'status' => 'OPEN']
        );
        $this->insertPositionWithTrade(
            ['symbol' => 'NASDAQ', 'size' => '2.0000'],
            ['remaining_size' => '0.0000', 'status' => 'CLOSED']
        );

        $response = $this->router->dispatch($this->authRequest('GET', '/positions/aggregated'));
        $body = $response->getBody();

        $this->assertCount(1, $body['data']);
        $this->assertEquals(1.0, (float) $body['data'][0]['total_size']);
    }
}
