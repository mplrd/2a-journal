<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\PositionRepository;
use App\Repositories\StatsRepository;
use App\Repositories\TradeRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class StatsRepositoryTest extends TestCase
{
    private StatsRepository $repo;
    private TradeRepository $tradeRepo;
    private PositionRepository $positionRepo;
    private PDO $pdo;
    private int $userId;
    private int $accountId;
    private int $accountId2;

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
        $this->repo = new StatsRepository($this->pdo);
        $this->tradeRepo = new TradeRepository($this->pdo);
        $this->positionRepo = new PositionRepository($this->pdo);

        // Clean tables
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');

        // Create test user
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('stats-test@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();

        // Create two accounts
        $accountRepo = new AccountRepository($this->pdo);
        $account = $accountRepo->create([
            'user_id' => $this->userId,
            'name' => 'Account 1',
            'account_type' => 'BROKER_DEMO',
        ]);
        $this->accountId = (int) $account['id'];

        $account2 = $accountRepo->create([
            'user_id' => $this->userId,
            'name' => 'Account 2',
            'account_type' => 'BROKER_LIVE',
        ]);
        $this->accountId2 = (int) $account2['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');
    }

    private function createClosedTrade(float $pnl, string $exitType = 'TP', array $overrides = []): array
    {
        $entryPrice = (float) ($overrides['entry_price'] ?? 18500);
        $size = (float) ($overrides['size'] ?? 1);

        $position = $this->positionRepo->create(array_merge([
            'user_id' => $this->userId,
            'account_id' => $overrides['account_id'] ?? $this->accountId,
            'direction' => 'BUY',
            'symbol' => $overrides['symbol'] ?? 'NASDAQ',
            'entry_price' => $entryPrice,
            'size' => $size,
            'setup' => '["Breakout"]',
            'sl_points' => '50.00',
            'sl_price' => '18450.00000',
            'position_type' => 'TRADE',
        ], array_intersect_key($overrides, array_flip(['account_id', 'symbol', 'direction', 'size', 'entry_price', 'setup']))));

        $trade = $this->tradeRepo->create([
            'position_id' => (int) $position['id'],
            'opened_at' => $overrides['opened_at'] ?? '2026-01-15 10:00:00',
            'remaining_size' => 0,
            'status' => 'CLOSED',
        ]);

        $riskReward = $pnl > 0 ? abs($pnl) / 50.0 : ($pnl < 0 ? -abs($pnl) / 50.0 : 0);

        $entryValue = $entryPrice * $size;
        $pnlPercent = array_key_exists('pnl_percent', $overrides)
            ? (float) $overrides['pnl_percent']
            : ($entryValue > 0 ? round($pnl / $entryValue * 100, 4) : 0.0);

        $closedAt = $overrides['closed_at'] ?? '2026-01-15 11:00:00';
        $this->tradeRepo->update((int) $trade['id'], [
            'status' => 'CLOSED',
            'exit_type' => $exitType,
            'pnl' => $pnl,
            'pnl_percent' => $pnlPercent,
            'risk_reward' => $riskReward,
            'closed_at' => $closedAt,
        ]);

        // Create partial exit so cumulative PnL query (via partial_exits) works
        $this->pdo->prepare(
            "INSERT INTO partial_exits (trade_id, exited_at, exit_price, size, exit_type, pnl)
             VALUES (:trade_id, :exited_at, :exit_price, :size, :exit_type, :pnl)"
        )->execute([
            'trade_id' => (int) $trade['id'],
            'exited_at' => $closedAt,
            'exit_price' => 18550.00,
            'size' => $overrides['size'] ?? 1.0,
            'exit_type' => $exitType,
            'pnl' => $pnl,
        ]);

        return $this->tradeRepo->findById((int) $trade['id']);
    }

    private function createOpenTrade(string $status = 'OPEN'): array
    {
        $position = $this->positionRepo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => '18500.00000',
            'size' => '1.00000',
            'setup' => '["Breakout"]',
            'sl_points' => '50.00',
            'sl_price' => '18450.00000',
            'position_type' => 'TRADE',
        ]);

        return $this->tradeRepo->create([
            'position_id' => (int) $position['id'],
            'opened_at' => '2026-01-15 10:00:00',
            'remaining_size' => 1.0,
            'status' => $status,
        ]);
    }

    /**
     * Trade that has taken at least one partial exit but is not fully closed.
     * `status` defaults to SECURED (matches TradeService auto-promotion) but can
     * be overridden — for example, a workflow where the user takes a partial TP
     * without moving the SL to BE keeps the trade in OPEN. Stats inclusion is
     * driven by `trades.pnl IS NOT NULL`, not by status.
     */
    private function createTradeWithPartial(float $partialPnl, array $overrides = []): array
    {
        $entryPrice = (float) ($overrides['entry_price'] ?? 18500);
        $size = (float) ($overrides['size'] ?? 2);
        $status = $overrides['status'] ?? 'SECURED';

        $position = $this->positionRepo->create([
            'user_id' => $this->userId,
            'account_id' => $overrides['account_id'] ?? $this->accountId,
            'direction' => $overrides['direction'] ?? 'BUY',
            'symbol' => $overrides['symbol'] ?? 'NASDAQ',
            'entry_price' => $entryPrice,
            'size' => $size,
            'setup' => $overrides['setup'] ?? '["Breakout"]',
            'sl_points' => '50.00',
            'sl_price' => '18450.00000',
            'position_type' => 'TRADE',
        ]);

        $trade = $this->tradeRepo->create([
            'position_id' => (int) $position['id'],
            'opened_at' => $overrides['opened_at'] ?? '2026-01-15 10:00:00',
            'remaining_size' => $size - 1, // 1 unit exited as partial
            'status' => $status,
        ]);

        $entryValue = $entryPrice * $size;
        $pnlPercent = $entryValue > 0 ? round($partialPnl / $entryValue * 100, 4) : 0.0;
        $rr = round($partialPnl / ($size * 50.0), 4);

        $this->tradeRepo->update((int) $trade['id'], [
            'pnl' => $partialPnl,
            'pnl_percent' => $pnlPercent,
            'risk_reward' => $rr,
        ]);

        $exitedAt = $overrides['exited_at'] ?? '2026-01-15 11:00:00';
        $this->pdo->prepare(
            "INSERT INTO partial_exits (trade_id, exited_at, exit_price, size, exit_type, pnl)
             VALUES (:trade_id, :exited_at, :exit_price, :size, :exit_type, :pnl)"
        )->execute([
            'trade_id' => (int) $trade['id'],
            'exited_at' => $exitedAt,
            'exit_price' => 18550.00,
            'size' => 1.0,
            'exit_type' => 'TP',
            'pnl' => $partialPnl,
        ]);

        return $this->tradeRepo->findById((int) $trade['id']);
    }

    // ── getOverview ─────────────────────────────────────────────

    public function testGetOverviewCountsAndPnlMetrics(): void
    {
        $this->createClosedTrade(100.0, 'TP');   // Win
        $this->createClosedTrade(200.0, 'TP');   // Win
        $this->createClosedTrade(-50.0, 'SL');   // Loss

        $overview = $this->repo->getOverview($this->userId);

        $this->assertSame(3, $overview['total_trades']);
        $this->assertEquals(250.0, (float) $overview['total_pnl']);
        $this->assertSame(2, $overview['winning_trades']);
        $this->assertSame(1, $overview['losing_trades']);
        $this->assertEquals(200.0, (float) $overview['best_trade']);
        $this->assertEquals(-50.0, (float) $overview['worst_trade']);
    }

    public function testGetOverviewWinRateAndRatios(): void
    {
        $this->createClosedTrade(100.0, 'TP');
        $this->createClosedTrade(200.0, 'TP');
        $this->createClosedTrade(-50.0, 'SL');

        $overview = $this->repo->getOverview($this->userId);

        // Win rate: 2/3 = 66.67%
        $this->assertEqualsWithDelta(66.67, (float) $overview['win_rate'], 0.01);
        // Profit factor: (100+200)/50 = 6.0
        $this->assertEquals(6.0, (float) $overview['profit_factor']);
        // Avg RR: average of risk_reward values
        $this->assertArrayHasKey('avg_rr', $overview);
    }

    public function testGetOverviewFiltersByAccountId(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['account_id' => $this->accountId]);
        $this->createClosedTrade(200.0, 'TP', ['account_id' => $this->accountId2]);

        $overview = $this->repo->getOverview($this->userId, ['account_id' => $this->accountId]);

        $this->assertSame(1, $overview['total_trades']);
        $this->assertEquals(100.0, (float) $overview['total_pnl']);
    }

    public function testGetOverviewExcludesOpenTradesWithoutExits(): void
    {
        // OPEN trades with no partial exits are still excluded — no realized P&L yet.
        $this->createClosedTrade(100.0, 'TP');
        $this->createOpenTrade();

        $overview = $this->repo->getOverview($this->userId);

        $this->assertSame(1, $overview['total_trades']);
        $this->assertEquals(100.0, (float) $overview['total_pnl']);
    }

    public function testGetOverviewIncludesSecuredTradesWithRealizedPnl(): void
    {
        // SECURED trades hold realized P&L from partial exits — they must count.
        $this->createClosedTrade(100.0, 'TP');
        $this->createTradeWithPartial(50.0);

        $overview = $this->repo->getOverview($this->userId);

        $this->assertSame(2, $overview['total_trades']);
        $this->assertEquals(150.0, (float) $overview['total_pnl']);
    }

    public function testGetOverviewIncludesOpenTradesWithPartialExits(): void
    {
        // A trade can keep status OPEN after taking a partial TP (e.g. when the
        // SL is left at the original level — the trade is not "secured" in the
        // trader's sense). Realized P&L from that partial must still appear.
        $this->createClosedTrade(100.0, 'TP');
        $this->createTradeWithPartial(75.0, ['status' => 'OPEN']);

        $overview = $this->repo->getOverview($this->userId);

        $this->assertSame(2, $overview['total_trades']);
        $this->assertEquals(175.0, (float) $overview['total_pnl']);
    }

    public function testGetOverviewFiltersDateRangeUsesPartialExitDateForSecured(): void
    {
        // SECURED trade with last partial in February → excluded by January filter.
        $this->createTradeWithPartial(50.0, [
            'opened_at' => '2026-02-10 10:00:00',
            'exited_at' => '2026-02-15 11:00:00',
        ]);

        $overview = $this->repo->getOverview($this->userId, [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $this->assertSame(0, $overview['total_trades']);
    }

    public function testGetOverviewEmptyState(): void
    {
        $overview = $this->repo->getOverview($this->userId);

        $this->assertSame(0, $overview['total_trades']);
        $this->assertEquals(0, (float) $overview['total_pnl']);
        $this->assertEquals(0, (float) $overview['win_rate']);
        $this->assertNull($overview['profit_factor']);
        $this->assertNull($overview['best_trade']);
        $this->assertNull($overview['worst_trade']);
        $this->assertNull($overview['avg_rr']);
    }

    // ── getCumulativePnl ────────────────────────────────────────

    public function testGetCumulativePnlReturnsChronologicalSeries(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-10 10:00:00']);
        $this->createClosedTrade(-30.0, 'SL', ['closed_at' => '2026-01-11 10:00:00']);
        $this->createClosedTrade(50.0, 'TP', ['closed_at' => '2026-01-12 10:00:00']);

        $series = $this->repo->getCumulativePnl($this->userId);

        $this->assertCount(3, $series);
        // Cumulative: 100, 70, 120
        $this->assertEquals(100.0, (float) $series[0]['cumulative_pnl']);
        $this->assertEquals(70.0, (float) $series[1]['cumulative_pnl']);
        $this->assertEquals(120.0, (float) $series[2]['cumulative_pnl']);
    }

    // ── getWinLossDistribution ──────────────────────────────────

    public function testGetWinLossDistributionCounts(): void
    {
        $this->createClosedTrade(100.0, 'TP');
        $this->createClosedTrade(200.0, 'TP');
        $this->createClosedTrade(0.0, 'BE');
        $this->createClosedTrade(-50.0, 'SL');

        $dist = $this->repo->getWinLossDistribution($this->userId);

        $this->assertSame(2, $dist['win']);
        $this->assertSame(1, $dist['loss']);
        $this->assertSame(1, $dist['be']);
    }

    // ── getPnlBySymbol ──────────────────────────────────────────

    public function testGetPnlBySymbolGroupsCorrectly(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['symbol' => 'NASDAQ']);
        $this->createClosedTrade(50.0, 'TP', ['symbol' => 'NASDAQ']);
        $this->createClosedTrade(-30.0, 'SL', ['symbol' => 'DAX']);

        $bySymbol = $this->repo->getPnlBySymbol($this->userId);

        $this->assertCount(2, $bySymbol);

        $indexed = [];
        foreach ($bySymbol as $row) {
            $indexed[$row['symbol']] = $row;
        }

        $this->assertEquals(150.0, (float) $indexed['NASDAQ']['total_pnl']);
        $this->assertSame(2, (int) $indexed['NASDAQ']['trade_count']);
        $this->assertEquals(-30.0, (float) $indexed['DAX']['total_pnl']);
    }

    // ── Advanced filters ─────────────────────────────────────────

    public function testGetOverviewFiltersDateRange(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-10 10:00:00']);
        $this->createClosedTrade(200.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(-50.0, 'SL', ['closed_at' => '2026-01-20 10:00:00']);

        $overview = $this->repo->getOverview($this->userId, [
            'date_from' => '2026-01-12',
            'date_to' => '2026-01-16',
        ]);

        $this->assertSame(1, $overview['total_trades']);
        $this->assertEquals(200.0, (float) $overview['total_pnl']);
    }

    public function testGetOverviewFiltersDirection(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['direction' => 'BUY']);
        $this->createClosedTrade(200.0, 'TP', ['direction' => 'SELL']);

        $overview = $this->repo->getOverview($this->userId, ['direction' => 'SELL']);

        $this->assertSame(1, $overview['total_trades']);
        $this->assertEquals(200.0, (float) $overview['total_pnl']);
    }

    public function testGetOverviewFiltersSymbols(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['symbol' => 'NASDAQ']);
        $this->createClosedTrade(200.0, 'TP', ['symbol' => 'DAX']);
        $this->createClosedTrade(-50.0, 'SL', ['symbol' => 'EURUSD']);

        $overview = $this->repo->getOverview($this->userId, ['symbols' => ['NASDAQ', 'DAX']]);

        $this->assertSame(2, $overview['total_trades']);
        $this->assertEquals(300.0, (float) $overview['total_pnl']);
    }

    public function testGetOverviewFiltersSetups(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["Breakout"]']);
        $this->createClosedTrade(200.0, 'TP', ['setup' => '["Pullback"]']);
        $this->createClosedTrade(-50.0, 'SL', ['setup' => '["Range"]']);

        $overview = $this->repo->getOverview($this->userId, ['setups' => ['Breakout', 'Pullback']]);

        $this->assertSame(2, $overview['total_trades']);
        $this->assertEquals(300.0, (float) $overview['total_pnl']);
    }

    public function testGetOverviewFiltersCombined(): void
    {
        $this->createClosedTrade(100.0, 'TP', [
            'direction' => 'BUY', 'symbol' => 'NASDAQ',
            'closed_at' => '2026-01-15 10:00:00',
        ]);
        $this->createClosedTrade(200.0, 'TP', [
            'direction' => 'BUY', 'symbol' => 'NASDAQ',
            'closed_at' => '2026-01-20 10:00:00',
        ]);
        $this->createClosedTrade(-50.0, 'SL', [
            'direction' => 'SELL', 'symbol' => 'NASDAQ',
            'closed_at' => '2026-01-15 10:00:00',
        ]);

        $overview = $this->repo->getOverview($this->userId, [
            'direction' => 'BUY',
            'symbols' => ['NASDAQ'],
            'date_from' => '2026-01-14',
            'date_to' => '2026-01-16',
        ]);

        $this->assertSame(1, $overview['total_trades']);
        $this->assertEquals(100.0, (float) $overview['total_pnl']);
    }

    public function testGetCumulativePnlFiltersDateRange(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-10 10:00:00']);
        $this->createClosedTrade(-30.0, 'SL', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(50.0, 'TP', ['closed_at' => '2026-01-20 10:00:00']);

        $series = $this->repo->getCumulativePnl($this->userId, [
            'date_from' => '2026-01-12',
            'date_to' => '2026-01-18',
        ]);

        $this->assertCount(1, $series);
        $this->assertEquals(-30.0, (float) $series[0]['pnl']);
    }

    public function testGetPnlBySymbolFiltersDirection(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['symbol' => 'NASDAQ', 'direction' => 'BUY']);
        $this->createClosedTrade(200.0, 'TP', ['symbol' => 'NASDAQ', 'direction' => 'SELL']);
        $this->createClosedTrade(-30.0, 'SL', ['symbol' => 'DAX', 'direction' => 'BUY']);

        $bySymbol = $this->repo->getPnlBySymbol($this->userId, ['direction' => 'BUY']);

        $indexed = [];
        foreach ($bySymbol as $row) {
            $indexed[$row['symbol']] = $row;
        }

        $this->assertCount(2, $bySymbol);
        $this->assertEquals(100.0, (float) $indexed['NASDAQ']['total_pnl']);
        $this->assertEquals(-30.0, (float) $indexed['DAX']['total_pnl']);
    }

    // ── getStatsBySymbol ───────────────────────────────────────

    public function testGetStatsBySymbolReturnsGroupedMetrics(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['symbol' => 'NASDAQ']);
        $this->createClosedTrade(200.0, 'TP', ['symbol' => 'NASDAQ']);
        $this->createClosedTrade(-50.0, 'SL', ['symbol' => 'DAX']);

        $result = $this->repo->getStatsBySymbol($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['symbol']] = $row;
        }

        $this->assertCount(2, $result);
        $this->assertSame(2, (int) $indexed['NASDAQ']['total_trades']);
        $this->assertSame(2, (int) $indexed['NASDAQ']['wins']);
        $this->assertSame(0, (int) $indexed['NASDAQ']['losses']);
        $this->assertEquals(300.0, (float) $indexed['NASDAQ']['total_pnl']);
        $this->assertArrayHasKey('win_rate', $indexed['NASDAQ']);
        $this->assertArrayHasKey('avg_rr', $indexed['NASDAQ']);
        $this->assertArrayHasKey('profit_factor', $indexed['NASDAQ']);
    }

    public function testGetStatsBySymbolRespectsFilters(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['symbol' => 'NASDAQ', 'direction' => 'BUY']);
        $this->createClosedTrade(200.0, 'TP', ['symbol' => 'NASDAQ', 'direction' => 'SELL']);

        $result = $this->repo->getStatsBySymbol($this->userId, ['direction' => 'BUY']);

        $this->assertCount(1, $result);
        $this->assertEquals(100.0, (float) $result[0]['total_pnl']);
    }

    // ── getStatsByDirection ─────────────────────────────────────

    public function testGetStatsByDirectionReturnsGroupedMetrics(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['direction' => 'BUY']);
        $this->createClosedTrade(200.0, 'TP', ['direction' => 'BUY']);
        $this->createClosedTrade(-50.0, 'SL', ['direction' => 'SELL']);

        $result = $this->repo->getStatsByDirection($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['direction']] = $row;
        }

        $this->assertCount(2, $result);
        $this->assertSame(2, (int) $indexed['BUY']['total_trades']);
        $this->assertSame(2, (int) $indexed['BUY']['wins']);
        $this->assertEquals(300.0, (float) $indexed['BUY']['total_pnl']);
        $this->assertSame(1, (int) $indexed['SELL']['total_trades']);
    }

    // ── getStatsBySetup ─────────────────────────────────────────

    public function testGetStatsBySetupReturnsGroupedMetrics(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["Breakout"]']);
        $this->createClosedTrade(200.0, 'TP', ['setup' => '["Breakout"]']);
        $this->createClosedTrade(-50.0, 'SL', ['setup' => '["Pullback"]']);

        $result = $this->repo->getStatsBySetup($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['setup']] = $row;
        }

        $this->assertCount(2, $result);
        $this->assertSame(2, (int) $indexed['Breakout']['total_trades']);
        $this->assertEquals(300.0, (float) $indexed['Breakout']['total_pnl']);
        $this->assertSame(1, (int) $indexed['Pullback']['total_trades']);
    }

    // ── getStatsByPeriod ────────────────────────────────────────

    public function testGetStatsByPeriodGroupsByMonth(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(200.0, 'TP', ['closed_at' => '2026-01-20 10:00:00']);
        $this->createClosedTrade(-50.0, 'SL', ['closed_at' => '2026-02-10 10:00:00']);

        $result = $this->repo->getStatsByPeriod($this->userId, 'month');

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['period']] = $row;
        }

        $this->assertCount(2, $result);
        $this->assertSame(2, (int) $indexed['2026-01']['total_trades']);
        $this->assertEquals(300.0, (float) $indexed['2026-01']['total_pnl']);
        $this->assertSame(1, (int) $indexed['2026-02']['total_trades']);
    }

    public function testGetStatsByPeriodGroupsByDay(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(200.0, 'TP', ['closed_at' => '2026-01-15 14:00:00']);
        $this->createClosedTrade(-50.0, 'SL', ['closed_at' => '2026-01-16 10:00:00']);

        $result = $this->repo->getStatsByPeriod($this->userId, 'day');

        $this->assertCount(2, $result);
        $this->assertSame(2, (int) $result[0]['total_trades']);
    }

    public function testGetStatsByPeriodGroupsByYear(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(-50.0, 'SL', ['closed_at' => '2026-06-10 10:00:00']);

        $result = $this->repo->getStatsByPeriod($this->userId, 'year');

        $this->assertCount(1, $result);
        $this->assertSame(2, (int) $result[0]['total_trades']);
        $this->assertSame('2026', $result[0]['period']);
    }

    // ── getRrDistribution ─────────────────────────────────────

    public function testGetRrDistributionReturnsBuckets(): void
    {
        // R:R = pnl / 50 (sl_points=50, size=1)
        $this->createClosedTrade(150.0, 'TP');  // RR = 3.0 → bucket ">3"
        $this->createClosedTrade(125.0, 'TP');  // RR = 2.5 → bucket "2-3"
        $this->createClosedTrade(75.0, 'TP');   // RR = 1.5 → bucket "1-2"
        $this->createClosedTrade(25.0, 'TP');   // RR = 0.5 → bucket "0-1"
        $this->createClosedTrade(-50.0, 'SL');  // RR = -1.0 → bucket "-1-0"
        $this->createClosedTrade(-150.0, 'SL'); // RR = -3.0 → bucket "<-2"

        $result = $this->repo->getRrDistribution($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['bucket']] = (int) $row['count'];
        }

        $this->assertSame(1, $indexed['>3']);
        $this->assertSame(1, $indexed['2-3']);
        $this->assertSame(1, $indexed['1-2']);
        $this->assertSame(1, $indexed['0-1']);
        $this->assertSame(1, $indexed['-1-0']);
        $this->assertSame(1, $indexed['<-2']);
    }

    public function testGetRrDistributionRespectsFilters(): void
    {
        $this->createClosedTrade(150.0, 'TP', ['symbol' => 'NASDAQ']);
        $this->createClosedTrade(75.0, 'TP', ['symbol' => 'DAX']);

        $result = $this->repo->getRrDistribution($this->userId, ['symbols' => ['NASDAQ']]);

        $total = array_sum(array_column($result, 'count'));
        $this->assertSame(1, $total);
    }

    public function testGetRrDistributionExcludesOpenTrades(): void
    {
        $this->createClosedTrade(100.0, 'TP');
        $this->createOpenTrade();

        $result = $this->repo->getRrDistribution($this->userId);

        $total = array_sum(array_column($result, 'count'));
        $this->assertSame(1, $total);
    }

    // ── getHeatmap ──────────────────────────────────────────────

    public function testGetHeatmapReturnsDayHourGrid(): void
    {
        // Wednesday (day 3) at 10h and 14h
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-14 10:30:00']);
        $this->createClosedTrade(-30.0, 'SL', ['closed_at' => '2026-01-14 14:15:00']);
        // Thursday (day 4) at 10h
        $this->createClosedTrade(50.0, 'TP', ['closed_at' => '2026-01-15 10:45:00']);

        $result = $this->repo->getHeatmap($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['day'] . '-' . $row['hour']] = $row;
        }

        $this->assertArrayHasKey('3-10', $indexed);
        $this->assertSame(1, (int) $indexed['3-10']['trade_count']);
        $this->assertEquals(100.0, (float) $indexed['3-10']['total_pnl']);

        $this->assertArrayHasKey('3-14', $indexed);
        $this->assertSame(1, (int) $indexed['3-14']['trade_count']);

        $this->assertArrayHasKey('4-10', $indexed);
        $this->assertSame(1, (int) $indexed['4-10']['trade_count']);
    }

    public function testGetHeatmapRespectsFilters(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-14 10:00:00', 'symbol' => 'NASDAQ']);
        $this->createClosedTrade(-30.0, 'SL', ['closed_at' => '2026-01-14 10:00:00', 'symbol' => 'DAX']);

        $result = $this->repo->getHeatmap($this->userId, ['symbols' => ['NASDAQ']]);

        $this->assertCount(1, $result);
        $this->assertSame(1, (int) $result[0]['trade_count']);
        $this->assertEquals(100.0, (float) $result[0]['total_pnl']);
    }

    // ── getTradesForSessionStats ─────────────────────────────

    public function testGetTradesForSessionStatsReturnsClosedTrades(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 03:00:00']);
        $this->createClosedTrade(200.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);

        $result = $this->repo->getTradesForSessionStats($this->userId);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('closed_at', $result[0]);
        $this->assertArrayHasKey('pnl', $result[0]);
        $this->assertArrayHasKey('risk_reward', $result[0]);
    }

    public function testGetTradesForSessionStatsRespectsFilters(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00', 'symbol' => 'NASDAQ']);
        $this->createClosedTrade(200.0, 'TP', ['closed_at' => '2026-01-15 10:00:00', 'symbol' => 'DAX']);

        $result = $this->repo->getTradesForSessionStats($this->userId, ['symbols' => ['NASDAQ']]);

        $this->assertCount(1, $result);
        $this->assertEquals(100.0, (float) $result[0]['pnl']);
    }

    public function testGetTradesForSessionStatsExcludesOpenTrades(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createOpenTrade();

        $result = $this->repo->getTradesForSessionStats($this->userId);

        $this->assertCount(1, $result);
    }

    // ── getStatsByAccount ───────────────────────────────────────

    public function testGetStatsByAccountReturnsGroupedByAccount(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['account_id' => $this->accountId]);
        $this->createClosedTrade(200.0, 'TP', ['account_id' => $this->accountId]);
        $this->createClosedTrade(-50.0, 'SL', ['account_id' => $this->accountId2]);

        $result = $this->repo->getStatsByAccount($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[(int) $row['account_id']] = $row;
        }

        $this->assertCount(2, $result);
        $this->assertSame(2, (int) $indexed[$this->accountId]['total_trades']);
        $this->assertEquals(300.0, (float) $indexed[$this->accountId]['total_pnl']);
        $this->assertSame(1, (int) $indexed[$this->accountId2]['total_trades']);
        $this->assertArrayHasKey('account_name', $indexed[$this->accountId]);
    }

    public function testGetStatsByAccountRespectsFilters(): void
    {
        $this->createClosedTrade(100.0, 'TP', [
            'account_id' => $this->accountId,
            'direction' => 'BUY',
        ]);
        $this->createClosedTrade(200.0, 'TP', [
            'account_id' => $this->accountId,
            'direction' => 'SELL',
        ]);

        $result = $this->repo->getStatsByAccount($this->userId, ['direction' => 'BUY']);

        $this->assertCount(1, $result);
        $this->assertEquals(100.0, (float) $result[0]['total_pnl']);
    }

    // ── getStatsByAccountType ───────────────────────────────────

    public function testGetStatsByAccountTypeReturnsGroupedByType(): void
    {
        // Account 1 = BROKER_DEMO, Account 2 = BROKER_LIVE
        $this->createClosedTrade(100.0, 'TP', ['account_id' => $this->accountId]);
        $this->createClosedTrade(200.0, 'TP', ['account_id' => $this->accountId]);
        $this->createClosedTrade(-50.0, 'SL', ['account_id' => $this->accountId2]);

        $result = $this->repo->getStatsByAccountType($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['account_type']] = $row;
        }

        $this->assertCount(2, $result);
        $this->assertSame(2, (int) $indexed['BROKER_DEMO']['total_trades']);
        $this->assertEquals(300.0, (float) $indexed['BROKER_DEMO']['total_pnl']);
        $this->assertSame(1, (int) $indexed['BROKER_LIVE']['total_trades']);
        $this->assertEquals(-50.0, (float) $indexed['BROKER_LIVE']['total_pnl']);
    }

    public function testGetStatsByAccountTypeRespectsFilters(): void
    {
        $this->createClosedTrade(100.0, 'TP', [
            'account_id' => $this->accountId,
            'closed_at' => '2026-01-15 10:00:00',
        ]);
        $this->createClosedTrade(200.0, 'TP', [
            'account_id' => $this->accountId2,
            'closed_at' => '2026-02-15 10:00:00',
        ]);

        $result = $this->repo->getStatsByAccountType($this->userId, [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals(100.0, (float) $result[0]['total_pnl']);
    }

    public function testGetStatsByAccountTypeExcludesOpenTrades(): void
    {
        $this->createClosedTrade(100.0, 'TP');
        $this->createOpenTrade();

        $result = $this->repo->getStatsByAccountType($this->userId);

        $total = array_sum(array_column($result, 'total_trades'));
        $this->assertSame(1, $total);
    }

    // ── getRecentTrades ─────────────────────────────────────────

    public function testGetRecentTradesReturnsLimitedClosedTrades(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->createClosedTrade($i * 10.0, 'TP', [
                'closed_at' => "2026-01-{$i} 10:00:00",
                'opened_at' => "2026-01-{$i} 09:00:00",
            ]);
        }
        $this->createOpenTrade();

        $recent = $this->repo->getRecentTrades($this->userId, 5);

        $this->assertCount(5, $recent);
        // Most recent first (closed_at DESC)
        $this->assertEquals(70.0, (float) $recent[0]['pnl']);
        $this->assertEquals(60.0, (float) $recent[1]['pnl']);
    }

    // ── getOpenTrades ──────────────────────────────────────────

    public function testGetOpenTradesReturnsOngoingTradesAndIgnoresClosed(): void
    {
        $this->createClosedTrade(100.0, 'TP');
        $this->createOpenTrade('OPEN');
        $this->createOpenTrade('OPEN');
        $this->createOpenTrade('SECURED');

        $result = $this->repo->getOpenTrades($this->userId);

        // "Open" on the dashboard means "ongoing" = OPEN + SECURED. CLOSED excluded.
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('symbol', $result[0]);
        $this->assertArrayHasKey('direction', $result[0]);
        $this->assertArrayHasKey('entry_price', $result[0]);
        $this->assertArrayHasKey('account_name', $result[0]);
    }

    public function testGetOpenTradesRespectsAccountFilter(): void
    {
        $this->createOpenTrade();

        $result = $this->repo->getOpenTrades($this->userId, 5, ['account_id' => $this->accountId2]);

        $this->assertCount(0, $result);
    }

    public function testGetOpenTradesRespectsLimit(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->createOpenTrade();
        }

        $result = $this->repo->getOpenTrades($this->userId, 5);

        $this->assertCount(5, $result);
    }

    // ── getDailyPnl ────────────────────────────────────────────

    public function testGetDailyPnlReturnsGroupedByDay(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(50.0, 'TP', ['closed_at' => '2026-01-15 14:00:00']);
        $this->createClosedTrade(-30.0, 'SL', ['closed_at' => '2026-01-16 10:00:00']);

        $result = $this->repo->getDailyPnl($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['date']] = $row;
        }

        $this->assertCount(2, $result);
        $this->assertEquals(150.0, (float) $indexed['2026-01-15']['total_pnl']);
        $this->assertSame(2, (int) $indexed['2026-01-15']['trade_count']);
        $this->assertEquals(-30.0, (float) $indexed['2026-01-16']['total_pnl']);
    }

    public function testGetDailyPnlRespectsFilters(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(200.0, 'TP', ['closed_at' => '2026-02-15 10:00:00']);

        $result = $this->repo->getDailyPnl($this->userId, [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals(100.0, (float) $result[0]['total_pnl']);
    }

    public function testGetDailyPnlExcludesOpenTrades(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createOpenTrade();

        $result = $this->repo->getDailyPnl($this->userId);

        $this->assertCount(1, $result);
    }

    // ── BE threshold classification ─────────────────────────────
    // Fixtures: entry_price=18500, size=1 → entry_value=18500.
    // pnl_percent = pnl / 18500 * 100
    //   pnl=1     → 0.0054 %
    //   pnl=-2    → -0.0108 %
    //   pnl=3.7   → 0.02 %   (pile seuil 0.02)
    //   pnl=100   → 0.5405 %
    //   pnl=-50   → -0.2703 %

    public function testGetOverviewClassifiesSmallProfitAsBeWhenThresholdApplied(): void
    {
        // +1€ de profit (spread), sans seuil = win. Avec seuil 0.02% = BE.
        $this->createClosedTrade(1.0, 'MANUAL');
        $this->createClosedTrade(100.0, 'TP');
        $this->createClosedTrade(-50.0, 'SL');

        $overview = $this->repo->getOverview($this->userId, ['be_threshold_percent' => 0.02]);

        $this->assertSame(3, $overview['total_trades']);
        $this->assertSame(1, $overview['winning_trades']);
        $this->assertSame(1, $overview['losing_trades']);
        $this->assertSame(1, $overview['be_trades']);
    }

    public function testGetOverviewClassifiesSmallLossAsBeWhenThresholdApplied(): void
    {
        // -2€ de perte (spread), sans seuil = loss. Avec seuil 0.02% = BE.
        $this->createClosedTrade(-2.0, 'MANUAL');
        $this->createClosedTrade(100.0, 'TP');

        $overview = $this->repo->getOverview($this->userId, ['be_threshold_percent' => 0.02]);

        $this->assertSame(2, $overview['total_trades']);
        $this->assertSame(1, $overview['winning_trades']);
        $this->assertSame(0, $overview['losing_trades']);
        $this->assertSame(1, $overview['be_trades']);
    }

    public function testGetOverviewKeepsClearWinOutsideThreshold(): void
    {
        $this->createClosedTrade(100.0, 'TP'); // 0.54% > 0.02%
        $this->createClosedTrade(-50.0, 'SL'); // -0.27% < -0.02%

        $overview = $this->repo->getOverview($this->userId, ['be_threshold_percent' => 0.02]);

        $this->assertSame(1, $overview['winning_trades']);
        $this->assertSame(1, $overview['losing_trades']);
        $this->assertSame(0, $overview['be_trades']);
    }

    public function testGetOverviewThresholdBoundaryIsInclusive(): void
    {
        // pnl_percent = 0.02 exactement → BE
        $this->createClosedTrade(3.7, 'MANUAL'); // 3.7/18500*100 = 0.02 pile
        $this->createClosedTrade(-3.7, 'MANUAL'); // -0.02 pile

        $overview = $this->repo->getOverview($this->userId, ['be_threshold_percent' => 0.02]);

        $this->assertSame(2, $overview['total_trades']);
        $this->assertSame(2, $overview['be_trades']);
        $this->assertSame(0, $overview['winning_trades']);
        $this->assertSame(0, $overview['losing_trades']);
    }

    public function testGetOverviewThresholdZeroMatchesLegacyBehavior(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL');   // win
        $this->createClosedTrade(-2.0, 'MANUAL');  // loss
        $this->createClosedTrade(0.0, 'BE');       // be

        $overview = $this->repo->getOverview($this->userId, ['be_threshold_percent' => 0]);

        $this->assertSame(1, $overview['winning_trades']);
        $this->assertSame(1, $overview['losing_trades']);
        $this->assertSame(1, $overview['be_trades']);
    }

    public function testGetOverviewThresholdDefaultsToZero(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL');
        $this->createClosedTrade(-2.0, 'MANUAL');

        $overview = $this->repo->getOverview($this->userId); // no threshold → legacy

        $this->assertSame(1, $overview['winning_trades']);
        $this->assertSame(1, $overview['losing_trades']);
    }

    public function testGetOverviewProfitFactorIgnoresBeTrades(): void
    {
        // Avec seuil, les quasi-BE ne doivent pas biaiser le profit factor.
        $this->createClosedTrade(1.0, 'MANUAL');   // BE avec seuil
        $this->createClosedTrade(-2.0, 'MANUAL');  // BE avec seuil
        $this->createClosedTrade(100.0, 'TP');
        $this->createClosedTrade(-50.0, 'SL');

        $overview = $this->repo->getOverview($this->userId, ['be_threshold_percent' => 0.02]);

        // PF = 100 / 50 = 2.0 (quasi-BE exclus)
        $this->assertEquals(2.0, (float) $overview['profit_factor']);
    }

    public function testGetWinLossDistributionAppliesThreshold(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL');
        $this->createClosedTrade(-2.0, 'MANUAL');
        $this->createClosedTrade(100.0, 'TP');
        $this->createClosedTrade(-50.0, 'SL');

        $dist = $this->repo->getWinLossDistribution($this->userId, ['be_threshold_percent' => 0.02]);

        $this->assertSame(1, $dist['win']);
        $this->assertSame(1, $dist['loss']);
        $this->assertSame(2, $dist['be']);
    }

    public function testGetStatsBySymbolAppliesThreshold(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL', ['symbol' => 'NASDAQ']);
        $this->createClosedTrade(100.0, 'TP', ['symbol' => 'NASDAQ']);
        $this->createClosedTrade(-50.0, 'SL', ['symbol' => 'DAX']);

        $result = $this->repo->getStatsBySymbol($this->userId, ['be_threshold_percent' => 0.02]);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['symbol']] = $row;
        }
        // NASDAQ: 1 win, 0 loss (le +1€ est BE, pas win)
        $this->assertSame(1, (int) $indexed['NASDAQ']['wins']);
        $this->assertSame(0, (int) $indexed['NASDAQ']['losses']);
    }

    public function testGetStatsByDirectionAppliesThreshold(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL', ['direction' => 'BUY']);
        $this->createClosedTrade(100.0, 'TP', ['direction' => 'BUY']);
        $this->createClosedTrade(-2.0, 'MANUAL', ['direction' => 'SELL']);
        $this->createClosedTrade(-50.0, 'SL', ['direction' => 'SELL']);

        $result = $this->repo->getStatsByDirection($this->userId, ['be_threshold_percent' => 0.02]);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['direction']] = $row;
        }
        $this->assertSame(1, (int) $indexed['BUY']['wins']);
        $this->assertSame(0, (int) $indexed['BUY']['losses']);
        $this->assertSame(0, (int) $indexed['SELL']['wins']);
        $this->assertSame(1, (int) $indexed['SELL']['losses']);
    }

    public function testGetStatsBySetupAppliesThreshold(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL', ['setup' => '["Breakout"]']);
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["Breakout"]']);

        $result = $this->repo->getStatsBySetup($this->userId, ['be_threshold_percent' => 0.02]);

        $this->assertCount(1, $result);
        $this->assertSame(1, (int) $result[0]['wins']);
        $this->assertSame(0, (int) $result[0]['losses']);
    }

    public function testGetStatsByPeriodAppliesThreshold(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-20 10:00:00']);

        $result = $this->repo->getStatsByPeriod($this->userId, 'month', ['be_threshold_percent' => 0.02]);

        $this->assertCount(1, $result);
        $this->assertSame(1, (int) $result[0]['wins']);
        $this->assertSame(0, (int) $result[0]['losses']);
    }

    public function testGetStatsByAccountAppliesThreshold(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL', ['account_id' => $this->accountId]);
        $this->createClosedTrade(100.0, 'TP', ['account_id' => $this->accountId]);

        $result = $this->repo->getStatsByAccount($this->userId, ['be_threshold_percent' => 0.02]);

        $this->assertCount(1, $result);
        $this->assertSame(1, (int) $result[0]['wins']);
        $this->assertSame(0, (int) $result[0]['losses']);
    }

    public function testGetStatsByAccountTypeAppliesThreshold(): void
    {
        $this->createClosedTrade(1.0, 'MANUAL', ['account_id' => $this->accountId]);
        $this->createClosedTrade(100.0, 'TP', ['account_id' => $this->accountId]);

        $result = $this->repo->getStatsByAccountType($this->userId, ['be_threshold_percent' => 0.02]);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['account_type']] = $row;
        }
        $this->assertSame(1, (int) $indexed['BROKER_DEMO']['wins']);
        $this->assertSame(0, (int) $indexed['BROKER_DEMO']['losses']);
    }

    // ── getStatsForSetupCombination ─────────────────────────────

    public function testGetStatsForSetupCombinationMatchesAllSetupsPresent(): void
    {
        // Trade A has both Breakout and Pullback → matches the combination
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["Breakout","Pullback"]']);
        // Trade B has only Breakout → does NOT match
        $this->createClosedTrade(200.0, 'TP', ['setup' => '["Breakout"]']);
        // Trade C has only Pullback → does NOT match
        $this->createClosedTrade(-50.0, 'SL', ['setup' => '["Pullback"]']);

        $result = $this->repo->getStatsForSetupCombination($this->userId, ['Breakout', 'Pullback']);

        $this->assertSame(1, $result['total_trades']);
        $this->assertEquals(100.0, $result['total_pnl']);
        $this->assertSame(1, $result['wins']);
    }

    public function testGetStatsForSetupCombinationMatchesSupersetTrades(): void
    {
        // Trades carrying [A, B, C] still match a [A, B] combination —
        // "all of the requested setups must be present" (AND), nothing
        // says the trade may not carry additional setups too.
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["A","B","C"]']);
        $this->createClosedTrade(200.0, 'TP', ['setup' => '["A","B"]']);
        $this->createClosedTrade(-50.0, 'SL', ['setup' => '["A"]']);

        $result = $this->repo->getStatsForSetupCombination($this->userId, ['A', 'B']);

        $this->assertSame(2, $result['total_trades']);
        $this->assertEquals(300.0, $result['total_pnl']);
    }

    public function testGetStatsForSetupCombinationReturnsZerosWhenNoMatch(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["Breakout"]']);

        $result = $this->repo->getStatsForSetupCombination($this->userId, ['Pullback', 'Range']);

        $this->assertSame(0, $result['total_trades']);
        $this->assertEquals(0.0, $result['total_pnl']);
        $this->assertSame(0, $result['wins']);
        $this->assertSame(0, $result['losses']);
        $this->assertNull($result['avg_rr']);
    }

    public function testGetStatsForSetupCombinationEmptyNamesReturnsBaseline(): void
    {
        // Empty setup name list = baseline (no JSON_CONTAINS filter, just the
        // global filters). Used by the service to compute the comparison set.
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["A"]']);
        $this->createClosedTrade(-50.0, 'SL', ['setup' => '["B"]']);

        $result = $this->repo->getStatsForSetupCombination($this->userId, []);

        $this->assertSame(2, $result['total_trades']);
        $this->assertEquals(50.0, $result['total_pnl']);
    }

    public function testGetStatsForSetupCombinationRespectsAccountFilter(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["A","B"]', 'account_id' => $this->accountId]);
        $this->createClosedTrade(200.0, 'TP', ['setup' => '["A","B"]', 'account_id' => $this->accountId2]);

        $result = $this->repo->getStatsForSetupCombination(
            $this->userId,
            ['A', 'B'],
            ['account_id' => $this->accountId]
        );

        $this->assertSame(1, $result['total_trades']);
        $this->assertEquals(100.0, $result['total_pnl']);
    }

    public function testGetStatsForSetupCombinationRespectsDateRangeFilter(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['setup' => '["A","B"]', 'closed_at' => '2026-01-10 10:00:00']);
        $this->createClosedTrade(200.0, 'TP', ['setup' => '["A","B"]', 'closed_at' => '2026-02-10 10:00:00']);

        $result = $this->repo->getStatsForSetupCombination(
            $this->userId,
            ['A', 'B'],
            ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']
        );

        $this->assertSame(1, $result['total_trades']);
        $this->assertEquals(100.0, $result['total_pnl']);
    }
}
