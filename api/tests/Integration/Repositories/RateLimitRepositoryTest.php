<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\RateLimitRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class RateLimitRepositoryTest extends TestCase
{
    private RateLimitRepository $repo;
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
        $this->repo = new RateLimitRepository($this->pdo);

        $this->pdo->exec('DELETE FROM rate_limits');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM rate_limits');
    }

    public function testIncrementCreatesNewEntry(): void
    {
        $this->repo->increment('192.168.1.1', '/auth/login', 900);

        $attempts = $this->repo->getAttempts('192.168.1.1', '/auth/login', 900);
        $this->assertSame(1, $attempts);
    }

    public function testIncrementIncrementsExistingEntry(): void
    {
        $this->repo->increment('192.168.1.1', '/auth/login', 900);
        $this->repo->increment('192.168.1.1', '/auth/login', 900);
        $this->repo->increment('192.168.1.1', '/auth/login', 900);

        $attempts = $this->repo->getAttempts('192.168.1.1', '/auth/login', 900);
        $this->assertSame(3, $attempts);
    }

    public function testDifferentEndpointsAreSeparate(): void
    {
        $this->repo->increment('192.168.1.1', '/auth/login', 900);
        $this->repo->increment('192.168.1.1', '/auth/register', 900);

        $this->assertSame(1, $this->repo->getAttempts('192.168.1.1', '/auth/login', 900));
        $this->assertSame(1, $this->repo->getAttempts('192.168.1.1', '/auth/register', 900));
    }

    public function testDifferentIpsAreSeparate(): void
    {
        $this->repo->increment('192.168.1.1', '/auth/login', 900);
        $this->repo->increment('10.0.0.1', '/auth/login', 900);

        $this->assertSame(1, $this->repo->getAttempts('192.168.1.1', '/auth/login', 900));
        $this->assertSame(1, $this->repo->getAttempts('10.0.0.1', '/auth/login', 900));
    }

    public function testGetAttemptsReturnsZeroWhenNoEntry(): void
    {
        $attempts = $this->repo->getAttempts('10.0.0.1', '/auth/login', 900);
        $this->assertSame(0, $attempts);
    }

    public function testCleanupRemovesExpiredEntries(): void
    {
        // Insert an entry with old window_start
        $this->pdo->prepare(
            'INSERT INTO rate_limits (ip, endpoint, attempts, window_start) VALUES (?, ?, ?, ?)'
        )->execute(['192.168.1.1', '/auth/login', 5, '2020-01-01 00:00:00']);

        // Insert a current entry
        $this->repo->increment('10.0.0.1', '/auth/login', 900);

        $this->repo->cleanup(900);

        // Old entry should be gone
        $this->assertSame(0, $this->repo->getAttempts('192.168.1.1', '/auth/login', 900));
        // Current entry should remain
        $this->assertSame(1, $this->repo->getAttempts('10.0.0.1', '/auth/login', 900));
    }
}
