<?php

namespace Tests\Integration\Auth;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cross-SPA SSO via short-lived one-time codes:
 *   POST /auth/sso/code     (auth) → returns a code
 *   POST /auth/sso/exchange (no auth) → returns access+refresh tokens, marks code used
 */
class SsoFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;
    private string $accessToken;
    private int $userId;

    protected function setUp(): void
    {
        // Load .env
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

        $this->pdo->exec('DELETE FROM sso_codes');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;

        // Register a user
        $response = $this->router->dispatch(Request::create('POST', '/auth/register', [
            'email' => 'sso@test.com',
            'password' => 'Test1234',
        ]));
        $this->accessToken = $response->getBody()['data']['access_token'];

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => 'sso@test.com']);
        $this->userId = (int) $stmt->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM sso_codes');
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function authRequest(string $method, string $uri, array $body = []): Request
    {
        return Request::create($method, $uri, $body, [], [
            'Authorization' => "Bearer {$this->accessToken}",
        ]);
    }

    public function testIssueCodeReturnsRandomString(): void
    {
        $response = $this->router->dispatch($this->authRequest('POST', '/auth/sso/code'));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('code', $body['data']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $body['data']['code']);
        $this->assertArrayHasKey('expires_in', $body['data']);
        $this->assertSame(30, $body['data']['expires_in']);
    }

    public function testIssueCodePersistsHashOnly(): void
    {
        $response = $this->router->dispatch($this->authRequest('POST', '/auth/sso/code'));
        $code = $response->getBody()['data']['code'];

        $row = $this->pdo->query('SELECT code_hash, user_id, used_at FROM sso_codes')->fetch();

        $this->assertNotFalse($row);
        $this->assertSame(hash('sha256', $code), $row['code_hash']);
        $this->assertNotSame($code, $row['code_hash']); // never store plaintext
        $this->assertSame($this->userId, (int) $row['user_id']);
        $this->assertNull($row['used_at']);
    }

    public function testIssueCodeRequiresAuth(): void
    {
        try {
            $this->router->dispatch(Request::create('POST', '/auth/sso/code'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testExchangeReturnsTokens(): void
    {
        $response = $this->router->dispatch($this->authRequest('POST', '/auth/sso/code'));
        $code = $response->getBody()['data']['code'];

        $response = $this->router->dispatch(Request::create('POST', '/auth/sso/exchange', ['code' => $code]));
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayHasKey('user', $body['data']);
        $this->assertSame('sso@test.com', $body['data']['user']['email']);

        // Refresh cookie should be set
        $this->assertNotNull($response->getHeader('Set-Cookie'));
        $this->assertStringContainsString('refresh_token=', $response->getHeader('Set-Cookie'));
    }

    public function testExchangeMarksCodeUsed(): void
    {
        $code = $this->router->dispatch($this->authRequest('POST', '/auth/sso/code'))
            ->getBody()['data']['code'];

        $this->router->dispatch(Request::create('POST', '/auth/sso/exchange', ['code' => $code]));

        $row = $this->pdo->query('SELECT used_at FROM sso_codes')->fetch();
        $this->assertNotNull($row['used_at']);
    }

    public function testExchangeRejectsReplay(): void
    {
        $code = $this->router->dispatch($this->authRequest('POST', '/auth/sso/code'))
            ->getBody()['data']['code'];

        $this->router->dispatch(Request::create('POST', '/auth/sso/exchange', ['code' => $code]));

        try {
            $this->router->dispatch(Request::create('POST', '/auth/sso/exchange', ['code' => $code]));
            $this->fail('Expected HttpException on replay');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('auth.error.sso_code_invalid', $e->getMessage());
        }
    }

    public function testExchangeRejectsExpiredCode(): void
    {
        $code = $this->router->dispatch($this->authRequest('POST', '/auth/sso/code'))
            ->getBody()['data']['code'];

        // Force expiry via SQL (avoids PHP/MySQL timezone mismatch)
        $this->pdo->prepare('UPDATE sso_codes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE code_hash = :hash')
            ->execute(['hash' => hash('sha256', $code)]);

        try {
            $this->router->dispatch(Request::create('POST', '/auth/sso/exchange', ['code' => $code]));
            $this->fail('Expected HttpException on expired code');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('auth.error.sso_code_invalid', $e->getMessage());
        }
    }

    public function testExchangeRejectsUnknownCode(): void
    {
        try {
            $this->router->dispatch(Request::create('POST', '/auth/sso/exchange', ['code' => str_repeat('a', 64)]));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('auth.error.sso_code_invalid', $e->getMessage());
        }
    }

    public function testExchangeRequiresCode(): void
    {
        try {
            $this->router->dispatch(Request::create('POST', '/auth/sso/exchange', []));
            $this->fail('Expected ValidationException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.field_required', $e->getMessage());
        }
    }

    public function testExchangeRefusesSuspendedUser(): void
    {
        $code = $this->router->dispatch($this->authRequest('POST', '/auth/sso/code'))
            ->getBody()['data']['code'];

        $this->pdo->prepare('UPDATE users SET suspended_at = NOW() WHERE id = :id')
            ->execute(['id' => $this->userId]);

        try {
            $this->router->dispatch(Request::create('POST', '/auth/sso/exchange', ['code' => $code]));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('auth.error.suspended', $e->getMessage());
        }
    }
}
