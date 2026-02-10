<?php

namespace Tests\Integration\Auth;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class AuthFlowTest extends TestCase
{
    private Router $router;
    private PDO $pdo;

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

        // Clean tables
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');

        // Build router with routes
        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    // ── Register ─────────────────────────────────────────────────

    public function testRegisterSuccess(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'new@test.com',
            'password' => 'Test1234',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayHasKey('refresh_token', $body['data']);
        $this->assertSame('new@test.com', $body['data']['user']['email']);
    }

    public function testRegisterMissingEmail(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('VALIDATION_ERROR', $e->getErrorCode());
            $this->assertSame('email', $e->getField());
        }
    }

    public function testRegisterInvalidEmail(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'not-valid',
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_email', $e->getMessageKey());
        }
    }

    public function testRegisterWeakPassword(): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => 'weak@test.com',
            'password' => 'weak',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.password_too_weak', $e->getMessageKey());
        }
    }

    public function testRegisterDuplicateEmail(): void
    {
        // First register
        $request = Request::create('POST', '/auth/register', [
            'email' => 'dup@test.com',
            'password' => 'Test1234',
        ]);
        $this->router->dispatch($request);

        // Second register with same email
        $request = Request::create('POST', '/auth/register', [
            'email' => 'dup@test.com',
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('EMAIL_TAKEN', $e->getErrorCode());
        }
    }

    // ── Login ────────────────────────────────────────────────────

    public function testLoginSuccess(): void
    {
        // Register first
        $request = Request::create('POST', '/auth/register', [
            'email' => 'login@test.com',
            'password' => 'Test1234',
        ]);
        $this->router->dispatch($request);

        // Login
        $request = Request::create('POST', '/auth/login', [
            'email' => 'login@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayHasKey('refresh_token', $body['data']);
        $this->assertSame('login@test.com', $body['data']['user']['email']);
    }

    public function testLoginWrongPassword(): void
    {
        // Register first
        $request = Request::create('POST', '/auth/register', [
            'email' => 'wrong@test.com',
            'password' => 'Test1234',
        ]);
        $this->router->dispatch($request);

        // Login with wrong password
        $request = Request::create('POST', '/auth/login', [
            'email' => 'wrong@test.com',
            'password' => 'Wrong123',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('INVALID_CREDENTIALS', $e->getErrorCode());
        }
    }

    public function testLoginUnknownEmail(): void
    {
        $request = Request::create('POST', '/auth/login', [
            'email' => 'nobody@test.com',
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('INVALID_CREDENTIALS', $e->getErrorCode());
        }
    }

    public function testLoginMissingFields(): void
    {
        $request = Request::create('POST', '/auth/login', []);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    // ── Refresh ──────────────────────────────────────────────────

    public function testRefreshSuccess(): void
    {
        // Register to get tokens
        $request = Request::create('POST', '/auth/register', [
            'email' => 'refresh@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $refreshToken = $response->getBody()['data']['refresh_token'];

        // Refresh
        $request = Request::create('POST', '/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayHasKey('refresh_token', $body['data']);
        // New refresh token should be different (rotation)
        $this->assertNotSame($refreshToken, $body['data']['refresh_token']);
    }

    public function testRefreshInvalidToken(): void
    {
        $request = Request::create('POST', '/auth/refresh', [
            'refresh_token' => 'bad-token',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('REFRESH_TOKEN_INVALID', $e->getErrorCode());
        }
    }

    public function testRefreshMissingToken(): void
    {
        $request = Request::create('POST', '/auth/refresh', []);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testOldRefreshTokenInvalidAfterRotation(): void
    {
        // Register
        $request = Request::create('POST', '/auth/register', [
            'email' => 'rotate@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $oldToken = $response->getBody()['data']['refresh_token'];

        // Refresh to get new token
        $request = Request::create('POST', '/auth/refresh', [
            'refresh_token' => $oldToken,
        ]);
        $this->router->dispatch($request);

        // Try old token again → should fail
        $request = Request::create('POST', '/auth/refresh', [
            'refresh_token' => $oldToken,
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Me (profile) ─────────────────────────────────────────────

    public function testMeSuccess(): void
    {
        // Register to get access token
        $request = Request::create('POST', '/auth/register', [
            'email' => 'me@test.com',
            'password' => 'Test1234',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);
        $response = $this->router->dispatch($request);
        $accessToken = $response->getBody()['data']['access_token'];

        // Get profile
        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => "Bearer $accessToken",
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('me@test.com', $body['data']['email']);
        $this->assertSame('Jane', $body['data']['first_name']);
        $this->assertSame('Smith', $body['data']['last_name']);
    }

    public function testMeWithoutToken(): void
    {
        $request = Request::create('GET', '/auth/me');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('TOKEN_MISSING', $e->getErrorCode());
        }
    }

    public function testMeWithInvalidToken(): void
    {
        $request = Request::create('GET', '/auth/me', [], [], [
            'Authorization' => 'Bearer invalid.token.here',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    // ── Logout ───────────────────────────────────────────────────

    public function testLogoutSuccess(): void
    {
        // Register
        $request = Request::create('POST', '/auth/register', [
            'email' => 'logout@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $data = $response->getBody()['data'];

        // Logout
        $request = Request::create('POST', '/auth/logout', [], [], [
            'Authorization' => "Bearer {$data['access_token']}",
        ]);
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getBody()['success']);

        // Refresh token should no longer work
        $request = Request::create('POST', '/auth/refresh', [
            'refresh_token' => $data['refresh_token'],
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testLogoutWithoutToken(): void
    {
        $request = Request::create('POST', '/auth/logout');

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }
}
