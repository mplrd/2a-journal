<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use App\Enums\TradeStatus;
use PDO;

class PositionRepository
{
    private PDO $pdo;

    private const COLUMNS = 'id, user_id, account_id, direction, symbol, entry_price, size, setup,
                    plan_id, plan_adherence, plan_adherence_reason,
                    sl_points, sl_price, be_points, be_price, be_size, targets, notes,
                    import_batch_id, external_id, position_type, created_at, updated_at';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup,
                    plan_id, plan_adherence, plan_adherence_reason,
                    sl_points, sl_price, be_points, be_price, be_size, targets, notes,
                    import_batch_id, external_id, position_type)
             VALUES (:user_id, :account_id, :direction, :symbol, :entry_price, :size, :setup,
                    :plan_id, :plan_adherence, :plan_adherence_reason,
                    :sl_points, :sl_price, :be_points, :be_price, :be_size, :targets, :notes,
                    :import_batch_id, :external_id, :position_type)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'direction' => $data['direction'],
            'symbol' => $data['symbol'],
            'entry_price' => $data['entry_price'],
            'size' => $data['size'],
            'setup' => $data['setup'] ?? null,
            'plan_id' => $data['plan_id'] ?? null,
            'plan_adherence' => $data['plan_adherence'] ?? null,
            'plan_adherence_reason' => $data['plan_adherence_reason'] ?? null,
            'sl_points' => $data['sl_points'] ?? null,
            'sl_price' => $data['sl_price'] ?? null,
            'be_points' => $data['be_points'] ?? null,
            'be_price' => $data['be_price'] ?? null,
            'be_size' => $data['be_size'] ?? null,
            'targets' => $data['targets'] ?? null,
            'notes' => $data['notes'] ?? null,
            'import_batch_id' => $data['import_batch_id'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'position_type' => $data['position_type'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findAllByUserId(int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        // Positions of soft-deleted accounts must not appear in the listing.
        $where = 'WHERE user_id = :user_id'
               . ' AND EXISTS (SELECT 1 FROM accounts a WHERE a.id = positions.account_id AND a.deleted_at IS NULL)';
        $params = ['user_id' => $userId];

        if (!empty($filters['account_id'])) {
            $where .= ' AND account_id = :account_id';
            $params['account_id'] = $filters['account_id'];
        }

        if (!empty($filters['position_type'])) {
            $where .= ' AND position_type = :position_type';
            $params['position_type'] = $filters['position_type'];
        }

        if (!empty($filters['symbol'])) {
            $where .= ' AND symbol = :symbol';
            $params['symbol'] = $filters['symbol'];
        }

        if (!empty($filters['direction'])) {
            $where .= ' AND direction = :direction';
            $params['direction'] = $filters['direction'];
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM positions $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT ' . self::COLUMNS . " FROM positions $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM positions WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'direction', 'symbol', 'entry_price', 'size', 'setup',
            'plan_id', 'plan_adherence', 'plan_adherence_reason',
            'sl_points', 'sl_price', 'be_points', 'be_price', 'be_size',
            'targets', 'notes', 'position_type',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->findById($id);
        }

        $sql = 'UPDATE positions SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM positions WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Return current OPEN positions of an account whose external_id starts
     * with the given prefix (e.g. 'ouinex_'), indexed by external_id for
     * O(1) lookup in the broker-snapshot diff. Each row carries the trade id
     * and status so the caller can decide insert vs update vs transition.
     *
     * The prefix is passed as a literal — only the caller knows which
     * provider scope it wants to reconcile. Wildcard ('%') is appended here
     * to keep the LIKE pattern safe.
     */
    public function findOpenByExternalIdPrefixInAccount(int $accountId, string $prefix): array
    {
        // "Open" here means NOT YET CLOSED, so SECURED belongs in the set just
        // as much as OPEN — same semantics as StatsRepository::getOpenTrades.
        // Restricting it to OPEN made the diff blind to any trade secured in
        // the meantime: apply() inserts whatever it cannot find, so the very
        // next sync duplicated the position, and its eventual close never
        // transitioned. It bit any broker trade the user secured by hand, and
        // would bite every one of them once the stop-at-entry detection
        // promotes them automatically.
        //
        // targets is read back because a broker take profit must never
        // overwrite an objective the user typed; the other broker-side fields
        // are taken from the snapshot, which is authoritative for them.
        //
        // sl_points and the three realized figures come back so the running
        // rollup can be computed AND compared without a second round-trip: it
        // runs on every pass now, and what keeps that from being a write per
        // tick is knowing what is already on file.
        $stmt = $this->pdo->prepare(
            'SELECT p.id AS position_id, p.external_id, p.entry_price, p.size,
                    p.sl_price, p.sl_points, p.direction, p.symbol, p.targets,
                    t.id AS trade_id, t.status AS trade_status,
                    t.pnl, t.pnl_percent, t.risk_reward
             FROM positions p
             INNER JOIN trades t ON t.position_id = p.id
             WHERE p.account_id = :account_id
               AND p.external_id LIKE :prefix
               AND t.status IN (:open_status, :secured_status)'
        );
        $stmt->execute([
            'account_id' => $accountId,
            'prefix' => $prefix . '%',
            'open_status' => TradeStatus::OPEN->value,
            'secured_status' => TradeStatus::SECURED->value,
        ]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['external_id']] = $row;
        }
        return $result;
    }

    /**
     * Positions still AT RISK under a plan on one account, for the cumulative
     * risk cap (docs/83-trading-plans.md). "Still at risk" is narrower than
     * "still open", and the difference is the whole point:
     *
     * - a PENDING order counts, at full size. Counting live trades only would
     *   leave the filter blind on the path it exists for: a robot's signals
     *   become orders first, so a burst of them would all pass — each seeing no
     *   exposure yet — and only start counting once they filled, too late to
     *   refuse any. A cancelled or expired order drops out on its own.
     * - an OPEN trade counts at its REMAINING size. Trimming a position halves
     *   what it can still lose, so it must halve what it takes from the
     *   envelope.
     * - a SECURED trade counts for NOTHING and is not returned at all. SECURED
     *   means the stop was moved to breakeven, and TradeService says it in as
     *   many words: "the remainder is risk-free". Charging it to the envelope
     *   would hold a robot back precisely when it has protected early and the
     *   market is proving it right.
     *
     * be_reached is checked alongside the status: the two move together today
     * (markBeReached promotes OPEN to SECURED), and a broker sync setting one
     * without the other must not resurrect a risk that no longer exists.
     *
     * @return array<int,array{id:int,symbol:string,size:string,sl_points:?string}>
     */
    public function findStillExposedByPlanAndAccount(
        int $planId,
        int $accountId,
        ?int $excludePositionId = null,
    ): array {
        $params = [
            'plan_id' => $planId,
            'account_id' => $accountId,
            'pending' => OrderStatus::PENDING->value,
            'open' => TradeStatus::OPEN->value,
        ];
        $exclude = '';
        if ($excludePositionId !== null) {
            $exclude = ' AND p.id <> :exclude_id';
            $params['exclude_id'] = $excludePositionId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.symbol, p.sl_points,
                    COALESCE(t.remaining_size, p.size) AS size
             FROM positions p
             LEFT JOIN orders o ON o.position_id = p.id
             LEFT JOIN trades t ON t.position_id = p.id
             WHERE p.plan_id = :plan_id
               AND p.account_id = :account_id' . $exclude . '
               AND (
                     o.status = :pending
                     OR (t.status = :open AND t.be_reached = 0 AND t.remaining_size > 0)
                   )'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findAggregatedByUserId(int $userId, array $filters = []): array
    {
        $where = 'WHERE p.user_id = :user_id AND t.status IN (:status_open, :status_secured) AND t.remaining_size > 0'
               . ' AND EXISTS (SELECT 1 FROM accounts a WHERE a.id = p.account_id AND a.deleted_at IS NULL)';
        $params = [
            'user_id' => $userId,
            'status_open' => TradeStatus::OPEN->value,
            'status_secured' => TradeStatus::SECURED->value,
        ];

        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            $placeholders = [];
            foreach (array_values($filters['account_ids']) as $i => $id) {
                $key = "account_id_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = (int) $id;
            }
            $where .= ' AND p.account_id IN (' . implode(', ', $placeholders) . ')';
        } elseif (!empty($filters['account_id'])) {
            $where .= ' AND p.account_id = :account_id';
            $params['account_id'] = $filters['account_id'];
        }

        $sql = "SELECT p.account_id, p.symbol, p.direction,
                       SUM(t.remaining_size) AS total_size,
                       SUM(p.entry_price * t.remaining_size) / SUM(t.remaining_size) AS pru,
                       MIN(t.opened_at) AS first_opened_at
                FROM trades t
                JOIN positions p ON p.id = t.position_id
                $where
                GROUP BY p.account_id, p.symbol, p.direction
                ORDER BY MIN(t.opened_at) DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function transfer(int $id, int $newAccountId): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE positions SET account_id = :account_id WHERE id = :id');
        $stmt->execute(['account_id' => $newAccountId, 'id' => $id]);

        return $this->findById($id);
    }

    /**
     * Replaces a setup label inside the positions.setup JSON array for every
     * position of the given user that contains the old label. Each position
     * carries at most one occurrence of a given setup (UI-enforced), so a
     * single JSON_REPLACE at the path returned by JSON_SEARCH suffices.
     * Returns the number of rows updated.
     */
    public function renameSetupLabel(int $userId, string $oldLabel, string $newLabel): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE positions
             SET setup = JSON_REPLACE(
                 setup,
                 REPLACE(JSON_SEARCH(setup, 'one', :old), '\"', ''),
                 :new
             )
             WHERE user_id = :user_id AND JSON_CONTAINS(setup, JSON_QUOTE(:old_match))"
        );
        $stmt->execute([
            'old' => $oldLabel,
            'new' => $newLabel,
            'user_id' => $userId,
            'old_match' => $oldLabel,
        ]);

        return $stmt->rowCount();
    }
}
