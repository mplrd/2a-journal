<?php

namespace Tests\Integration\Symbols;

use App\Core\Database;
use App\Repositories\PositionRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;
use App\Services\SymbolCodeRenamer;
use App\Services\SymbolService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * An asset's code is copied as a bare string into three other places, with no
 * foreign key and, until now, no propagation (docs/evolutions.md):
 *
 *   positions.symbol               every trade and order
 *   trading_plans.symbol           the asset a plan targets (docs/99)
 *   symbol_aliases.journal_symbol  the broker mappings pointing at it
 *
 * Renaming DE40.CASH to GER40 from My assets therefore detached the lot in
 * silence: historical positions could no longer be priced, statistics split in
 * two for one market, and the trading plan went on targeting a code nothing
 * carried any more — so it matched no signal at all, without a word.
 */
class SymbolCodeRenameTest extends TestCase
{
    private PDO $pdo;
    private SymbolService $service;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        $this->loadEnv();
        Database::reset();
        $this->pdo = Database::getConnection();
        $this->wipeTables();

        $symbolRepo = new SymbolRepository($this->pdo);
        $this->service = new SymbolService(
            $symbolRepo,
            null,
            null,
            new SymbolCodeRenamer(
                $symbolRepo,
                new PositionRepository($this->pdo),
                new SymbolAliasRepository($this->pdo),
                $this->pdo,
            ),
        );

