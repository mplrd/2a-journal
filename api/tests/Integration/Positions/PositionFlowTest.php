<?php

namespace Tests\Integration\Positions;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class PositionFlowTest extends TestCase
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
            'email' => 'position@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'position@test.com']);
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

    private function insertPosition(array $overrides = []): int
    {
        $data = array_merge([
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
        ], $overrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, sl_price, position_type)
             VALUES (:user_id, :account_id, :direction, :symbol, :entry_price, :size, :setup, :sl_points, :sl_price, :position_type)'
        );
        $stmt->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListPositionsEmpty(): void
    {
        $response = $this->router->dispatch($this->authRequest('GET', '/positions'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(0, $body['data']);
    }

    public function testListPositionsReturnsOwned(): void
    {
        $this->insertPosition(['symbol' => 'NASDAQ']);
        $this->insertPosition(['symbol' => 'DAX']);

        $response = $this->router->dispatch($this->authRequest('GET', '/positions'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
    }

    public function testListPositionsWithFilters(): void
    {
        $this->insertPosition(['symbol' => 'NASDAQ', 'position_type' => 'TRADE']);
        $this->insertPosition(['symbol' => 'DAX', 'position_type' => 'ORDER']);

        $response = $this->router->dispatch(
            $this->authRequest('GET', '/positions', [], ['position_type' => 'TRADE'])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('NASDAQ', $body['data'][0]['symbol']);
    }

    // ── Show ─────────────────────────────────────────────────────

    public function testShowPositionSuccess(): void
    {
        $positionId = $this->insertPosition();

        $response = $this->router->dispatch($this->authRequest('GET', "/positions/{$positionId}"));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('NASDAQ', $body['data']['symbol']);
        $this->assertSame('BUY', $body['data']['direction']);
    }

    public function testShowPositionNotFound(): void
    {
        $request = $this->authRequest('GET', '/positions/99999');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('positions.error.not_found', $e->getMessageKey());
        }
    }

    public function testShowPositionForbidden(): void
    {
        $positionId = $this->insertPosition();

        // Register another user
        $response = $this->router->dispatch(
            Request::create('POST', '/auth/register', ['email' => 'other@test.com', 'password' => 'Test1234'])
        );
        $otherToken = $response->getBody()['data']['access_token'];

        $request = Request::create('GET', "/positions/{$positionId}", [], [], [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ── Update ──────────────────────────────────────────────────

    public function testUpdatePositionSuccess(): void
    {
        $positionId = $this->insertPosition();

        $response = $this->router->dispatch($this->authRequest('PUT', "/positions/{$positionId}", [
            'entry_price' => 19000,
            'sl_points' => 60,
        ]));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals(19000, (float) $body['data']['entry_price']);
        // BUY: sl_price = 19000 - 60 = 18940
        $this->assertEquals(18940, (float) $body['data']['sl_price']);
    }

    public function testUpdatePositionValidationError(): void
    {
        $positionId = $this->insertPosition();

        $request = $this->authRequest('PUT', "/positions/{$positionId}", [
            'entry_price' => -100,
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('VALIDATION_ERROR', $e->getErrorCode());
        }
    }

    // ── Delete ──────────────────────────────────────────────────

    public function testDeletePositionSuccess(): void
    {
        $positionId = $this->insertPosition();

        $response = $this->router->dispatch($this->authRequest('DELETE', "/positions/{$positionId}"));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        // Verify it's gone
        try {
            $this->router->dispatch($this->authRequest('GET', "/positions/{$positionId}"));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ── Transfer ────────────────────────────────────────────────

    public function testTransferPositionSuccess(): void
    {
        $positionId = $this->insertPosition();

        // Create second account
        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Second Account',
            'account_type' => 'BROKER',
            'mode' => 'LIVE',
        ]));
        $newAccountId = (int) $response->getBody()['data']['id'];

        $response = $this->router->dispatch(
            $this->authRequest('POST', "/positions/{$positionId}/transfer", ['account_id' => $newAccountId])
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEquals($newAccountId, $body['data']['account_id']);
    }

    public function testTransferPositionForbiddenTargetAccount(): void
    {
        $positionId = $this->insertPosition();

        // Register another user and create their account
        $response = $this->router->dispatch(
            Request::create('POST', '/auth/register', ['email' => 'other2@test.com', 'password' => 'Test1234'])
        );
        $otherToken = $response->getBody()['data']['access_token'];

        $otherResponse = $this->router->dispatch(
            Request::create('POST', '/accounts', ['name' => 'Other', 'account_type' => 'BROKER', 'mode' => 'DEMO'], [], [
                'Authorization' => "Bearer {$otherToken}",
            ])
        );
        $otherAccountId = (int) $otherResponse->getBody()['data']['id'];

        // Try to transfer to other user's account
        $request = $this->authRequest('POST', "/positions/{$positionId}/transfer", [
            'account_id' => $otherAccountId,
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ── History ──────────────────────────────────────────────────

    public function testHistoryEmptyByDefault(): void
    {
        $positionId = $this->insertPosition();

        $response = $this->router->dispatch($this->authRequest('GET', "/positions/{$positionId}/history"));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $body['data']);
    }

    public function testHistoryAfterTransfer(): void
    {
        $positionId = $this->insertPosition();

        // Create second account and transfer
        $response = $this->router->dispatch($this->authRequest('POST', '/accounts', [
            'name' => 'Transfer Target',
            'account_type' => 'BROKER',
            'mode' => 'LIVE',
        ]));
        $newAccountId = (int) $response->getBody()['data']['id'];

        $this->router->dispatch(
            $this->authRequest('POST', "/positions/{$positionId}/transfer", ['account_id' => $newAccountId])
        );

        $response = $this->router->dispatch($this->authRequest('GET', "/positions/{$positionId}/history"));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('transferred', $body['data'][0]['new_status']);
    }
}
