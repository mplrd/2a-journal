<?php

namespace App\Repositories;

use PDO;

class PlatformSettingsRepository
{
    public function __construct(private PDO $pdo) {}

    public function get(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT setting_key, setting_value, value_type, description, updated_at, updated_by_user_id
             FROM platform_settings
             WHERE setting_key = :k'
        );
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function list(): array
    {
        $stmt = $this->pdo->query(
            'SELECT setting_key, setting_value, value_type, description, updated_at, updated_by_user_id
             FROM platform_settings
             ORDER BY setting_key ASC'
        );
        return $stmt->fetchAll();
    }

    public function upsert(string $key, ?string $value, string $valueType, ?string $description, ?int $updatedByUserId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO platform_settings (setting_key, setting_value, value_type, description, updated_by_user_id)
             VALUES (:k, :v, :t, :d, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     value_type = VALUES(value_type),
                                     description = VALUES(description),
                                     updated_by_user_id = VALUES(updated_by_user_id)'
        );
        $stmt->execute([
            'k' => $key,
            'v' => $value,
            't' => $valueType,
            'd' => $description,
            'u' => $updatedByUserId,
        ]);
    }
}
