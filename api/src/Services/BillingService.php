<?php

namespace App\Services;

use App\Exceptions\HttpException;
use App\Exceptions\NotFoundException;
use App\Repositories\SubscriptionRepository;
use App\Repositories\UserRepository;
use App\Repositories\WebhookEventRepository;

class BillingService
{
    private const ACCESS_GRANTING_STATUSES = ['active', 'trialing'];

    private UserRepository $userRepo;
    private SubscriptionRepository $subscriptionRepo;
    private WebhookEventRepository $webhookEventRepo;
    /** @var \Stripe\StripeClient */
    private $stripeClient;
    private array $config; // price_id, webhook_secret, frontend_url, grace_days

    public function __construct(
        UserRepository $userRepo,
        SubscriptionRepository $subscriptionRepo,
        WebhookEventRepository $webhookEventRepo,
        $stripeClient,
        array $config
    ) {
        $this->userRepo = $userRepo;
        $this->subscriptionRepo = $subscriptionRepo;
        $this->webhookEventRepo = $webhookEventRepo;
        $this->stripeClient = $stripeClient;
        $this->config = $config;
    }

    public function hasActiveAccess(int $userId): bool
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            return false;
        }

        if ((int) ($user['bypass_subscription'] ?? 0) === 1) {
            return true;
        }

        if (!empty($user['grace_period_end']) && strtotime($user['grace_period_end']) > time()) {
            return true;
        }

        $subscription = $this->subscriptionRepo->findByUserId($userId);
        if ($subscription && in_array($subscription['status'], self::ACCESS_GRANTING_STATUSES, true)) {
            return true;
        }

        return false;
    }

    public function getStatus(int $userId): array
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('auth.error.user_not_found');
        }

        $subscription = $this->subscriptionRepo->findByUserId($userId);
        $status = [
            'has_access' => false,
            'reason' => 'no_access',
            'grace_period_end' => $user['grace_period_end'] ?? null,
            'subscription' => $subscription ? [
                'status' => $subscription['status'],
                'current_period_end' => $subscription['current_period_end'],
                'cancel_at_period_end' => (bool) $subscription['cancel_at_period_end'],
            ] : null,
        ];

        if ((int) ($user['bypass_subscription'] ?? 0) === 1) {
            $status['has_access'] = true;
            $status['reason'] = 'bypass';
            return $status;
        }

        if ($subscription && in_array($subscription['status'], self::ACCESS_GRANTING_STATUSES, true)) {
            $status['has_access'] = true;
            $status['reason'] = 'subscription_active';
            return $status;
        }

        if (!empty($user['grace_period_end']) && strtotime($user['grace_period_end']) > time()) {
            $status['has_access'] = true;
            $status['reason'] = 'grace_period';
            return $status;
        }

        return $status;
    }

    public function createCheckoutSession(int $userId): string
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('auth.error.user_not_found');
        }

        $customerId = $user['stripe_customer_id'] ?? null;
        if (!$customerId) {
            $customer = $this->stripeClient->customers->create([
                'email' => $user['email'],
                'metadata' => ['user_id' => (string) $userId],
            ]);
            $customerId = $customer->id;
            $this->userRepo->setStripeCustomerId($userId, $customerId);
        }

        $session = $this->stripeClient->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [[
                'price' => $this->config['price_id'],
                'quantity' => 1,
            ]],
            'allow_promotion_codes' => true,
            'success_url' => rtrim($this->config['frontend_url'], '/') . '/subscribe/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim($this->config['frontend_url'], '/') . '/subscribe',
            'client_reference_id' => (string) $userId,
        ]);

        return $session->url;
    }

    public function createPortalSession(int $userId): string
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new NotFoundException('auth.error.user_not_found');
        }
        if (empty($user['stripe_customer_id'])) {
            throw new HttpException('NO_STRIPE_CUSTOMER', 'billing.error.no_stripe_customer', null, 400);
        }

        $session = $this->stripeClient->billingPortal->sessions->create([
            'customer' => $user['stripe_customer_id'],
            'return_url' => rtrim($this->config['frontend_url'], '/') . '/subscribe',
        ]);

        return $session->url;
    }

    public function handleWebhook(string $payload, string $signature): void
    {
        if ($signature === '') {
            throw new HttpException('WEBHOOK_MISSING_SIGNATURE', 'billing.error.webhook_invalid', null, 400);
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $this->config['webhook_secret']);
        } catch (\Throwable $e) {
            throw new HttpException('WEBHOOK_INVALID_SIGNATURE', 'billing.error.webhook_invalid', null, 400);
        }

        if ($this->webhookEventRepo->existsByStripeId($event->id)) {
            // Idempotence: Stripe can redeliver an event. We've already processed this one.
            return;
        }

        $this->dispatchEvent($event);
        $this->webhookEventRepo->markProcessed($event->id, $event->type);
    }

    private function dispatchEvent(object $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event->data->object);
                break;
            case 'customer.subscription.updated':
            case 'customer.subscription.created':
                $this->handleSubscriptionUpdated($event->data->object);
                break;
            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event->data->object);
                break;
            case 'invoice.payment_succeeded':
                $this->handleInvoicePaymentSucceeded($event->data->object);
                break;
            case 'invoice.payment_failed':
                // Stripe handles retries automatically via Smart Retries.
                // A terminal failure will trigger customer.subscription.updated → past_due/unpaid.
                // No-op here.
                break;
        }
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $userId = isset($session->client_reference_id) ? (int) $session->client_reference_id : null;
        if (!$userId) {
            // Session without client_reference_id cannot be linked to a user.
            return;
        }
        if (!empty($session->customer) && empty($this->userRepo->findById($userId)['stripe_customer_id'])) {
            $this->userRepo->setStripeCustomerId($userId, $session->customer);
        }

        if (!empty($session->subscription)) {
            // Retrieve the subscription to persist its current state.
            $subscription = $this->stripeClient->subscriptions->retrieve($session->subscription);
            $this->persistSubscription($userId, $subscription);
        }
    }

    private function handleSubscriptionUpdated(object $subscription): void
    {
        $userId = $this->resolveUserIdFromSubscription($subscription);
        if (!$userId) {
            return;
        }
        $this->persistSubscription($userId, $subscription);
    }

    private function handleSubscriptionDeleted(object $subscription): void
    {
        $userId = $this->resolveUserIdFromSubscription($subscription);
        if (!$userId) {
            return;
        }
        // Record terminal status so the middleware blocks from now on.
        $this->subscriptionRepo->upsert(
            $userId,
            $subscription->id,
            'canceled',
            $this->formatTimestamp($this->extractCurrentPeriodEnd($subscription)),
            false
        );
    }

    private function handleInvoicePaymentSucceeded(object $invoice): void
    {
        if (empty($invoice->subscription)) {
            return;
        }
        $subscription = $this->stripeClient->subscriptions->retrieve($invoice->subscription);
        $userId = $this->resolveUserIdFromSubscription($subscription);
        if (!$userId) {
            return;
        }
        $this->persistSubscription($userId, $subscription);
    }

    private function persistSubscription(int $userId, object $subscription): void
    {
        $this->subscriptionRepo->upsert(
            $userId,
            $subscription->id,
            $subscription->status,
            $this->formatTimestamp($this->extractCurrentPeriodEnd($subscription)),
            (bool) ($subscription->cancel_at_period_end ?? false)
        );
    }

    /**
     * Read current_period_end from a subscription object, supporting both the legacy shape
     * (pre-2025-08-27, field on the subscription root) and the new shape (field on items[0]).
     */
    private function extractCurrentPeriodEnd(object $subscription): ?int
    {
        if (!empty($subscription->current_period_end)) {
            return (int) $subscription->current_period_end;
        }
        if (!empty($subscription->items->data[0]->current_period_end)) {
            return (int) $subscription->items->data[0]->current_period_end;
        }
        return null;
    }

    private function resolveUserIdFromSubscription(object $subscription): ?int
    {
        // First try metadata, then fall back to customer lookup.
        if (!empty($subscription->metadata->user_id)) {
            return (int) $subscription->metadata->user_id;
        }
        if (!empty($subscription->customer)) {
            $user = $this->userRepo->findByStripeCustomerId($subscription->customer);
            return $user ? (int) $user['id'] : null;
        }
        return null;
    }

    private function formatTimestamp(?int $unixTimestamp): ?string
    {
        return $unixTimestamp ? date('Y-m-d H:i:s', $unixTimestamp) : null;
    }
}
