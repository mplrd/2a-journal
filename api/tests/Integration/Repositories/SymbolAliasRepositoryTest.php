<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\SymbolAliasRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class SymbolAliasRepositoryTest extends TestCase
{
    private SymbolAliasRepository $repo;
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
        $this->repo = new SymbolAliasRepository($this->pdo);

        $this->pdo->exec('DELETE FROM symbol_aliases');
        $this->pdo->exec('DELETE FROM users');

        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('alias-test@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindAlias(): void
    {
        $this->repo->upsert($this->userId, 'GER40.cash', 'DAX40', 'ctrader');

        $alias = $this->repo->findByBrokerSymbol($this->userId, 'GER40.cash', 'ctrader');

        $this->assertNotNull($alias);
        $this->assertSame('GER40.cash', $alias['broker_symbol']);
        $this->assertSame('DAX40', $alias['journal_symbol']);
    }

    public function testUpsertUpdatesExisting(): void
    {
        $this->repo->upsert($this->userId, 'GER40.cash', 'DAX40', 'ctrader');
        $this->repo->upsert($this->userId, 'GER40.cash', 'GER40', 'ctrader');

        $alias = $this->repo->findByBrokerSymbol($this->userId, 'GER40.cash', 'ctrader');

        $this->assertSame('GER40', $alias['journal_symbol']);
    }

    public function testFindAllByUserId(): void
    {
        $this->repo->upsert($this->userId, 'GER40.cash', 'DAX40', 'ctrader');
        $this->repo->upsert($this->userId, 'EURUSD', 'EURUSD', 'ctrader');

        $aliases = $this->repo->findAllByUserId($this->userId);

        $this->assertCount(2, $aliases);
    }

    public function testFindReturnsNullForUnknown(): void
    {
        $alias = $this->repo->findByBrokerSymbol($this->userId, 'UNKNOWN', 'ctrader');
        $this->assertNull($alias);
    }
}
