<?php

/**
 * Auto-promote a user to ADMIN if their email matches INITIAL_ADMIN_EMAIL.
 * Run by api/docker/entrypoint.sh at every container boot, after migrations.
 *
 * Behaviour:
 *   - INITIAL_ADMIN_EMAIL absent or empty -> exit 0 silently
 *   - User not found in DB -> exit 0 with a warning to stdout
 *   - User exists and is already ADMIN -> exit 0, no write
 *   - User exists with role USER -> promote to ADMIN, exit 0
 *
 * Always exits 0 unless a hard runtime error occurs (DB unreachable, fatal
 * config). The script must never block the api boot.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Enums\UserRole;

// Load .env (same pattern as seed-demo.php)
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

$email = getenv('INITIAL_ADMIN_EMAIL') ?: null;
if (!$email) {
    fwrite(STDOUT, "[bootstrap-admin] INITIAL_ADMIN_EMAIL not set, skipping.\n");
    exit(0);
}

try {
    Database::reset();
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'SELECT id, role FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        fwrite(STDOUT, "[bootstrap-admin] WARNING: no user found with email '{$email}'. Skipping.\n");
        exit(0);
    }

    if ($user['role'] === UserRole::ADMIN->value) {
        fwrite(STDOUT, "[bootstrap-admin] User '{$email}' is already ADMIN. Nothing to do.\n");
        exit(0);
    }

    $update = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id AND deleted_at IS NULL');
    $update->execute([
        'role' => UserRole::ADMIN->value,
        'id' => $user['id'],
    ]);

    fwrite(STDOUT, "[bootstrap-admin] Promoted user '{$email}' (id={$user['id']}) to ADMIN.\n");
    exit(0);
} catch (Throwable $e) {
    // Boot should not be blocked by a bootstrap failure
    fwrite(STDERR, "[bootstrap-admin] ERROR: {$e->getMessage()}\n");
    exit(0);
}
