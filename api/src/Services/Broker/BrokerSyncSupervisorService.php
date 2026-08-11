<?php

namespace App\Services\Broker;

use App\Repositories\BrokerConnectionRepository;
use App\Services\Process\ProcessPoolInterface;

/**
 * Fans the auto-sync run out across several child processes.
 *
 * Before this, one cron tick processed every due connection of every user
 * sequentially in a single process: the duration of a run was the sum of every
 * user's sync, so a handful of accounts turned the platform into a queue.
 *
 * There is deliberately no work-splitting here. Each child fetches the same due
 * list and reserves what it can (`BrokerConnectionRepository::claimForSync`);
 * the reservation is what shares the work out. A static split would idle the
 * workers that drew the fast connections, and would have to be recomputed the
 * moment a connection is added, removed or already held by a manual sync.
 */
class BrokerSyncSupervisorService
{
    /** Hard ceiling on children, whatever the setting says. */
    private const MAX_WORKERS = 16;

    /**
     * Ceiling on the stderr lines carried over from one child per run.
     *
     * Generous enough for a fatal error and its stack, or a run's worth of
     * connector diagnostics, without letting a child in a logging loop flood
     * the container stream.
     */
    private const FORWARDED_STDERR_LINES = 200;

    public function __construct(
        private ProcessPoolInterface $pool,
        private BrokerConnectionRepository $connectionRepo,
        private string $phpBinary,
        private string $scriptPath,
    ) {}

    /**
     * @param  array{auto_sync_enabled: bool, sync_interval_minutes: int, workers: int} $config
     * @return array{skipped: bool, workers: int, total_active: int, processed: int, success: int, failed: int, deferred: int, already_syncing: int, deactivated: int, worker_errors: int, interval_minutes: int, duration_ms: int}
     */
    public function run(array $config): array
    {
        $startedAt = microtime(true);
        $intervalMinutes = (int) $config['sync_interval_minutes'];

        if (!$config['auto_sync_enabled']) {
            return $this->summary(true, 0, 0, 0, [], 0, $intervalMinutes, $startedAt);
        }

        $totalActive = $this->connectionRepo->countActive();
        $due = $this->connectionRepo->countDueForAutoSync($intervalMinutes);

        // Booting N PHP processes every tick to discover there is nothing to do
        // would cost more than the work itself.
        if ($due === 0) {
            return $this->summary(false, 0, $totalActive, 0, [], 0, $intervalMinutes, $startedAt);
        }

        $workers = $this->workerCount((int) $config['workers'], $due);

        $commands = [];
        for ($i = 0; $i < $workers; $i++) {
            $commands[] = [$this->phpBinary, $this->scriptPath, '--worker', "--worker-index={$i}"];
        }

        $results = $this->pool->runConcurrently($commands);

        $summaries = [];
        $workerErrors = 0;
        foreach ($results as $index => $result) {
            // Before anything else, and whatever the child's fate: its stderr
            // is the only record of what the connectors reported.
            $this->forwardWorkerDiagnostics($index, (string) ($result['stderr'] ?? ''));

            $parsed = $this->parseWorkerSummary($result);
            if ($parsed === null) {
                $workerErrors++;
                BrokerLogger::failure('broker-sync', 'worker_failed', [
                    'worker_index' => $index,
                    'exit_code' => $result['exit_code'] ?? null,
                ]);
                continue;
            }
            $summaries[] = $parsed;
        }

        return $this->summary(false, $workers, $totalActive, $due, $summaries, $workerErrors, $intervalMinutes, $startedAt);
    }

    /**
     * Never more children than there is work, never more than the ceiling, and
     * never fewer than one — a misconfigured 0 must not silently disable the
     * auto-sync.
     */
    private function workerCount(int $configured, int $due): int
    {
        if ($configured < 1) {
            $configured = 1;
        } elseif ($configured > self::MAX_WORKERS) {
            $configured = self::MAX_WORKERS;
        }

        return min($configured, $due);
    }

    /**
     * Re-emit a child's stderr into the supervisor's own, so what happened
     * inside a worker reaches the container stream.
     *
     * The pool has to capture each child's output to read its summary off
     * stdout, and a successful child's captured stderr used to be dropped
     * right there. Everything the connectors reported during a working sync
     * went with it: `take_profit_levels_unresolved`, the cTrader request
     * budget, every non-fatal warning. Written correctly, reaching nobody —
     * checked against the test environment on 2026-08-11, where six hours of
     * scheduler logs held 360 supervisor lines and not one worker line. It
     * made a sync defect impossible to diagnose anywhere but locally.
     *
     * Lines go out verbatim through error_log(), not through BrokerLogger:
     * the child already formatted them, and re-wrapping would bury a JSON
     * diagnostic inside another JSON envelope. A separate header line says
     * which worker they came from — several run at once, and a line nobody can
     * attribute is half a diagnostic.
     *
     * Silent when the child wrote nothing, which is the nominal case: this
     * runs every minute and must not add noise per tick.
     */
    private function forwardWorkerDiagnostics(int $index, string $stderr): void
    {
        $lines = array_values(array_filter(
            array_map('rtrim', explode("\n", $stderr)),
            fn(string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            return;
        }

        $kept = array_slice($lines, 0, self::FORWARDED_STDERR_LINES);

        BrokerLogger::event('broker-sync', 'worker_output', [
            'worker_index' => $index,
            'lines' => count($lines),
            'dropped' => count($lines) - count($kept),
        ]);

        foreach ($kept as $line) {
            error_log($line);
        }
    }

    /**
     * Read a child's run summary off its stdout. Scans from the last line
     * backwards: a deprecation notice printed ahead of the JSON must not cost
     * us the whole worker's report.
     *
     * @param  array{exit_code: int, stdout: string, stderr: string} $result
     */
    private function parseWorkerSummary(array $result): ?array
    {
        if (($result['exit_code'] ?? -1) !== 0) {
            return null;
        }

        $lines = array_filter(array_map('trim', explode("\n", (string) ($result['stdout'] ?? ''))));
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['status'])) {
                return $decoded;
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $summaries */
    private function summary(
        bool $skipped,
        int $workers,
        int $totalActive,
        int $due,
        array $summaries,
        int $workerErrors,
        int $intervalMinutes,
        float $startedAt,
    ): array {
        $sum = fn(string $key) => array_sum(array_map(fn($s) => (int) ($s[$key] ?? 0), $summaries));

        return [
            'skipped' => $skipped,
            'workers' => $workers,
            'total_active' => $totalActive,
            // The size of the due list, NOT the sum of the workers' views: they
            // all see the same list, so summing would report six connections
            // as twelve.
            'processed' => $due,
            'success' => $sum('success'),
            'failed' => $sum('failed'),
            'deferred' => $sum('deferred'),
            // Contention, not connections: N-1 workers each skip the same
            // reserved connection. Expected by design, useful only as a hint
            // that the pool is oversized for the workload.
            'already_syncing' => $sum('already_syncing'),
            'deactivated' => $sum('deactivated'),
            'worker_errors' => $workerErrors,
            'interval_minutes' => $intervalMinutes,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