        $this->userId = $this->seedUser('rename-owner@test.com');
        $this->otherUserId = $this->seedUser('rename-bystander@test.com');
    }

    protected function tearDown(): void
    {
        $this->wipeTables();
    }

    public function testRenamingTheCodeCarriesThePositionsAlong(): void
    {
        $symbolId = $this->seedSymbol($this->userId, 'DE40.CASH');
        $this->seedPosition($this->userId, 'DE40.CASH');

        $this->service->update($this->userId, $symbolId, ['code' => 'GER40', 'name' => 'DAX 40', 'type' => 'INDEX']);

        $this->assertSame(['GER40'], $this->positionSymbols($this->userId));
    }

    /**
     * A plan needs no cascade at all: it references the asset by symbol_id with
     * a foreign key (migration 042), so it follows a rename on its own. The
     * first version of that migration copied the code like the two tables above,
     * and a rename left the plan targeting a code nothing carried — so it matched
     * no signal, silently. This is the proof it no longer can.
     */
    public function testAPlanFollowsTheRenameWithoutBeingTouched(): void
    {
        $symbolId = $this->seedSymbol($this->userId, 'DE40.CASH');
        $this->seedPlan($this->userId, $symbolId);

        $this->service->update($this->userId, $symbolId, ['code' => 'GER40', 'name' => 'DAX 40', 'type' => 'INDEX']);

        $this->assertSame(['GER40'], $this->planSymbols($this->userId));
    }

    public function testRenamingTheCodeCarriesTheBrokerAliasesAlong(): void
    {
        $symbolId = $this->seedSymbol($this->userId, 'DE40.CASH');
        $this->seedAlias($this->userId, 'GER40.pro', 'DE40.CASH');

        $this->service->update($this->userId, $symbolId, ['code' => 'GER40', 'name' => 'DAX 40', 'type' => 'INDEX']);

        $this->assertSame(['GER40'], $this->aliasTargets($this->userId));
    }

    public function testAnotherUsersRowsAreLeftAlone(): void
    {
        // Codes are unique per user, not globally: two users owning DE40.CASH
        // is the normal case, not an edge one.
        $symbolId = $this->seedSymbol($this->userId, 'DE40.CASH');
        $otherSymbolId = $this->seedSymbol($this->otherUserId, 'DE40.CASH');
        $this->seedPosition($this->otherUserId, 'DE40.CASH');
        $this->seedPlan($this->otherUserId, $otherSymbolId);
        $this->seedAlias($this->otherUserId, 'GER40.pro', 'DE40.CASH');

        $this->service->update($this->userId, $symbolId, ['code' => 'GER40', 'name' => 'DAX 40', 'type' => 'INDEX']);

        $this->assertSame(['DE40.CASH'], $this->positionSymbols($this->otherUserId));
        $this->assertSame(['DE40.CASH'], $this->planSymbols($this->otherUserId));
        $this->assertSame(['DE40.CASH'], $this->aliasTargets($this->otherUserId));
    }

    public function testEditingOnlyTheNameTouchesNothingElse(): void
    {
        $symbolId = $this->seedSymbol($this->userId, 'DE40.CASH');
        $this->seedPosition($this->userId, 'DE40.CASH');

        $this->service->update($this->userId, $symbolId, ['code' => 'DE40.CASH', 'name' => 'DAX Allemagne', 'type' => 'INDEX']);

        $this->assertSame(['DE40.CASH'], $this->positionSymbols($this->userId));
        $this->assertSame('DAX Allemagne', $this->service->get($this->userId, $symbolId)['name']);
    }

    public function testTheRenameAndItsCascadeAreOneUnit(): void
    {
        // A cascade that half-applied would be worse than none: some positions
        // priced, others orphaned, with nothing to tell them apart afterwards.
        $symbolId = $this->seedSymbol($this->userId, 'DE40.CASH');
        $this->seedSymbol($this->userId, 'GER40');   // the new code is taken
        $this->seedPosition($this->userId, 'DE40.CASH');

        try {
            $this->service->update($this->userId, $symbolId, ['code' => 'GER40', 'name' => 'DAX 40', 'type' => 'INDEX']);
            $this->fail('a duplicate code should have been refused');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(['DE40.CASH'], $this->positionSymbols($this->userId));
        $this->assertSame('DE40.CASH', $this->service->get($this->userId, $symbolId)['code']);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function positionSymbols(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT symbol FROM positions WHERE user_id = :u ORDER BY id');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Read through the join, exactly as the evaluator gets it. */
    private function planSymbols(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.code FROM trading_plans p
             INNER JOIN symbols s ON s.id = p.symbol_id
             WHERE p.user_id = :u ORDER BY p.id'
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function aliasTargets(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT journal_symbol FROM symbol_aliases WHERE user_id = :u ORDER BY id');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function seedUser(string $email): int
    {
        $this->pdo->prepare("INSERT INTO users (email, password, locale) VALUES (:email, :pw, 'fr')")
            ->execute(['email' => $email, 'pw' => password_hash('Test1234', PASSWORD_BCRYPT)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedAccount(int $userId): int
    {
        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, current_capital, currency)
             VALUES (:u, 'Compte', 'BROKER_LIVE', 10000, 10000, 'EUR')"
        )->execute(['u' => $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedSymbol(int $userId, string $code): int
    {
        // Deux placeholders distincts : un nom répété est refusé quand
        // ATTR_EMULATE_PREPARES est désactivé.
        $this->pdo->prepare(
            "INSERT INTO symbols (user_id, code, name, type, point_value, currency)
             VALUES (:u, :code, :name, 'INDEX', 25, 'EUR')"
        )->execute(['u' => $userId, 'code' => $code, 'name' => $code]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedPosition(int $userId, string $symbol): void
    {
        $this->pdo->prepare(
            "INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, position_type)
             VALUES (:u, :a, 'BUY', :s, 18000, 1, 'TRADE')"
        )->execute(['u' => $userId, 'a' => $this->seedAccount($userId), 's' => $symbol]);
    }

    private function seedPlan(int $userId, int $symbolId): void
    {
        $this->pdo->prepare(
            "INSERT INTO trading_plans (user_id, name, symbol_id, status) VALUES (:u, 'Plan', :s, 'ACTIVE')"
        )->execute(['u' => $userId, 's' => $symbolId]);
    }

    private function seedAlias(int $userId, string $brokerSymbol, string $journalSymbol): void
    {
        $this->pdo->prepare(
            "INSERT INTO symbol_aliases (user_id, broker_symbol, journal_symbol, broker_template)
             VALUES (:u, :b, :j, 'MT5')"
        )->execute(['u' => $userId, 'b' => $brokerSymbol, 'j' => $journalSymbol]);
    }

    private function wipeTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'trading_plan_zones',
            'trading_plan_windows',
            'robot_plans',
            'trading_plans',
            'symbol_aliases',
            'trades',
            'orders',
            'positions',
            'symbols',
            'accounts',
            'users',
        ] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function loadEnv(): void
    {
        $envFile = __DIR__ . '/../../../.env';
        if (!file_exists($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
}
