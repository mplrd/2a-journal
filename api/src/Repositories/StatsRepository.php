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
     * SQL expression for the trade's "effective realized date":
     * - For CLOSED trades, that's `closed_at`.
     * - For SECURED trades (partials taken but not fully closed), that's the
     *   timestamp of the latest partial exit. Used to filter SECURED trades by
     *   date range without requiring a denormalized column.
     */
    private function effectiveDate(): string
    {
        return "COALESCE(t.closed_at, (SELECT MAX(pe.exited_at) FROM partial_exits pe WHERE pe.trade_id = t.id))";
    }

    /**
     * Build WHERE clause and params from filters.
     * BE threshold classification is injected via `injectBeThreshold()` into the SQL itself,
     * not via bound param (emulated prepares are OFF and named placeholders cannot be repeated).
     *
     * Inclusion criterion is `t.pnl IS NOT NULL` — i.e. "trade has realized P&L".
     * That's status-agnostic on purpose: a trade can have taken a partial exit
     * while still being marked OPEN (the SL hasn't been moved to BE, so the user
     * doesn't consider the trade secured). Filtering on status would miss those.
     * Since TradeService::close() now sets trades.pnl on every exit, NULL pnl
     * exactly means "no exit ever taken".
     * @param string|null $dateColumn SQL expression used for date_from/date_to filtering.
     *                                Defaults to {@see effectiveDate()} (exit-based). Pass
     *                                't.opened_at' for entry-based filtering (heatmap).
     * @return array{0: string, 1: array}
     */
    private function buildWhereClause(int $userId, array $filters = [], ?string $dateColumn = null): array
    {
        // Soft-deleted accounts must be excluded from every aggregation. The EXISTS
        // is rewritten as a semi-join by MariaDB since accounts.id is the PK, so
        // perf is equivalent to an explicit INNER JOIN.
        $where = "WHERE p.user_id = :user_id AND t.pnl IS NOT NULL"
               . " AND EXISTS (SELECT 1 FROM accounts a WHERE a.id = p.account_id AND a.deleted_at IS NULL)";
        $params = ['user_id' => $userId];

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
            $params['account_id'] = (int) $filters['account_id'];
        }

        $effective = $dateColumn ?? $this->effectiveDate();
        if (!empty($filters['date_from'])) {
            $where .= " AND {$effective} >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where .= " AND {$effective} <= :date_to";
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

    /**
     * Inject the BE threshold (% of entry price) as a safe numeric literal into the SQL.
     * The value is validated upstream (AuthService: 0..5 float) and read from the user's profile,
     * never directly from user input — safe to format into SQL.
     * Prefer this over bound params because named placeholders cannot be repeated when
     * ATTR_EMULATE_PREPARES is off, and the classification SQL uses the threshold in multiple CASE WHEN.
     */
    private function injectBeThreshold(string $sql, array $filters): string
    {
        $threshold = isset($filters['be_threshold_percent']) ? (float) $filters['be_threshold_percent'] : 0.0;
        // Clamp to the documented range to defend against any unvalidated path
        if ($threshold < 0) {
            $threshold = 0.0;
        } elseif ($threshold > 100) {
            $threshold = 100.0;
        }
        $literal = number_format($threshold, 4, '.', '');
        return str_replace(':be_threshold', $literal, $sql);
    }

    /**
     * Win / loss / breakeven classification, stated once for every aggregate
     * that reports the three figures.
     *
     * The breakeven band is a percentage of the entry value, so it can only be
     * applied to a trade that carries one. A trade whose entry value could not
     * be worked out has `pnl_percent` NULL — an import with no price is the way
     * in ({@see \App\Services\Import\ImportService::createImportedTrade}) — and
     * comparing NULL to the threshold answers NULL, which is neither true nor
     * false. Such a trade used to fall into none of the three buckets while
     * still counting in `COUNT(*)`, the win rate's denominator: the pie showed
     * fewer trades than the total, and the rate was quietly dragged down.
     *
     * The sign of the P&L is what remains once the percentage is gone, and it
     * is enough to tell a winner from a loser. `t.pnl` is never NULL here —
     * {@see buildWhereClause()} makes that the inclusion criterion — so the
     * three expressions are mutually exclusive and always add up to the count.
     */
    private function isWin(): string
    {
        return '(CASE WHEN t.pnl_percent IS NULL THEN t.pnl > 0 ELSE t.pnl_percent > :be_threshold END)';
    }

    private function isLoss(): string
    {
        return '(CASE WHEN t.pnl_percent IS NULL THEN t.pnl < 0 ELSE t.pnl_percent < -:be_threshold END)';
    }

    private function isBreakeven(): string
    {
        return '(CASE WHEN t.pnl_percent IS NULL THEN t.pnl = 0'
             . ' ELSE t.pnl_percent BETWEEN -:be_threshold AND :be_threshold END)';
    }

    public function getOverview(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        [$win, $loss, $be] = [$this->isWin(), $this->isLoss(), $this->isBreakeven()];

        $sql = "SELECT
                    COUNT(*) AS total_trades,
                    COALESCE(SUM(t.pnl), 0) AS total_pnl,
                    SUM(CASE WHEN {$win} THEN 1 ELSE 0 END) AS winning_trades,
                    SUM(CASE WHEN {$loss} THEN 1 ELSE 0 END) AS losing_trades,
                    SUM(CASE WHEN {$be} THEN 1 ELSE 0 END) AS be_trades,
                    CASE WHEN COUNT(*) > 0
                        THEN ROUND(SUM(CASE WHEN {$win} THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2)
                        ELSE 0
                    END AS win_rate,
                    CASE WHEN SUM(CASE WHEN {$loss} THEN ABS(t.pnl) ELSE 0 END) > 0
                        THEN ROUND(
                            SUM(CASE WHEN {$win} THEN t.pnl ELSE 0 END)
                            / SUM(CASE WHEN {$loss} THEN ABS(t.pnl) ELSE 0 END),
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

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
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

        // Use partial_exits for granular chronological P&L (not trade-level which groups partial exits)
        $sql = "SELECT pe.exited_at AS closed_at, pe.pnl, p.symbol
                FROM partial_exits pe
                INNER JOIN trades t ON t.id = pe.trade_id
                INNER JOIN positions p ON p.id = t.position_id
                $where
                ORDER BY pe.exited_at ASC";

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
                    SUM(CASE WHEN {$this->isWin()} THEN 1 ELSE 0 END) AS win,
                    SUM(CASE WHEN {$this->isLoss()} THEN 1 ELSE 0 END) AS loss,
                    SUM(CASE WHEN {$this->isBreakeven()} THEN 1 ELSE 0 END) AS be
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                $where";

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
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
        [$win, $loss] = [$this->isWin(), $this->isLoss()];

        return "COUNT(*) AS total_trades,
                SUM(CASE WHEN {$win} THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN {$loss} THEN 1 ELSE 0 END) AS losses,
                CASE WHEN COUNT(*) > 0
                    THEN ROUND(SUM(CASE WHEN {$win} THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2)
                    ELSE 0
                END AS win_rate,
                COALESCE(SUM(t.pnl), 0) AS total_pnl,
                ROUND(AVG(t.risk_reward), 2) AS avg_rr,
                CASE WHEN SUM(CASE WHEN {$loss} THEN ABS(t.pnl) ELSE 0 END) > 0
                    THEN ROUND(
                        SUM(CASE WHEN {$win} THEN t.pnl ELSE 0 END)
                        / SUM(CASE WHEN {$loss} THEN ABS(t.pnl) ELSE 0 END),
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

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
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

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getStatsBySetup(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $select = $this->dimensionStatsSelect();

        // JSON_TABLE flattens the setup array; LEFT JOIN with setups exposes
        // the category alongside the label (NULL when the label no longer
        // matches an active setup row, e.g. orphaned legacy strings).
        // MAX() is structurally a no-op since (user_id, label) is unique on
        // the active subset — it just satisfies GROUP BY.
        // The JSON_TABLE-extracted column defaults to utf8mb4_general_ci while
        // setups.label is utf8mb4_unicode_ci — explicit COLLATE on the column
        // definition aligns both sides for the LEFT JOIN equality.
        $sql = "SELECT s.setup, MAX(setups.category) AS category, {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                CROSS JOIN JSON_TABLE(
                    p.setup,
                    '$[*]' COLUMNS (setup VARCHAR(255) COLLATE utf8mb4_unicode_ci PATH '$')
                ) AS s
                LEFT JOIN setups ON setups.user_id = p.user_id
                                AND setups.label = s.setup
                                AND setups.deleted_at IS NULL
                {$where}
                GROUP BY s.setup
                ORDER BY total_pnl DESC";

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Aggregated stats for trades that contain ALL of the given setup names.
     * Empty $setupNames means no JSON_CONTAINS filter — useful as the baseline
     * counterpart against which a combination's stats are compared. Always
     * returns a single normalized row (zeroed when no trades match).
     */
    public function getStatsForSetupCombination(int $userId, array $setupNames, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);

        // AND-chained JSON_CONTAINS — the trade must carry every requested
        // setup. Naming the placeholders distinctly from `setup_*` (used by
        // the existing OR-style `setups` filter) avoids accidental clashes.
        $i = 0;
        foreach (array_values($setupNames) as $name) {
            $key = "combo_setup_{$i}";
            $where .= " AND JSON_CONTAINS(p.setup, :{$key})";
            $params[$key] = json_encode($name);
            $i++;
        }

        $select = $this->dimensionStatsSelect();
        $sql = "SELECT {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}";

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'total_trades' => (int) ($row['total_trades'] ?? 0),
            'wins' => (int) ($row['wins'] ?? 0),
            'losses' => (int) ($row['losses'] ?? 0),
            'win_rate' => (float) ($row['win_rate'] ?? 0),
            'total_pnl' => (float) ($row['total_pnl'] ?? 0),
            'avg_rr' => isset($row['avg_rr']) && $row['avg_rr'] !== null ? (float) $row['avg_rr'] : null,
            'profit_factor' => isset($row['profit_factor']) && $row['profit_factor'] !== null ? (float) $row['profit_factor'] : null,
        ];
    }

    public function getStatsByPeriod(int $userId, string $group = 'month', array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $select = $this->dimensionStatsSelect();

        $eff = $this->effectiveDate();
        // Linear modes return a unique sortable string per period (one bucket
        // per calendar day/week/month/year). Cyclic modes collapse the year
        // dimension to expose seasonality: the same Monday across years lands
        // in the same bucket. WEEKDAY returns 0=Monday..6=Sunday so numeric
        // sort matches the natural reading order.
        $periodExpr = match ($group) {
            'day' => "DATE_FORMAT({$eff}, '%Y-%m-%d')",
            'week' => "DATE_FORMAT({$eff}, '%x-W%v')",
            'year' => "DATE_FORMAT({$eff}, '%Y')",
            'day_of_week' => "WEEKDAY({$eff})",
            'iso_week' => "WEEK({$eff}, 3)",
            'month_of_year' => "MONTH({$eff})",
            default => "DATE_FORMAT({$eff}, '%Y-%m')",
        };

        $sql = "SELECT {$periodExpr} AS period, {$select}
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                GROUP BY period
                ORDER BY period ASC";

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
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
                {$where} AND t.risk_reward IS NOT NULL
                GROUP BY bucket
                ORDER BY MIN(t.risk_reward) ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function localizedTimestamp(string $column, string $tzOffset): string
    {
        if ($tzOffset === '+00:00') {
            return $column;
        }
        $tzSafe = $this->pdo->quote($tzOffset);
        return "CONVERT_TZ({$column}, '+00:00', {$tzSafe})";
    }

    /**
     * Heatmap groups by ENTRY timestamp, not exit. The question "did this hour
     * of the week work for me?" is about the moment the position was taken,
     * not the moment it was unwound (which can be hours or days later and
     * tells us about market regime at exit, not entry).
     */
    public function getHeatmap(int $userId, array $filters = [], string $tzOffset = '+00:00'): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters, 't.opened_at');
        $local = $this->localizedTimestamp('t.opened_at', $tzOffset);

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
     * Fetch trades (CLOSED + SECURED) with fields needed for session classification.
     * The "closed_at" column in the result holds the effective realized date —
     * for SECURED trades that's the latest partial exit timestamp.
     */
    public function getTradesForSessionStats(int $userId, array $filters = []): array
    {
        [$where, $params] = $this->buildWhereClause($userId, $filters);
        $eff = $this->effectiveDate();

        $sql = "SELECT {$eff} AS closed_at, t.pnl, t.risk_reward
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                {$where}
                ORDER BY closed_at";

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

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
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

        $stmt = $this->pdo->prepare($this->injectBeThreshold($sql, $filters));
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

    /**
     * Ongoing trades for the dashboard "En cours" panel: OPEN + SECURED.
     * Despite the "open" name, the semantics are "not yet closed".
     * The method keeps its historical name to avoid ripple renames through
     * the service/controller/frontend store layers.
     */
    public function getOpenTrades(int $userId, int $limit = 5, array $filters = []): array
    {
        $where = 'WHERE p.user_id = :user_id AND t.status IN (:status_open, :status_secured)'
               . ' AND a.deleted_at IS NULL';
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

    /**
     * Realized P&L per calendar day, each amount on the day it was banked.
     *
     * A trade is NOT one amount on one date. Its legs come off on the days they
     * come off, and the calendar has to show them there. Attributing the whole
     * of `trades.pnl` to a single date — the close, or failing that the last
     * partial exit — made the money MOVE: bank 400 at TP1 on Monday, take TP2
     * on Wednesday, and Monday silently loses its 400 to Wednesday. Already
     * wrong on real history, on every trade whose plan spans more than a day.
     *
     * Two contributions, summed per day:
     *
     *   1. every partial exit, on DATE(pe.exited_at);
     *   2. what the CLOSE realized beyond those legs, on DATE(t.closed_at) —
     *      `t.pnl` minus the legs already counted.
     *
     * The residual covers the two cases the legs cannot: a trade closed without
     * ever recording a partial (its whole P&L lands on the close), and a total
     * the broker states above the sum of its legs, swap and commission
     * included. It is zero for a trade fully described by its legs, which is
     * the overwhelming majority, and such rows are dropped rather than counted
     * as a day's activity.
     *
     * Two queries rather than one UNION: the filter has to apply to a DIFFERENT
     * date column on each side — a leg is filtered on its own exit date, not on
     * its trade's — and a named placeholder cannot appear twice with emulated
     * prepares off. Merging in PHP also keeps GROUP BY away from the correlated
     * subquery that ONLY_FULL_GROUP_BY rejects in production.
     *
     * @return list<array{date: string, trade_count: int, total_pnl: float}>
     */
    public function getDailyPnl(int $userId, array $filters = []): array
    {
        // A leg belongs to the day it was taken, so that is what a date range
        // filters on here.
        [$legWhere, $legParams] = $this->buildWhereClause($userId, $filters, 'pe.exited_at');
        $legs = $this->pdo->prepare(
            "SELECT DATE(pe.exited_at) AS `date`, t.id AS trade_id, pe.pnl AS pnl
             FROM partial_exits pe
             INNER JOIN trades t ON t.id = pe.trade_id
             INNER JOIN positions p ON p.id = t.position_id
             {$legWhere}"
        );
        $legs->execute($legParams);

        [$closeWhere, $closeParams] = $this->buildWhereClause($userId, $filters, 't.closed_at');
        $residuals = $this->pdo->prepare(
            "SELECT DATE(t.closed_at) AS `date`, t.id AS trade_id,
                    t.pnl - COALESCE(
                        (SELECT SUM(pe.pnl) FROM partial_exits pe WHERE pe.trade_id = t.id), 0
                    ) AS pnl
             FROM trades t
             INNER JOIN positions p ON p.id = t.position_id
             {$closeWhere} AND t.closed_at IS NOT NULL"
        );
        $residuals->execute($closeParams);

        $byDate = [];

        // A leg always counts, even at exactly zero: an exit taken at
        // breakeven happened, and the day it happened is a day of activity.
        foreach ($legs->fetchAll() as $row) {
            $this->addToDay($byDate, $row);
        }

        // A residual at zero is not an event, it is an artefact: the trade was
        // fully described by its legs and they have already been counted.
        // Recording it would invent a day of activity on the close date.
        foreach ($residuals->fetchAll() as $row) {
            if (round((float) $row['pnl'], 2) === 0.0) {
                continue;
            }
            $this->addToDay($byDate, $row);
        }

        $result = [];
        foreach ($byDate as $date => $bucket) {
            $result[] = [
                'date' => $date,
                // Trades that realized something that day, counted once even
                // when several of their legs came off on it.
                'trade_count' => count($bucket['trades']),
                'total_pnl' => round($bucket['pnl'], 2),
            ];
        }

        usort($result, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $result;
    }

    /**
     * @param array<string, array{pnl: float, trades: array<int, true>}> $byDate
     * @param array{date: string, trade_id: int|string, pnl: string|float} $row
     */
    private function addToDay(array &$byDate, array $row): void
    {
        $date = (string) $row['date'];
        $byDate[$date] ??= ['pnl' => 0.0, 'trades' => []];
        $byDate[$date]['pnl'] += (float) $row['pnl'];
        $byDate[$date]['trades'][(int) $row['trade_id']] = true;
    }
}
