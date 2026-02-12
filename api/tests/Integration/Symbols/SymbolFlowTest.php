<?php

namespace Tests\Integration\Symbols;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class SymbolFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $accessToken;

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
            'email' => 'symbol@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $this->accessToken = $response->getBody()['data']['access_token'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function authRequest(string $method, string $uri): Request
    {
        return Request::create($method, $uri, [], [], [
            'Authorization' => "Bearer {$this->accessToken}",
        ]);
    }

    public function testListSymbolsRequiresAuth(): void
    {
        $request = Request::create('GET', '/symbols');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testListSymbolsReturnsActiveSymbols(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/symbols'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['data']);
        $this->assertGreaterThanOrEqual(6, count($body['data']));

        $codes = array_column($body['data'], 'code');
        $this->assertContains('NASDAQ', $codes);
        $this->assertContains('EURUSD', $codes);
    }

    public function testListSymbolsReturnsExpectedFields(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/symbols'));
        $body = $response->getBody();

        $first = $body['data'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('point_value', $first);
        $this->assertArrayHasKey('currency', $first);
    }

    public function testListSymbolsExcludesInactive(): void
    {
        $this->pdo->exec("UPDATE symbols SET is_active = 0 WHERE code = 'BTCUSD'");

        $response = $this->router->dispatch($this->authRequest('GET', '/symbols'));
        $body = $response->getBody();

        $codes = array_column($body['data'], 'code');
        $this->assertNotContains('BTCUSD', $codes);

        // Restore
        $this->pdo->exec("UPDATE symbols SET is_active = 1 WHERE code = 'BTCUSD'");
    }
}
