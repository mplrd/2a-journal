<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\StatusHistoryRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class StatusHistoryRepositoryTest extends TestCase
{
    private StatusHistoryRepository $repo;
    private PDO $pdo;
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
        $this->repo = new StatusHistoryRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM users');

        // Create a test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('test@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM status_history');
        $this->pdo->exec('DELETE FROM users');
    }

    public function testCreateReturnsEntry(): void
    {
        $entry = $this->repo->create([
            'entity_type' => 'POSITION',
            'entity_id' => 1,
            'previous_status' => null,
            'new_status' => 'transferred',
            'user_id' => $this->userId,
            'trigger_type' => 'MANUAL',
            'details' => json_encode(['from_account' => 1, 'to_account' => 2]),
        ]);

        $this->assertIsArray($entry);
        $this->assertSame('POSITION', $entry['entity_type']);
        $this->assertEquals(1, $entry['entity_id']);
        $this->assertSame('transferred', $entry['new_status']);
        $this->assertNull($entry['previous_status']);
    }

    public function testFindByEntityReturnsOrderedEntries(): void
    {
        $this->repo->create([
            'entity_type' => 'POSITION',
            'entity_id' => 1,
            'new_status' => 'created',
            'user_id' => $this->userId,
        ]);

        $this->repo->create([
            'entity_type' => 'POSITION',
            'entity_id' => 1,
            'previous_status' => 'created',
            'new_status' => 'transferred',
            'user_id' => $this->userId,
        ]);

        $entries = $this->repo->findByEntity('POSITION', 1);

        $this->assertCount(2, $entries);
        // Most recent first
        $this->assertSame('transferred', $entries[0]['new_status']);
        $this->assertSame('created', $entries[1]['new_status']);
    }

    public function testFindByEntityReturnsEmptyForNonExistent(): void
    {
        $entries = $this->repo->findByEntity('POSITION', 99999);

        $this->assertCount(0, $entries);
    }
}
