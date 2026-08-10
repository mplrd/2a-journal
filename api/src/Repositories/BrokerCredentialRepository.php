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
     */
    public function upsert(int $userId, string $provider, string $ciphertext, string $iv): void
    {
        $this->pdo->prepare(
            "INSERT INTO broker_credentials (user_id, provider, credentials_encrypted, credentials_iv)
             VALUES (:user_id, :provider, :ciphertext, :iv)
             ON DUPLICATE KEY UPDATE
                credentials_encrypted = VALUES(credentials_encrypted),
                credentials_iv = VALUES(credentials_iv)"
        )->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'ciphertext' => $ciphertext,
            'iv' => $iv,
        ]);
    }

    /**
     * How long ago the stored credentials were last written, in seconds — null
     * when the user has none for that provider.
     *
     * Computed in SQL rather than in PHP on purpose: `updated_at` is a
     * TIMESTAMP read back in the session timezone, so comparing it to a PHP
     * clock would be off by the offset. Both sides of TIMESTAMPDIFF here are
     * session-local, which makes the difference correct whatever it is set to.
     */
    public function secondsSinceUpdate(int $userId, string $provider): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, updated_at, CURRENT_TIMESTAMP)
             FROM broker_credentials WHERE user_id = :user_id AND provider = :provider"
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
