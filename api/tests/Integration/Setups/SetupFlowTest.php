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
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
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
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM setups');
        $this->pdo->exec('DELETE FROM symbols');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function findSetupIdByLabel(string $label): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM setups WHERE user_id = :uid AND label = :label AND deleted_at IS NULL');
        $stmt->execute(['uid' => $this->userId, 'label' => $label]);
        return (int)$stmt->fetchColumn();
    }

    private function insertAccount(): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type) VALUES (:uid, 'Test Account', 'BROKER_DEMO')"
        );
        $stmt->execute(['uid' => $this->userId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertPositionWithSetup(int $accountId, array $setupLabels): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, sl_price, position_type)
             VALUES (:uid, :acc, 'BUY', 'NASDAQ', '18500.00000', '1.00000', :setup, '50.00', '18450.00000', 'TRADE')"
        );
        $stmt->execute([
            'uid' => $this->userId,
            'acc' => $accountId,
            'setup' => json_encode($setupLabels),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function fetchPositionSetup(int $positionId): array
    {
        $stmt = $this->pdo->prepare('SELECT setup FROM positions WHERE id = :id');
        $stmt->execute(['id' => $positionId]);
        return json_decode((string)$stmt->fetchColumn(), true);
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

    // ── Rename: propagation to positions.setup + soft-deleted conflict ───

    public function testUpdateSetupRenamePropagatesToPositions(): void
    {
        $accountId = $this->insertAccount();
        $setupId = $this->findSetupIdByLabel('Liquidity Sweep');

        // Two positions reference the old label, one doesn't
        $pos1 = $this->insertPositionWithSetup($accountId, ['Liquidity Sweep']);
        $pos2 = $this->insertPositionWithSetup($accountId, ['Breakout', 'Liquidity Sweep']);
        $pos3 = $this->insertPositionWithSetup($accountId, ['Breakout']);

        $response = $this->router->dispatch(
            $this->authRequest('PUT', "/setups/{$setupId}", ['label' => 'Prise de liquidité'])
        );
        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame(['Prise de liquidité'], $this->fetchPositionSetup($pos1));
        $this->assertSame(['Breakout', 'Prise de liquidité'], $this->fetchPositionSetup($pos2));
        $this->assertSame(['Breakout'], $this->fetchPositionSetup($pos3));
    }

    public function testUpdateSetupRenameSucceedsWhenSoftDeletedConflictExists(): void
    {
        // Pre-existing soft-deleted setup matching the target label.
        // Without the fix this triggers a 1062 Duplicate entry → 500.
        $this->pdo->exec(
            "INSERT INTO setups (user_id, label, category, deleted_at)
             VALUES ({$this->userId}, 'Prise de liquidité', 'pattern', NOW())"
        );
        $setupId = $this->findSetupIdByLabel('Liquidity Sweep');

        $response = $this->router->dispatch(
            $this->authRequest('PUT', "/setups/{$setupId}", ['label' => 'Prise de liquidité'])
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Prise de liquidité', $response->getBody()['data']['label']);

        // Soft-deleted row was hard-deleted to free the unique constraint
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM setups WHERE user_id = :uid AND label = :label AND deleted_at IS NOT NULL'
        );
        $stmt->execute(['uid' => $this->userId, 'label' => 'Prise de liquidité']);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testUpdateSetupRenameDoesNotTouchPositionsWhenLabelUnchanged(): void
    {
        $accountId = $this->insertAccount();
        $setupId = $this->findSetupIdByLabel('Liquidity Sweep');
        $pos = $this->insertPositionWithSetup($accountId, ['Liquidity Sweep']);

        // Capture updated_at, then rename to the same label, then re-check
        $stmt = $this->pdo->prepare('SELECT updated_at FROM positions WHERE id = :id');
        $stmt->execute(['id' => $pos]);
        $before = (string)$stmt->fetchColumn();

        // Sleep 1s to ensure any UPDATE would tick updated_at (TIMESTAMP precision = 1s)
        sleep(1);

        $response = $this->router->dispatch(
            $this->authRequest('PUT', "/setups/{$setupId}", ['label' => 'Liquidity Sweep'])
        );
        $this->assertSame(200, $response->getStatusCode());

        $stmt->execute(['id' => $pos]);
        $after = (string)$stmt->fetchColumn();

        $this->assertSame($before, $after, 'Position must not be touched when label is unchanged');
        $this->assertSame(['Liquidity Sweep'], $this->fetchPositionSetup($pos));
    }
}
