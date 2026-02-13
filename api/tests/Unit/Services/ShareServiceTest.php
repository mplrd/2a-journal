<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;
use App\Services\ShareService;
use PHPUnit\Framework\TestCase;

class ShareServiceTest extends TestCase
{
    private ShareService $service;
    private PositionRepository $positionRepo;
    private TradeRepository $tradeRepo;

    protected function setUp(): void
    {
        $this->positionRepo = $this->createMock(PositionRepository::class);
        $this->tradeRepo = $this->createMock(TradeRepository::class);
        $this->service = new ShareService($this->positionRepo, $this->tradeRepo);
    }

    private function fakePosition(array $overrides = []): array
    {
        return array_merge([
            'id' => 10,
            'user_id' => 1,
            'account_id' => 100,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => '18240.00000',
            'size' => '1.0000',
            'setup' => 'Divergence haussière sur RSI',
            'sl_points' => '50.00',
            'sl_price' => '18190.00000',
            'be_points' => null,
            'be_price' => null,
            'be_size' => null,
            'targets' => json_encode([['points' => 110, 'size' => 1, 'price' => 18350]]),
            'notes' => null,
            'position_type' => 'ORDER',
            'created_at' => '2026-02-13 10:00:00',
            'updated_at' => '2026-02-13 10:00:00',
        ], $overrides);
    }

