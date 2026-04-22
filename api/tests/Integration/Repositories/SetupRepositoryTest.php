<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\SetupRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class SetupRepositoryTest extends TestCase
{
    private SetupRepository $repo;
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
        $this->repo = new SetupRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM setups');
        $this->pdo->exec('DELETE FROM users');

        // Create a test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('test@test.com', 'hashed')");
        $this->userId = (int)$this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM setups');
        $this->pdo->exec('DELETE FROM users');
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->userId,
            'label' => 'Breakout',
        ], $overrides);
    }

    public function testCreateReturnsSetup(): void
    {
        $setup = $this->repo->create($this->validData());

        $this->assertIsArray($setup);
        $this->assertSame('Breakout', $setup['label']);
        $this->assertEquals($this->userId, $setup['user_id']);
        $this->assertArrayHasKey('id', $setup);
        $this->assertArrayHasKey('created_at', $setup);
    }

    public function testFindByIdReturnsSetup(): void
    {
        $created = $this->repo->create($this->validData());
        $found = $this->repo->findById((int)$created['id']);

        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);
        $this->assertSame('Breakout', $found['label']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $found = $this->repo->findById(99999);
        $this->assertNull($found);
    }

    public function testFindAllByUserIdReturnsUserSetups(): void
    {
        $this->repo->create($this->validData(['label' => 'Breakout']));
        $this->repo->create($this->validData(['label' => 'FVG']));

        // Create another user's setup
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('other@test.com', 'hashed')");
        $otherUserId = (int)$this->pdo->lastInsertId();
        $this->repo->create($this->validData(['user_id' => $otherUserId, 'label' => 'OB']));

        $result = $this->repo->findAllByUserId($this->userId);

        $this->assertCount(2, $result);
        $labels = array_column($result, 'label');
        $this->assertContains('Breakout', $labels);
        $this->assertContains('FVG', $labels);
        $this->assertNotContains('OB', $labels);
    }

    public function testFindAllByUserIdOrdersByLabel(): void
    {
        $this->repo->create($this->validData(['label' => 'FVG']));
        $this->repo->create($this->validData(['label' => 'Breakout']));
        $this->repo->create($this->validData(['label' => 'OB']));

        $result = $this->repo->findAllByUserId($this->userId);

        $labels = array_column($result, 'label');
        $this->assertSame(['Breakout', 'FVG', 'OB'], $labels);
    }

    public function testFindByUserAndLabelReturnsSetup(): void
    {
        $this->repo->create($this->validData());

        $found = $this->repo->findByUserAndLabel($this->userId, 'Breakout');
        $this->assertNotNull($found);
        $this->assertSame('Breakout', $found['label']);
    }

    public function testFindByUserAndLabelReturnsNullWhenNotFound(): void
    {
        $found = $this->repo->findByUserAndLabel($this->userId, 'Nonexistent');
        $this->assertNull($found);
    }

    public function testSoftDeleteMarksAsDeleted(): void
    {
        $created = $this->repo->create($this->validData());
        $result = $this->repo->softDelete((int)$created['id']);

        $this->assertTrue($result);

        $found = $this->repo->findById((int)$created['id']);
        $this->assertNull($found);
    }

    public function testSoftDeletedSetupNotInList(): void
    {
        $created = $this->repo->create($this->validData());
        $this->repo->softDelete((int)$created['id']);

        $result = $this->repo->findAllByUserId($this->userId);
        $this->assertCount(0, $result);
    }

    public function testSoftDeletedSetupNotFoundByLabel(): void
    {
        $created = $this->repo->create($this->validData());
        $this->repo->softDelete((int)$created['id']);

        $found = $this->repo->findByUserAndLabel($this->userId, 'Breakout');
        $this->assertNull($found);
    }

    public function testSeedForUserCreatesDefaultSetups(): void
    {
        $this->repo->seedForUser($this->userId);

        $result = $this->repo->findAllByUserId($this->userId);
        $this->assertSame(8, count($result));

        $labels = array_column($result, 'label');
        $this->assertContains('Breakout', $labels);
        $this->assertContains('FVG', $labels);
        $this->assertContains('OB', $labels);
        $this->assertContains('Liquidity Sweep', $labels);
        $this->assertContains('BOS', $labels);
        $this->assertContains('CHoCH', $labels);
        $this->assertContains('Supply/Demand', $labels);
        $this->assertContains('Trend Follow', $labels);
    }

    public function testEnsureExistCreatesNewSetups(): void
    {
        $this->repo->ensureExist($this->userId, ['Breakout', 'FVG', 'OB']);

        $result = $this->repo->findAllByUserId($this->userId);
        $this->assertSame(3, count($result));

        $labels = array_column($result, 'label');
        $this->assertContains('Breakout', $labels);
        $this->assertContains('FVG', $labels);
        $this->assertContains('OB', $labels);
    }

    public function testEnsureExistDoesNotDuplicateExisting(): void
    {
        $this->repo->create($this->validData(['label' => 'Breakout']));

        // Should not throw or create duplicates
        $this->repo->ensureExist($this->userId, ['Breakout', 'FVG']);

        $result = $this->repo->findAllByUserId($this->userId);
        $this->assertSame(2, count($result));
    }

    public function testEnsureExistHandlesEmptyArray(): void
    {
        $this->repo->ensureExist($this->userId, []);

        $result = $this->repo->findAllByUserId($this->userId);
        $this->assertCount(0, $result);
    }
}
