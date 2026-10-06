<?php

namespace Tests\Integration\Database;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The demo seeder runs on every container boot (`docker/entrypoint.sh`, under
 * `set -e`), so a seeder that fails takes the whole API down in a restart loop.
 * That is what happened on the test environment on 2026-09-24: the second run
 * has to clean up the demo user it created on the first, and deleting a user
 * who owns both a symbol and a trading plan pointing at it hit
 * `fk_plan_symbol` (ON DELETE RESTRICT).
 *
 * The scenario only shows up on the *second* run, which is why no local run
 * ever caught it: the test suite wipes the tables first, so the seeder always
 * met an empty database.
 *
 * Honest limit: this test cannot go red on a developer machine. Whether the
 * cascade from `users` is allowed depends on the engine — MariaDB 11.4 and
 * MySQL 8.4 accept it, the MySQL 9.7 Railway runs refuses it (checked by hand
 * on 2026-09-24). What it does guard, everywhere, is that a second seeding
 * still succeeds and leaves a single demo account behind.
 */
class SeedDemoTest extends TestCase
{
    private const EMAIL = 'demo@2a.journal';

    protected function setUp(): void
    {
        // Load .env, as the other integration tests do.
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
    }

    public function testSeedsTwiceInARowAsAContainerRestartDoes(): void
    {
        $first = $this->runSeeder();
        $this->assertSame(0, $first['code'], "First run failed:\n{$first['output']}");

        $second = $this->runSeeder();
        $this->assertSame(0, $second['code'], "Second run failed:\n{$second['output']}");
        $this->assertStringContainsString('Cleaning up', $second['output']);
    }

    public function testLeavesExactlyOneDemoUserOwningATradingPlan(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => self::EMAIL]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $ids, 'The seeder must replace the demo user, not pile copies up');

        // Guards the scenario itself: a demo account without a plan pointing at
        // one of its own symbols would no longer reproduce the boot failure.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM trading_plans p JOIN symbols s ON s.id = p.symbol_id WHERE p.user_id = :id AND s.user_id = :id2');
        $stmt->execute(['id' => $ids[0], 'id2' => $ids[0]]);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn());
    }

    public function testSeedsTradesScaledOutOverSeveralTargets(): void
    {
        // The demo must show a trade taken off in pieces — targets hit or not,
        // a break-even exit, the rest closed later — or the account export and
        // the trade detail can't be seen on anything but production data.
        $this->runSeeder();
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            "SELECT t.id, JSON_LENGTH(p.targets) AS targets,
                    (SELECT COUNT(*) FROM partial_exits pe WHERE pe.trade_id = t.id) AS exits,
                    (SELECT COUNT(*) FROM partial_exits pe WHERE pe.trade_id = t.id AND pe.target_id IS NOT NULL) AS on_target,
                    (SELECT COUNT(*) FROM status_history sh WHERE sh.entity_type = 'TRADE' AND sh.entity_id = t.id AND sh.new_status = 'SECURED') AS secured
             FROM trades t JOIN positions p ON p.id = t.position_id JOIN users u ON u.id = p.user_id
             WHERE u.email = :email AND t.status = 'CLOSED' AND JSON_LENGTH(p.targets) >= 2"
        );
        $stmt->execute(['email' => self::EMAIL]);
        $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertGreaterThanOrEqual(3, count($trades));
        $scaledOut = array_filter($trades, fn ($t) => $t['exits'] >= 3 && $t['on_target'] >= 1 && $t['secured'] >= 1);
        $this->assertNotEmpty($scaledOut, 'Expected a trade with several exits, some on targets, secured at break-even');

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM orders o JOIN positions p ON p.id = o.position_id JOIN users u ON u.id = p.user_id
             WHERE u.email = :email AND o.status = 'PENDING' AND JSON_LENGTH(p.targets) >= 2"
        );
        $stmt->execute(['email' => self::EMAIL]);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn(), 'Expected a pending order with several targets');
    }

    /**
     * @return array{code: int, output: string}
     */
    private function runSeeder(): array
    {
        $script = __DIR__ . '/../../../database/seed-demo.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';

        $lines = [];
        $code = 0;
        exec($command, $lines, $code);

        return ['code' => $code, 'output' => implode("\n", $lines)];
    }
}
