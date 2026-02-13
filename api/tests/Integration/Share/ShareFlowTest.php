<?php

namespace Tests\Integration\Share;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class ShareFlowTest extends TestCase
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
            'email' => 'share@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'share@test.com']);
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

    private function createOrder(): int
    {
        $response = $this->router->dispatch($this->authRequest('POST', '/orders', [
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => 18240,
            'size' => 1,
            'setup' => 'Touchette haut de zone weekly',
            'sl_points' => 50,
            'targets' => json_encode([['points' => 110, 'size' => 1, 'price' => 18350]]),
        ]));
        $order = $response->getBody()['data'];
        return (int) $order['position_id'];
    }

    private function createTrade(): int
    {
        $response = $this->router->dispatch($this->authRequest('POST', '/trades', [
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => 18240,
            'size' => 1,
            'setup' => 'Divergence haussière sur RSI',
            'sl_points' => 50,
            'targets' => json_encode([['points' => 110, 'size' => 1, 'price' => 18350]]),
            'opened_at' => '2026-02-13 10:00:00',
        ]));
        $trade = $response->getBody()['data'];
        return (int) $trade['position_id'];
    }

    // ── Share text (with emojis) ──────────────────────────────────

    public function testShareTextForOrder(): void
    {
        $positionId = $this->createOrder();

        $response = $this->router->dispatch(
            $this->authRequest('GET', "/positions/{$positionId}/share/text")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('text', $body['data']);

        $text = $body['data']['text'];
        $this->assertStringContainsString('📈 BUY NASDAQ @ 18240', $text);
        $this->assertStringContainsString('🎯 TP: 18350 (+110 pts)', $text);
        $this->assertStringContainsString('🛑 SL: 18190 (-50 pts)', $text);
        $this->assertStringContainsString('💬 Touchette haut de zone weekly', $text);
    }

    public function testShareTextForTrade(): void
    {
        $positionId = $this->createTrade();

        $response = $this->router->dispatch(
            $this->authRequest('GET', "/positions/{$positionId}/share/text")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $text = $body['data']['text'];
        $this->assertStringContainsString('📈 BUY NASDAQ @ 18240', $text);
        $this->assertStringContainsString('💬 Divergence haussière sur RSI', $text);
    }

    public function testShareTextForClosedTrade(): void
    {
        // Create and close a trade
        $response = $this->router->dispatch($this->authRequest('POST', '/trades', [
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => 18240,
            'size' => 1,
            'setup' => 'Breakout',
            'sl_points' => 50,
            'opened_at' => '2026-02-13 10:00:00',
        ]));
        $trade = $response->getBody()['data'];
        $positionId = (int) $trade['position_id'];
        $tradeId = (int) $trade['id'];

        // Close the trade
        $this->router->dispatch($this->authRequest('POST', "/trades/{$tradeId}/close", [
            'exit_price' => 18350,
            'exit_size' => 1,
            'exit_type' => 'TP',
            'exited_at' => '2026-02-13 12:30:00',
        ]));

        $response = $this->router->dispatch(
            $this->authRequest('GET', "/positions/{$positionId}/share/text")
        );
        $body = $response->getBody();
        $text = $body['data']['text'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('→ 18350', $text);
        $this->assertStringContainsString('PnL:', $text);
        $this->assertStringContainsString('Exit: TP', $text);
        $this->assertStringContainsString('R/R:', $text);
    }

    // ── Share text plain (no emojis) ──────────────────────────────

    public function testShareTextPlainForOrder(): void
    {
        $positionId = $this->createOrder();

        $response = $this->router->dispatch(
            $this->authRequest('GET', "/positions/{$positionId}/share/text-plain")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $text = $body['data']['text'];
        $this->assertStringContainsString('BUY NASDAQ @ 18240', $text);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1F9FF}]/u', $text);
    }

    // ── Auth & ownership ──────────────────────────────────────────

    public function testShareRequiresAuth(): void
    {
        $positionId = $this->createOrder();

        try {
            $this->router->dispatch(
                Request::create('GET', "/positions/{$positionId}/share/text")
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testShareOtherUserPositionForbidden(): void
    {
        $positionId = $this->createOrder();

        // Register another user
        $response = $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'other@test.com',
            'password' => 'Test1234',
        ]));
        $otherToken = $response->getBody()['data']['access_token'];

        try {
            $this->router->dispatch(
                Request::create('GET', "/positions/{$positionId}/share/text", [], [], [
                    'Authorization' => "Bearer {$otherToken}",
                ])
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testShareNonExistentPosition(): void
    {
        try {
            $this->router->dispatch(
                $this->authRequest('GET', '/positions/99999/share/text')
            );
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
