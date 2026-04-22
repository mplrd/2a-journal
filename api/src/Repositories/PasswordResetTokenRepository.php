<?php

namespace App\Repositories;

use PDO;

class PasswordResetTokenRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(int $userId, string $token, string $expiresAt): void
    {
        // Delete any existing tokens for this user first
        $this->deleteByUserId($userId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM password_reset_tokens WHERE token = :token'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deleteByToken(string $token): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE token = :token');
        $stmt->execute(['token' => $token]);
    }

    public function deleteByUserId(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }
}
