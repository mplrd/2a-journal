<?php

namespace App\Repositories;

use PDO;

class CustomFieldValueRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function saveForTrade(int $tradeId, array $fields): void
    {
        $this->deleteByTradeId($tradeId);

        if (empty($fields)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO custom_field_values (custom_field_id, trade_id, value)
             VALUES (:custom_field_id, :trade_id, :value)'
        );

        foreach ($fields as $field) {
            $stmt->execute([
                'custom_field_id' => $field['field_id'],
                'trade_id' => $tradeId,
                'value' => $field['value'],
            ]);
        }
    }

    public function findByTradeId(int $tradeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cfv.custom_field_id AS field_id, cfd.name, cfd.field_type, cfv.value
             FROM custom_field_values cfv
             INNER JOIN custom_field_definitions cfd ON cfd.id = cfv.custom_field_id
             WHERE cfv.trade_id = :trade_id AND cfd.deleted_at IS NULL
             ORDER BY cfd.sort_order ASC, cfd.id ASC'
        );
        $stmt->execute(['trade_id' => $tradeId]);

        return $stmt->fetchAll();
    }

    public function findByTradeIds(array $tradeIds): array
    {
        if (empty($tradeIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tradeIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT cfv.trade_id, cfv.custom_field_id AS field_id, cfd.name, cfd.field_type, cfv.value
             FROM custom_field_values cfv
             INNER JOIN custom_field_definitions cfd ON cfd.id = cfv.custom_field_id
             WHERE cfv.trade_id IN ($placeholders) AND cfd.deleted_at IS NULL
             ORDER BY cfd.sort_order ASC, cfd.id ASC"
        );
        $stmt->execute(array_values($tradeIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $tradeId = (int) $row['trade_id'];
            unset($row['trade_id']);
            $result[$tradeId][] = $row;
        }

        return $result;
    }

    public function deleteByTradeId(int $tradeId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM custom_field_values WHERE trade_id = :trade_id');
        $stmt->execute(['trade_id' => $tradeId]);
    }
}
