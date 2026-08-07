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
     * How much of a dead child's stderr we carry into the run log. Its output
     * no longer flows straight into the cron log now that the pool captures it,
     * so this is the only trace left — long enough for a fatal error line plus
     * the first frames of its stack.
     */
    private const STDERR_LOG_CHARS = 2000;

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
            $parsed = $this->parseWorkerSummary($result);
            if ($parsed === null) {
                $workerErrors++;
                BrokerLogger::failure('broker-sync', 'worker_failed', [
                    'worker_index' => $index,
                    'exit_code' => $result['exit_code'] ?? null,
                    'stderr' => substr((string) ($result['stderr'] ?? ''), 0, self::STDERR_LOG_CHARS),
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
