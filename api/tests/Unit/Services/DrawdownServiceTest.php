<?php

namespace Tests\Unit\Services;

use App\Repositories\AccountRepository;
use App\Repositories\TradeRepository;
use App\Repositories\UserRepository;
use App\Services\DrawdownService;
use App\Services\EmailService;
use PHPUnit\Framework\TestCase;

class DrawdownServiceTest extends TestCase
{
    private DrawdownService $service;
    private AccountRepository $accountRepo;
    private TradeRepository $tradeRepo;
    private UserRepository $userRepo;
    private EmailService $emailService;

    protected function setUp(): void
    {
        $this->accountRepo = $this->createMock(AccountRepository::class);
        $this->tradeRepo = $this->createMock(TradeRepository::class);
        $this->userRepo = $this->createMock(UserRepository::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->service = new DrawdownService($this->accountRepo, $this->tradeRepo, $this->userRepo, $this->emailService);
    }

    private function fakeUser(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'email' => 'demo@example.com',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'dd_alert_threshold_percent' => 5.0,
        ], $overrides);
    }

    private function fakeAccount(array $overrides = []): array
    {
        return array_merge([
            'id' => 100,
            'user_id' => 1,
            'name' => 'PF Account A',
            'currency' => 'USD',
            'max_drawdown' => 5000.0,
            'daily_drawdown' => 2000.0,
            'last_max_dd_alert_at' => null,
            'last_daily_dd_alert_at' => null,
        ], $overrides);
    }

    public function testGetStatusForUserSkipsAccountsWithoutDdConfig(): void
    {
        $this->userRepo->method('findById')->willReturn($this->fakeUser());
        $this->accountRepo->method('findAllByUserId')->willReturn([
            'items' => [
                $this->fakeAccount(['id' => 100, 'max_drawdown' => null, 'daily_drawdown' => null]),
                $this->fakeAccount(['id' => 101]),
            ],
            'total' => 2,
        ]);
        $this->tradeRepo->method('sumRealizedPnlForAccount')->willReturn(0.0);
        $this->tradeRepo->method('sumRealizedPnlForAccountSince')->willReturn(0.0);

        $result = $this->service->getStatusForUser(1);

        $this->assertCount(1, $result);
        $this->assertSame(101, $result[0]['account_id']);
    }

    public function testComputeReturnsZeroPercentsWhenAccountIsBreakEven(): void
    {
        $this->userRepo->method('findById')->willReturn($this->fakeUser());
        $this->accountRepo->method('findAllByUserId')->willReturn([
            'items' => [$this->fakeAccount()],
            'total' => 1,
        ]);
        $this->tradeRepo->method('sumRealizedPnlForAccount')->willReturn(0.0);
        $this->tradeRepo->method('sumRealizedPnlForAccountSince')->willReturn(0.0);

        $result = $this->service->getStatusForUser(1);

        $this->assertCount(1, $result);
        $this->assertSame(0.0, $result[0]['max_used_percent']);
        $this->assertSame(0.0, $result[0]['daily_used_percent']);
        $this->assertFalse($result[0]['alert_max']);
        $this->assertFalse($result[0]['alert_daily']);
    }

    public function testComputeRaisesAlertMaxWhenLossExceedsCutoff(): void
    {
        // max DD 5000, threshold 5% → cutoff 95% → alert when used ≥ 4750.
        // realized -4800 → 96% used → alert_max = true.
        $this->userRepo->method('findById')->willReturn($this->fakeUser());
        $this->accountRepo->method('findAllByUserId')->willReturn([
            'items' => [$this->fakeAccount()],
            'total' => 1,
        ]);
        $this->tradeRepo->method('sumRealizedPnlForAccount')->willReturn(-4800.0);
        $this->tradeRepo->method('sumRealizedPnlForAccountSince')->willReturn(0.0);

        $result = $this->service->getStatusForUser(1);

        $this->assertEquals(96.0, $result[0]['max_used_percent']);
        $this->assertTrue($result[0]['alert_max']);
        $this->assertFalse($result[0]['alert_daily']);
    }

    public function testCheckAndNotifyEmailsOnceWhenThresholdCrossed(): void
    {
        $this->userRepo->method('findById')->willReturn($this->fakeUser());
        $this->accountRepo->method('findByIdForDdCheck')->willReturn($this->fakeAccount());
        $this->tradeRepo->method('sumRealizedPnlForAccount')->willReturn(-4900.0); // 98% of 5000
        $this->tradeRepo->method('sumRealizedPnlForAccountSince')->willReturn(0.0);

        $this->emailService->expects($this->once())
            ->method('sendDdAlertEmail')
            ->with('demo@example.com', 'fr', 'max', $this->anything());
        $this->accountRepo->expects($this->once())
            ->method('markDdAlertSent')
            ->with(100, 'max');

        $this->service->checkAndNotifyForAccount(100, 1);
    }

    public function testCheckAndNotifySkipsWhenAlreadySentToday(): void
    {
        // last_max_dd_alert_at is "now" → already sent today in user TZ → no email.
        $this->userRepo->method('findById')->willReturn($this->fakeUser());
        $this->accountRepo->method('findByIdForDdCheck')->willReturn($this->fakeAccount([
            'last_max_dd_alert_at' => gmdate('Y-m-d H:i:s'),
        ]));
        $this->tradeRepo->method('sumRealizedPnlForAccount')->willReturn(-4900.0);
        $this->tradeRepo->method('sumRealizedPnlForAccountSince')->willReturn(0.0);

        $this->emailService->expects($this->never())->method('sendDdAlertEmail');
        $this->accountRepo->expects($this->never())->method('markDdAlertSent');

        $this->service->checkAndNotifyForAccount(100, 1);
    }
}
