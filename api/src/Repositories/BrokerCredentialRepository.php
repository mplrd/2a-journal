<?php

namespace App\Repositories;

use PDO;

/**
 * Data access for the app credentials a user shares across every connection of
 * one provider (docs/91-broker-shared-credentials.md).
 *
 * One row per (user, provider), enforced by a UNIQUE key — that constraint is
 * what makes the sharing a fact of the schema rather than a convention. The
 * blob is encrypted exactly like a connection's, by CredentialEncryptionService.
 */
class BrokerCredentialRepository
{
    public function __construct(private PDO $pdo) {}

    public function findByUserAndProvider(int $userId, string $provider): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM broker_credentials WHERE user_id = :user_id AND provider = :provider"
        );
        $stmt->execute(['user_id' => $userId, 'provider' => $provider]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array> every provider this user has app credentials for */
    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM broker_credentials WHERE user_id = :user_id ORDER BY provider"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Write the user's credentials for a provider, creating the row or
     * replacing its blob.
     *
     * ON DUPLICATE KEY rather than a read-then-write: two connections of the
     * same provider can be saved concurrently (a manual reconfigure while the
     * scheduler persists a refreshed access token), and a SELECT-then-INSERT
     * would lose one of them to a duplicate-key error instead of settling.
     *
     * `$fromRefresh` stamps `refreshed_at`, and ONLY a successful token renewal
     * may set it. A user typing or reconfiguring credentials leaves it alone:
     * the access token they pasted can be arbitrarily old, so that write must
     * not look like a renewal to the sync's skip window (migration 037).
     */
    public function upsert(
        int $userId,
        string $provider,
        string $ciphertext,
        string $iv,
        bool $fromRefresh = false,
    ): void {
        // Both are hardcoded SQL fragments chosen by a boolean — no value from
        // the caller ever reaches the statement text. On a plain write the
        // UPDATE assigns refreshed_at to itself, i.e. leaves it untouched.
        $onInsert = $fromRefresh ? 'UTC_TIMESTAMP()' : 'NULL';
        $onUpdate = $fromRefresh ? 'UTC_TIMESTAMP()' : 'refreshed_at';

        $this->pdo->prepare(
            "INSERT INTO broker_credentials (user_id, provider, credentials_encrypted, credentials_iv, refreshed_at)
             VALUES (:user_id, :provider, :ciphertext, :iv, {$onInsert})
             ON DUPLICATE KEY UPDATE
                credentials_encrypted = VALUES(credentials_encrypted),
                credentials_iv = VALUES(credentials_iv),
                refreshed_at = {$onUpdate}"
        )->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'ciphertext' => $ciphertext,
            'iv' => $iv,
        ]);
    }

    /**
     * How long ago a token renewal last succeeded for this user and provider,
     * in seconds. Null when there is no row, or when nothing has ever renewed
     * it — the two cases where no sync may skip its refresh.
     *
     * Reads `refreshed_at`, never `updated_at`: writing credentials is not
     * renewing them (migration 037). `refreshed_at` is a DATETIME written with
     * UTC_TIMESTAMP() and compared to UTC_TIMESTAMP(), so the difference never
     * depends on the session timezone — same convention as
     * `broker_connections.syncing_since`.
     */
    public function secondsSinceRefresh(int $userId, string $provider): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, refreshed_at, UTC_TIMESTAMP())
             FROM broker_credentials
             WHERE user_id = :user_id AND provider = :provider AND refreshed_at IS NOT NULL"
        );
        $stmt->execute(['user_id' => $userId, 'provider' => $provider]);
        $seconds = $stmt->fetchColumn();

        return $seconds === false || $seconds === null ? null : (int) $seconds;
    }

    /**
     * Drop the stored credentials. Called when the user's last connection for
     * that provider goes away: "disconnect" must not leave a usable access
     * token in the database for a broker the user believes they unplugged.
     */
    public function deleteForUserAndProvider(int $userId, string $provider): void
    {
        $this->pdo->prepare(
            "DELETE FROM broker_credentials WHERE user_id = :user_id AND provider = :provider"
        )->execute(['user_id' => $userId, 'provider' => $provider]);
    }
}
