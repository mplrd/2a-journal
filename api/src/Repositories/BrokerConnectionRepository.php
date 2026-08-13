<?php

namespace App\Repositories;

use App\Enums\ConnectionStatus;
use PDO;

class BrokerConnectionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO broker_connections (user_id, account_id, provider, status, credentials_encrypted, credentials_iv)
             VALUES (:user_id, :account_id, :provider, :status, :credentials_encrypted, :credentials_iv)"
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'provider' => $data['provider'],
            'status' => $data['status'],
            'credentials_encrypted' => $data['credentials_encrypted'],
            'credentials_iv' => $data['credentials_iv'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM broker_connections WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByAccountId(int $accountId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM broker_connections WHERE account_id = :account_id");
        $stmt->execute(['account_id' => $accountId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM broker_connections WHERE user_id = :user_id ORDER BY created_at DESC");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['status', 'credentials_encrypted', 'credentials_iv', 'last_sync_at', 'last_sync_status', 'last_sync_error', 'sync_cursor', 'symbols_seen'];
        $sets = [];
        $params = ['id' => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed)) {
                $sets[] = "$key = :$key";
                $params[$key] = $value;
            }
        }

        if (empty($sets)) {
            return;
        }

        $sql = "UPDATE broker_connections SET " . implode(', ', $sets) . " WHERE id = :id";
        $this->pdo->prepare($sql)->execute($params);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM broker_connections WHERE id = :id")->execute(['id' => $id]);
    }

    /**
     * Return ACTIVE connections that are due for an auto-sync pass.
     *
     * A connection is due when it was never synced, when its last sync is older
     * than the given interval, or when a user asked for one from the UI. The
     * interval is clamped to [1, 1440] minutes and injected as a safe integer
     * literal because MariaDB does not support bound parameters inside INTERVAL
     * expressions.
     *
     * Requested connections come first: someone is watching a spinner for those,
     * while the rest are background refreshes nobody is waiting on.
     */
    public function findDueForAutoSync(int $intervalMinutes): array
    {
        $intervalMinutes = $this->clampInterval($intervalMinutes);

        // UTC_TIMESTAMP() sidesteps the MySQL session-timezone setting so the
        // comparison stays consistent with PHP's UTC-written last_sync_at.
        $sql = "SELECT * FROM broker_connections
                WHERE status = :status
                  AND (sync_requested_at IS NOT NULL
                       OR last_sync_at IS NULL
                       OR last_sync_at < UTC_TIMESTAMP() - INTERVAL {$intervalMinutes} MINUTE)
                ORDER BY sync_requested_at IS NOT NULL DESC,
                         sync_requested_at ASC,
                         last_sync_at IS NULL DESC,
                         last_sync_at ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['status' => ConnectionStatus::ACTIVE->value]);
        return $stmt->fetchAll();
    }

    /**
     * How many connections findDueForAutoSync() would return. Lets the
     * supervisor size its worker pool without fetching every row.
     */
    public function countDueForAutoSync(int $intervalMinutes): int
    {
        $intervalMinutes = $this->clampInterval($intervalMinutes);

        $sql = "SELECT COUNT(*) FROM broker_connections
                WHERE status = :status
                  AND (sync_requested_at IS NOT NULL
                       OR last_sync_at IS NULL
                       OR last_sync_at < UTC_TIMESTAMP() - INTERVAL {$intervalMinutes} MINUTE)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['status' => ConnectionStatus::ACTIVE->value]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Flag a connection as wanting a sync now, whatever its interval says. The
     * next scheduler tick — under a minute away — picks it up.
     *
     * The flag is consumed by claimForSync(), in the same UPDATE that takes the
     * reservation: a click landing while a run is already going stays pending
     * for the tick after it, instead of being silently swallowed.
     */
    public function requestSync(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE broker_connections SET sync_requested_at = UTC_TIMESTAMP() WHERE id = :id"
        )->execute(['id' => $id]);
    }

    /**
     * Interpolated into INTERVAL expressions, which MariaDB will not accept as
     * a bound parameter — hence int-typed and clamped rather than bound.
     */
    private function clampInterval(int $intervalMinutes): int
    {
        if ($intervalMinutes < 1) {
            return 1;
        }

        return $intervalMinutes > 1440 ? 1440 : $intervalMinutes;
    }

    /**
     * Requests this connection has already spent at the broker today, 0 when
     * the stored count belongs to an earlier day.
     *
     * The counter carries the day it was written for, so the daily reset is
     * implicit — no purge job, no sliding window. UTC_DATE() on both sides,
     * like syncing_since, so the boundary never depends on the session
     * timezone.
     */
    public function requestsSpentToday(int $id): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT IF(requests_counted_on = UTC_DATE(), requests_today, 0)
             FROM broker_connections
             WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
        $spent = $stmt->fetchColumn();

        return $spent === false || $spent === null ? 0 : (int) $spent;
    }

    /**
     * Add to today's request count, starting a fresh day when the stored count
     * belongs to an earlier one.
     *
     * One conditional UPDATE rather than read-then-write: several workers can
     * finish syncs of the same user seconds apart, and a read-then-write would
     * lose one of the increments — undercounting the very thing the budget
     * exists to bound.
     *
     * Counting is per CONNECTION, not per provider: a prop firm's cap applies
     * to a trading account whatever protocol reads it. MetaTrader covers the
     * same brokers as cTrader and feeds the same counter as soon as it exposes
     * getRequestCounts().
     */
    public function addRequestsSpent(int $id, int $count): void
    {
        // A connector with no request counter reports 0. Stamping a counting
        // day for it would be a lie, and it would cost a write on every sync.
        if ($count <= 0) {
            return;
        }

        // Two placeholders for one value: emulated prepares are off, and PDO
        // refuses a named parameter used twice in the same statement.
        $this->pdo->prepare(
            "UPDATE broker_connections
             SET requests_today = IF(requests_counted_on = UTC_DATE(), requests_today + :added, :fresh),
                 requests_counted_on = UTC_DATE()
             WHERE id = :id"
        )->execute(['id' => $id, 'added' => $count, 'fresh' => $count]);
    }

    /**
     * Reserve a connection for a sync run. Returns false when another run
     * already holds it.
     *
     * The WHERE clause IS the lock: a single conditional UPDATE, so two callers
     * racing on the same connection can never both come back with true. No
     * SELECT-then-UPDATE, which would leave a window between the read and the
     * write. rowCount() is the number of rows actually changed — PDO is not
     * configured with MYSQL_ATTR_FOUND_ROWS.
     *
     * A claim older than $staleAfterSeconds is taken over: a worker killed
     * mid-run must not lock its connection forever. Keep the window generous
     * enough that a slow-but-alive sync is never doubled.
     */
    public function claimForSync(int $id, int $staleAfterSeconds): bool
    {
        // Clamped to [1, 86400] and injected as an integer literal: MariaDB does
        // not accept a bound parameter inside an INTERVAL expression (same
        // constraint as findDueForAutoSync).
        if ($staleAfterSeconds < 1) {
            $staleAfterSeconds = 1;
        } elseif ($staleAfterSeconds > 86400) {
            $staleAfterSeconds = 86400;
        }

        // UTC_TIMESTAMP() on both sides, and syncing_since is a DATETIME, so the
        // comparison never depends on the MySQL session timezone.
        //
        // Clearing sync_requested_at here, rather than at the end of the run,
        // is what makes a manual request exactly-once: the same statement that
        // wins the reservation consumes the request, so there is no window in
        // which a second worker could honour it again.
        $stmt = $this->pdo->prepare(
            "UPDATE broker_connections
             SET syncing_since = UTC_TIMESTAMP(),
                 sync_requested_at = NULL
             WHERE id = :id
               AND (syncing_since IS NULL
                    OR syncing_since < UTC_TIMESTAMP() - INTERVAL {$staleAfterSeconds} SECOND)"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Release a reservation taken by claimForSync(). */
    public function releaseSync(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE broker_connections SET syncing_since = NULL WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public function incrementFailures(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE broker_connections SET consecutive_failures = consecutive_failures + 1 WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public function resetFailures(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE broker_connections SET consecutive_failures = 0 WHERE id = :id"
        )->execute(['id' => $id]);
    }

    /**
     * How many connections this user holds on a provider — every status
     * included.
     *
     * Backs two things: the "these credentials feed N connections" banner, and
     * the decision to forget the shared app credentials once the count reaches
     * zero. A REVOKED connection counts because it still reads that row.
     */
    public function countByUserAndProvider(int $userId, string $provider): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM broker_connections WHERE user_id = :user_id AND provider = :provider"
        );
        $stmt->execute(['user_id' => $userId, 'provider' => $provider]);

        return (int) $stmt->fetchColumn();
    }

    public function countActive(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM broker_connections WHERE status = :status");
        $stmt->execute(['status' => ConnectionStatus::ACTIVE->value]);
        return (int) $stmt->fetchColumn();
    }

    public function markError(int $id, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE broker_connections
             SET status = :status, last_sync_error = :err
             WHERE id = :id"
        )->execute([
            'status' => ConnectionStatus::ERROR->value,
            'err' => $error,
            'id' => $id,
        ]);
    }
}
