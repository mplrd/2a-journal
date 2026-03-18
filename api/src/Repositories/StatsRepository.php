<?php

namespace App\Repositories;

use App\Enums\TradeStatus;
use PDO;

class StatsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Build WHERE clause and params from filters.
     * @return array{0: string, 1: array}
     */
    private function buildWhereClause(int $userId, array $filters = []): array
    {
        $where = 'WHERE p.user_id = :user_id AND t.status = :status';
        $params = ['user_id' => $userId, 'status' => TradeStatus::CLOSED->value];

        if (!empty($filters['account_id'])) {
            $where .= ' AND p.account_id = :account_id';
            $params['account_id'] = (int) $filters['account_id'];
        }

        if (!empty($filters['date_from'])) {
            $where .= ' AND t.closed_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where .= ' AND t.closed_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['direction'])) {
            $where .= ' AND p.direction = :direction';
            $params['direction'] = $filters['direction'];
        }

        if (!empty($filters['symbols'])) {
            $placeholders = [];
            foreach ($filters['symbols'] as $i => $symbol) {
                $key = "sym_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $symbol;
            }
            $where .= ' AND p.symbol IN (' . implode(', ', $placeholders) . ')';
        }

        if (!empty($filters['setups'])) {
            $conditions = [];
            foreach ($filters['setups'] as $i => $setup) {
                $key = "setup_{$i}";
                $conditions[] = "JSON_CONTAINS(p.setup, :{$key})";
                $params[$key] = json_encode($setup);
            }
            $where .= ' AND (' . implode(' OR ', $conditions) . ')';
        }

        return [$where, $params];
    }

    public function getOverview(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        $sql = "SELECT
                    COUNT(*) AS total_trades,
                    COALESCE(SUM(t.pnl), 0) AS total_pnl,
                    SUM(CASE WHEN t.pnl > 0 THEN 1 ELSE 0 END) AS winning_trades,
                    SUM(CASE WHEN t.pnl < 0 THEN 1 ELSE 0 END) AS losing_trades,
                    SUM(CASE WHEN t.pnl = 0 THEN 1 ELSE 0 END) AS be_trades,
                    CASE WHEN COUNT(*) > 0
                        THEN ROUND(SUM(CASE WHEN t.pnl > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2)
                        ELSE 0
                    END AS win_rate,
                    CASE WHEN SUM(CASE WHEN t.pnl < 0 THEN ABS(t.pnl) ELSE 0 END) > 0
                        THEN ROUND(
                            SUM(CASE WHEN t.pnl > 0 THEN t.pnl ELSE 0 END)
                            / SUM(CASE WHEN t.pnl < 0 THEN ABS(t.pnl) ELSE 0 END),
                            2
                        )
                        ELSE NULL
                    END AS profit_factor,
                    MAX(t.pnl) AS best_trade,
                    MIN(t.pnl) AS worst_trade,
                    ROUND(AVG(t.risk_reward), 2) AS avg_rr
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                $where";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'total_trades' => (int) $row['total_trades'],
            'total_pnl' => (float) $row['total_pnl'],
            'winning_trades' => (int) ($row['winning_trades'] ?? 0),
            'losing_trades' => (int) ($row['losing_trades'] ?? 0),
            'be_trades' => (int) ($row['be_trades'] ?? 0),
            'win_rate' => (float) $row['win_rate'],
            'profit_factor' => $row['profit_factor'] !== null ? (float) $row['profit_factor'] : null,
            'best_trade' => $row['total_trades'] > 0 ? (float) $row['best_trade'] : null,
            'worst_trade' => $row['total_trades'] > 0 ? (float) $row['worst_trade'] : null,
            'avg_rr' => $row['avg_rr'] !== null ? (float) $row['avg_rr'] : null,
        ];
    }

    public function getCumulativePnl(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        $sql = "SELECT t.closed_at, t.pnl, p.symbol
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                $where
                ORDER BY t.closed_at ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $cumulative = 0;
        $result = [];
        foreach ($rows as $row) {
            $cumulative += (float) $row['pnl'];
            $result[] = [
                'closed_at' => $row['closed_at'],
                'pnl' => (float) $row['pnl'],
                'cumulative_pnl' => round($cumulative, 2),
                'symbol' => $row['symbol'],
            ];
        }

        return $result;
    }

    public function getWinLossDistribution(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        $sql = "SELECT
                    SUM(CASE WHEN t.pnl > 0 THEN 1 ELSE 0 END) AS win,
                    SUM(CASE WHEN t.pnl < 0 THEN 1 ELSE 0 END) AS loss,
                    SUM(CASE WHEN t.pnl = 0 THEN 1 ELSE 0 END) AS be
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                $where";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'win' => (int) ($row['win'] ?? 0),
            'loss' => (int) ($row['loss'] ?? 0),
            'be' => (int) ($row['be'] ?? 0),
        ];
    }

    public function getPnlBySymbol(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        $sql = "SELECT p.symbol,
                    COUNT(*) AS trade_count,
                    COALESCE(SUM(t.pnl), 0) AS total_pnl
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                $where
                GROUP BY p.symbol
                ORDER BY total_pnl DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function dimensionStatsSelect(): string
    {
        return "COUNT(*) AS total_trades,
                SUM(CASE WHEN t.pnl > 0 THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN t.pnl < 0 THEN 1 ELSE 0 END) AS losses,
                CASE WHEN COUNT(*) > 0
                    THEN ROUND(SUM(CASE WHEN t.pnl > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2)
                    ELSE 0
                END AS win_rate,
                COALESCE(SUM(t.pnl), 0) AS total_pnl,
                ROUND(AVG(t.risk_reward), 2) AS avg_rr,
                CASE WHEN SUM(CASE WHEN t.pnl < 0 THEN ABS(t.pnl) ELSE 0 END) > 0
                    THEN ROUND(
                        SUM(CASE WHEN t.pnl > 0 THEN t.pnl ELSE 0 END)
                        / SUM(CASE WHEN t.pnl < 0 THEN ABS(t.pnl) ELSE 0 END),
                        2
                    )
                    ELSE NULL
                END AS profit_factor";
    }

    public function getStatsBySymbol(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $select = $this->dimensionStatsSelect();

        $sql = "SELECT p.symbol, {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                GROUP BY p.symbol
                ORDER BY total_pnl DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getStatsByDirection(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $select = $this->dimensionStatsSelect();

        $sql = "SELECT p.direction, {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                GROUP BY p.direction
                ORDER BY total_pnl DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getStatsBySetup(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $select = $this->dimensionStatsSelect();

        // JSON_UNQUOTE + JSON_EXTRACT to flatten the setup array
        $sql = "SELECT s.setup, {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                CROSS JOIN JSON_TABLE(p.setup, '$[*]' COLUMNS (setup VARCHAR(255) PATH '$')) AS s
                {$where}
                GROUP BY s.setup
                ORDER BY total_pnl DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getStatsByPeriod(int $userId, string $group = 'month', array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $select = $this->dimensionStatsSelect();

        $periodExpr = match ($group) {
            'day' => "DATE_FORMAT(t.closed_at, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(t.closed_at, '%x-W%v')",
            'year' => "DATE_FORMAT(t.closed_at, '%Y')",
            default => "DATE_FORMAT(t.closed_at, '%Y-%m')",
        };

        $sql = "SELECT {$periodExpr} AS period, {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                GROUP BY period
                ORDER BY period ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getRrDistribution(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        $sql = "SELECT
                    CASE
                        WHEN t.risk_reward < -2 THEN '<-2'
                        WHEN t.risk_reward < -1 THEN '-2--1'
                        WHEN t.risk_reward < 0  THEN '-1-0'
                        WHEN t.risk_reward < 1  THEN '0-1'
                        WHEN t.risk_reward < 2  THEN '1-2'
                        WHEN t.risk_reward < 3  THEN '2-3'
                        ELSE '>3'
                    END AS bucket,
                    COUNT(*) AS count
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                GROUP BY bucket
                ORDER BY MIN(t.risk_reward) ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function localClosedAt(string $tzOffset): string
    {
        if ($tzOffset === '+00:00') {
            return 't.closed_at';
        }
        $tzSafe = $this->pdo->quote($tzOffset);
        return "CONVERT_TZ(t.closed_at, '+00:00', {$tzSafe})";
    }

    public function getHeatmap(int $userId, array $filters = [], string $tzOffset = '+00:00'): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $local = $this->localClosedAt($tzOffset);

        $sql = "SELECT
                    DAYOFWEEK({$local}) - 1 AS day,
                    HOUR({$local}) AS hour,
                    COUNT(*) AS trade_count,
                    COALESCE(SUM(t.pnl), 0) AS total_pnl,
                    ROUND(AVG(t.pnl), 2) AS avg_pnl
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                GROUP BY day, hour
                ORDER BY day, hour";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch closed trades with fields needed for session classification in PHP.
     */
    public function getTradesForSessionStats(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        $sql = "SELECT t.closed_at, t.pnl, t.risk_reward
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                ORDER BY t.closed_at";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getStatsByAccount(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $select = $this->dimensionStatsSelect();

        $sql = "SELECT p.account_id, a.name AS account_name, a.account_type, {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                INNER JOIN accounts a ON a.id = p.account_id
                {$where}
                GROUP BY p.account_id, a.name, a.account_type
                ORDER BY total_pnl DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getStatsByAccountType(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $select = $this->dimensionStatsSelect();

        $sql = "SELECT a.account_type, {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                INNER JOIN accounts a ON a.id = p.account_id
                {$where}
                GROUP BY a.account_type
                ORDER BY total_pnl DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getRecentTrades(int $userId, int $limit = 5, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        $sql = "SELECT t.id, t.pnl, t.exit_type, t.closed_at, t.risk_reward,
                       p.symbol, p.direction
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                $where
                ORDER BY t.closed_at DESC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getOpenTrades(int $userId, int $limit = 5, array $filters = []): array
    {
        $where = 'WHERE p.user_id = :user_id AND t.status = :status';
        $params = ['user_id' => $userId, 'status' => TradeStatus::OPEN->value];

        if (!empty($filters['account_id'])) {
            $where .= ' AND p.account_id = :account_id';
            $params['account_id'] = (int) $filters['account_id'];
        }

        $sql = "SELECT t.id, t.opened_at, t.remaining_size,
                       p.symbol, p.direction, p.entry_price, p.size,
                       a.name AS account_name
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                INNER JOIN accounts a ON a.id = p.account_id
                $where
                ORDER BY t.opened_at DESC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDailyPnl(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        $sql = "SELECT DATE(t.closed_at) AS date,
                       COUNT(*) AS trade_count,
                       COALESCE(SUM(t.pnl), 0) AS total_pnl
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                GROUP BY DATE(t.closed_at)
                ORDER BY date ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
