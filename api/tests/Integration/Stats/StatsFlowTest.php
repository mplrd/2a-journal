<?php

namespace Tests\Integration\Stats;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class StatsFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $accessToken;
    private int $userId;
    private int $accountId;
    private int $accountId2;

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
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
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
            'email' => 'stats@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'stats@test.com']);
        $this->userId = (int) $stmt->fetchColumn();

        // Create two accounts
        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Account 1',
            'account_type' => 'BROKER_DEMO',
        ]));
        $this->accountId = (int) $response->getBody()['data']['id'];

        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Account 2',
            'account_type' => 'BROKER_LIVE',
        ]));
        $this->accountId2 = (int) $response->getBody()['data']['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
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

    private function createAndCloseTrade(int $accountId, float $exitPrice, string $exitType = 'TP'): void
    {
        // Create trade
        $response = $this->router->dispatch($this->authRequest('POST', '/trades', [
            'account_id' => $accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => 18500,
            'size' => 1,
            'setup' => ['Breakout'],
            'sl_points' => 50,
            'opened_at' => '2026-01-15 10:00:00',
        ]));
        $tradeId = $response->getBody()['data']['id'];

        // Close trade
        $this->router->dispatch($this->authRequest('POST', "/trades/{$tradeId}/close", [
            'exit_price' => $exitPrice,
            'exit_size' => 1,
            'exit_type' => $exitType,
        ]));
    }

    // ── Overview ────────────────────────────────────────────────

    public function testOverviewReturns200WithStructure(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');
        $this->createAndCloseTrade($this->accountId, 18400, 'SL');

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/overview'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('overview', $body['data']);
        $this->assertArrayHasKey('recent_trades', $body['data']);
        $this->assertSame(2, $body['data']['overview']['total_trades']);
        $this->assertArrayHasKey('win_rate', $body['data']['overview']);
        $this->assertArrayHasKey('profit_factor', $body['data']['overview']);
    }

    public function testOverviewRequiresAuth(): void
    {
        $request = Request::create('GET', '/stats/overview');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testOverviewFiltersByAccount(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');
        $this->createAndCloseTrade($this->accountId2, 18400, 'SL');

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/stats/overview', [], ['account_id' => $this->accountId])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $body['data']['overview']['total_trades']);
    }

    // ── Charts ──────────────────────────────────────────────────

    public function testChartsReturns200WithStructure(): void
    {
        $this->createAndCloseTrade($this->accountId, 18600, 'TP');

        $response = $this->router->dispatch($this->authRequest('GET', '/stats/charts'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('cumulative_pnl', $body['data']);
        $this->assertArrayHasKey('win_loss', $body['data']);
        $this->assertArrayHasKey('pnl_by_symbol', $body['data']);
    }

    // ── Empty state ─────────────────────────────────────────────

    public function testOverviewEmptyState(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/stats/overview'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['data']['overview']['total_trades']);
        $this->assertCount(0, $body['data']['recent_trades']);
    }
}
