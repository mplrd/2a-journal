<?php

namespace App\Repositories;

use PDO;

class SubscriptionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, stripe_subscription_id, status, current_period_end, cancel_at_period_end, created_at, updated_at
             FROM subscriptions WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByStripeId(string $stripeSubscriptionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, stripe_subscription_id, status, current_period_end, cancel_at_period_end, created_at, updated_at
             FROM subscriptions WHERE stripe_subscription_id = :sid'
        );
        $stmt->execute(['sid' => $stripeSubscriptionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Insert or update the subscription row for a user based on the Stripe state.
     * Keyed on user_id (one subscription per user).
     */
    public function upsert(int $userId, string $stripeSubscriptionId, string $status, ?string $currentPeriodEnd, bool $cancelAtPeriodEnd): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (user_id, stripe_subscription_id, status, current_period_end, cancel_at_period_end)
             VALUES (:uid, :sid, :status, :cpe, :cape)
             ON DUPLICATE KEY UPDATE
                stripe_subscription_id = VALUES(stripe_subscription_id),
                status = VALUES(status),
                current_period_end = VALUES(current_period_end),
                cancel_at_period_end = VALUES(cancel_at_period_end)'
        );
        $stmt->execute([
            'uid' => $userId,
            'sid' => $stripeSubscriptionId,
            'status' => $status,
            'cpe' => $currentPeriodEnd,
            'cape' => $cancelAtPeriodEnd ? 1 : 0,
        ]);
    }

    public function deleteByStripeId(string $stripeSubscriptionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM subscriptions WHERE stripe_subscription_id = :sid');
        $stmt->execute(['sid' => $stripeSubscriptionId]);
    }
}
