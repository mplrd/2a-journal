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
        $position = $this->positionRepo->create(array_merge([
            'user_id' => $this->userId,
            'account_id' => $overrides['account_id'] ?? $this->accountId,
            'direction' => 'BUY',
            'symbol' => $overrides['symbol'] ?? 'NASDAQ',
            'entry_price' => '18500.00000',
            'size' => '1.00000',
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

        $this->tradeRepo->update((int) $trade['id'], [
            'status' => 'CLOSED',
            'exit_type' => $exitType,
            'pnl' => $pnl,
            'risk_reward' => $riskReward,
            'closed_at' => $overrides['closed_at'] ?? '2026-01-15 11:00:00',
        ]);

        return $this->tradeRepo->findById((int) $trade['id']);
    }

    private function createOpenTrade(): array
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
            'status' => 'OPEN',
        ]);
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

    public function testGetOverviewExcludesNonClosedTrades(): void
    {
        $this->createClosedTrade(100.0, 'TP');
        $this->createOpenTrade();

        $overview = $this->repo->getOverview($this->userId);

        $this->assertSame(1, $overview['total_trades']);
        $this->assertEquals(100.0, (float) $overview['total_pnl']);
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

    // ── getStatsBySession ──────────────────────────────────────

    public function testGetStatsBySessionReturnsGroupedByTradingSession(): void
    {
        // ASIA: 0-8h UTC → closed_at 03:00
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 03:00:00']);
        // EUROPE: 8-14h UTC → closed_at 10:00
        $this->createClosedTrade(200.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createClosedTrade(-50.0, 'SL', ['closed_at' => '2026-01-15 12:00:00']);
        // US: 14-22h UTC → closed_at 16:00
        $this->createClosedTrade(150.0, 'TP', ['closed_at' => '2026-01-15 16:00:00']);

        $result = $this->repo->getStatsBySession($this->userId);

        $indexed = [];
        foreach ($result as $row) {
            $indexed[$row['session']] = $row;
        }

        $this->assertCount(3, $result);
        $this->assertSame(1, (int) $indexed['ASIA']['total_trades']);
        $this->assertEquals(100.0, (float) $indexed['ASIA']['total_pnl']);
        $this->assertSame(2, (int) $indexed['EUROPE']['total_trades']);
        $this->assertEquals(150.0, (float) $indexed['EUROPE']['total_pnl']);
        $this->assertSame(1, (int) $indexed['US']['total_trades']);
        $this->assertEquals(150.0, (float) $indexed['US']['total_pnl']);
    }

    public function testGetStatsBySessionRespectsFilters(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00', 'symbol' => 'NASDAQ']);
        $this->createClosedTrade(200.0, 'TP', ['closed_at' => '2026-01-15 10:00:00', 'symbol' => 'DAX']);

        $result = $this->repo->getStatsBySession($this->userId, ['symbols' => ['NASDAQ']]);

        $this->assertCount(1, $result);
        $this->assertEquals(100.0, (float) $result[0]['total_pnl']);
    }

    public function testGetStatsBySessionExcludesOpenTrades(): void
    {
        $this->createClosedTrade(100.0, 'TP', ['closed_at' => '2026-01-15 10:00:00']);
        $this->createOpenTrade();

        $result = $this->repo->getStatsBySession($this->userId);

        $total = array_sum(array_column($result, 'total_trades'));
        $this->assertSame(1, $total);
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

    public function testGetOpenTradesReturnsOnlyOpenTrades(): void
    {
        $this->createClosedTrade(100.0, 'TP');
        $this->createOpenTrade();
        $this->createOpenTrade();

        $result = $this->repo->getOpenTrades($this->userId);

        $this->assertCount(2, $result);
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
}
