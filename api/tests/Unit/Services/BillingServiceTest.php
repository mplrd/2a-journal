<?php

namespace Tests\Unit\Services;

use App\Exceptions\HttpException;
use App\Exceptions\NotFoundException;
use App\Repositories\SubscriptionRepository;
use App\Repositories\UserRepository;
use App\Repositories\WebhookEventRepository;
use App\Services\BillingService;
use PHPUnit\Framework\TestCase;

class BillingServiceTest extends TestCase
{
    private BillingService $service;
    private UserRepository $userRepo;
    private SubscriptionRepository $subscriptionRepo;
    private WebhookEventRepository $webhookEventRepo;
    private $stripeClient;
    private array $config;

    protected function setUp(): void
    {
        $this->userRepo = $this->createMock(UserRepository::class);
        $this->subscriptionRepo = $this->createMock(SubscriptionRepository::class);
        $this->webhookEventRepo = $this->createMock(WebhookEventRepository::class);
        $this->stripeClient = $this->createMock(\Stripe\StripeClient::class);

        $this->config = [
            'price_id' => 'price_test_123',
            'webhook_secret' => 'whsec_test_fake',
            'frontend_url' => 'http://2a.journal.local',
            'grace_days' => 14,
        ];

        $this->service = new BillingService(
            $this->userRepo,
            $this->subscriptionRepo,
            $this->webhookEventRepo,
            $this->stripeClient,
            $this->config
        );
    }

    // ── hasActiveAccess ──────────────────────────────────────────

    public function testHasActiveAccessReturnsTrueForBypassUser(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 1,
            'grace_period_end' => null,
        ]);

        $this->assertTrue($this->service->hasActiveAccess(1));
    }

    public function testHasActiveAccessReturnsTrueDuringGracePeriod(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $this->assertTrue($this->service->hasActiveAccess(1));
    }

    public function testHasActiveAccessReturnsTrueWithActiveSubscription(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => date('Y-m-d H:i:s', time() - 86400), // expired
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn([
            'user_id' => 1,
            'status' => 'active',
        ]);

        $this->assertTrue($this->service->hasActiveAccess(1));
    }

    public function testHasActiveAccessReturnsTrueWithTrialingSubscription(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => null,
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn([
            'user_id' => 1,
            'status' => 'trialing',
        ]);

        $this->assertTrue($this->service->hasActiveAccess(1));
    }

    public function testHasActiveAccessReturnsFalseWhenNothing(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => date('Y-m-d H:i:s', time() - 86400),
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn(null);

        $this->assertFalse($this->service->hasActiveAccess(1));
    }

    public function testHasActiveAccessReturnsFalseWithCanceledSubscription(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => null,
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn([
            'user_id' => 1,
            'status' => 'canceled',
        ]);

        $this->assertFalse($this->service->hasActiveAccess(1));
    }

    public function testHasActiveAccessReturnsFalseWithPastDueSubscription(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => null,
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn([
            'user_id' => 1,
            'status' => 'past_due',
        ]);

        $this->assertFalse($this->service->hasActiveAccess(1));
    }

    // ── handleWebhook signature verification ─────────────────────

    public function testHandleWebhookThrowsOnMissingSignature(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0); // HttpException stores status in its own field
        $this->service->handleWebhook('{}', '');
    }

    public function testHandleWebhookThrowsOnInvalidSignature(): void
    {
        try {
            $this->service->handleWebhook('{"id":"evt_1","type":"foo"}', 'invalid-sig');
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('billing.error.webhook_invalid', $e->getMessageKey());
        }
    }

    // ── getStatus ────────────────────────────────────────────────

    public function testGetStatusReturnsBypassWhenEnabled(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 1,
            'grace_period_end' => null,
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn(null);

        $status = $this->service->getStatus(1);

        $this->assertTrue($status['has_access']);
        $this->assertSame('bypass', $status['reason']);
    }

    public function testGetStatusReturnsGraceWhenActive(): void
    {
        $graceEnd = date('Y-m-d H:i:s', time() + 86400 * 10);
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => $graceEnd,
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn(null);

        $status = $this->service->getStatus(1);

        $this->assertTrue($status['has_access']);
        $this->assertSame('grace_period', $status['reason']);
        $this->assertSame($graceEnd, $status['grace_period_end']);
    }

    public function testGetStatusReturnsSubscriptionActive(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => null,
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn([
            'user_id' => 1,
            'status' => 'active',
            'current_period_end' => '2026-12-31 23:59:59',
            'cancel_at_period_end' => 0,
        ]);

        $status = $this->service->getStatus(1);

        $this->assertTrue($status['has_access']);
        $this->assertSame('subscription_active', $status['reason']);
        $this->assertSame('active', $status['subscription']['status']);
    }

    public function testGetStatusReturnsNoAccess(): void
    {
        $this->userRepo->method('findById')->willReturn([
            'id' => 1,
            'bypass_subscription' => 0,
            'grace_period_end' => null,
        ]);
        $this->subscriptionRepo->method('findByUserId')->willReturn(null);

        $status = $this->service->getStatus(1);

        $this->assertFalse($status['has_access']);
        $this->assertSame('no_access', $status['reason']);
    }

    public function testGetStatusThrowsWhenUserNotFound(): void
    {
        $this->userRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->getStatus(999);
    }
}
