<?php

namespace App\Repositories;

use PDO;

class UserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password, first_name, last_name, locale) VALUES (:email, :password, :first_name, :last_name, :locale)'
        );
        $stmt->execute([
            'email' => $data['email'],
            'password' => $data['password'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'locale' => $data['locale'] ?? 'en',
        ]);

        return $this->findById((int)$this->pdo->lastInsertId());
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, password, first_name, last_name, timezone, default_currency, locale, theme, be_threshold_percent, email_verified_at, failed_login_attempts, locked_until, created_at, updated_at FROM users WHERE email = :email AND deleted_at IS NULL'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name, timezone, default_currency, locale, theme, be_threshold_percent, profile_picture, onboarding_completed_at, email_verified_at, created_at, updated_at FROM users WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        if ($user) {
            $user['email_verified'] = $user['email_verified_at'] !== null;
        }

        return $user ?: null;
    }

    public function completeOnboarding(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET onboarding_completed_at = CURRENT_TIMESTAMP WHERE id = :id AND onboarding_completed_at IS NULL AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $this->findById($id);
    }

    public function existsByEmail(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND deleted_at IS NULL');
        $stmt->execute(['email' => $email]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public function updateLocale(int $id, string $locale): array
    {
        $stmt = $this->pdo->prepare('UPDATE users SET locale = :locale WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id, 'locale' => $locale]);

        return $this->findById($id);
    }

    public function incrementFailedLoginAttempts(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        $stmt = $this->pdo->prepare('SELECT failed_login_attempts FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    public function lockAccount(int $id, string $lockedUntil): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET locked_until = :locked_until WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id, 'locked_until' => $lockedUntil]);
    }

    public function resetLoginAttempts(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    public function setEmailVerified(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE id = :id AND email_verified_at IS NULL AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    public function updatePassword(int $id, string $hashedPassword): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password = :password, failed_login_attempts = 0, locked_until = NULL WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id, 'password' => $hashedPassword]);
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    private const PROFILE_FIELDS = ['first_name', 'last_name', 'timezone', 'default_currency', 'theme', 'locale', 'be_threshold_percent', 'profile_picture'];

    public function updateProfile(int $id, array $data): ?array
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->findById($id);
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }
}
