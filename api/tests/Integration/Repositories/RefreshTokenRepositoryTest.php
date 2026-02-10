<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class RefreshTokenRepositoryTest extends TestCase
{
    private RefreshTokenRepository $tokenRepo;
    private UserRepository $userRepo;
    private PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        // Load .env for DB config
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
        $this->userRepo = new UserRepository($this->pdo);
        $this->tokenRepo = new RefreshTokenRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');

        // Create a user for token tests
        $user = $this->userRepo->create([
            'email' => 'tokenuser@example.com',
            'password' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);
        $this->userId = (int)$user['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM refresh_tokens');
        $this->pdo->exec('DELETE FROM users');
    }

    public function testCreateAndFindToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $this->tokenRepo->create($this->userId, $token, $expiresAt);

        $found = $this->tokenRepo->findByToken($token);

        $this->assertNotNull($found);
        $this->assertSame($this->userId, (int)$found['user_id']);
        $this->assertSame($token, $found['token']);
    }

    public function testFindByTokenReturnsNullWhenNotFound(): void
    {
        $found = $this->tokenRepo->findByToken('nonexistent-token');

        $this->assertNull($found);
    }

    public function testDeleteByToken(): void
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $this->tokenRepo->create($this->userId, $token, $expiresAt);
        $this->tokenRepo->deleteByToken($token);

        $this->assertNull($this->tokenRepo->findByToken($token));
    }

    public function testDeleteAllByUserId(): void
    {
        $token1 = bin2hex(random_bytes(32));
        $token2 = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        $this->tokenRepo->create($this->userId, $token1, $expiresAt);
        $this->tokenRepo->create($this->userId, $token2, $expiresAt);

        $this->tokenRepo->deleteAllByUserId($this->userId);

        $this->assertNull($this->tokenRepo->findByToken($token1));
        $this->assertNull($this->tokenRepo->findByToken($token2));
    }
}
