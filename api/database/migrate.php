<?php

/**
 * Incremental migration runner.
 *
 * - Creates a `migrations` table to track executed migrations
 * - Runs all pending SQL files from database/migrations/ in order
 * - Safe to run on every deploy (idempotent)
 *
 * Usage: php api/database/migrate.php
 */

declare(strict_types=1);

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

// ── 1. Create migrations table if not exists ────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_migrations_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── 2. Get already-executed migrations ──────────────────────────
$executed = $pdo->query("SELECT filename FROM migrations ORDER BY filename")
    ->fetchAll(PDO::FETCH_COLUMN);
$executedMap = array_flip($executed);

// ── 3. Discover migration files ─────────────────────────────────
$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

if (empty($files)) {
    echo "No migration files found in database/migrations/\n";
    exit(0);
}

// ── 4. Run pending migrations ───────────────────────────────────
$ran = 0;
foreach ($files as $file) {
    $filename = basename($file);

    if (isset($executedMap[$filename])) {
        continue;
    }

    echo "Running: {$filename}... ";

    $sql = file_get_contents($file);
    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO migrations (filename) VALUES (:f)")
            ->execute(['f' => $filename]);
        echo "OK\n";
        $ran++;
    } catch (\Throwable $e) {
        echo "FAILED\n";
        echo "  Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($ran === 0) {
    echo "Nothing to migrate. All " . count($executed) . " migrations already applied.\n";
} else {
    echo "Done. Ran {$ran} migration(s).\n";
}
