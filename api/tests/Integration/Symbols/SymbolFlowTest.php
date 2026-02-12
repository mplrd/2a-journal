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
    private int $userId;

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
        $this->pdo->exec('DELETE FROM symbols');
        $this->pdo->exec('DELETE FROM rate_limits');
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
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'symbol@test.com']);
        $this->userId = (int)$stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM symbols');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function authRequest(string $method, string $uri, array $body = [], array $query = []): Request
    {
        return Request::create($method, $uri, $body, $query, [
            'Authorization' => "Bearer {$this->accessToken}",
        ]);
    }

    // ── Registration seeds symbols ───────────────────────────────

    public function testRegistrationSeedsDefaultSymbols(): void
    {
        $request = $this->authRequest('GET', '/symbols');
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(6, $body['data']);

        $codes = array_column($body['data'], 'code');
        $this->assertContains('US100.CASH', $codes);
        $this->assertContains('DE40.CASH', $codes);
        $this->assertContains('US500.CASH', $codes);
        $this->assertContains('FRA40.CASH', $codes);
        $this->assertContains('EURUSD', $codes);
        $this->assertContains('BTCUSD', $codes);
    }

    // ── Create ───────────────────────────────────────────────────

    public function testCreateSymbolSuccess(): void
    {
        $request = $this->authRequest('POST', '/symbols', [
            'code' => 'GOLD',
            'name' => 'Gold',
            'type' => 'COMMODITY',
            'point_value' => 100.0,
            'currency' => 'USD',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('GOLD', $body['data']['code']);
        $this->assertSame('Gold', $body['data']['name']);
        $this->assertSame('COMMODITY', $body['data']['type']);
        $this->assertEquals(100.0, $body['data']['point_value']);
        $this->assertSame('USD', $body['data']['currency']);
    }

    public function testCreateSymbolValidationError(): void
    {
        $request = $this->authRequest('POST', '/symbols', [
            'code' => '',
            'name' => 'Missing Code',
            'type' => 'INDEX',
            'point_value' => 1.0,
            'currency' => 'USD',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('code', $e->getField());
        }
    }

    public function testCreateSymbolDuplicateCode(): void
    {
        $request = $this->authRequest('POST', '/symbols', [
            'code' => 'US100.CASH',
            'name' => 'Duplicate',
            'type' => 'INDEX',
            'point_value' => 1.0,
            'currency' => 'USD',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('symbols.error.duplicate_code', $e->getMessageKey());
        }
    }

    public function testCreateSymbolRequiresAuth(): void
    {
        $request = Request::create('POST', '/symbols', [
            'code' => 'GOLD',
            'name' => 'Gold',
            'type' => 'COMMODITY',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListSymbolsReturnsPagination(): void
    {
        $request = $this->authRequest('GET', '/symbols', [], ['per_page' => '2']);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $this->assertSame(6, $body['meta']['total']);
        $this->assertSame(3, $body['meta']['total_pages']);
    }

    // ── Show ─────────────────────────────────────────────────────

    public function testShowSymbolSuccess(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/symbols'));
        $symbolId = $listResponse->getBody()['data'][0]['id'];

        $request = $this->authRequest('GET', "/symbols/{$symbolId}");
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($body['data']['code']);
    }

    public function testShowSymbolNotFound(): void
    {
        $request = $this->authRequest('GET', '/symbols/99999');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('symbols.error.not_found', $e->getMessageKey());
        }
    }

    // ── Update ───────────────────────────────────────────────────

    public function testUpdateSymbolSuccess(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/symbols'));
        $symbol = $listResponse->getBody()['data'][0];
        $symbolId = $symbol['id'];

        $request = $this->authRequest('PUT', "/symbols/{$symbolId}", [
            'code' => $symbol['code'],
            'name' => 'Updated Name',
            'type' => $symbol['type'],
            'point_value' => 99.0,
            'currency' => $symbol['currency'],
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Updated Name', $body['data']['name']);
        $this->assertEquals(99.0, $body['data']['point_value']);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function testDeleteSymbolSuccess(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/symbols'));
        $symbolId = $listResponse->getBody()['data'][0]['id'];

        $request = $this->authRequest('DELETE', "/symbols/{$symbolId}");
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        try {
            $this->router->dispatch($this->authRequest('GET', "/symbols/{$symbolId}"));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ── Ownership ────────────────────────────────────────────────

    public function testCannotAccessOtherUsersSymbol(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/symbols'));
        $symbolId = $listResponse->getBody()['data'][0]['id'];

        // Register another user
        $request = Request::create('POST', '/auth/register', [
            'email' => 'other@test.com',
            'password' => 'Test1234',
        ]);
        $otherResponse = $this->router->dispatch($request);
        $otherToken = $otherResponse->getBody()['data']['access_token'];

        $request = Request::create('GET', "/symbols/{$symbolId}", [], [], [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('FORBIDDEN', $e->getErrorCode());
        }
    }
}
