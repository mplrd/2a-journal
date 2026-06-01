<?php

namespace App\Repositories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use PDO;

class SupportTicketRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_tickets (user_id, type, status, priority, subject, details)
             VALUES (:user_id, :type, :status, :priority, :subject, :details)'
        );
        $details = $data['details'] ?? null;
        $stmt->execute([
            'user_id' => $data['user_id'],
            'type' => $data['type'],
            'status' => $data['status'] ?? TicketStatus::OPEN->value,
            'priority' => $data['priority'] ?? TicketPriority::NORMAL->value,
            'subject' => $data['subject'],
            'details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_tickets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Same as findById but scoped to a single owner (404/403 enforcement). */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_tickets WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = 'WHERE t.user_id = :user_id';
        $params = ['user_id' => $userId];
        $this->applyCommonFilters($filters, $where, $params, false);

        return $this->paginate($where, $params, $limit, $offset, false);
    }

    public function findAllAdmin(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = 'WHERE 1 = 1';
        $params = [];
        $this->applyCommonFilters($filters, $where, $params, true);

        return $this->paginate($where, $params, $limit, $offset, true);
    }

    public function updateStatus(int $id, string $status, ?string $closedAt): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE support_tickets SET status = :status, closed_at = :closed_at WHERE id = :id');
        $stmt->execute(['status' => $status, 'closed_at' => $closedAt, 'id' => $id]);

        return $this->findById($id);
    }

    public function updatePriority(int $id, string $priority): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE support_tickets SET priority = :priority WHERE id = :id');
        $stmt->execute(['priority' => $priority, 'id' => $id]);

        return $this->findById($id);
    }

    /** Bump updated_at so a new message lifts the ticket in activity ordering. */
    public function touch(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE support_tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function applyCommonFilters(array $filters, string &$where, array &$params, bool $admin): void
    {
        $this->applyEnumFilter('t.type', $filters['type'] ?? null, TicketType::class, $where, $params);
        $this->applyEnumFilter('t.status', $filters['status'] ?? null, TicketStatus::class, $where, $params);
        $this->applyEnumFilter('t.priority', $filters['priority'] ?? null, TicketPriority::class, $where, $params);

        if ($admin && !empty($filters['search'])) {
            $where .= ' AND (t.subject LIKE :search OR u.email LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
    }

    /**
     * Apply a single- or multi-value enum filter. Accepts a scalar or a CSV
     * string (e.g. "OPEN,IN_PROGRESS"); each value is validated against the
     * backing enum so unknown values are silently dropped. With one value it
     * emits `col = :p`, with several `col IN (:p0, :p1, …)`. If nothing valid
     * remains, the dimension is left unfiltered.
     *
     * @param class-string<\BackedEnum> $enumClass
     */
    private function applyEnumFilter(string $column, mixed $raw, string $enumClass, string &$where, array &$params): void
    {
        if ($raw === null || $raw === '') {
            return;
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $valid = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && $enumClass::tryFrom($value) !== null && !in_array($value, $valid, true)) {
                $valid[] = $value;
            }
        }
        if ($valid === []) {
            return;
        }

        // Derive safe placeholder names from the column (e.g. t.type → type0).
        $base = str_contains($column, '.') ? substr($column, strpos($column, '.') + 1) : $column;
        $placeholders = [];
        foreach ($valid as $i => $value) {
            $key = "{$base}{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $value;
        }

        $where .= count($placeholders) === 1
            ? " AND {$column} = {$placeholders[0]}"
            : " AND {$column} IN (" . implode(', ', $placeholders) . ')';
    }

    private function paginate(string $where, array $params, int $limit, int $offset, bool $admin): array
    {
        $join = $admin ? 'INNER JOIN users u ON u.id = t.user_id' : '';
        $userCols = $admin
            ? ', u.email AS user_email, u.first_name AS user_first_name, u.last_name AS user_last_name'
            : '';

        $countSql = "SELECT COUNT(*) FROM support_tickets t {$join} {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT t.*{$userCols},
                       (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id = t.id) AS message_count
                FROM support_tickets t
                {$join}
                {$where}
                ORDER BY t.updated_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
