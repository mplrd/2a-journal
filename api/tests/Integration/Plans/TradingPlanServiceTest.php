<?php

namespace Tests\Integration\Plans;

use App\Core\Database;
use App\Enums\PlanStatus;
use App\Exceptions\ValidationException;
use App\Repositories\RobotRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\TradingPlanRepository;
use App\Services\TradingPlanService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * CRUD + validation of trading plans (docs/83-trading-plans.md). The plan is a
 * reusable, account-agnostic frame: create/update replace zones and windows in
 * bulk; archiving is refused while an active robot still references the plan.
 */
class TradingPlanServiceTest extends TestCase
{
    private PDO $pdo;
    private TradingPlanRepository $repo;
    private RobotRepository $robotRepo;
    private TradingPlanService $service;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        $this->loadEnv();
        Database::reset();
        $this->pdo = Database::getConnection();
        $this->wipeTables();

        $this->repo = new TradingPlanRepository($this->pdo);
        $this->robotRepo = new RobotRepository($this->pdo);
        $this->service = new TradingPlanService($this->repo, new SymbolRepository($this->pdo));

        $this->userId = $this->seedUser('plan-owner@test.com');
        $this->otherUserId = $this->seedUser('plan-intruder@test.com');
    }

    protected function tearDown(): void
    {
        $this->wipeTables();
    }

    private function fullPlanData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'DAX intraday',
            'allowed_direction' => 'BUY',
            'timezone' => 'Europe/Paris',
            'max_risk_percent' => 1.0,
            'zones' => [
                ['direction' => 'BUY', 'low_price' => 24500, 'high_price' => 24550],
                ['direction' => 'BUY', 'low_price' => 24000, 'high_price' => 24400],
            ],
            'windows' => [
                ['days_mask' => 0b0011111, 'start_time' => '09:00', 'end_time' => '17:30'],
            ],
        ], $overrides);
    }

    public function testCreatePersistsPlanWithZonesAndWindows(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData());

        $this->assertSame('DAX intraday', $plan['name']);
        $this->assertSame('BUY', $plan['allowed_direction']);
        $this->assertSame(PlanStatus::ACTIVE->value, $plan['status']);
        $this->assertCount(2, $plan['zones']);
        $this->assertCount(1, $plan['windows']);
        $this->assertSame('09:00:00', $plan['windows'][0]['start_time']);
    }

    // ── Instrument ciblé ──────────────────────────────────────────

    public function testCreateStoresTheTargetedInstrument(): void
    {
        $this->seedSymbol($this->userId, 'NASDAQ');
        $plan = $this->service->create($this->userId, $this->fullPlanData(['symbol' => 'NASDAQ']));

        $this->assertSame('NASDAQ', $plan['symbol']);
    }

    public function testAPlanWithoutAnInstrumentKeepsItNull(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData());

        $this->assertNull($plan['symbol']);
    }

    /**
     * A typo would otherwise reject every single signal, silently: the plan
     * would target an instrument that never arrives.
     */
    public function testAnInstrumentTheUserDoesNotOwnIsRefused(): void
    {
        $this->seedSymbol($this->userId, 'NASDAQ');

        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData(['symbol' => 'NASDQ']));
    }

    public function testAnotherUsersInstrumentIsRefused(): void
    {
        $this->seedSymbol($this->otherUserId, 'NASDAQ');

        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData(['symbol' => 'NASDAQ']));
    }

    public function testTheInstrumentIsStoredInItsCanonicalForm(): void
    {
        $this->seedSymbol($this->userId, 'NASDAQ');
        $plan = $this->service->create($this->userId, $this->fullPlanData(['symbol' => ' nasdaq ']));

        $this->assertSame('NASDAQ', $plan['symbol']);
    }

    public function testUpdateCanClearTheInstrument(): void
    {
        $this->seedSymbol($this->userId, 'NASDAQ');
        $plan = $this->service->create($this->userId, $this->fullPlanData(['symbol' => 'NASDAQ']));

        $updated = $this->service->update($this->userId, (int) $plan['id'], $this->fullPlanData(['symbol' => null]));

        $this->assertNull($updated['symbol']);
    }

    public function testCreateNormalizesZoneBounds(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData([
            'zones' => [['direction' => 'BUY', 'low_price' => 24400, 'high_price' => 24000]],
        ]));

        $zone = $plan['zones'][0];
        $this->assertSame(24000.0, (float) $zone['low_price']);
        $this->assertSame(24400.0, (float) $zone['high_price']);
    }

    public function testListReturnsOnlyOwnActivePlans(): void
    {
        $this->service->create($this->userId, $this->fullPlanData(['name' => 'Mine']));
        $this->service->create($this->otherUserId, $this->fullPlanData(['name' => 'Theirs']));

        $plans = $this->service->listForUser($this->userId);
        $this->assertCount(1, $plans);
        $this->assertSame('Mine', $plans[0]['name']);
    }

    public function testUpdateReplacesZonesAndWindows(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData());
        $updated = $this->service->update($this->userId, (int) $plan['id'], $this->fullPlanData([
            'name' => 'DAX v2',
            'zones' => [['direction' => 'SELL', 'low_price' => 25000, 'high_price' => 25100]],
            'windows' => [],
        ]));

        $this->assertSame('DAX v2', $updated['name']);
        $this->assertCount(1, $updated['zones']);
        $this->assertSame('SELL', $updated['zones'][0]['direction']);
        $this->assertCount(0, $updated['windows']);
    }

    public function testArchiveHidesPlanFromList(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData());
        $this->service->archive($this->userId, (int) $plan['id']);

        $this->assertCount(0, $this->service->listForUser($this->userId));
    }

    public function testArchiveIsBlockedWhileAnActiveRobotUsesThePlan(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData());
        $accountId = $this->seedAccount($this->userId);
        $robot = $this->robotRepo->create([
            'user_id' => $this->userId,
            'account_id' => $accountId,
            'name' => 'Bot',
        ]);
        $this->repo->setRobotPlans((int) $robot['id'], [(int) $plan['id']]);

        $this->expectException(ValidationException::class);
        $this->service->archive($this->userId, (int) $plan['id']);
    }

    public function testCannotAccessAnotherUsersPlan(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData());
        $this->expectException(\App\Exceptions\ForbiddenException::class);
        $this->service->getForUser($this->otherUserId, (int) $plan['id']);
    }

    public function testInvalidDirectionIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData(['allowed_direction' => 'LONG']));
    }

    public function testWindowsWithoutTimezoneAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData([
            'timezone' => null,
            'windows' => [['days_mask' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
        ]));
    }

    public function testWindowWithStartAfterEndIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData([
            'windows' => [['days_mask' => 1, 'start_time' => '18:00', 'end_time' => '09:00']],
        ]));
    }

    public function testZoneWithNonPositivePriceIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData([
            'zones' => [['direction' => 'BUY', 'low_price' => 0, 'high_price' => 100]],
        ]));
    }

    // ── Plafond de risque cumulé ──────────────────────────────────
    // Le plafond par trade ne dit rien de l'exposition totale : vingt entrées à
    // 1 % chacune respectent la règle et engagent 20 %.

    public function testCumulativeRiskCapIsPersisted(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData([
            'max_plan_risk_percent' => 5.0,
        ]));
        $this->assertSame(5.0, (float) $plan['max_plan_risk_percent']);
    }

    public function testCumulativeRiskCapIsOptional(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData());
        $this->assertNull($plan['max_plan_risk_percent']);
    }

    public function testCumulativeRiskCapCanBeCleared(): void
    {
        $plan = $this->service->create($this->userId, $this->fullPlanData(['max_plan_risk_percent' => 5.0]));
        $updated = $this->service->update($this->userId, (int) $plan['id'], $this->fullPlanData([
            'max_plan_risk_percent' => null,
        ]));
        $this->assertNull($updated['max_plan_risk_percent']);
    }

    public function testANonPositiveCumulativeRiskCapIsRejected(): void
    {
        // Zero would refuse every signal without ever saying why on screen.
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData(['max_plan_risk_percent' => 0]));
    }

    /**
     * Both caps live in a DECIMAL(6,3). Unbounded, a larger value passed
     * validation and blew up on write — a 500 under the production sql_mode,
     * where the user deserves a field message.
     */
    public function testARiskCapBeyondWhatTheColumnHoldsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData(['max_plan_risk_percent' => 1000]));
    }

    public function testAPerTradeRiskCapBeyondWhatTheColumnHoldsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userId, $this->fullPlanData(['max_risk_percent' => 1000]));
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function seedUser(string $email): int
    {
        $this->pdo->prepare("INSERT INTO users (email, password, locale) VALUES (:email, :pw, 'fr')")
            ->execute(['email' => $email, 'pw' => password_hash('Test1234!', PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedAccount(int $userId): int
    {
        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, currency)
             VALUES (:u, 'Plan Account', 'BROKER_DEMO', 10000, 'USD')"
        )->execute(['u' => $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedSymbol(int $userId, string $code): int
    {
        $this->pdo->prepare(
            "INSERT INTO symbols (user_id, code, name, type, point_value, currency)
             VALUES (:u, :code, :code2, 'INDEX', 1, 'USD')"
        )->execute(['u' => $userId, 'code' => $code, 'code2' => $code]);
        return (int) $this->pdo->lastInsertId();
    }

    private function wipeTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'symbols',
            'robot_plans',
            'trading_plan_zones',
            'trading_plan_windows',
            'trading_plans',
            'tradingview_webhooks',
            'robots',
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
