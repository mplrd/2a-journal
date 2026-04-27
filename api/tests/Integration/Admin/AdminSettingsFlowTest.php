<?php

namespace Tests\Integration\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class AdminSettingsFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $adminAccessToken;
    private string $userAccessToken;

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

        $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'admin-set@test.com',
            'password' => 'Test1234',
        ]));
        $this->pdo->prepare("UPDATE users SET role='ADMIN' WHERE email='admin-set@test.com'")->execute();

        $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'user-set@test.com',
            'password' => 'Test1234',
        ]));

        $this->adminAccessToken = $this->loginAndGetToken('admin-set@test.com');
        $this->userAccessToken = $this->loginAndGetToken('user-set@test.com');
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM platform_settings');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->router->dispatch(Request::create('POST', '/auth/login', [
            'email' => $email, 'password' => 'Test1234',
        ]));
        return $response->getBody()['data']['access_token'];
    }

    private function adminRequest(string $method, string $uri, array $body = []): Request
    {
        return Request::create($method, $uri, $body, [], [
            'Authorization' => "Bearer {$this->adminAccessToken}",
        ]);
    }

    private function userRequest(string $method, string $uri, array $body = []): Request
    {
        return Request::create($method, $uri, $body, [], [
            'Authorization' => "Bearer {$this->userAccessToken}",
        ]);
    }

    public function testListReturnsAllKnownSettings(): void
    {
        $response = $this->router->dispatch($this->adminRequest('GET', '/admin/settings'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $keys = array_column($body['data'], 'key');
        $this->assertContains('broker_auto_sync_enabled', $keys);
        $this->assertContains('broker_sync_interval_minutes', $keys);
        $this->assertContains('broker_sync_max_failures', $keys);
        $this->assertContains('email_verification_enabled', $keys);
        $this->assertContains('mail_enabled', $keys);
        $this->assertContains('billing_grace_days', $keys);
    }

    public function testUpdateRequiresAdminRole(): void
    {
        try {
            $this->router->dispatch(
                $this->userRequest('PUT', '/admin/settings/broker_sync_interval_minutes', ['value' => 30])
            );
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testUpdatePersistsValue(): void
    {
        $response = $this->router->dispatch(
            $this->adminRequest('PUT', '/admin/settings/broker_sync_interval_minutes', ['value' => 30])
        );

        $this->assertSame(200, $response->getStatusCode());

        // Read back via list
        $list = $this->router->dispatch($this->adminRequest('GET', '/admin/settings'))->getBody()['data'];
        $entry = null;
        foreach ($list as $e) {
            if ($e['key'] === 'broker_sync_interval_minutes') { $entry = $e; break; }
        }
        $this->assertNotNull($entry);
        $this->assertSame(30, $entry['value']);
        $this->assertSame('db', $entry['source']);
    }

    public function testUpdateRejectsInvalidType(): void
    {
        try {
            $this->router->dispatch(
                $this->adminRequest('PUT', '/admin/settings/broker_sync_interval_minutes', ['value' => 'not-a-number'])
            );
            $this->fail('Expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('admin.settings.error.invalid_type', $e->getMessageKey());
        }
    }

    public function testUpdateRejectsUnknownKey(): void
    {
        try {
            $this->router->dispatch(
                $this->adminRequest('PUT', '/admin/settings/totally_made_up', ['value' => '42'])
            );
            $this->fail('Expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('admin.settings.error.unknown_key', $e->getMessageKey());
        }
    }
}
