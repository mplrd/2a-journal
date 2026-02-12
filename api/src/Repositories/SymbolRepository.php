<?php

namespace App\Repositories;

use PDO;

class SymbolRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findAllActive(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, type, point_value, currency FROM symbols WHERE is_active = 1 ORDER BY code ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name, type, point_value, currency FROM symbols WHERE code = :code AND is_active = 1'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
