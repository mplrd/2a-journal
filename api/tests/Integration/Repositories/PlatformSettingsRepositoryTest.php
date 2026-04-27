<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\PlatformSettingsRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class PlatformSettingsRepositoryTest extends TestCase
{
    private PlatformSettingsRepository $repo;
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
        $this->repo = new PlatformSettingsRepository($this->pdo);
        $this->pdo->exec('DELETE FROM platform_settings');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM platform_settings');
    }

    public function testGetReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->repo->get('does_not_exist'));
    }

    public function testUpsertCreatesNew(): void
    {
        $this->repo->upsert('test_key', '42', 'INT', null, null);

        $row = $this->repo->get('test_key');
        $this->assertNotNull($row);
        $this->assertSame('42', $row['setting_value']);
        $this->assertSame('INT', $row['value_type']);
    }

    public function testUpsertUpdatesExisting(): void
    {
        $this->repo->upsert('test_key', '42', 'INT', null, null);
        $this->repo->upsert('test_key', '99', 'INT', null, null);

        $row = $this->repo->get('test_key');
        $this->assertSame('99', $row['setting_value']);
    }

    public function testListReturnsAll(): void
    {
        $this->repo->upsert('a', '1', 'INT', null, null);
        $this->repo->upsert('b', 'true', 'BOOL', null, null);

        $rows = $this->repo->list();
        $this->assertCount(2, $rows);
        $keys = array_column($rows, 'setting_key');
        sort($keys);
        $this->assertSame(['a', 'b'], $keys);
    }
}
