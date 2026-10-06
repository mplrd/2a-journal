<?php

namespace Tests\Integration\Repositories;

use App\Core\Database;
use App\Repositories\PositionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * findForAccountExport: the account's whole position history — orders and
 * trades, whatever their status — with what each position carries.
 */
class PositionRepositoryExportTest extends TestCase
{
    private PositionRepository $repo;
    private PDO $pdo;
    private int $userId;
    private int $otherUserId;
    private int $accountId;

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/../../../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (($eq = strpos($line, '=')) === false) continue;
                $key = trim(substr($line, 0, $eq));
                $value = trim(substr($line, $eq + 1));
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
                    $value = substr($value, 1, -1);
                }
                if (!getenv($key)) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                }
            }
        }

        Database::reset();
        $this->pdo = Database::getConnection();
        $this->repo = new PositionRepository($this->pdo);
        $this->cleanTables();

        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('export@test.com', 'hashed')");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO users (email, password) VALUES ('other@test.com', 'hashed')");
        $this->otherUserId = (int) $this->pdo->lastInsertId();
        $this->accountId = $this->insertAccount($this->userId);
    }

    protected function tearDown(): void
    {
        $this->cleanTables();
    }

    private function cleanTables(): void
    {
        $this->pdo->exec("DELETE FROM status_history WHERE entity_type = 'TRADE'");
        $this->pdo->exec('DELETE FROM partial_exits');
        $this->pdo->exec('DELETE FROM trades');
        $this->pdo->exec('DELETE FROM orders');
        $this->pdo->exec('DELETE FROM positions');
        $this->pdo->exec('DELETE FROM trading_plans');
        $this->pdo->exec('DELETE FROM accounts');
        $this->pdo->exec('DELETE FROM users');
    }

    private function insertAccount(int $userId): int
    {
        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, current_capital)
             VALUES (:uid, 'Export', 'PROP_FIRM', 10000, 10000)"
        )->execute(['uid' => $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertPosition(int $accountId, string $type, array $overrides = []): int
    {
        $this->pdo->prepare(
            "INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, point_value, setup,
                                    sl_points, sl_price, be_points, be_price, be_size, targets, notes, plan_id,
                                    plan_adherence, plan_adherence_reason, position_type)
             VALUES (:uid, :aid, 'BUY', 'GER40', 25000, 0.1, 1, '[\"Break 5\"]', 100, 24900, 40, 25040, 0.05,
                     :targets, :notes, :plan_id, :adherence, :reason, :type)"
        )->execute([
            'uid' => $overrides['user_id'] ?? $this->userId,
            'aid' => $accountId,
            'targets' => $overrides['targets'] ?? null,
            'notes' => $overrides['notes'] ?? null,
            'plan_id' => $overrides['plan_id'] ?? null,
            'adherence' => $overrides['plan_adherence'] ?? null,
            'reason' => $overrides['plan_adherence_reason'] ?? null,
            'type' => $type,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertOrder(int $positionId, string $status, string $createdAt): int
    {
        $this->pdo->prepare(
            "INSERT INTO orders (position_id, created_at, expires_at, status)
             VALUES (:pid, :created, '2026-06-30 22:00:00', :status)"
        )->execute(['pid' => $positionId, 'created' => $createdAt, 'status' => $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertTrade(int $positionId, string $status, string $openedAt, ?int $sourceOrderId = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO trades (position_id, source_order_id, opened_at, closed_at, remaining_size, status,
                                 exit_type, pnl, pnl_percent, risk_reward, avg_exit_price, be_reached)
             VALUES (:pid, :oid, :opened, :closed, :remaining, :status, :exit, :pnl, 0.5, 1.2, 25050, :be)"
        )->execute([
            'pid' => $positionId,
            'oid' => $sourceOrderId,
            'opened' => $openedAt,
            'closed' => $status === 'CLOSED' ? $openedAt : null,
            'remaining' => $status === 'CLOSED' ? 0 : 0.1,
            'status' => $status,
            'exit' => $status === 'CLOSED' ? 'TP' : null,
            'pnl' => $status === 'CLOSED' ? 12.5 : null,
            'be' => $status === 'OPEN' ? 0 : 1,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function export(?int $accountId = null): array
    {
        return $this->repo->findForAccountExport($this->userId, $accountId ?? $this->accountId);
    }

    public function testFindForAccountExport_listsOrdersAndTradesOfEveryStatus(): void
    {
        $pending = $this->insertPosition($this->accountId, 'ORDER');
        $this->insertOrder($pending, 'PENDING', '2026-06-01 08:00:00');
        $cancelled = $this->insertPosition($this->accountId, 'ORDER');
        $this->insertOrder($cancelled, 'CANCELLED', '2026-06-01 09:00:00');
        foreach (['OPEN' => '2026-06-02 10:00:00', 'SECURED' => '2026-06-03 10:00:00', 'CLOSED' => '2026-06-04 10:00:00'] as $status => $at) {
            $this->insertTrade($this->insertPosition($this->accountId, 'TRADE'), $status, $at);
        }

        $rows = $this->export();

        $this->assertSame(
            [['ORDER', 'PENDING', null], ['ORDER', 'CANCELLED', null], ['TRADE', null, 'OPEN'], ['TRADE', null, 'SECURED'], ['TRADE', null, 'CLOSED']],
            array_map(fn ($r) => [$r['position_type'], $r['order_status'], $r['trade_status']], $rows)
        );
    }

    public function testFindForAccountExport_ordersByOpeningThenPlacement(): void
    {
        $this->insertTrade($this->insertPosition($this->accountId, 'TRADE'), 'CLOSED', '2026-06-05 10:00:00');
        $order = $this->insertPosition($this->accountId, 'ORDER');
        $this->insertOrder($order, 'EXPIRED', '2026-06-03 10:00:00');
        $this->insertTrade($this->insertPosition($this->accountId, 'TRADE'), 'CLOSED', '2026-06-01 10:00:00');

        $this->assertSame(
            ['2026-06-01 10:00:00', null, '2026-06-05 10:00:00'],
            array_column($this->export(), 'opened_at')
        );
    }

    public function testFindForAccountExport_aTradeFromAnOrderKeepsWhenTheOrderWasPlaced(): void
    {
        $positionId = $this->insertPosition($this->accountId, 'TRADE');
        $orderId = $this->insertOrder($positionId, 'EXECUTED', '2026-06-01 08:00:00');
        $this->insertTrade($positionId, 'CLOSED', '2026-06-01 10:00:00', $orderId);

        $row = $this->export()[0];

        $this->assertSame('2026-06-01 08:00:00', $row['order_created_at']);
        $this->assertSame('2026-06-30 22:00:00', $row['order_expires_at']);
        $this->assertSame('EXECUTED', $row['order_status']);
        $this->assertSame('CLOSED', $row['trade_status']);
    }

    public function testFindForAccountExport_givesWhenTheTradeWasSecured(): void
    {
        $tradeId = $this->insertTrade($this->insertPosition($this->accountId, 'TRADE'), 'CLOSED', '2026-06-01 10:00:00');
        $this->pdo->prepare(
            "INSERT INTO status_history (entity_type, entity_id, previous_status, new_status, changed_at)
             VALUES ('TRADE', :id, 'OPEN', 'SECURED', '2026-06-01 10:45:00'),
                    ('TRADE', :id2, 'SECURED', 'CLOSED', '2026-06-01 11:00:00')"
        )->execute(['id' => $tradeId, 'id2' => $tradeId]);

        $this->assertSame('2026-06-01 10:45:00', $this->export()[0]['secured_at']);
    }

    public function testFindForAccountExport_exposesEverythingThePositionCarries(): void
    {
        $this->pdo->prepare("INSERT INTO trading_plans (user_id, name) VALUES (:uid, 'Plan DAX')")->execute(['uid' => $this->userId]);
        $planId = (int) $this->pdo->lastInsertId();
        $positionId = $this->insertPosition($this->accountId, 'TRADE', [
            'targets' => '[{"id":"tp1","label":"TP1","price":25100,"points":100,"size":0.05}]',
            'notes' => 'Patience',
            'plan_id' => $planId,
            'plan_adherence' => 'OUT_OF_PLAN',
            'plan_adherence_reason' => 'direction BUY not allowed',
        ]);
        $tradeId = $this->insertTrade($positionId, 'CLOSED', '2026-06-01 10:00:00');

        $row = $this->export()[0];

        foreach ([
            'position_id' => $positionId, 'trade_id' => $tradeId, 'symbol' => 'GER40', 'direction' => 'BUY',
            'exit_type' => 'TP', 'notes' => 'Patience', 'plan_name' => 'Plan DAX',
            'plan_adherence' => 'OUT_OF_PLAN', 'plan_adherence_reason' => 'direction BUY not allowed',
            'setup' => '["Break 5"]',
        ] as $field => $expected) {
            $this->assertEquals($expected, $row[$field], $field);
        }
        foreach ([
            'size' => 0.1, 'remaining_size' => 0, 'point_value' => 1, 'entry_price' => 25000, 'sl_price' => 24900,
            'sl_points' => 100, 'be_price' => 25040, 'be_points' => 40, 'be_size' => 0.05, 'be_reached' => 1,
            'avg_exit_price' => 25050, 'risk_reward' => 1.2, 'pnl' => 12.5, 'pnl_percent' => 0.5,
        ] as $field => $expected) {
            $this->assertEquals($expected, (float) $row[$field], $field);
        }
        $this->assertSame(25100, json_decode($row['targets'], true)[0]['price']);
        $this->assertSame('2026-06-01 10:00:00', $row['closed_at']);
    }

    public function testFindForAccountExport_leavesOutOtherAccounts(): void
    {
        $this->insertTrade($this->insertPosition($this->insertAccount($this->userId), 'TRADE'), 'CLOSED', '2026-06-01 10:00:00');

        $this->assertSame([], $this->export());
    }

    public function testFindForAccountExport_onAnotherUsersAccount_returnsNothing(): void
    {
        $foreign = $this->insertAccount($this->otherUserId);
        $this->insertTrade($this->insertPosition($foreign, 'TRADE', ['user_id' => $this->otherUserId]), 'CLOSED', '2026-06-01 10:00:00');

        $this->assertSame([], $this->export($foreign));
    }

    public function testFindForAccountExport_onASoftDeletedAccount_returnsNothing(): void
    {
        $this->insertTrade($this->insertPosition($this->accountId, 'TRADE'), 'CLOSED', '2026-06-01 10:00:00');
        $this->pdo->exec("UPDATE accounts SET deleted_at = NOW() WHERE id = {$this->accountId}");

        $this->assertSame([], $this->export());
    }
}
