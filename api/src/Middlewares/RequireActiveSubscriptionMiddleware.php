<?php

namespace App\Middlewares;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Exceptions\HttpException;
use App\Services\BillingService;

class RequireActiveSubscriptionMiddleware implements MiddlewareInterface
{
    private BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    public function handle(Request $request): void
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId || !$this->billingService->hasActiveAccess((int) $userId)) {
            throw new HttpException('SUBSCRIPTION_REQUIRED', 'billing.error.subscription_required', null, 402);
        }
    }
}
