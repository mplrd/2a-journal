<?php

namespace Tests\Integration\Accounts;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class AccountFlowTest extends TestCase
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
            'email' => 'account@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'account@test.com']);
        $this->userId = (int)$stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function authRequest(string $method, string $uri, array $body = []): Request
    {
        return Request::create($method, $uri, $body, [], [
            'Authorization' => "Bearer {$this->accessToken}",
        ]);
    }

    // ── Create ───────────────────────────────────────────────────

    public function testCreateAccountPropFirmSuccess(): void
    {
        $request = $this->authRequest('POST', '/accounts', [
            'name' => 'FTMO Challenge',
            'account_type' => 'PROP_FIRM',
            'stage' => 'CHALLENGE',
            'currency' => 'USD',
            'initial_capital' => 100000,
            'broker' => 'FTMO',
            'max_drawdown' => 10000,
            'daily_drawdown' => 5000,
            'profit_target' => 10000,
            'profit_split' => 80,
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('FTMO Challenge', $body['data']['name']);
        $this->assertSame('PROP_FIRM', $body['data']['account_type']);
        $this->assertSame('CHALLENGE', $body['data']['stage']);
        $this->assertSame('USD', $body['data']['currency']);
        $this->assertEquals(100000, $body['data']['initial_capital']);
        $this->assertSame('FTMO', $body['data']['broker']);
    }

    public function testCreateAccountBrokerDemoMinimalFields(): void
    {
        $request = $this->authRequest('POST', '/accounts', [
            'name' => 'Demo Account',
            'account_type' => 'BROKER_DEMO',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('BROKER_DEMO', $body['data']['account_type']);
        $this->assertNull($body['data']['stage']);
        $this->assertSame('EUR', $body['data']['currency']);
        $this->assertEquals(0, $body['data']['initial_capital']);
    }

    public function testCreateAccountValidationError(): void
    {
        $request = $this->authRequest('POST', '/accounts', [
            'name' => '',
            'account_type' => 'BROKER_DEMO',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('VALIDATION_ERROR', $e->getErrorCode());
            $this->assertSame('name', $e->getField());
        }
    }

    public function testCreateAccountInvalidType(): void
    {
        $request = $this->authRequest('POST', '/accounts', [
            'name' => 'Bad Type',
            'account_type' => 'INVALID',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('accounts.error.invalid_type', $e->getMessageKey());
        }
    }

    public function testCreateAccountStageRequiredForPropFirm(): void
    {
        $request = $this->authRequest('POST', '/accounts', [
            'name' => 'Missing Stage',
            'account_type' => 'PROP_FIRM',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('accounts.error.stage_required', $e->getMessageKey());
        }
    }

    public function testCreateAccountStageNotAllowedForBroker(): void
    {
        $request = $this->authRequest('POST', '/accounts', [
            'name' => 'Bad Stage',
            'account_type' => 'BROKER_DEMO',
            'stage' => 'CHALLENGE',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('accounts.error.stage_not_allowed', $e->getMessageKey());
        }
    }

    public function testCreateAccountRequiresAuth(): void
    {
        $request = Request::create('POST', '/accounts', [
            'name' => 'No Auth',
            'account_type' => 'BROKER_DEMO',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListAccountsEmpty(): void
    {
        $request = $this->authRequest('GET', '/accounts');
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(0, $body['data']);
    }

    public function testListAccountsReturnsOnlyOwnAccounts(): void
    {
        // Create two accounts for current user
        $this->authRequest('POST', '/accounts', ['name' => 'A1', 'account_type' => 'BROKER_DEMO']);
        $this->router->dispatch($this->authRequest('POST', '/accounts', ['name' => 'A1', 'account_type' => 'BROKER_DEMO']));
        $this->router->dispatch($this->authRequest('POST', '/accounts', ['name' => 'A2', 'account_type' => 'BROKER_LIVE']));

        $request = $this->authRequest('GET', '/accounts');
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
    }

    // ── Show ─────────────────────────────────────────────────────

    public function testShowAccountSuccess(): void
    {
        // Create an account
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Show Me',
            'account_type' => 'BROKER_DEMO',
        ]));
        $accountId = $createResponse->getBody()['data']['id'];

        $request = $this->authRequest('GET', "/accounts/{$accountId}");
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Show Me', $body['data']['name']);
    }

    public function testShowAccountNotFound(): void
    {
        $request = $this->authRequest('GET', '/accounts/99999');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('accounts.error.not_found', $e->getMessageKey());
        }
    }

    // ── Update ───────────────────────────────────────────────────

    public function testUpdateAccountSuccess(): void
    {
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Before Update',
            'account_type' => 'BROKER_DEMO',
        ]));
        $accountId = $createResponse->getBody()['data']['id'];

        $request = $this->authRequest('PUT', "/accounts/{$accountId}", [
            'name' => 'After Update',
            'account_type' => 'PROP_FIRM',
            'stage' => 'FUNDED',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('After Update', $body['data']['name']);
        $this->assertSame('PROP_FIRM', $body['data']['account_type']);
        $this->assertSame('FUNDED', $body['data']['stage']);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function testDeleteAccountSuccess(): void
    {
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'To Delete',
            'account_type' => 'BROKER_DEMO',
        ]));
        $accountId = $createResponse->getBody()['data']['id'];

        $request = $this->authRequest('DELETE', "/accounts/{$accountId}");
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        // Verify it's no longer visible
        try {
            $this->router->dispatch($this->authRequest('GET', "/accounts/{$accountId}"));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ── Ownership ────────────────────────────────────────────────

    public function testCannotAccessOtherUsersAccount(): void
    {
        // Create an account for current user
        $createResponse = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'My Account',
            'account_type' => 'BROKER_DEMO',
        ]));
        $accountId = $createResponse->getBody()['data']['id'];

        // Register another user
        $request = Request::create('POST', '/auth/register', [
            'email' => 'other@test.com',
            'password' => 'Test1234',
        ]);
        $otherResponse = $this->router->dispatch($request);
        $otherToken = $otherResponse->getBody()['data']['access_token'];

        // Try to access with the other user
        $request = Request::create('GET', "/accounts/{$accountId}", [], [], [
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
