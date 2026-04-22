<?php

namespace Tests\Integration\Auth;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class AccountLockoutTest extends TestCase
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

    private function registerUser(string $email = 'lockout@test.com', string $password = 'Test1234'): void
    {
        $request = Request::create('POST', '/auth/register', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->router->dispatch($request);
    }

    // ── Account Lockout ──────────────────────────────────────────

    public function testLoginFailedAttemptsIncrement(): void
    {
        $this->registerUser();

        // Make 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            try {
                $request = Request::create('POST', '/auth/login', [
                    'email' => 'lockout@test.com',
                    'password' => 'WrongPass1',
                ]);
                $this->router->dispatch($request);
            } catch (HttpException $e) {
                $this->assertSame(401, $e->getStatusCode());
            }
        }

        // Check that failed_login_attempts is 3
        $stmt = $this->pdo->prepare('SELECT failed_login_attempts FROM users WHERE email = :email');
        $stmt->execute(['email' => 'lockout@test.com']);
        $user = $stmt->fetch();
        $this->assertSame(3, (int)$user['failed_login_attempts']);
    }

    public function testAccountLockedAfterMaxAttempts(): void
    {
        $this->registerUser();

        // Make 5 failed attempts (default max)
        for ($i = 0; $i < 5; $i++) {
            try {
                $request = Request::create('POST', '/auth/login', [
                    'email' => 'lockout@test.com',
                    'password' => 'WrongPass1',
                ]);
                $this->router->dispatch($request);
            } catch (HttpException $e) {
                // Expected
            }
        }

        // 6th attempt should get ACCOUNT_LOCKED
        try {
            $request = Request::create('POST', '/auth/login', [
                'email' => 'lockout@test.com',
                'password' => 'Test1234', // Even correct password
            ]);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
            $this->assertSame('ACCOUNT_LOCKED', $e->getErrorCode());
            $this->assertSame('auth.error.account_locked', $e->getMessageKey());
        }
    }

    public function testSuccessfulLoginResetsFailedAttempts(): void
    {
        $this->registerUser();

        // Make 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            try {
                $request = Request::create('POST', '/auth/login', [
                    'email' => 'lockout@test.com',
                    'password' => 'WrongPass1',
                ]);
                $this->router->dispatch($request);
            } catch (HttpException $e) {
                // Expected
            }
        }

        // Successful login
        $request = Request::create('POST', '/auth/login', [
            'email' => 'lockout@test.com',
            'password' => 'Test1234',
        ]);
        $this->router->dispatch($request);

        // Check counter is reset
        $stmt = $this->pdo->prepare('SELECT failed_login_attempts FROM users WHERE email = :email');
        $stmt->execute(['email' => 'lockout@test.com']);
        $user = $stmt->fetch();
        $this->assertSame(0, (int)$user['failed_login_attempts']);
    }

    public function testAccountUnlocksAfterTimeout(): void
    {
        $this->registerUser();

        // Lock the account by setting locked_until in the past
        $this->pdo->prepare(
            'UPDATE users SET failed_login_attempts = 5, locked_until = :locked WHERE email = :email'
        )->execute([
            'locked' => date('Y-m-d H:i:s', time() - 1), // 1 second ago
            'email' => 'lockout@test.com',
        ]);

        // Login should succeed now
        $request = Request::create('POST', '/auth/login', [
            'email' => 'lockout@test.com',
            'password' => 'Test1234',
        ]);
        $response = $this->router->dispatch($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testLockedAccountCannotLogin(): void
    {
        $this->registerUser();

        // Lock the account for 15 minutes in the future
        $this->pdo->prepare(
            'UPDATE users SET failed_login_attempts = 5, locked_until = :locked WHERE email = :email'
        )->execute([
            'locked' => date('Y-m-d H:i:s', time() + 900),
            'email' => 'lockout@test.com',
        ]);

        try {
            $request = Request::create('POST', '/auth/login', [
                'email' => 'lockout@test.com',
                'password' => 'Test1234',
            ]);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
            $this->assertSame('ACCOUNT_LOCKED', $e->getErrorCode());
        }
    }

    public function testInvalidCredentialsForUnknownEmail_noLockout(): void
    {
        // Should not crash on unknown email
        try {
            $request = Request::create('POST', '/auth/login', [
                'email' => 'nobody@test.com',
                'password' => 'WrongPass1',
            ]);
            $this->router->dispatch($request);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('INVALID_CREDENTIALS', $e->getErrorCode());
        }
    }
}
