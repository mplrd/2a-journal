<?php

namespace App\Repositories;

use PDO;

/**
 * Persists one-time SSO codes used to bridge sessions across the user SPA
 * and the admin SPA. Only the SHA-256 hash of the code is stored — the
 * plaintext exists in HTTP responses and URL params, never at rest.
 */
class SsoCodeRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(string $codeHash, int $userId, int $ttlSeconds): void
    {
        // Compute expires_at in SQL (DATE_ADD on NOW()) so the value uses the
        // same clock as the comparison done in findRedeemable. Mixing PHP-side
        // timestamps with MySQL NOW() breaks when the two run on different
        // timezones (e.g. PHP in UTC, MySQL in system local time).
        $stmt = $this->pdo->prepare(
            'INSERT INTO sso_codes (code_hash, user_id, expires_at)
             VALUES (:hash, :user_id, DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
        );
        $stmt->bindValue('hash', $codeHash);
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('ttl', $ttlSeconds, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Atomically claim a code: only one concurrent caller can win.
     *
     * The UPDATE itself is the gate — its WHERE clause requires the row to be
     * still redeemable, and rowCount() tells us whether we got it. This
     * closes the check-then-act race that splitting the lookup and the
     * mark-used into two statements would open.
     *
     * Returns the row's id + user_id on success, null when the code is
     * unknown, already used, or expired.
     */
    public function redeem(string $codeHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sso_codes SET used_at = NOW()
             WHERE code_hash = :hash AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute(['hash' => $codeHash]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        // Safe to read now: we own the row (used_at is set, no other request
        // can acquire it again).
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id FROM sso_codes WHERE code_hash = :hash'
        );
        $stmt->execute(['hash' => $codeHash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Best-effort cleanup of codes that can never be redeemed again.
     * Called opportunistically to keep the table small without a cron.
     */
    public function deleteExpiredOrUsed(): void
    {
        $this->pdo->exec(
            'DELETE FROM sso_codes WHERE expires_at < NOW() OR used_at IS NOT NULL'
        );
    }
}
