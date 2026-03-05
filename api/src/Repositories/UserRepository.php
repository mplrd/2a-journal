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
            'SELECT id, email, password, first_name, last_name, timezone, default_currency, locale, theme, created_at, updated_at FROM users WHERE email = :email AND deleted_at IS NULL'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name, timezone, default_currency, locale, theme, profile_picture, created_at, updated_at FROM users WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
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

    private const PROFILE_FIELDS = ['first_name', 'last_name', 'timezone', 'default_currency', 'theme', 'locale', 'profile_picture'];

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
