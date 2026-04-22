<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\BillingService;

class BillingController extends Controller
{
    private BillingService $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    public function status(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        return $this->jsonSuccess($this->billingService->getStatus($userId));
    }

    public function checkout(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $url = $this->billingService->createCheckoutSession($userId);
        return $this->jsonSuccess(['url' => $url]);
    }

    public function portal(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $url = $this->billingService->createPortalSession($userId);
        return $this->jsonSuccess(['url' => $url]);
    }

    public function webhook(Request $request): Response
    {
        $signature = $request->getHeader('STRIPE-SIGNATURE') ?? '';
        $payload = $request->getRawBody();

        $this->billingService->handleWebhook($payload, $signature);

        return $this->jsonSuccess(['received' => true]);
    }

    public function cancel(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $this->billingService->cancelSubscription($userId);

        return $this->jsonSuccess(['message_key' => 'billing.success.cancellation_scheduled']);
    }

    public function reactivate(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $this->billingService->reactivateSubscription($userId);

        return $this->jsonSuccess(['message_key' => 'billing.success.subscription_reactivated']);
    }
}
