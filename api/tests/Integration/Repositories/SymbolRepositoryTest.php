<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\SymbolRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class SymbolRepositoryTest extends TestCase
{
    private SymbolRepository $repo;
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
        $this->repo = new SymbolRepository($this->pdo);

        // Ensure all symbols are active before each test
        $this->pdo->exec('UPDATE symbols SET is_active = 1');
    }

    public function testFindAllActiveReturnsSeededSymbols(): void
    {
        $symbols = $this->repo->findAllActive();

        $this->assertIsArray($symbols);
        $this->assertGreaterThanOrEqual(6, count($symbols));

        $codes = array_column($symbols, 'code');
        $this->assertContains('NASDAQ', $codes);
        $this->assertContains('DAX', $codes);
        $this->assertContains('SP500', $codes);
        $this->assertContains('CAC40', $codes);
        $this->assertContains('EURUSD', $codes);
        $this->assertContains('BTCUSD', $codes);
    }

    public function testFindAllActiveReturnsExpectedFields(): void
    {
        $symbols = $this->repo->findAllActive();

        $first = $symbols[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('point_value', $first);
        $this->assertArrayHasKey('currency', $first);
    }

    public function testFindAllActiveExcludesInactive(): void
    {
        // Deactivate one symbol
        $this->pdo->exec("UPDATE symbols SET is_active = 0 WHERE code = 'BTCUSD'");

        $symbols = $this->repo->findAllActive();
        $codes = array_column($symbols, 'code');

        $this->assertNotContains('BTCUSD', $codes);
        $this->assertContains('NASDAQ', $codes);

        // Restore
        $this->pdo->exec("UPDATE symbols SET is_active = 1 WHERE code = 'BTCUSD'");
    }

    public function testFindByCodeReturnsSymbol(): void
    {
        $symbol = $this->repo->findByCode('NASDAQ');

        $this->assertNotNull($symbol);
        $this->assertSame('NASDAQ', $symbol['code']);
        $this->assertSame('INDEX', $symbol['type']);
        $this->assertArrayHasKey('point_value', $symbol);
        $this->assertArrayHasKey('currency', $symbol);
    }

    public function testFindByCodeReturnsNullWhenNotFound(): void
    {
        $symbol = $this->repo->findByCode('NONEXISTENT');

        $this->assertNull($symbol);
    }
}
