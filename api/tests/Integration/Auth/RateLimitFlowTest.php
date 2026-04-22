<?php

namespace Tests\Integration\Auth;

use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Exceptions\HttpException;
use PDO;
use PHPUnit\Framework\TestCase;

class RateLimitFlowTest extends TestCase
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
        $this->pdo->exec('DELETE FROM users');

        $router = new Router();
        require __DIR__ . '/../../../config/routes.php';
        $this->router = $router;
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM rate_limits');
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    public function testLogin429AfterTooManyAttempts(): void
    {
        // Make 10 failed login attempts (all allowed)
        for ($i = 1; $i <= 10; $i++) {
            $request = Request::create('POST', '/auth/login', [
                'email' => 'nobody@test.com',
                'password' => 'Wrong123',
            ]);

            try {
                $this->router->dispatch($request);
            } catch (HttpException $e) {
                // 401 is expected for wrong credentials
                $this->assertSame(401, $e->getStatusCode(), "Attempt $i should return 401");
            }
        }

        // 11th attempt should be rate limited
        $request = Request::create('POST', '/auth/login', [
            'email' => 'nobody@test.com',
            'password' => 'Wrong123',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException with 429');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('TOO_MANY_REQUESTS', $e->getErrorCode());
        }
    }

    public function testRegister429AfterTooManyAttempts(): void
    {
        // Make 5 register attempts (all allowed)
        for ($i = 1; $i <= 5; $i++) {
            $request = Request::create('POST', '/auth/register', [
                'email' => "user{$i}@test.com",
                'password' => 'Test1234',
            ]);

            $response = $this->router->dispatch($request);
            $this->assertSame(201, $response->getStatusCode(), "Attempt $i should return 201");
        }

        // 6th attempt should be rate limited
        $request = Request::create('POST', '/auth/register', [
            'email' => 'user6@test.com',
            'password' => 'Test1234',
        ]);

        try {
            $this->router->dispatch($request);
            $this->fail('Expected HttpException with 429');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('TOO_MANY_REQUESTS', $e->getErrorCode());
        }
    }
}
