<?php

namespace Tests\Integration\Auth;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class PasswordResetTest extends TestCase
{
    private Router $router;
    private PDO $pdo;

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

        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM email_verification_tokens');
        $this->pdo->exec('DELETE FROM password_reset_tokens');
        $this->pdo->exec('DELETE FROM users');

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM email_verification_tokens');
        $this->pdo->exec('DELETE FROM password_reset_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    private function registerUser(string $email = 'reset@test.com', string $password = 'Test1234'): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->router->dispatch($request);
    }

    // ── Forgot password ─────────────────────────────────────────

    public function testForgotPasswordSuccess(): void
    {
        $this->registerUser();

        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('auth.success.reset_email_sent', $body['data']['message_key']);
    }

    public function testForgotPasswordCreatesToken(): void
    {
        $this->registerUser();

        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        $stmt = $this->pdo->prepare(
            'SELECT prt.* FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'reset@test.com']);
        $token = $stmt->fetch();

        $this->assertNotFalse($token);
        $this->assertNotEmpty($token['token']);
    }

    public function testForgotPasswordUnknownEmailStillReturns200(): void
    {
        // To prevent email enumeration, always return 200
        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'nobody@test.com',
        ]);
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testForgotPasswordMissingEmail(): void
    {
        try {
            $request = Request::create('POST', '/auth/forgot-password', []);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testForgotPasswordReplacesOldToken(): void
    {
        $this->registerUser();

        // First request
        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        $stmt = $this->pdo->prepare(
            'SELECT prt.token FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'reset@test.com']);
        $oldToken = $stmt->fetch()['token'];

        // Second request
        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        $stmt->execute(['email' => 'reset@test.com']);
        $newToken = $stmt->fetch()['token'];

        $this->assertNotSame($oldToken, $newToken);

        // Only one token should exist
        $stmt2 = $this->pdo->prepare(
            'SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = (SELECT id FROM users WHERE email = :email)'
        );
        $stmt2->execute(['email' => 'reset@test.com']);
        $this->assertSame(1, (int)$stmt2->fetchColumn());
    }

    // ── Reset password ──────────────────────────────────────────

    public function testResetPasswordSuccess(): void
    {
        $this->registerUser();

        // Get forgot token
        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        $stmt = $this->pdo->prepare(
            'SELECT prt.token FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'reset@test.com']);
        $token = $stmt->fetch()['token'];

        // Reset password
        $request = Request::create('POST', '/auth/reset-password', [
            'token' => $token,
            'password' => 'NewPass123',
        ]);
        $response = $this->router->dispatch($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('auth.success.password_reset', $body['data']['message_key']);
    }

    public function testResetPasswordCanLoginWithNewPassword(): void
    {
        $this->registerUser();

        // Forgot + Reset
        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        $stmt = $this->pdo->prepare(
            'SELECT prt.token FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'reset@test.com']);
        $token = $stmt->fetch()['token'];

        $request = Request::create('POST', '/auth/reset-password', [
            'token' => $token,
            'password' => 'NewPass123',
        ]);
        $this->router->dispatch($request);

        // Login with new password
        $request = Request::create('POST', '/auth/login', [
            'email' => 'reset@test.com',
            'password' => 'NewPass123',
        ]);
        $response = $this->router->dispatch($request);
        $this->assertSame(200, $response->getStatusCode());

        // Old password no longer works
        try {
            $request = Request::create('POST', '/auth/login', [
                'email' => 'reset@test.com',
                'password' => 'Test1234',
            ]);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testResetPasswordTokenDeletedAfterUse(): void
    {
        $this->registerUser();

        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        $stmt = $this->pdo->prepare(
            'SELECT prt.token FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'reset@test.com']);
        $token = $stmt->fetch()['token'];

        // Use token
        $request = Request::create('POST', '/auth/reset-password', [
            'token' => $token,
            'password' => 'NewPass123',
        ]);
        $this->router->dispatch($request);

        // Try same token again
        try {
            $request = Request::create('POST', '/auth/reset-password', [
                'token' => $token,
                'password' => 'AnotherPass1',
            ]);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_reset_token', $e->getMessageKey());
        }
    }

    public function testResetPasswordExpiredToken(): void
    {
        $this->registerUser();

        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        // Expire the token
        $this->pdo->exec(
            "UPDATE password_reset_tokens SET expires_at = '2020-01-01 00:00:00' WHERE user_id = (SELECT id FROM users WHERE email = 'reset@test.com')"
        );

        $stmt = $this->pdo->prepare(
            'SELECT prt.token FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'reset@test.com']);
        $token = $stmt->fetch()['token'];

        try {
            $request = Request::create('POST', '/auth/reset-password', [
                'token' => $token,
                'password' => 'NewPass123',
            ]);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('auth.error.reset_token_expired', $e->getMessageKey());
        }
    }

    public function testResetPasswordInvalidToken(): void
    {
        try {
            $request = Request::create('POST', '/auth/reset-password', [
                'token' => 'nonexistent-token',
                'password' => 'NewPass123',
            ]);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('auth.error.invalid_reset_token', $e->getMessageKey());
        }
    }

    public function testResetPasswordWeakPassword(): void
    {
        $this->registerUser();

        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        $stmt = $this->pdo->prepare(
            'SELECT prt.token FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'reset@test.com']);
        $token = $stmt->fetch()['token'];

        try {
            $request = Request::create('POST', '/auth/reset-password', [
                'token' => $token,
                'password' => 'weak',
            ]);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('auth.error.password_too_weak', $e->getMessageKey());
        }
    }

    public function testResetPasswordMissingFields(): void
    {
        try {
            $request = Request::create('POST', '/auth/reset-password', []);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testResetPasswordResetsLockout(): void
    {
        $this->registerUser();

        // Lock the account
        $this->pdo->prepare(
            'UPDATE users SET failed_login_attempts = 5, locked_until = :locked WHERE email = :email'
        )->execute([
            'locked' => date('Y-m-d H:i:s', time() + 900),
            'email' => 'reset@test.com',
        ]);

        // Forgot + Reset
        $request = Request::create('POST', '/auth/forgot-password', [
            'email' => 'reset@test.com',
        ]);
        $this->router->dispatch($request);

        $stmt = $this->pdo->prepare(
            'SELECT prt.token FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE u.email = :email'
        );
        $stmt->execute(['email' => 'reset@test.com']);
        $token = $stmt->fetch()['token'];

        $request = Request::create('POST', '/auth/reset-password', [
            'token' => $token,
            'password' => 'NewPass123',
        ]);
        $this->router->dispatch($request);

        // Account should be unlocked
        $stmt = $this->pdo->prepare('SELECT failed_login_attempts, locked_until FROM users WHERE email = :email');
        $stmt->execute(['email' => 'reset@test.com']);
        $user = $stmt->fetch();
        $this->assertSame(0, (int)$user['failed_login_attempts']);
        $this->assertNull($user['locked_until']);
    }
}
