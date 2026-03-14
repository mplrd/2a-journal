<?php

/**
 * Demo seeder — creates a demo user with realistic trading data.
 *
 * Usage: php api/database/seed-demo.php
 *
 * Credentials: demo@2a.journal / Demo*123
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

// Load .env
$envFile = __DIR__ . '/../.env';
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
$pdo = Database::getConnection();

$email = 'demo@2a.journal';
$password = 'Demo*123';

// Check if demo user already exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND deleted_at IS NULL');
$stmt->execute(['email' => $email]);
$existingId = $stmt->fetchColumn();

if ($existingId) {
    echo "Demo user already exists (id={$existingId}). Cleaning up...\n";
    // Delete cascades take care of accounts, positions, trades, etc.
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $existingId]);
}

// ── 1. Create user ──────────────────────────────────────────
$hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare("INSERT INTO users (email, password, first_name, last_name, timezone, default_currency, locale, theme, onboarding_completed_at, email_verified_at)
    VALUES (:email, :password, 'Demo', 'Trader', 'Europe/Paris', 'EUR', 'fr', 'light', NOW(), NOW())")
    ->execute(['email' => $email, 'password' => $hashedPassword]);
$userId = (int) $pdo->lastInsertId();
echo "Created user: {$email} (id={$userId})\n";

// ── 2. Create account ───────────────────────────────────────
$pdo->prepare("INSERT INTO accounts (user_id, name, account_type, broker, currency, initial_capital, current_capital)
    VALUES (:uid, 'FTMO Challenge', 'PROP_FIRM', 'FTMO', 'EUR', 100000.00, 100000.00)")
    ->execute(['uid' => $userId]);
$accountId = (int) $pdo->lastInsertId();

$pdo->prepare("UPDATE accounts SET stage = 'CHALLENGE', max_drawdown = 10000.00, daily_drawdown = 5000.00, profit_target = 10000.00 WHERE id = :id")
    ->execute(['id' => $accountId]);

$pdo->prepare("INSERT INTO accounts (user_id, name, account_type, broker, currency, initial_capital, current_capital)
    VALUES (:uid, 'Compte perso', 'BROKER_LIVE', 'Interactive Brokers', 'EUR', 25000.00, 25000.00)")
    ->execute(['uid' => $userId]);
$accountId2 = (int) $pdo->lastInsertId();

echo "Created accounts: FTMO Challenge (id={$accountId}), Compte perso (id={$accountId2})\n";

// ── 3. Create symbols ───────────────────────────────────────
$symbols = [
    ['NASDAQ', 'Nasdaq 100', 'INDEX', 20.0, 'USD'],
    ['DAX', 'DAX 40', 'INDEX', 25.0, 'EUR'],
    ['EURUSD', 'EUR/USD', 'FOREX', 10.0, 'USD'],
    ['GBPUSD', 'GBP/USD', 'FOREX', 10.0, 'USD'],
    ['BTCUSD', 'Bitcoin', 'CRYPTO', 1.0, 'USD'],
];

foreach ($symbols as [$code, $name, $type, $pointValue, $currency]) {
    $pdo->prepare("INSERT INTO symbols (user_id, code, name, type, point_value, currency)
        VALUES (:uid, :code, :name, :type, :pv, :cur)")
        ->execute(['uid' => $userId, 'code' => $code, 'name' => $name, 'type' => $type, 'pv' => $pointValue, 'cur' => $currency]);
}
echo "Created " . count($symbols) . " symbols\n";

// ── 4. Create setups ────────────────────────────────────────
$setups = ['Breakout', 'Pullback', 'Range', 'Trend Follow', 'Reversal'];
foreach ($setups as $label) {
    $pdo->prepare("INSERT INTO setups (user_id, label) VALUES (:uid, :label)")
        ->execute(['uid' => $userId, 'label' => $label]);
}
echo "Created " . count($setups) . " setups\n";

// ── 5. Create trades ────────────────────────────────────────
$trades = [
    // [symbol, direction, entry, sl_points, exit_price, exit_type, setup, opened_at, closed_at, size, account]
    // account: 1 = FTMO (PROP_FIRM), 2 = Compte perso (BROKER_LIVE)
    ['NASDAQ', 'BUY',  18500, 50, 18620, 'TP', 'Breakout',     '2026-01-06 09:30:00', '2026-01-06 11:15:00', 1, 1],
    ['NASDAQ', 'SELL', 18600, 40, 18520, 'TP', 'Reversal',     '2026-01-07 10:00:00', '2026-01-07 12:30:00', 1, 1],
    ['DAX',    'BUY',  17800, 30, 17750, 'SL', 'Pullback',     '2026-01-08 08:00:00', '2026-01-08 08:45:00', 2, 1],
    ['EURUSD', 'BUY',  1.0850, 0.0030, 1.0920, 'TP', 'Trend Follow', '2026-01-09 14:00:00', '2026-01-09 17:00:00', 1, 2],
    ['NASDAQ', 'BUY',  18400, 60, 18400, 'BE', 'Breakout',     '2026-01-10 09:00:00', '2026-01-10 10:30:00', 1, 1],
    ['GBPUSD', 'SELL', 1.2700, 0.0025, 1.2640, 'TP', 'Range',       '2026-01-13 10:00:00', '2026-01-13 15:00:00', 2, 2],
    ['DAX',    'BUY',  17900, 25, 17980, 'TP', 'Pullback',     '2026-01-14 08:30:00', '2026-01-14 10:00:00', 1, 1],
    ['NASDAQ', 'SELL', 18700, 45, 18760, 'SL', 'Reversal',     '2026-01-15 09:30:00', '2026-01-15 10:00:00', 1, 1],
    ['BTCUSD', 'BUY',  42000, 500, 43200, 'TP', 'Breakout',   '2026-01-16 02:00:00', '2026-01-16 05:30:00', 0.5, 2],
    ['EURUSD', 'SELL', 1.0900, 0.0020, 1.0870, 'TP', 'Trend Follow', '2026-01-20 03:00:00', '2026-01-20 06:00:00', 3, 2],
    ['NASDAQ', 'BUY',  18550, 50, 18700, 'TP', 'Breakout',     '2026-01-21 09:30:00', '2026-01-21 14:00:00', 1, 1],
    ['DAX',    'SELL', 18000, 35, 17920, 'TP', 'Range',         '2026-01-22 08:00:00', '2026-01-22 11:00:00', 1, 1],
    ['NASDAQ', 'BUY',  18650, 55, 18580, 'SL', 'Pullback',     '2026-01-23 10:00:00', '2026-01-23 10:45:00', 1, 1],
    ['GBPUSD', 'BUY',  1.2650, 0.0030, 1.2720, 'TP', 'Trend Follow', '2026-01-24 08:00:00', '2026-01-24 16:00:00', 2, 2],
    ['BTCUSD', 'SELL', 43500, 600, 43000, 'TP', 'Reversal',   '2026-01-27 03:00:00', '2026-01-27 07:00:00', 0.3, 2],
    ['NASDAQ', 'BUY',  18800, 40, 18900, 'TP', 'Breakout',     '2026-01-28 09:30:00', '2026-01-28 12:00:00', 1, 1],
    ['DAX',    'BUY',  18100, 30, 18050, 'SL', 'Pullback',     '2026-01-29 08:15:00', '2026-01-29 08:50:00', 1, 1],
    ['EURUSD', 'BUY',  1.0820, 0.0025, 1.0880, 'TP', 'Range',   '2026-01-30 14:00:00', '2026-01-30 17:30:00', 2, 2],
    ['NASDAQ', 'SELL', 18950, 50, 18850, 'TP', 'Reversal',     '2026-02-03 09:30:00', '2026-02-03 11:00:00', 1, 1],
    ['DAX',    'BUY',  18200, 20, 18260, 'TP', 'Trend Follow', '2026-02-04 08:00:00', '2026-02-04 09:30:00', 2, 1],
    ['GBPUSD', 'SELL', 1.2580, 0.0020, 1.2600, 'SL', 'Range',   '2026-02-05 10:00:00', '2026-02-05 11:00:00', 1, 2],
    ['NASDAQ', 'BUY',  19000, 60, 19000, 'BE', 'Breakout',     '2026-02-06 09:30:00', '2026-02-06 12:00:00', 1, 1],
    ['BTCUSD', 'BUY',  44000, 800, 45500, 'TP', 'Breakout',   '2026-02-07 02:00:00', '2026-02-07 06:00:00', 0.5, 2],
    ['EURUSD', 'SELL', 1.0950, 0.0035, 1.0900, 'TP', 'Pullback', '2026-02-10 09:00:00', '2026-02-10 15:00:00', 2, 2],
    ['NASDAQ', 'BUY',  19100, 45, 19200, 'TP', 'Trend Follow', '2026-02-11 09:30:00', '2026-02-11 14:30:00', 1, 1],
    ['DAX',    'SELL', 18300, 25, 18340, 'SL', 'Reversal',     '2026-02-12 08:00:00', '2026-02-12 08:40:00', 1, 1],
    ['NASDAQ', 'BUY',  19150, 50, 19280, 'TP', 'Breakout',     '2026-02-13 09:30:00', '2026-02-13 13:00:00', 1, 1],
    ['GBPUSD', 'BUY',  1.2700, 0.0020, 1.2750, 'TP', 'Trend Follow', '2026-02-17 15:00:00', '2026-02-17 19:00:00', 3, 2],
    ['DAX',    'BUY',  18400, 30, 18500, 'TP', 'Pullback',     '2026-02-18 08:00:00', '2026-02-18 10:30:00', 1, 1],
    ['NASDAQ', 'SELL', 19300, 40, 19350, 'SL', 'Range',         '2026-02-19 15:00:00', '2026-02-19 16:30:00', 1, 1],
];

$tradeCount = 0;
foreach ($trades as [$symbol, $direction, $entry, $slPoints, $exitPrice, $exitType, $setup, $openedAt, $closedAt, $size, $acctNum]) {
    $tradeAccountId = $acctNum === 2 ? $accountId2 : $accountId;
    // Calculate SL price
    if ($direction === 'BUY') {
        $slPrice = $entry - $slPoints;
    } else {
        $slPrice = $entry + $slPoints;
    }

    // Create position
    $pdo->prepare("INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, sl_price, position_type)
        VALUES (:uid, :aid, :dir, :sym, :entry, :size, :setup, :sl_pts, :sl_price, 'TRADE')")
        ->execute([
            'uid' => $userId,
            'aid' => $tradeAccountId,
            'dir' => $direction,
            'sym' => $symbol,
            'entry' => $entry,
            'size' => $size,
            'setup' => json_encode([$setup]),
            'sl_pts' => $slPoints,
            'sl_price' => $slPrice,
        ]);
    $positionId = (int) $pdo->lastInsertId();

    // Calculate PnL
    if ($direction === 'BUY') {
        $pnl = ($exitPrice - $entry) * $size;
    } else {
        $pnl = ($entry - $exitPrice) * $size;
    }
    $pnl = round($pnl, 2);

    // Calculate risk_reward
    $risk = $slPoints * $size;
    if ($risk > 0) {
        $rr = round($pnl / $risk, 4);
    } else {
        $rr = 0;
    }

    // Calculate duration
    $opened = new DateTime($openedAt);
    $closed = new DateTime($closedAt);
    $durationMinutes = (int) (($closed->getTimestamp() - $opened->getTimestamp()) / 60);

    // Create trade
    $pdo->prepare("INSERT INTO trades (position_id, opened_at, closed_at, remaining_size, status, exit_type, pnl, risk_reward, duration_minutes, avg_exit_price)
        VALUES (:pid, :opened, :closed, 0, 'CLOSED', :exit_type, :pnl, :rr, :dur, :exit_price)")
        ->execute([
            'pid' => $positionId,
            'opened' => $openedAt,
            'closed' => $closedAt,
            'exit_type' => $exitType,
            'pnl' => $pnl,
            'rr' => $rr,
            'dur' => $durationMinutes,
            'exit_price' => $exitPrice,
        ]);

    // Create partial exit
    $tradeId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO partial_exits (trade_id, exited_at, exit_price, size, exit_type, pnl)
        VALUES (:tid, :exited, :price, :size, :exit_type, :pnl)")
        ->execute([
            'tid' => $tradeId,
            'exited' => $closedAt,
            'price' => $exitPrice,
            'size' => $size,
            'exit_type' => $exitType,
            'pnl' => $pnl,
        ]);

    $tradeCount++;
}

// Update account capital with total PnL
$stmt = $pdo->prepare("SELECT COALESCE(SUM(t.pnl), 0) FROM trades t INNER JOIN positions p ON p.id = t.position_id WHERE p.account_id = :aid AND t.status = 'CLOSED'");
$stmt->execute(['aid' => $accountId]);
$totalPnl = (float) $stmt->fetchColumn();
$pdo->prepare("UPDATE accounts SET current_capital = initial_capital + :pnl WHERE id = :id")
    ->execute(['pnl' => $totalPnl, 'id' => $accountId]);

echo "Created {$tradeCount} trades (total P&L: {$totalPnl})\n";
echo "\nDemo account ready!\n";
echo "Login: {$email} / {$password}\n";
