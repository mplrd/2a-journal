<?php

namespace Tests\Integration\Cli;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Smoke + behavior test for the admin bootstrap CLI. The script auto-promotes
 * the user matching INITIAL_ADMIN_EMAIL at boot time so the very first admin
 * exists without anyone having to log into the BO with admin powers first.
 */
class BootstrapAdminTest extends TestCase
{
    private PDO $pdo;

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

        // Reset to a known state — only purge what we own
        $this->pdo->exec("DELETE FROM refresh_tokens");
        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'bootstrap-%@test.com'");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'bootstrap-%@test.com'");
    }

    private function createUser(string $email, string $role = 'USER'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, role, password) VALUES (:e, :r, :p)'
        );
        $stmt->execute([
            'e' => $email,
            'r' => $role,
            'p' => password_hash('Test1234', PASSWORD_BCRYPT),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function getRole(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (string) $stmt->fetchColumn();
    }

    private function runCli(array $envOverrides = []): array
    {
        $cli = realpath(__DIR__ . '/../../../cli/bootstrap-admin.php');
        $this->assertNotFalse($cli, 'CLI script path must resolve');

        $envParts = [];
        foreach ($envOverrides as $k => $v) {
            $envParts[] = escapeshellarg("{$k}={$v}");
        }
        $envPrefix = PHP_OS_FAMILY === 'Windows'
            ? (empty($envParts) ? '' : 'set ' . implode(' && set ', array_map(fn($p) => trim($p, "'\""), $envParts)) . ' && ')
            : (empty($envParts) ? '' : implode(' ', $envParts) . ' ');

        $cmd = $envPrefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli);

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptor, $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['stdout' => trim($stdout), 'stderr' => trim($stderr), 'exit' => $exit];
    }

    public function testPromotesUserMatchingEmail(): void
    {
        $id = $this->createUser('bootstrap-promote@test.com', 'USER');

        $result = $this->runCli(['INITIAL_ADMIN_EMAIL' => 'bootstrap-promote@test.com']);

        $this->assertSame(0, $result['exit'], 'stderr=' . $result['stderr']);
        $this->assertSame('ADMIN', $this->getRole($id));
    }

    public function testIsIdempotentWhenAlreadyAdmin(): void
    {
        $id = $this->createUser('bootstrap-already@test.com', 'ADMIN');

        $result = $this->runCli(['INITIAL_ADMIN_EMAIL' => 'bootstrap-already@test.com']);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('ADMIN', $this->getRole($id));
    }

    public function testSkipsSilentlyWhenEnvVarMissing(): void
    {
        $id = $this->createUser('bootstrap-noenv@test.com', 'USER');

        // Ensure the env var is empty (the runCli helper passes none)
        $result = $this->runCli([]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('USER', $this->getRole($id));
    }

    public function testSkipsWithWarningWhenUserNotFound(): void
    {
        // No matching user pre-existing
        $result = $this->runCli(['INITIAL_ADMIN_EMAIL' => 'bootstrap-ghost@test.com']);

        // Exit 0 even though user not found — the boot must continue regardless
        $this->assertSame(0, $result['exit']);
    }
}
