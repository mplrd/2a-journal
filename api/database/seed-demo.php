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

// Check if demo user exists (including soft-deleted rows — the email UNIQUE index
// ignores deleted_at, so we must clean up any prior row before re-inserting).
$stmt = $pdo->prepare('SELECT id, deleted_at FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$existing = $stmt->fetch();

if ($existing) {
    $state = $existing['deleted_at'] ? 'soft-deleted' : 'active';
    echo "Demo user already exists (id={$existing['id']}, {$state}). Cleaning up...\n";
    // Delete cascades take care of accounts, positions, trades, etc.
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $existing['id']]);
}

// ── 1. Create user ──────────────────────────────────────────
// bypass_subscription=1 so the demo account is exempt from the Stripe paywall.
$hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare("INSERT INTO users (email, password, first_name, last_name, timezone, default_currency, locale, theme, bypass_subscription, onboarding_completed_at, email_verified_at)
    VALUES (:email, :password, 'Demo', 'Trader', 'Europe/Paris', 'EUR', 'fr', 'light', 1, NOW(), NOW())")
    ->execute(['email' => $email, 'password' => $hashedPassword]);
$userId = (int) $pdo->lastInsertId();
echo "Created user: {$email} (id={$userId}, bypass_subscription=1)\n";

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

$pdo->prepare("INSERT INTO accounts (user_id, name, account_type, broker, currency, initial_capital, current_capital)
    VALUES (:uid, 'MFF Évaluation', 'PROP_FIRM', 'MyForexFunds', 'USD', 50000.00, 50000.00)")
    ->execute(['uid' => $userId]);
$accountId3 = (int) $pdo->lastInsertId();
$pdo->prepare("UPDATE accounts SET stage = 'CHALLENGE', max_drawdown = 5000.00, daily_drawdown = 2500.00, profit_target = 4000.00 WHERE id = :id")
    ->execute(['id' => $accountId3]);

echo "Created 3 accounts (ids: {$accountId}, {$accountId2}, {$accountId3})\n";

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
// Each setup is tagged with a category so the demo showcases all four
// visual buckets (timeframe / pattern / context / uncategorized) on the
// Performance filter.
$setups = [
    ['Breakout',     'pattern'],
    ['Pullback',     'pattern'],
    ['Range',        'context'],
    ['Trend Follow', 'timeframe'],
    ['Reversal',     null], // outlier — uncategorized bucket
];
foreach ($setups as [$label, $category]) {
    $pdo->prepare("INSERT INTO setups (user_id, label, category) VALUES (:uid, :label, :cat)")
        ->execute(['uid' => $userId, 'label' => $label, 'cat' => $category]);
}
echo "Created " . count($setups) . " setups\n";

// ── 5. Create trades ────────────────────────────────────────
// Setup field accepts a string (single setup) OR an array (combination).
// Combinations chosen so that the demo highlights the new "setup combination
// analysis" feature: a clearly-outperforming combo (Breakout + Trend Follow),
// a mediocre one (Pullback + Range), and a rare 3-setup combo to trigger
// the "too few trades" warning in the modal.
$trades = [
    // [symbol, direction, entry, sl_points, exit_price, exit_type, setups, opened_at, closed_at, size, account]
    // account: 1 = FTMO (PROP_FIRM), 2 = Compte perso (BROKER_LIVE)
    ['NASDAQ', 'BUY',  18500, 50, 18620, 'TP', ['Breakout', 'Trend Follow'],     '2026-01-06 09:30:00', '2026-01-06 11:15:00', 1, 1],
    ['NASDAQ', 'SELL', 18600, 40, 18520, 'TP', 'Reversal',                       '2026-01-07 10:00:00', '2026-01-07 12:30:00', 1, 1],
    ['DAX',    'BUY',  17800, 30, 17750, 'SL', ['Pullback', 'Range'],            '2026-01-08 08:00:00', '2026-01-08 08:45:00', 2, 1],
    ['EURUSD', 'BUY',  1.0850, 0.0030, 1.0920, 'TP', 'Trend Follow',             '2026-01-09 14:00:00', '2026-01-09 17:00:00', 1, 2],
    ['NASDAQ', 'BUY',  18400, 60, 18400, 'BE', 'Breakout',                       '2026-01-10 09:00:00', '2026-01-10 10:30:00', 1, 1],
    ['GBPUSD', 'SELL', 1.2700, 0.0025, 1.2640, 'TP', 'Range',                    '2026-01-13 10:00:00', '2026-01-13 15:00:00', 2, 2],
    ['DAX',    'BUY',  17900, 25, 17980, 'TP', ['Pullback', 'Range'],            '2026-01-14 08:30:00', '2026-01-14 10:00:00', 1, 1],
    ['NASDAQ', 'SELL', 18700, 45, 18760, 'SL', 'Reversal',                       '2026-01-15 09:30:00', '2026-01-15 10:00:00', 1, 1],
    ['BTCUSD', 'BUY',  42000, 500, 43200, 'TP', ['Breakout', 'Trend Follow'],    '2026-01-16 02:00:00', '2026-01-16 05:30:00', 0.5, 2],
    ['EURUSD', 'SELL', 1.0900, 0.0020, 1.0870, 'TP', 'Trend Follow',             '2026-01-20 03:00:00', '2026-01-20 06:00:00', 3, 2],
    ['NASDAQ', 'BUY',  18550, 50, 18700, 'TP', ['Breakout', 'Trend Follow'],     '2026-01-21 09:30:00', '2026-01-21 14:00:00', 1, 1],
    ['DAX',    'SELL', 18000, 35, 17920, 'TP', 'Range',                          '2026-01-22 08:00:00', '2026-01-22 11:00:00', 1, 1],
    ['NASDAQ', 'BUY',  18650, 55, 18580, 'SL', ['Pullback', 'Range'],            '2026-01-23 10:00:00', '2026-01-23 10:45:00', 1, 1],
    ['GBPUSD', 'BUY',  1.2650, 0.0030, 1.2720, 'TP', 'Trend Follow',             '2026-01-24 08:00:00', '2026-01-24 16:00:00', 2, 2],
    ['BTCUSD', 'SELL', 43500, 600, 43000, 'TP', 'Reversal',                      '2026-01-27 03:00:00', '2026-01-27 07:00:00', 0.3, 2],
    ['NASDAQ', 'BUY',  18800, 40, 18900, 'TP', ['Breakout', 'Trend Follow'],     '2026-01-28 09:30:00', '2026-01-28 12:00:00', 1, 1],
    ['DAX',    'BUY',  18100, 30, 18050, 'SL', 'Pullback',                       '2026-01-29 08:15:00', '2026-01-29 08:50:00', 1, 1],
    ['EURUSD', 'BUY',  1.0820, 0.0025, 1.0880, 'TP', 'Range',                    '2026-01-30 14:00:00', '2026-01-30 17:30:00', 2, 2],
    ['NASDAQ', 'SELL', 18950, 50, 18850, 'TP', 'Reversal',                       '2026-02-03 09:30:00', '2026-02-03 11:00:00', 1, 1],
    ['DAX',    'BUY',  18200, 20, 18260, 'TP', 'Trend Follow',                   '2026-02-04 08:00:00', '2026-02-04 09:30:00', 2, 1],
    ['GBPUSD', 'SELL', 1.2580, 0.0020, 1.2600, 'SL', 'Range',                    '2026-02-05 10:00:00', '2026-02-05 11:00:00', 1, 2],
    ['NASDAQ', 'BUY',  19000, 60, 19000, 'BE', ['Breakout', 'Trend Follow'],     '2026-02-06 09:30:00', '2026-02-06 12:00:00', 1, 1],
    ['BTCUSD', 'BUY',  44000, 800, 45500, 'TP', ['Breakout', 'Trend Follow'],    '2026-02-07 02:00:00', '2026-02-07 06:00:00', 0.5, 2],
    ['EURUSD', 'SELL', 1.0950, 0.0035, 1.0900, 'TP', 'Pullback',                 '2026-02-10 09:00:00', '2026-02-10 15:00:00', 2, 2],
    ['NASDAQ', 'BUY',  19100, 45, 19200, 'TP', 'Trend Follow',                   '2026-02-11 09:30:00', '2026-02-11 14:30:00', 1, 1],
    ['DAX',    'SELL', 18300, 25, 18340, 'SL', 'Reversal',                       '2026-02-12 08:00:00', '2026-02-12 08:40:00', 1, 1],
    ['NASDAQ', 'BUY',  19150, 50, 19280, 'TP', ['Breakout', 'Trend Follow', 'Pullback'], '2026-02-13 09:30:00', '2026-02-13 13:00:00', 1, 1],
    ['GBPUSD', 'BUY',  1.2700, 0.0020, 1.2750, 'TP', 'Trend Follow',             '2026-02-17 15:00:00', '2026-02-17 19:00:00', 3, 2],
    ['DAX',    'BUY',  18400, 30, 18500, 'TP', ['Pullback', 'Range'],            '2026-02-18 08:00:00', '2026-02-18 10:30:00', 1, 1],
    ['NASDAQ', 'SELL', 19300, 40, 19350, 'SL', 'Range',                          '2026-02-19 15:00:00', '2026-02-19 16:30:00', 1, 1],
    // ── Account 3: MFF (losing account) ──
    ['NASDAQ', 'BUY',  19200, 50, 19140, 'SL', 'Breakout',                       '2026-02-20 09:30:00', '2026-02-20 10:00:00', 2, 3],
    ['DAX',    'SELL', 18500, 30, 18550, 'SL', 'Reversal',                       '2026-02-21 08:00:00', '2026-02-21 08:30:00', 2, 3],
    ['NASDAQ', 'BUY',  19100, 40, 19050, 'SL', 'Pullback',                       '2026-02-24 09:30:00', '2026-02-24 10:15:00', 1, 3],
    ['EURUSD', 'SELL', 1.0900, 0.0020, 1.0930, 'SL', 'Range',                    '2026-02-25 10:00:00', '2026-02-25 11:00:00', 3, 3],
    ['NASDAQ', 'BUY',  19000, 50, 19080, 'TP', 'Trend Follow',                   '2026-02-26 09:30:00', '2026-02-26 13:00:00', 1, 3],
    ['DAX',    'BUY',  18400, 25, 18360, 'SL', 'Pullback',                       '2026-02-27 08:00:00', '2026-02-27 08:40:00', 2, 3],
    ['NASDAQ', 'SELL', 19200, 45, 19260, 'SL', 'Reversal',                       '2026-03-02 09:30:00', '2026-03-02 10:00:00', 1, 3],
    ['GBPUSD', 'BUY',  1.2680, 0.0020, 1.2650, 'SL', 'Range',                    '2026-03-03 10:00:00', '2026-03-03 11:00:00', 2, 3],
    // ── March trades (for calendar) ──
    ['NASDAQ', 'BUY',  19400, 50, 19520, 'TP', ['Breakout', 'Trend Follow'],     '2026-03-02 09:30:00', '2026-03-02 12:00:00', 1, 1],
    ['DAX',    'SELL', 18600, 30, 18550, 'TP', 'Range',                          '2026-03-03 08:00:00', '2026-03-03 10:00:00', 1, 1],
    ['NASDAQ', 'BUY',  19500, 45, 19440, 'SL', 'Pullback',                       '2026-03-04 09:30:00', '2026-03-04 10:00:00', 1, 1],
    ['EURUSD', 'BUY',  1.0800, 0.0025, 1.0860, 'TP', 'Trend Follow',             '2026-03-05 10:00:00', '2026-03-05 14:00:00', 2, 2],
    ['NASDAQ', 'SELL', 19600, 50, 19520, 'TP', 'Reversal',                       '2026-03-06 09:30:00', '2026-03-06 11:00:00', 1, 1],
    ['DAX',    'BUY',  18700, 30, 18650, 'SL', 'Pullback',                       '2026-03-09 08:00:00', '2026-03-09 08:45:00', 1, 1],
    ['NASDAQ', 'BUY',  19550, 40, 19630, 'TP', ['Breakout', 'Trend Follow'],     '2026-03-10 09:30:00', '2026-03-10 13:00:00', 1, 1],
    ['GBPUSD', 'SELL', 1.2720, 0.0020, 1.2680, 'TP', 'Range',                    '2026-03-11 10:00:00', '2026-03-11 15:00:00', 2, 2],
    ['NASDAQ', 'BUY',  19650, 50, 19600, 'SL', 'Trend Follow',                   '2026-03-12 09:30:00', '2026-03-12 10:15:00', 1, 1],
    ['DAX',    'BUY',  18800, 25, 18870, 'TP', 'Pullback',                       '2026-03-13 08:00:00', '2026-03-13 10:00:00', 1, 1],
];

$tradeCount = 0;
foreach ($trades as [$symbol, $direction, $entry, $slPoints, $exitPrice, $exitType, $setup, $openedAt, $closedAt, $size, $acctNum]) {
    $tradeAccountId = match ($acctNum) { 2 => $accountId2, 3 => $accountId3, default => $accountId };
    // Calculate SL price
    if ($direction === 'BUY') {
        $slPrice = $entry - $slPoints;
    } else {
        $slPrice = $entry + $slPoints;
    }

    // Setup field: accept either a string (single setup) or an array (combo).
    $setupArray = is_array($setup) ? $setup : [$setup];

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
            'setup' => json_encode($setupArray),
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

    // Calculate pnl_percent (required for BE threshold classification in stats)
    $entryValue = $entry * $size;
    $pnlPercent = $entryValue > 0 ? round($pnl / $entryValue * 100, 4) : 0;

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
    $pdo->prepare("INSERT INTO trades (position_id, opened_at, closed_at, remaining_size, status, exit_type, pnl, pnl_percent, risk_reward, duration_minutes, avg_exit_price)
        VALUES (:pid, :opened, :closed, 0, 'CLOSED', :exit_type, :pnl, :pnl_percent, :rr, :dur, :exit_price)")
        ->execute([
            'pid' => $positionId,
            'opened' => $openedAt,
            'closed' => $closedAt,
            'exit_type' => $exitType,
            'pnl' => $pnl,
            'pnl_percent' => $pnlPercent,
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

// ── 6. Create open trades ──────────────────────────────────
$openTrades = [
    // [symbol, direction, entry, sl_points, setup, opened_at, size, account]
    ['NASDAQ', 'BUY',  19700, 50, 'Breakout',     '2026-03-16 09:30:00', 1, 1],
    ['DAX',    'SELL', 18900, 30, 'Range',         '2026-03-16 08:15:00', 1, 1],
    ['EURUSD', 'BUY',  1.0830, 0.0025, 'Trend Follow', '2026-03-16 10:00:00', 2, 2],
];

$openCount = 0;
foreach ($openTrades as [$symbol, $direction, $entry, $slPoints, $setup, $openedAt, $size, $acctNum]) {
    $openAccountId = match ($acctNum) { 2 => $accountId2, 3 => $accountId3, default => $accountId };
    $slPrice = $direction === 'BUY' ? $entry - $slPoints : $entry + $slPoints;

    $pdo->prepare("INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, sl_price, position_type)
        VALUES (:uid, :aid, :dir, :sym, :entry, :size, :setup, :sl_pts, :sl_price, 'TRADE')")
        ->execute([
            'uid' => $userId,
            'aid' => $openAccountId,
            'dir' => $direction,
            'sym' => $symbol,
            'entry' => $entry,
            'size' => $size,
            'setup' => json_encode(is_array($setup) ? $setup : [$setup]),
            'sl_pts' => $slPoints,
            'sl_price' => $slPrice,
        ]);
    $positionId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO trades (position_id, opened_at, remaining_size, status)
        VALUES (:pid, :opened, :size, 'OPEN')")
        ->execute([
            'pid' => $positionId,
            'opened' => $openedAt,
            'size' => $size,
        ]);

    $openCount++;
}

// ── 6b. Create SECURED trades (BE reached, partial taken) ──
// Each entry has a BE price (typically = entry) with a partial size already
// exited at BE — leaving remaining_size = size - be_size and status=SECURED.
$securedTrades = [
    // [symbol, direction, entry, sl_points, be_size, setup, opened_at, be_at, size, account]
    ['NASDAQ', 'BUY',  19750, 50, 0.5, 'Breakout',     '2026-03-17 09:30:00', '2026-03-17 10:30:00', 1, 1],
    ['GBPUSD', 'SELL', 1.2700, 0.0025, 1.0, 'Range',     '2026-03-17 10:00:00', '2026-03-17 12:00:00', 2, 2],
];

$securedCount = 0;
foreach ($securedTrades as [$symbol, $direction, $entry, $slPoints, $beSize, $setup, $openedAt, $beAt, $size, $acctNum]) {
    $secAccountId = match ($acctNum) { 2 => $accountId2, 3 => $accountId3, default => $accountId };
    $slPrice = $direction === 'BUY' ? $entry - $slPoints : $entry + $slPoints;
    $bePrice = $entry; // BE = entry by convention

    $pdo->prepare("INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, sl_price, be_price, be_size, position_type)
        VALUES (:uid, :aid, :dir, :sym, :entry, :size, :setup, :sl_pts, :sl_price, :be_price, :be_size, 'TRADE')")
        ->execute([
            'uid' => $userId,
            'aid' => $secAccountId,
            'dir' => $direction,
            'sym' => $symbol,
            'entry' => $entry,
            'size' => $size,
            'setup' => json_encode(is_array($setup) ? $setup : [$setup]),
            'sl_pts' => $slPoints,
            'sl_price' => $slPrice,
            'be_price' => $bePrice,
            'be_size' => $beSize,
        ]);
    $positionId = (int) $pdo->lastInsertId();

    $remaining = $size - $beSize;
    $pdo->prepare("INSERT INTO trades (position_id, opened_at, remaining_size, status, be_reached)
        VALUES (:pid, :opened, :remaining, 'SECURED', 1)")
        ->execute([
            'pid' => $positionId,
            'opened' => $openedAt,
            'remaining' => $remaining,
        ]);
    $tradeId = (int) $pdo->lastInsertId();

    // Partial exit at BE: pnl=0 by definition
    $pdo->prepare("INSERT INTO partial_exits (trade_id, exited_at, exit_price, size, exit_type, pnl)
        VALUES (:tid, :exited, :price, :size, 'BE', 0)")
        ->execute([
            'tid' => $tradeId,
            'exited' => $beAt,
            'price' => $bePrice,
            'size' => $beSize,
        ]);

    $securedCount++;
}

// ── 6c. Create orders (one per status) ─────────────────────
// Orders extend positions just like trades, but live on the orders table.
// EXECUTED orders also get a child trade so the "trade created" flow is
// realistic. PENDING / CANCELLED / EXPIRED stay order-only.
$now = new DateTime('2026-03-18 10:00:00');
$ordersToSeed = [
    // [symbol, direction, entry, sl_points, setup, status, size, account, expires_at_offset_days]
    ['NASDAQ', 'BUY',  19850, 50, 'Breakout',     'PENDING',   1, 1, 7],
    ['EURUSD', 'SELL', 1.0950, 0.0025, 'Range',     'PENDING',   2, 2, 3],
    ['DAX',    'BUY',  18950, 30, 'Pullback',     'EXECUTED',  1, 1, null],
    ['GBPUSD', 'SELL', 1.2780, 0.0030, 'Trend Follow', 'CANCELLED', 2, 2, null],
    ['BTCUSD', 'BUY',  44500, 600, 'Reversal',     'EXPIRED',   0.5, 2, null],
];

$orderCount = 0;
foreach ($ordersToSeed as [$symbol, $direction, $entry, $slPoints, $setup, $status, $size, $acctNum, $expiresOffset]) {
    $orderAccountId = match ($acctNum) { 2 => $accountId2, 3 => $accountId3, default => $accountId };
    $slPrice = $direction === 'BUY' ? $entry - $slPoints : $entry + $slPoints;

    $pdo->prepare("INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup, sl_points, sl_price, position_type)
        VALUES (:uid, :aid, :dir, :sym, :entry, :size, :setup, :sl_pts, :sl_price, 'ORDER')")
        ->execute([
            'uid' => $userId,
            'aid' => $orderAccountId,
            'dir' => $direction,
            'sym' => $symbol,
            'entry' => $entry,
            'size' => $size,
            'setup' => json_encode(is_array($setup) ? $setup : [$setup]),
            'sl_pts' => $slPoints,
            'sl_price' => $slPrice,
        ]);
    $positionId = (int) $pdo->lastInsertId();

    $createdAt = $now->format('Y-m-d H:i:s');
    $expiresAt = null;
    if ($expiresOffset !== null) {
        $exp = clone $now;
        $exp->modify("+{$expiresOffset} day");
        $expiresAt = $exp->format('Y-m-d H:i:s');
    }

    $pdo->prepare("INSERT INTO orders (position_id, created_at, expires_at, status)
        VALUES (:pid, :created, :expires, :status)")
        ->execute([
            'pid' => $positionId,
            'created' => $createdAt,
            'expires' => $expiresAt,
            'status' => $status,
        ]);
    $orderId = (int) $pdo->lastInsertId();

    if ($status === 'EXECUTED') {
        // EXECUTED order → child trade (opened, still active)
        $pdo->prepare("UPDATE positions SET position_type = 'TRADE' WHERE id = :id")
            ->execute(['id' => $positionId]);
        $pdo->prepare("INSERT INTO trades (position_id, source_order_id, opened_at, remaining_size, status)
            VALUES (:pid, :oid, :opened, :size, 'OPEN')")
            ->execute([
                'pid' => $positionId,
                'oid' => $orderId,
                'opened' => $createdAt,
                'size' => $size,
            ]);
    }

    $orderCount++;
}

// ── 7. Create custom field "Tendance" ──────────────────────
$pdo->prepare("INSERT INTO custom_field_definitions (user_id, name, field_type, options, sort_order, is_active)
    VALUES (:uid, 'Tendance', 'SELECT', :options, 0, 1)")
    ->execute(['uid' => $userId, 'options' => json_encode(['Bullish', 'Bearish'])]);
$tendanceFieldId = (int) $pdo->lastInsertId();
echo "Created custom field 'Tendance' (id={$tendanceFieldId})\n";

// Assign tendance values to some trades (alternating based on direction)
$stmt = $pdo->prepare("SELECT t.id, p.direction FROM trades t INNER JOIN positions p ON p.id = t.position_id WHERE p.user_id = :uid AND t.status = 'CLOSED' ORDER BY t.opened_at");
$stmt->execute(['uid' => $userId]);
$closedTrades = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cfCount = 0;
foreach ($closedTrades as $i => $trade) {
    // Assign to ~70% of trades for realistic demo
    if ($i % 10 >= 7) continue;

    $tendance = $trade['direction'] === 'BUY' ? 'Bullish' : 'Bearish';
    $pdo->prepare("INSERT INTO custom_field_values (custom_field_id, trade_id, value) VALUES (:fid, :tid, :val)")
        ->execute(['fid' => $tendanceFieldId, 'tid' => $trade['id'], 'val' => $tendance]);
    $cfCount++;
}
echo "Assigned 'Tendance' to {$cfCount} trades\n";

// ── 8. Update account capitals ─────────────────────────────
foreach ([$accountId, $accountId2, $accountId3] as $aid) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(t.pnl), 0) FROM trades t INNER JOIN positions p ON p.id = t.position_id WHERE p.account_id = :aid AND t.status = 'CLOSED'");
    $stmt->execute(['aid' => $aid]);
    $pnl = (float) $stmt->fetchColumn();
    $pdo->prepare("UPDATE accounts SET current_capital = initial_capital + :pnl WHERE id = :id")
        ->execute(['pnl' => $pnl, 'id' => $aid]);
}

echo "Created {$tradeCount} closed + {$openCount} open + {$securedCount} secured trades, {$orderCount} orders\n";
echo "\nDemo account ready!\n";
echo "Login: {$email} / {$password}\n";
