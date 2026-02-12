<?php

namespace Tests\Integration\Orders;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class OrderFlowTest extends TestCase
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
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM partial_exits');
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
            'email' => 'order@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'order@test.com']);
        $this->userId = (int) $stmt->fetchColumn();

        // Create an account
        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Test Account',
            'account_type' => 'BROKER',
            'mode' => 'DEMO',
        ]));
        $this->accountId = (int) $response->getBody()['data']['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM partial_exits');
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

    private function validOrderData(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => 18500,
            'size' => 1,
            'setup' => 'Breakout',
            'sl_points' => 50,
        ], $overrides);
    }

    private function createOrder(array $overrides = []): array
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/orders', $this->validOrderData($overrides))
        );
        return $response->getBody()['data'];
    }

    // ── Create ──────────────────────────────────────────────────

    public function testCreateOrderSuccess(): void
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/orders', $this->validOrderData())
        );
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('PENDING', $body['data']['status']);
        $this->assertSame('NASDAQ', $body['data']['symbol']);
        $this->assertSame('BUY', $body['data']['direction']);
        $this->assertSame('ORDER', $body['data']['position_type']);

        // Verify position was created
        $positionId = (int) $body['data']['position_id'];
        $stmt = $this->pdo->prepare('SELECT * FROM positions WHERE id = :id');
        $stmt->execute(['id' => $positionId]);
        $position = $stmt->fetch();
        $this->assertSame('ORDER', $position['position_type']);

        // Verify SL price calculated (BUY: 18500 - 50 = 18450)
        $this->assertEquals(18450, (float) $body['data']['sl_price']);
    }

    public function testCreateOrderWithAllFields(): void
    {
        $response = $this->router->dispatch(
            $this->authRequest('POST', '/orders', $this->validOrderData([
                'be_points' => 30,
                'be_size' => 0.5,
                'notes' => 'Test notes',
                'targets' => [['id' => 'tp1', 'label' => 'TP1', 'points' => 100, 'size' => 0.5]],
                'expires_at' => '2030-12-31 23:59:59',
            ]))
        );
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('2030-12-31 23:59:59', $body['data']['expires_at']);
        // BUY: be_price = 18500 + 30 = 18530
        $this->assertEquals(18530, (float) $body['data']['be_price']);
    }

    public function testCreateOrderValidationError(): void
    {
        $request = $this->authRequest('POST', '/orders', [
            'account_id' => $this->accountId,
            // Missing required fields
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('VALIDATION_ERROR', $e->getErrorCode());
        }
    }

    public function testCreateOrderRequiresAuth(): void
    {
        $request = Request::create('POST', '/orders', $this->validOrderData());

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── List ────────────────────────────────────────────────────

    public function testListOrdersEmpty(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/orders'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(0, $body['data']);
    }

    public function testListOrdersReturnsOwned(): void
    {
        $this->createOrder(['symbol' => 'NASDAQ']);
        $this->createOrder(['symbol' => 'DAX']);

        $response = $this->router->dispatch($this->authRequest('GET', '/orders'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
    }

    public function testListOrdersWithFilters(): void
    {
        $this->createOrder(['symbol' => 'NASDAQ']);
        $this->createOrder(['symbol' => 'DAX']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/orders', [], ['symbol' => 'NASDAQ'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('NASDAQ', $body['data'][0]['symbol']);
    }

    // ── Show ────────────────────────────────────────────────────

    public function testShowOrderSuccess(): void
    {
        $order = $this->createOrder();

        $response = $this->router->dispatch($this->authRequest('GET', "/orders/{$order['id']}"));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('NASDAQ', $body['data']['symbol']);
    }

    public function testShowOrderNotFound(): void
    {
        $request = $this->authRequest('GET', '/orders/99999');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('orders.error.not_found', $e->getMessageKey());
        }
    }

    public function testShowOrderForbidden(): void
    {
        $order = $this->createOrder();

        // Register another user
        $response = $this->router->dispatch(
            Request::create('POST', '/auth/register', ['email' => 'other@test.com', 'password' => 'Test1234'])
        );
        $otherToken = $response->getBody()['data']['access_token'];

        $request = Request::create('GET', "/orders/{$order['id']}", [], [], [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ── Cancel ──────────────────────────────────────────────────

    public function testCancelOrderSuccess(): void
    {
        $order = $this->createOrder();

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/orders/{$order['id']}/cancel")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('CANCELLED', $body['data']['status']);

        // Verify status history
        $stmt = $this->pdo->prepare(
            "SELECT * FROM status_history WHERE entity_type = 'ORDER' AND entity_id = :id ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['id' => $order['id']]);
        $history = $stmt->fetch();
        $this->assertSame('CANCELLED', $history['new_status']);
        $this->assertSame('PENDING', $history['previous_status']);
    }

    public function testCancelOrderAlreadyCancelled(): void
    {
        $order = $this->createOrder();

        // Cancel once
        $this->router->dispatch($this->authRequest('POST', "/orders/{$order['id']}/cancel"));

        // Cancel again
        try {
            $this->router->dispatch($this->authRequest('POST', "/orders/{$order['id']}/cancel"));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('orders.error.not_pending', $e->getMessageKey());
        }
    }

    // ── Execute ─────────────────────────────────────────────────

    public function testExecuteOrderSuccess(): void
    {
        $order = $this->createOrder();

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/orders/{$order['id']}/execute")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('EXECUTED', $body['data']['status']);
        $this->assertArrayHasKey('trade_id', $body['data']);

        // Verify trade was created
        $tradeId = $body['data']['trade_id'];
        $stmt = $this->pdo->prepare('SELECT * FROM trades WHERE id = :id');
        $stmt->execute(['id' => $tradeId]);
        $trade = $stmt->fetch();
        $this->assertNotFalse($trade);
        $this->assertSame('OPEN', $trade['status']);
        $this->assertSame((int) $order['position_id'], (int) $trade['position_id']);
        $this->assertSame((int) $order['id'], (int) $trade['source_order_id']);
        $this->assertEquals((float) $order['size'], (float) $trade['remaining_size']);

        // Verify position type changed to TRADE
        $stmt = $this->pdo->prepare('SELECT position_type FROM positions WHERE id = :id');
        $stmt->execute(['id' => $order['position_id']]);
        $this->assertSame('TRADE', $stmt->fetchColumn());

        // Verify status history has both ORDER→EXECUTED and TRADE→OPEN
        $stmt = $this->pdo->prepare(
            "SELECT * FROM status_history WHERE entity_type = 'TRADE' AND entity_id = :id"
        );
        $stmt->execute(['id' => $tradeId]);
        $tradeHistory = $stmt->fetch();
        $this->assertNotFalse($tradeHistory);
        $this->assertSame('OPEN', $tradeHistory['new_status']);
    }

    public function testExecuteOrderAlreadyExecuted(): void
    {
        $order = $this->createOrder();

        // Execute once
        $this->router->dispatch($this->authRequest('POST', "/orders/{$order['id']}/execute"));

        // Execute again
        try {
            $this->router->dispatch($this->authRequest('POST', "/orders/{$order['id']}/execute"));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('orders.error.not_pending', $e->getMessageKey());
        }
    }

    // ── Delete ──────────────────────────────────────────────────

    public function testDeleteOrderSuccess(): void
    {
        $order = $this->createOrder();

        $response = $this->router->dispatch(
            $this->authRequest('DELETE', "/orders/{$order['id']}")
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        // Verify it's gone
        try {
            $this->router->dispatch($this->authRequest('GET', "/orders/{$order['id']}"));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        // Verify position is also gone (CASCADE)
        $stmt = $this->pdo->prepare('SELECT * FROM positions WHERE id = :id');
        $stmt->execute(['id' => $order['position_id']]);
        $this->assertFalse($stmt->fetch());
    }
}
