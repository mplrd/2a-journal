<?php

namespace Tests\Integration\Cli;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test on the scheduler CLI entry point. We exec the script in a
 * subprocess — same as Railway's supercronic will — and check the exit
 * code plus the JSON contract on stdout. The point isn't to re-test the
 * scheduler logic (covered by the unit suite) but to catch wiring /
 * bootstrap regressions (missing dep, typo in config path, etc.).
 */
class SyncBrokersCliTest extends TestCase
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
        $this->pdo->exec('DELETE FROM sync_logs');
        $this->pdo->exec('DELETE FROM broker_connections');
    }

    private function runCli(array $envOverrides = []): array
    {
        $cli = realpath(__DIR__ . '/../../../cli/sync-brokers.php');
        $this->assertNotFalse($cli, 'CLI script path must resolve');

        $envParts = [];
        foreach ($envOverrides as $k => $v) {
            $envParts[] = escapeshellarg("{$k}={$v}");
        }
        $envPrefix = PHP_OS_FAMILY === 'Windows'
            ? (empty($envParts) ? '' : 'set ' . implode(' && set ', array_map(fn($p) => trim($p, "'\""), $envParts)) . ' && ')
            : (empty($envParts) ? '' : implode(' ', $envParts) . ' ');

        $cmd = $envPrefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli);

        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptor, $pipes);
        $this->assertIsResource($proc, 'proc_open failed');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return ['stdout' => trim($stdout), 'stderr' => trim($stderr), 'exit' => $exitCode];
    }

    public function testCliRunsWithZeroConnections(): void
    {
        $result = $this->runCli(['BROKER_AUTO_SYNC_ENABLED' => 'true']);

        $this->assertSame(0, $result['exit'], 'Expected exit 0, stderr: ' . $result['stderr']);

        $payload = json_decode($result['stdout'], true);
        $this->assertIsArray($payload, 'Stdout must be valid JSON: ' . $result['stdout']);
        $this->assertSame('ok', $payload['status']);
        $this->assertFalse($payload['skipped']);
        $this->assertSame(0, $payload['processed']);
    }

    public function testCliSkipsWhenFlagDisabled(): void
    {
        $result = $this->runCli(['BROKER_AUTO_SYNC_ENABLED' => 'false']);

        $this->assertSame(0, $result['exit']);

        $payload = json_decode($result['stdout'], true);
        $this->assertTrue($payload['skipped']);
    }
}
