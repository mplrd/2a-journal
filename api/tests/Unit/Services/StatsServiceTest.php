<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Repositories\AccountRepository;
use App\Repositories\StatsRepository;
use App\Services\StatsService;
use PHPUnit\Framework\TestCase;

class StatsServiceTest extends TestCase
{
    private StatsService $service;
    private StatsRepository $statsRepo;
    private AccountRepository $accountRepo;

    protected function setUp(): void
    {
        $this->statsRepo = $this->createMock(StatsRepository::class);
        $this->accountRepo = $this->createMock(AccountRepository::class);
        $this->service = new StatsService($this->statsRepo, $this->accountRepo);
    }

    private function fakeOverview(array $overrides = []): array
    {
        return array_merge([
            'total_trades' => 10,
            'total_pnl' => 500.0,
            'winning_trades' => 7,
            'losing_trades' => 2,
            'be_trades' => 1,
            'win_rate' => 70.0,
            'profit_factor' => 3.5,
            'best_trade' => 200.0,
            'worst_trade' => -80.0,
            'avg_rr' => 1.5,
        ], $overrides);
    }

    private function fakeRecentTrades(): array
    {
        return [
            ['id' => 1, 'symbol' => 'NASDAQ', 'direction' => 'BUY', 'pnl' => 100.0, 'exit_type' => 'TP', 'closed_at' => '2026-01-15 11:00:00'],
            ['id' => 2, 'symbol' => 'DAX', 'direction' => 'SELL', 'pnl' => -50.0, 'exit_type' => 'SL', 'closed_at' => '2026-01-14 11:00:00'],
        ];
    }

    public function testGetDashboardReturnsCombinedData(): void
    {
        $overview = $this->fakeOverview();
        $recentTrades = $this->fakeRecentTrades();

        $this->statsRepo->method('getOverview')->willReturn($overview);
        $this->statsRepo->method('getRecentTrades')->willReturn($recentTrades);

        $result = $this->service->getDashboard(1);

        $this->assertSame(10, $result['overview']['total_trades']);
        $this->assertEquals(500.0, $result['overview']['total_pnl']);
        $this->assertCount(2, $result['recent_trades']);
        $this->assertSame('NASDAQ', $result['recent_trades'][0]['symbol']);
    }

    public function testGetDashboardValidatesAccountOwnership(): void
    {
        $this->accountRepo->method('findById')->willReturn(['id' => 5, 'user_id' => 99]);

        $this->expectException(ForbiddenException::class);
        $this->service->getDashboard(1, ['account_id' => 5]);
    }

    public function testGetDashboardPassesAccountFilter(): void
    {
        $this->accountRepo->method('findById')->willReturn(['id' => 5, 'user_id' => 1]);

        $this->statsRepo->expects($this->once())
            ->method('getOverview')
            ->with(1, ['account_id' => 5])
            ->willReturn($this->fakeOverview());

        $this->statsRepo->expects($this->once())
            ->method('getRecentTrades')
            ->with(1, 5, ['account_id' => 5])
            ->willReturn($this->fakeRecentTrades());

        $result = $this->service->getDashboard(1, ['account_id' => 5]);

        $this->assertSame(10, $result['overview']['total_trades']);
    }

    public function testGetDashboardEmptyState(): void
    {
        $this->statsRepo->method('getOverview')->willReturn($this->fakeOverview([
            'total_trades' => 0,
            'total_pnl' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
            'be_trades' => 0,
            'win_rate' => 0,
            'profit_factor' => null,
            'best_trade' => null,
            'worst_trade' => null,
            'avg_rr' => null,
        ]));
        $this->statsRepo->method('getRecentTrades')->willReturn([]);

        $result = $this->service->getDashboard(1);

        $this->assertSame(0, $result['overview']['total_trades']);
        $this->assertCount(0, $result['recent_trades']);
    }

    public function testGetChartsReturnsStructure(): void
    {
        $cumPnl = [
            ['closed_at' => '2026-01-10', 'pnl' => 100.0, 'cumulative_pnl' => 100.0, 'symbol' => 'NASDAQ'],
        ];
        $winLoss = ['win' => 5, 'loss' => 2, 'be' => 1];
        $bySymbol = [
            ['symbol' => 'NASDAQ', 'trade_count' => 3, 'total_pnl' => 150.0],
        ];

        $this->statsRepo->method('getCumulativePnl')->willReturn($cumPnl);
        $this->statsRepo->method('getWinLossDistribution')->willReturn($winLoss);
        $this->statsRepo->method('getPnlBySymbol')->willReturn($bySymbol);

        $result = $this->service->getCharts(1);

        $this->assertArrayHasKey('cumulative_pnl', $result);
        $this->assertArrayHasKey('win_loss', $result);
        $this->assertArrayHasKey('pnl_by_symbol', $result);
        $this->assertCount(1, $result['cumulative_pnl']);
        $this->assertSame(5, $result['win_loss']['win']);
    }

    // ── Filter validation ───────────────────────────────────────

