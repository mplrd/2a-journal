<?php

namespace Tests\Integration\Trades;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class TradeFlowTest extends TestCase
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
            'email' => 'trade@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'trade@test.com']);
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

    private function validTradeData(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => 18500,
            'size' => 2,
            'setup' => 'Breakout',
            'sl_points' => 50,
            'opened_at' => '2026-01-15 10:00:00',
        ], $overrides);
    }

    private function createTrade(array $overrides = []): array
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/trades', $this->validTradeData($overrides))
        );
        return $response->getBody()['data'];
    }

    // ── Create ──────────────────────────────────────────────────

    public function testCreateTradeSuccess(): void
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/trades', $this->validTradeData())
        );
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('OPEN', $body['data']['status']);
        $this->assertSame('NASDAQ', $body['data']['symbol']);
        $this->assertSame('BUY', $body['data']['direction']);
        $this->assertSame('TRADE', $body['data']['position_type']);
        $this->assertEquals(2, (float) $body['data']['remaining_size']);

        // Verify SL price calculated (BUY: 18500 - 50 = 18450)
        $this->assertEquals(18450, (float) $body['data']['sl_price']);
    }

    public function testCreateTradeWithAllFields(): void
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/trades', $this->validTradeData([
                'be_points' => 30,
                'be_size' => 0.5,
                'notes' => 'Test notes',
                'targets' => [['id' => 'tp1', 'label' => 'TP1', 'points' => 100, 'size' => 0.5]],
            ]))
        );
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        // BUY: be_price = 18500 + 30 = 18530
        $this->assertEquals(18530, (float) $body['data']['be_price']);
    }

    public function testCreateTradeValidationError(): void
    {
        $request = $this->authRequest('POST', '/trades', [
            'account_id' => $this->accountId,
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testCreateTradeRequiresAuth(): void
    {
        $request = Request::create('POST', '/trades', $this->validTradeData());

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── List ────────────────────────────────────────────────────

    public function testListTradesEmpty(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/trades'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(0, $body['data']);
    }

    public function testListTradesReturnsOwned(): void
    {
        $this->createTrade(['symbol' => 'NASDAQ']);
        $this->createTrade(['symbol' => 'DAX']);

        $response = $this->router->dispatch($this->authRequest('GET', '/trades'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $this->assertArrayHasKey('meta', $body);
        $this->assertSame(2, $body['meta']['total']);
    }

    public function testListTradesWithFilters(): void
    {
        $this->createTrade(['symbol' => 'NASDAQ']);
        $this->createTrade(['symbol' => 'DAX']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/trades', [], ['symbol' => 'NASDAQ'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('NASDAQ', $body['data'][0]['symbol']);
    }

    // ── Show ────────────────────────────────────────────────────

    public function testShowTradeSuccess(): void
    {
        $trade = $this->createTrade();

        $response = $this->router->dispatch($this->authRequest('GET', "/trades/{$trade['id']}"));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('NASDAQ', $body['data']['symbol']);
        $this->assertArrayHasKey('partial_exits', $body['data']);
    }

    public function testShowTradeNotFound(): void
    {
        $request = $this->authRequest('GET', '/trades/99999');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('trades.error.not_found', $e->getMessageKey());
        }
    }

    // ── Close (full lifecycle) ──────────────────────────────────

    public function testLifecycleCreatePartialCloseFinalClose(): void
    {
        // Create trade: BUY 2 lots NASDAQ @ 18500, SL 50pts
        $trade = $this->createTrade();
        $this->assertSame('OPEN', $trade['status']);
        $this->assertEquals(2.0, (float) $trade['remaining_size']);

        // Partial close: exit 1 lot at 18600 → SECURED
        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );
        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('SECURED', $body['data']['status']);
        $this->assertEquals(1.0, (float) $body['data']['remaining_size']);

        // Final close: exit remaining 1 lot at 18650 → CLOSED
        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18650,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );
        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('CLOSED', $body['data']['status']);
        $this->assertEquals(0, (float) $body['data']['remaining_size']);

        // Verify PnL: (18600-18500)*1 + (18650-18500)*1 = 100 + 150 = 250
        $this->assertEquals(250.0, (float) $body['data']['pnl']);

        // Verify avg exit price: (18600*1 + 18650*1) / 2 = 18625
        $this->assertEquals(18625.0, (float) $body['data']['avg_exit_price']);
    }

    public function testCloseTradeCalculatesPnlBuy(): void
    {
        $trade = $this->createTrade(['size' => 1]);

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );
        $body = $response->getBody();

        // BUY: PnL = (18600 - 18500) * 1 * 1 = 100
        $this->assertEquals(100.0, (float) $body['data']['pnl']);
        $this->assertSame('CLOSED', $body['data']['status']);
    }

    public function testCloseTradeCalculatesPnlSell(): void
    {
        $trade = $this->createTrade(['direction' => 'SELL', 'size' => 1]);

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18400,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );
        $body = $response->getBody();

        // SELL: PnL = (18400 - 18500) * 1 * -1 = 100
        $this->assertEquals(100.0, (float) $body['data']['pnl']);
    }

    public function testCloseTradeAlreadyClosed(): void
    {
        $trade = $this->createTrade(['size' => 1]);

        // Close the trade
        $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );

        // Try to close again
        try {
            $this->router->dispatch(
                $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                    'exit_price' => 18600,
                    'exit_size' => 1,
                    'exit_type' => 'TP',
                ])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('trades.error.already_closed', $e->getMessageKey());
        }
    }

    public function testCloseTradeStatusHistory(): void
    {
        $trade = $this->createTrade(['size' => 1]);

        // Close fully
        $this->router->dispatch(
            $this->authRequest('POST', "/trades/{$trade['id']}/close", [
                'exit_price' => 18600,
                'exit_size' => 1,
                'exit_type' => 'TP',
            ])
        );

        // Verify status history: OPEN → ... → CLOSED
        $stmt = $this->pdo->prepare(
            "SELECT * FROM status_history WHERE entity_type = 'TRADE' AND entity_id = :id ORDER BY id"
        );
        $stmt->execute(['id' => $trade['id']]);
        $history = $stmt->fetchAll();

        // First: null → OPEN (from create), Last: OPEN → CLOSED
        $this->assertGreaterThanOrEqual(2, count($history));
        $this->assertSame('OPEN', $history[0]['new_status']);
        $last = end($history);
        $this->assertSame('CLOSED', $last['new_status']);
    }

    // ── Delete ──────────────────────────────────────────────────

    public function testDeleteTradeSuccess(): void
    {
        $trade = $this->createTrade();

        $response = $this->router->dispatch(
            $this->authRequest('DELETE', "/trades/{$trade['id']}")
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        // Verify it's gone
        try {
            $this->router->dispatch($this->authRequest('GET', "/trades/{$trade['id']}"));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // Verify position is also gone (CASCADE)
        $stmt = $this->pdo->prepare('SELECT * FROM positions WHERE id = :id');
        $stmt->execute(['id' => $trade['position_id']]);
        $this->assertFalse($stmt->fetch());
    }

    public function testDeleteTradeForbidden(): void
    {
        $trade = $this->createTrade();

        // Register another user
        $response = $this->router->dispatch(
            Request::create('POST', '/auth/register', ['email' => 'other@test.com', 'password' => 'Test1234'])
        );
        $otherToken = $response->getBody()['data']['access_token'];

        $request = Request::create('DELETE', "/trades/{$trade['id']}", [], [], [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
