<?php

namespace Tests\Integration\Import;

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\TradeRepository;
use App\Services\Import\ColumnMapperService;
use App\Services\Import\FileParserService;
use App\Services\Import\ImportService;
use App\Services\Import\RowGroupingService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * What an import is allowed to consider a duplicate.
 *
 * The rule this pins down: an import into an account depends on that account
 * and nothing else. Deduplication used to read every position of the *user*,
 * so an external_id seen anywhere — including on an account the user had
 * deleted — silently swallowed the row. Deleting an account and re-importing
 * it into a fresh one, which is the obvious way to start clean, lost trades
 * with no error and no warning: the only trace was a skipped_duplicates
 * counter nothing displays.
 *
 * Observed on the test environment on 2026-08-10: two freshly created accounts
 * lost 8 and 6 positions to accounts deleted thirty seconds earlier.
 */
class ImportDeduplicationTest extends TestCase
{
    private PDO $pdo;
    private ImportService $service;
    private PositionRepository $positionRepo;
    private int $userId;
    private int $sourceAccountId;
    private int $freshAccountId;

    protected function setUp(): void
    {
        $this->loadEnv();
        Database::reset();
        $this->pdo = Database::getConnection();
        $this->wipeTables();

        $this->positionRepo = new PositionRepository($this->pdo);
        $this->service = new ImportService(
            new FileParserService(),
            new ColumnMapperService(),
            new RowGroupingService(),
            new ImportBatchRepository($this->pdo),
            new SymbolAliasRepository($this->pdo),
            new SymbolRepository($this->pdo),
            $this->positionRepo,
            new TradeRepository($this->pdo),
            new AccountRepository($this->pdo),
            $this->pdo,
        );

        $this->userId = $this->seedUser('import-dedup@test.com');
        $this->sourceAccountId = $this->seedAccount('Ancien compte');
        $this->freshAccountId = $this->seedAccount('Compte neuf');
    }

    protected function tearDown(): void
    {
        $this->wipeTables();
    }

    // ── The rule: one account, one scope ────────────────────────────

    public function testImportsIntoAFreshAccountDespiteTheSameIdOnAnotherAccount(): void
    {
        $this->seedPosition($this->sourceAccountId, 'ctrader_57365388');

        $result = $this->import($this->freshAccountId, ['ctrader_57365388']);

        $this->assertSame(1, $result['imported_positions']);
        $this->assertSame(0, $result['skipped_duplicates']);
    }

    public function testImportsDespiteTheSameIdOnAnAccountTheUserDeleted(): void
    {
        // The scenario that lost the trades: delete the account, create a new
        // one, re-import. Deleting must free the identifiers it held.
        $this->seedPosition($this->sourceAccountId, 'ctrader_57365388');
        $this->softDeleteAccount($this->sourceAccountId);

        $result = $this->import($this->freshAccountId, ['ctrader_57365388']);

        $this->assertSame(1, $result['imported_positions']);
        $this->assertSame(0, $result['skipped_duplicates']);
        // A counter that moved is not proof the row landed where it was asked
        // to: the whole bug was rows going missing without one.
        $this->assertSame(1, $this->countPositionsIn($this->freshAccountId));
    }

    public function testStillRefusesADuplicateInsideTheSameAccount(): void
    {
        // The protection that must survive: re-syncing an account does not
        // import its own history twice.
        $this->seedPosition($this->freshAccountId, 'ctrader_57365388');

        $result = $this->import($this->freshAccountId, ['ctrader_57365388']);

        $this->assertSame(0, $result['imported_positions']);
        $this->assertSame(1, $result['skipped_duplicates']);
    }

    public function testImportsOnlyTheRowsThatAreNewToTheAccount(): void
    {
        $this->seedPosition($this->freshAccountId, 'ctrader_1');
        $this->seedPosition($this->sourceAccountId, 'ctrader_2');

        $result = $this->import($this->freshAccountId, ['ctrader_1', 'ctrader_2', 'ctrader_3']);

        // ctrader_1 is already here; ctrader_2 belongs to another account and
        // must not count; ctrader_3 is new.
        $this->assertSame(2, $result['imported_positions']);
        $this->assertSame(1, $result['skipped_duplicates']);
    }

    public function testAnotherUsersPositionsNeverBlockAnImport(): void
    {
        $otherUser = $this->seedUser('import-dedup-other@test.com');
        $otherAccount = $this->seedAccount('Compte tiers', $otherUser);
        $this->seedPosition($otherAccount, 'ctrader_57365388', $otherUser);

        $result = $this->import($this->freshAccountId, ['ctrader_57365388']);

        $this->assertSame(1, $result['imported_positions']);
    }

    public function testTheSkippedCountIsReportedSoNothingVanishesInSilence(): void
    {
        // The counter is what a caller needs to be able to say "14 rows were
        // ignored". Losing rows without a number to show is what turned this
        // bug into an afternoon of guessing.
        $this->seedPosition($this->freshAccountId, 'ctrader_1');
        $this->seedPosition($this->freshAccountId, 'ctrader_2');

        $result = $this->import($this->freshAccountId, ['ctrader_1', 'ctrader_2', 'ctrader_3']);

        $this->assertSame(2, $result['skipped_duplicates']);
        $this->assertSame(1, $result['imported_positions']);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Rows shaped exactly like RowGroupingService::group() emits them — same
     * keys, same order. A fixture that drifts from that contract tests nothing.
     *
     * @param list<string> $externalIds
     */
    private function import(int $accountId, array $externalIds): array
    {
        $positions = array_map(
            fn(string $id) => [
                'symbol' => 'GER40',
                'direction' => 'BUY',
                'entry_price' => 19200.0,
                'total_size' => 1.0,
                'total_pnl' => 26.0,
                'avg_exit_price' => 19226.0,
                'opened_at' => '2026-08-03 09:00:00',
                'closed_at' => '2026-08-03 10:00:00',
                'comment' => null,
                'external_id' => $id,
                'exits' => [],
            ],
            $externalIds,
        );

        return $this->service->importNormalizedPositions(
            $this->userId,
            $accountId,
            $positions,
            'api-sync-ctrader',
            'ctrader',
        );
    }

    private function countPositionsIn(int $accountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM positions WHERE account_id = :a');
        $stmt->execute(['a' => $accountId]);

        return (int) $stmt->fetchColumn();
    }

    private function seedPosition(int $accountId, string $externalId, ?int $userId = null): void
    {
        $this->pdo->prepare(
            "INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, external_id, position_type)
             VALUES (:u, :a, 'BUY', 'GER40', 19200, 1, :e, 'TRADE')"
        )->execute(['u' => $userId ?? $this->userId, 'a' => $accountId, 'e' => $externalId]);
    }

    private function softDeleteAccount(int $accountId): void
    {
        $this->pdo->prepare("UPDATE accounts SET deleted_at = NOW() WHERE id = :id")
            ->execute(['id' => $accountId]);
    }

    private function seedUser(string $email): int
    {
        $this->pdo->prepare("INSERT INTO users (email, password, locale) VALUES (:email, :pw, 'fr')")
            ->execute(['email' => $email, 'pw' => password_hash('Test1234!', PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedAccount(string $name, ?int $userId = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, currency)
             VALUES (:u, :n, 'BROKER_DEMO', 10000, 'EUR')"
        )->execute(['u' => $userId ?? $this->userId, 'n' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function wipeTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['partial_exits', 'trades', 'positions', 'import_batches', 'accounts', 'users'] as $table) {
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
