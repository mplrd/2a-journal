<?php

namespace Tests\Unit\Middlewares;

use App\Core\Request;
use App\Exceptions\HttpException;
use App\Middlewares\RequireActiveSubscriptionMiddleware;
use App\Services\BillingService;
use PHPUnit\Framework\TestCase;

class RequireActiveSubscriptionMiddlewareTest extends TestCase
{
    private BillingService $billingService;

    protected function setUp(): void
    {
        $this->billingService = $this->createMock(BillingService::class);
    }

    private function makeRequest(int $userId = 1): Request
    {
        $request = Request::create('GET', '/accounts');
        $request->setAttribute('user_id', $userId);
        return $request;
    }

    public function testPassesWhenUserHasAccess(): void
    {
        $this->billingService->method('hasActiveAccess')->with(1)->willReturn(true);

        $middleware = new RequireActiveSubscriptionMiddleware($this->billingService);

        // No exception means pass
        $middleware->handle($this->makeRequest(1));
        $this->assertTrue(true); // explicit pass
    }

    public function testThrows402WhenUserHasNoAccess(): void
    {
        $this->billingService->method('hasActiveAccess')->with(1)->willReturn(false);

        $middleware = new RequireActiveSubscriptionMiddleware($this->billingService);

        try {
            $middleware->handle($this->makeRequest(1));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(402, $e->getStatusCode());
            $this->assertSame('SUBSCRIPTION_REQUIRED', $e->getErrorCode());
            $this->assertSame('billing.error.subscription_required', $e->getMessageKey());
        }
    }

    public function testReadsUserIdFromRequestAttribute(): void
    {
        $this->billingService
            ->expects($this->once())
            ->method('hasActiveAccess')
            ->with(42)
            ->willReturn(true);

        $middleware = new RequireActiveSubscriptionMiddleware($this->billingService);
        $middleware->handle($this->makeRequest(42));
    }
}