    public function testValidateFiltersPassesValidDateRange(): void
    {
        $this->statsRepo->method('getOverview')->willReturn($this->fakeOverview());
        $this->statsRepo->method('getRecentTrades')->willReturn([]);

        $this->statsRepo->expects($this->once())
            ->method('getOverview')
            ->with(1, ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $this->service->getDashboard(1, ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
    }

    public function testValidateFiltersRejectsInvalidDateFrom(): void
    {
        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->service->getDashboard(1, ['date_from' => 'not-a-date']);
    }

    public function testValidateFiltersRejectsInvalidDateTo(): void
    {
        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->service->getDashboard(1, ['date_to' => '2026-13-01']);
    }

    public function testValidateFiltersRejectsInvalidDirection(): void
    {
        $this->expectException(\App\Exceptions\ValidationException::class);
        $this->service->getDashboard(1, ['direction' => 'LONG']);
    }

    public function testValidateFiltersPassesValidDirection(): void
    {
        $this->statsRepo->method('getOverview')->willReturn($this->fakeOverview());
        $this->statsRepo->method('getRecentTrades')->willReturn([]);

        $this->statsRepo->expects($this->once())
            ->method('getOverview')
            ->with(1, ['direction' => 'BUY']);

        $this->service->getDashboard(1, ['direction' => 'BUY']);
    }

    public function testValidateFiltersPassesSymbolsArray(): void
    {
        $this->statsRepo->method('getOverview')->willReturn($this->fakeOverview());
        $this->statsRepo->method('getRecentTrades')->willReturn([]);

        $this->statsRepo->expects($this->once())
            ->method('getOverview')
            ->with(1, ['symbols' => ['NASDAQ', 'DAX']]);

        $this->service->getDashboard(1, ['symbols' => ['NASDAQ', 'DAX']]);
    }

    public function testValidateFiltersPassesSetupsArray(): void
    {
        $this->statsRepo->method('getOverview')->willReturn($this->fakeOverview());
        $this->statsRepo->method('getRecentTrades')->willReturn([]);

        $this->statsRepo->expects($this->once())
            ->method('getOverview')
            ->with(1, ['setups' => ['Breakout', 'Pullback']]);

        $this->service->getDashboard(1, ['setups' => ['Breakout', 'Pullback']]);
    }

    // ── getStatsBySession ─────────────────────────────────────

    public function testGetStatsBySessionDelegatesToRepo(): void
    {
        $expected = [
            ['session' => 'ASIA', 'total_trades' => 5, 'total_pnl' => 200.0],
            ['session' => 'EUROPE', 'total_trades' => 10, 'total_pnl' => 500.0],
        ];

        $this->statsRepo->expects($this->once())
            ->method('getStatsBySession')
            ->with(1, [])
            ->willReturn($expected);

        $result = $this->service->getStatsBySession(1);

        $this->assertCount(2, $result);
        $this->assertSame('ASIA', $result[0]['session']);
    }

    public function testGetStatsBySessionValidatesFilters(): void
    {
        $this->accountRepo->method('findById')->willReturn(['id' => 5, 'user_id' => 99]);

        $this->expectException(ForbiddenException::class);
        $this->service->getStatsBySession(1, ['account_id' => 5]);
    }

    // ── getStatsByAccount ──────────────────────────────────────

    public function testGetStatsByAccountDelegatesToRepo(): void
    {
        $expected = [
            ['account_id' => 1, 'account_name' => 'Account 1', 'total_trades' => 5, 'total_pnl' => 200.0],
        ];

        $this->statsRepo->expects($this->once())
            ->method('getStatsByAccount')
            ->with(1, [])
            ->willReturn($expected);

        $result = $this->service->getStatsByAccount(1);

        $this->assertCount(1, $result);
        $this->assertSame('Account 1', $result[0]['account_name']);
    }

    // ── getStatsByAccountType ──────────────────────────────────

    public function testGetStatsByAccountTypeDelegatesToRepo(): void
    {
        $expected = [
            ['account_type' => 'BROKER_DEMO', 'total_trades' => 5, 'total_pnl' => 200.0],
            ['account_type' => 'PROP_FIRM', 'total_trades' => 3, 'total_pnl' => -100.0],
        ];

        $this->statsRepo->expects($this->once())
            ->method('getStatsByAccountType')
            ->with(1, [])
            ->willReturn($expected);

        $result = $this->service->getStatsByAccountType(1);

        $this->assertCount(2, $result);
        $this->assertSame('BROKER_DEMO', $result[0]['account_type']);
    }

    public function testGetChartsProfitFactorNullSafety(): void
    {
        $this->statsRepo->method('getCumulativePnl')->willReturn([]);
        $this->statsRepo->method('getWinLossDistribution')->willReturn(['win' => 0, 'loss' => 0, 'be' => 0]);
        $this->statsRepo->method('getPnlBySymbol')->willReturn([]);

        $result = $this->service->getCharts(1);

        $this->assertCount(0, $result['cumulative_pnl']);
        $this->assertSame(0, $result['win_loss']['win']);
        $this->assertCount(0, $result['pnl_by_symbol']);
    }
}
