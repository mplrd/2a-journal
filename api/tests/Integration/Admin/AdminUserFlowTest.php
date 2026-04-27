<?php

namespace Tests\Integration\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class AdminUserFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $adminAccessToken;
    private string $userAccessToken;
    private int $adminUserId;
    private int $regularUserId;

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
        $this->cleanup();

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;

        // Register an admin and a regular user
        $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'admin@test.com',
            'password' => 'Test1234',
        ]));
        $this->pdo->prepare("UPDATE users SET role='ADMIN' WHERE email='admin@test.com'")->execute();

        $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'user@test.com',
            'password' => 'Test1234',
        ]));

        // Login both to get tokens (the admin token is reissued AFTER the role
        // promotion above so the JWT carries the ADMIN claim)
        $this->adminAccessToken = $this->loginAndGetToken('admin@test.com');
        $this->userAccessToken = $this->loginAndGetToken('user@test.com');

        $this->adminUserId = $this->fetchUserId('admin@test.com');
        $this->regularUserId = $this->fetchUserId('user@test.com');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM custom_field_values');
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM password_reset_tokens');
        $this->pdo->exec('DELETE FROM email_verification_tokens');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->router->dispatch(Request::create('POST', '/auth/login', [
            'email' => $email,
            'password' => 'Test1234',
        ]));
        return $response->getBody()['data']['access_token'];
    }

    private function fetchUserId(string $email): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :e');
        $stmt->execute(['e' => $email]);
        return (int) $stmt->fetchColumn();
    }

    private function adminRequest(string $method, string $uri, array $body = [], array $query = []): Request
    {
        return Request::create($method, $uri, $body, $query, [
            'Authorization' => "Bearer {$this->adminAccessToken}",
        ]);
    }

    private function userRequest(string $method, string $uri, array $body = [], array $query = []): Request
    {
        return Request::create($method, $uri, $body, $query, [
            'Authorization' => "Bearer {$this->userAccessToken}",
        ]);
    }

    // ── List ────────────────────────────────────────────────────

    public function testListReturnsAllUsersForAdmin(): void
    {
        $response = $this->router->dispatch($this->adminRequest('GET', '/admin/users'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['data']);
        $emails = array_column($body['data'], 'email');
        $this->assertContains('admin@test.com', $emails);
        $this->assertContains('user@test.com', $emails);
    }

    public function testListExcludesPasswordHash(): void
    {
        $response = $this->router->dispatch($this->adminRequest('GET', '/admin/users'));
        $body = $response->getBody();

        foreach ($body['data'] as $user) {
            $this->assertArrayNotHasKey('password', $user);
        }
    }

    public function testListIncludesTradeCount(): void
    {
        $response = $this->router->dispatch($this->adminRequest('GET', '/admin/users'));
        $body = $response->getBody();

        foreach ($body['data'] as $user) {
            $this->assertArrayHasKey('trade_count', $user);
        }
    }

    public function testListIncludesAccountCount(): void
    {
        // Create one account for the regular user
        $this->router->dispatch(
            Request::create('POST', '/accounts', [
                'name' => 'Compte Test', 'account_type' => 'BROKER_DEMO',
            ], [], ['Authorization' => "Bearer {$this->userAccessToken}"])
        );

        $response = $this->router->dispatch($this->adminRequest('GET', '/admin/users'));
        $body = $response->getBody();

        $userRow = null;
        foreach ($body['data'] as $u) {
            if ($u['email'] === 'user@test.com') { $userRow = $u; break; }
        }
        $this->assertNotNull($userRow);
        $this->assertArrayHasKey('account_count', $userRow);
        $this->assertSame(1, (int) $userRow['account_count']);
    }

    public function testListIncludesGracePeriodAndLastLogin(): void
    {
        $response = $this->router->dispatch($this->adminRequest('GET', '/admin/users'));
        $body = $response->getBody();

        foreach ($body['data'] as $user) {
            $this->assertArrayHasKey('grace_period_end', $user);
            $this->assertArrayHasKey('last_login_at', $user);
        }
    }

    public function testListIncludesSubscriptionFields(): void
    {
        $response = $this->router->dispatch($this->adminRequest('GET', '/admin/users'));
        $body = $response->getBody();

        foreach ($body['data'] as $user) {
            $this->assertArrayHasKey('subscription_started_at', $user);
            $this->assertArrayHasKey('subscription_status', $user);
        }
    }

    public function testListFiltersBySearchEmail(): void
    {
        $response = $this->router->dispatch(
            $this->adminRequest('GET', '/admin/users', [], ['search' => 'admin'])
        );
        $body = $response->getBody();

        $this->assertCount(1, $body['data']);
        $this->assertSame('admin@test.com', $body['data'][0]['email']);
    }

    public function testListFiltersByStatusSuspended(): void
    {
        // Suspend the regular user
        $this->router->dispatch(
            $this->adminRequest('POST', "/admin/users/{$this->regularUserId}/suspend")
        );

        $response = $this->router->dispatch(
            $this->adminRequest('GET', '/admin/users', [], ['status' => 'suspended'])
        );
        $body = $response->getBody();

        $this->assertCount(1, $body['data']);
        $this->assertSame('user@test.com', $body['data'][0]['email']);
    }

    public function testListFiltersByStatusActive(): void
    {
        $this->router->dispatch(
            $this->adminRequest('POST', "/admin/users/{$this->regularUserId}/suspend")
        );

        $response = $this->router->dispatch(
            $this->adminRequest('GET', '/admin/users', [], ['status' => 'active'])
        );
        $body = $response->getBody();

        $this->assertCount(1, $body['data']);
        $this->assertSame('admin@test.com', $body['data'][0]['email']);
    }

    public function testListRequiresAdminRole(): void
    {
        try {
            $this->router->dispatch($this->userRequest('GET', '/admin/users'));
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('auth.error.admin_only', $e->getMessageKey());
        }
    }

    public function testListRejectsAnonymous(): void
    {
        try {
            $this->router->dispatch(Request::create('GET', '/admin/users'));
            $this->fail('Expected 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Get detail ──────────────────────────────────────────────

    public function testGetUserDetailSuccess(): void
    {
        $response = $this->router->dispatch(
            $this->adminRequest('GET', "/admin/users/{$this->regularUserId}")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('user@test.com', $body['data']['email']);
        $this->assertArrayNotHasKey('password', $body['data']);
    }

    public function testGetUserDetailNotFound(): void
    {
        try {
            $this->router->dispatch($this->adminRequest('GET', '/admin/users/99999'));
            $this->fail('Expected 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ── Suspend / unsuspend ─────────────────────────────────────

    public function testSuspendUser(): void
    {
        $response = $this->router->dispatch(
            $this->adminRequest('POST', "/admin/users/{$this->regularUserId}/suspend")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($body['data']['suspended_at']);
    }

    public function testUnsuspendUser(): void
    {
        $this->router->dispatch(
            $this->adminRequest('POST', "/admin/users/{$this->regularUserId}/suspend")
        );
        $response = $this->router->dispatch(
            $this->adminRequest('POST', "/admin/users/{$this->regularUserId}/unsuspend")
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($body['data']['suspended_at']);
    }

    public function testCannotSuspendSelf(): void
    {
        try {
            $this->router->dispatch(
                $this->adminRequest('POST', "/admin/users/{$this->adminUserId}/suspend")
            );
            $this->fail('Expected validation error');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('admin.error.cannot_self_suspend', $e->getMessageKey());
        }
    }

    // ── Reset password ──────────────────────────────────────────

    public function testResetPasswordReturnsSuccess(): void
    {
        $response = $this->router->dispatch(
            $this->adminRequest('POST', "/admin/users/{$this->regularUserId}/reset-password")
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);
    }

    // ── Delete ──────────────────────────────────────────────────

    public function testDeleteUserSoftDeletes(): void
    {
        $response = $this->router->dispatch(
            $this->adminRequest('DELETE', "/admin/users/{$this->regularUserId}")
        );

        $this->assertSame(200, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT deleted_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->regularUserId]);
        $this->assertNotNull($stmt->fetchColumn());
    }

    public function testCannotDeleteSelf(): void
    {
        try {
            $this->router->dispatch(
                $this->adminRequest('DELETE', "/admin/users/{$this->adminUserId}")
            );
            $this->fail('Expected validation error');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('admin.error.cannot_self_delete', $e->getMessageKey());
        }
    }
}
