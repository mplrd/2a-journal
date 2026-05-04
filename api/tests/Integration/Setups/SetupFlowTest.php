<?php

namespace Tests\Integration\Setups;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class SetupFlowTest extends TestCase
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
        $this->pdo->exec('DELETE FROM setups');
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
            'email' => 'setup@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];
        $this->accessToken = $data['access_token'];

        // Get user ID
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'setup@test.com']);
        $this->userId = (int)$stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM setups');
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

    // ── Registration seeds setups ────────────────────────────────

    public function testRegistrationSeedsDefaultSetups(): void
    {
        $request = $this->authRequest('GET', '/setups');
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertCount(8, $body['data']);

        $labels = array_column($body['data'], 'label');
        $this->assertContains('Breakout', $labels);
        $this->assertContains('FVG', $labels);
        $this->assertContains('OB', $labels);
        $this->assertContains('Liquidity Sweep', $labels);
        $this->assertContains('BOS', $labels);
        $this->assertContains('CHoCH', $labels);
        $this->assertContains('Supply/Demand', $labels);
        $this->assertContains('Trend Follow', $labels);
    }

    // ── Create ───────────────────────────────────────────────────

    public function testCreateSetupSuccess(): void
    {
        $request = $this->authRequest('POST', '/setups', ['label' => 'My Custom Setup']);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('My Custom Setup', $body['data']['label']);
    }

    public function testCreateSetupValidationError(): void
    {
        $request = $this->authRequest('POST', '/setups', ['label' => '']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('label', $e->getField());
        }
    }

    public function testCreateSetupDuplicateLabel(): void
    {
        $request = $this->authRequest('POST', '/setups', ['label' => 'Breakout']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('setups.error.duplicate_label', $e->getMessageKey());
        }
    }

    public function testCreateSetupRequiresAuth(): void
    {
        $request = Request::create('POST', '/setups', ['label' => 'Test']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListSetupsSuccess(): void
    {
        $request = $this->authRequest('GET', '/setups');
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['data']);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function testDeleteSetupSuccess(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/setups'));
        $setupId = $listResponse->getBody()['data'][0]['id'];

        $request = $this->authRequest('DELETE', "/setups/{$setupId}");
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        // Verify it's gone from list
        $listResponse2 = $this->router->dispatch($this->authRequest('GET', '/setups'));
        $ids = array_column($listResponse2->getBody()['data'], 'id');
        $this->assertNotContains($setupId, $ids);
    }

    public function testDeleteSetupNotFound(): void
    {
        $request = $this->authRequest('DELETE', '/setups/99999');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('setups.error.not_found', $e->getMessageKey());
        }
    }

    // ── Update label (inline edit) ───────────────────────────────

    public function testUpdateSetupLabelSuccess(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/setups'));
        $setupId = $listResponse->getBody()['data'][0]['id'];

        $request = $this->authRequest('PUT', "/setups/{$setupId}", ['label' => 'Renamed Setup']);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('Renamed Setup', $body['data']['label']);

        // Verify persisted
        $listResponse2 = $this->router->dispatch($this->authRequest('GET', '/setups'));
        $labels = array_column($listResponse2->getBody()['data'], 'label');
        $this->assertContains('Renamed Setup', $labels);
    }

    public function testUpdateSetupLabelRejectsDuplicate(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/setups'));
        $setupId = $listResponse->getBody()['data'][0]['id'];

        // Try to rename to "FVG" which is another seeded setup
        $request = $this->authRequest('PUT', "/setups/{$setupId}", ['label' => 'FVG']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('setups.error.duplicate_label', $e->getMessageKey());
        }
    }

    public function testUpdateSetupLabelRejectsEmpty(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/setups'));
        $setupId = $listResponse->getBody()['data'][0]['id'];

        $request = $this->authRequest('PUT', "/setups/{$setupId}", ['label' => '   ']);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('setups.error.field_required', $e->getMessageKey());
        }
    }

    // ── Ownership ────────────────────────────────────────────────

    public function testCannotDeleteOtherUsersSetup(): void
    {
        $listResponse = $this->router->dispatch($this->authRequest('GET', '/setups'));
        $setupId = $listResponse->getBody()['data'][0]['id'];

        // Register another user
        $request = Request::create('POST', '/auth/register', [
            'email' => 'other@test.com',
            'password' => 'Test1234',
        ]);
        $otherResponse = $this->router->dispatch($request);
        $otherToken = $otherResponse->getBody()['data']['access_token'];

        $request = Request::create('DELETE', "/setups/{$setupId}", [], [], [
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