    private function fakeTrade(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'position_id' => 10,
            'source_order_id' => null,
            'opened_at' => '2026-02-13 10:00:00',
            'closed_at' => '2026-02-13 12:30:00',
            'remaining_size' => '0.0000',
            'be_reached' => 0,
            'avg_exit_price' => '18350.00000',
            'pnl' => '110.00',
            'pnl_percent' => '0.6030',
            'risk_reward' => '2.2000',
            'duration_minutes' => 150,
            'status' => 'CLOSED',
            'exit_type' => 'TP',
        ], $overrides);
    }

    // ── Order text (with emojis) ──────────────────────────────────

    public function testGenerateTextForBuyOrder(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('📈 BUY NASDAQ @ 18240', $text);
        $this->assertStringContainsString('🎯 TP: 18350 (+110 pts)', $text);
        $this->assertStringContainsString('🛑 SL: 18190 (-50 pts)', $text);
        $this->assertStringContainsString('⚖️ R/R: 2.2', $text);
        $this->assertStringContainsString('💬 Divergence haussière sur RSI', $text);
    }

    public function testGenerateTextForSellOrder(): void
    {
        $position = $this->fakePosition([
            'direction' => 'SELL',
            'symbol' => 'DAX',
            'entry_price' => '16500.00000',
            'sl_points' => '30.00',
            'sl_price' => '16530.00000',
            'targets' => json_encode([['points' => 60, 'size' => 1, 'price' => 16440]]),
            'setup' => 'Rejet résistance',
        ]);
        $this->positionRepo->method('findById')->willReturn($position);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('📉 SELL DAX @ 16500', $text);
        $this->assertStringContainsString('🎯 TP: 16440 (+60 pts)', $text);
        $this->assertStringContainsString('🛑 SL: 16530 (-30 pts)', $text);
        $this->assertStringContainsString('⚖️ R/R: 2', $text);
    }

    public function testGenerateTextForOrderWithMultipleTargets(): void
    {
        $position = $this->fakePosition([
            'targets' => json_encode([
                ['points' => 50, 'size' => 0.5, 'price' => 18290],
                ['points' => 110, 'size' => 0.5, 'price' => 18350],
            ]),
        ]);
        $this->positionRepo->method('findById')->willReturn($position);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('🎯 TP1: 18290 (+50 pts)', $text);
        $this->assertStringContainsString('🎯 TP2: 18350 (+110 pts)', $text);
    }

    public function testGenerateTextForOrderWithoutTargets(): void
    {
        $position = $this->fakePosition(['targets' => null]);
        $this->positionRepo->method('findById')->willReturn($position);

        $text = $this->service->generateText(1, 10);

        $this->assertStringNotContainsString('🎯', $text);
        $this->assertStringNotContainsString('R/R', $text);
    }

    public function testGenerateTextForOrderWithBE(): void
    {
        $position = $this->fakePosition([
            'be_points' => '25.00',
            'be_price' => '18265.00000',
        ]);
        $this->positionRepo->method('findById')->willReturn($position);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('🔒 BE: 18265 (+25 pts)', $text);
    }

    // ── Trade text (with emojis) ──────────────────────────────────

    public function testGenerateTextForOpenTrade(): void
    {
        $position = $this->fakePosition(['position_type' => 'TRADE']);
        $trade = $this->fakeTrade([
            'status' => 'OPEN',
            'closed_at' => null,
            'pnl' => null,
            'remaining_size' => '1.0000',
        ]);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->tradeRepo->method('findByPositionId')->willReturn($trade);

        $text = $this->service->generateText(1, 10);

        // Open trade shows same format as order (entry + TP/SL)
        $this->assertStringContainsString('📈 BUY NASDAQ @ 18240', $text);
        $this->assertStringContainsString('🎯 TP: 18350 (+110 pts)', $text);
        $this->assertStringContainsString('🛑 SL: 18190 (-50 pts)', $text);
    }

    public function testGenerateTextForClosedTrade(): void
    {
        $position = $this->fakePosition(['position_type' => 'TRADE']);
        $trade = $this->fakeTrade();
        $this->positionRepo->method('findById')->willReturn($position);
        $this->tradeRepo->method('findByPositionId')->willReturn($trade);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('📈 BUY NASDAQ @ 18240 → 18350', $text);
        $this->assertStringContainsString('✅ PnL: +110', $text);
        $this->assertStringContainsString('+0.60%', $text);
        $this->assertStringContainsString('🎯 Exit: TP', $text);
        $this->assertStringContainsString('⚖️ R/R: 2.2', $text);
        $this->assertStringContainsString('⏱️ 2h30', $text);
        $this->assertStringContainsString('💬 Divergence haussière sur RSI', $text);
    }

    public function testGenerateTextForClosedTradeNegativePnl(): void
    {
        $position = $this->fakePosition(['position_type' => 'TRADE']);
        $trade = $this->fakeTrade([
            'pnl' => '-50.00',
            'pnl_percent' => '-0.2740',
            'risk_reward' => '-1.0000',
            'exit_type' => 'SL',
            'avg_exit_price' => '18190.00000',
        ]);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->tradeRepo->method('findByPositionId')->willReturn($trade);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('📈 BUY NASDAQ @ 18240 → 18190', $text);
        $this->assertStringContainsString('❌ PnL: -50', $text);
        $this->assertStringContainsString('-0.27%', $text);
        $this->assertStringContainsString('🎯 Exit: SL', $text);
    }

    public function testDurationFormatHoursAndMinutes(): void
    {
        $position = $this->fakePosition(['position_type' => 'TRADE']);
        $trade = $this->fakeTrade(['duration_minutes' => 150]);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->tradeRepo->method('findByPositionId')->willReturn($trade);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('⏱️ 2h30', $text);
    }

    public function testDurationFormatMinutesOnly(): void
    {
        $position = $this->fakePosition(['position_type' => 'TRADE']);
        $trade = $this->fakeTrade(['duration_minutes' => 45]);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->tradeRepo->method('findByPositionId')->willReturn($trade);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('⏱️ 45min', $text);
    }

    public function testDurationFormatExactHours(): void
    {
        $position = $this->fakePosition(['position_type' => 'TRADE']);
        $trade = $this->fakeTrade(['duration_minutes' => 120]);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->tradeRepo->method('findByPositionId')->willReturn($trade);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('⏱️ 2h', $text);
    }

    // ── Plain text (no emojis) ────────────────────────────────────

    public function testGenerateTextPlainForOrder(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $text = $this->service->generateTextPlain(1, 10);

        $this->assertStringContainsString('BUY NASDAQ @ 18240', $text);
        $this->assertStringContainsString('TP: 18350 (+110 pts)', $text);
        $this->assertStringContainsString('SL: 18190 (-50 pts)', $text);
        $this->assertStringContainsString('R/R: 2.2', $text);
        $this->assertStringContainsString('Divergence haussière sur RSI', $text);
        // No emojis
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1F9FF}]/u', $text);
    }

    public function testGenerateTextPlainForClosedTrade(): void
    {
        $position = $this->fakePosition(['position_type' => 'TRADE']);
        $trade = $this->fakeTrade();
        $this->positionRepo->method('findById')->willReturn($position);
        $this->tradeRepo->method('findByPositionId')->willReturn($trade);

        $text = $this->service->generateTextPlain(1, 10);

        $this->assertStringContainsString('BUY NASDAQ @ 18240 → 18350', $text);
        $this->assertStringContainsString('PnL: +110', $text);
        $this->assertStringContainsString('Exit: TP', $text);
        $this->assertStringContainsString('R/R: 2.2', $text);
        $this->assertStringContainsString('2h30', $text);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F300}-\x{1F9FF}]/u', $text);
    }

    // ── Error cases ───────────────────────────────────────────────

    public function testNotFoundThrows(): void
    {
        $this->positionRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->generateText(1, 999);
    }

    public function testOtherUserThrows(): void
    {
        $position = $this->fakePosition(['user_id' => 2]);
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ForbiddenException::class);
        $this->service->generateText(1, 10);
    }

    // ── Edge cases ────────────────────────────────────────────────

    public function testStripTrailingZerosOnPrices(): void
    {
        $position = $this->fakePosition([
            'entry_price' => '18240.50000',
            'sl_price' => '18190.50000',
            'sl_points' => '50.00',
            'targets' => json_encode([['points' => 110, 'size' => 1, 'price' => 18350.5]]),
        ]);
        $this->positionRepo->method('findById')->willReturn($position);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('@ 18240.5', $text);
        $this->assertStringContainsString('TP: 18350.5', $text);
        $this->assertStringContainsString('SL: 18190.5', $text);
    }

    public function testSetupIsIncludedForOrder(): void
    {
        $position = $this->fakePosition(['setup' => 'Touchette haut de zone weekly']);
        $this->positionRepo->method('findById')->willReturn($position);

        $text = $this->service->generateText(1, 10);

        $this->assertStringContainsString('💬 Touchette haut de zone weekly', $text);
    }
}
