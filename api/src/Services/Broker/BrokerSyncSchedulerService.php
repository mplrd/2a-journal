<?php

namespace App\Services\Broker;

use App\Core\ErrorLogger;
use App\Enums\SyncStatus;
use App\Exceptions\BrokerDailyBudgetException;
use App\Exceptions\BrokerRateLimitException;
use App\Repositories\BrokerConnectionRepository;
use Throwable;

/**
 * Orchestrates auto-sync of all ACTIVE broker connections whose last sync is
 * older than the configured interval. Isolates failures per connection and
 * trips a per-connection circuit breaker after N consecutive failures.
 *
 * Invoked from the CLI entry point (cli/sync-brokers.php) run by the
 * scheduler container. Not exposed over HTTP.
 */
class BrokerSyncSchedulerService
{
    /** @param array{auto_sync_enabled: bool, sync_interval_minutes: int, max_consecutive_failures: int, worker_index?: int} $config */
    public function __construct(
        private BrokerConnectionRepository $connectionRepo,
        private BrokerSyncService $syncService,
        private array $config,
    ) {}

    /**
     * Sync all due connections. Returns a run summary for logging.
     *
     * @return array{skipped: bool, total_active: int, processed: int, success: int, failed: int, deferred: int, already_syncing: int, deactivated: int, interval_minutes: int}
     */
    public function runDueConnections(): array
    {
        $intervalMinutes = (int) $this->config['sync_interval_minutes'];

        if (!$this->config['auto_sync_enabled']) {
            return [
                'skipped' => true,
                'total_active' => 0,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'deferred' => 0,
                'already_syncing' => 0,
                'deactivated' => 0,
                'interval_minutes' => $intervalMinutes,
            ];
        }

        $totalActive = $this->connectionRepo->countActive();
        $connections = $this->stagger($this->connectionRepo->findDueForAutoSync($intervalMinutes));

        $success = 0;
        $failed = 0;
        $deferred = 0;
        $alreadySyncing = 0;
        $deactivated = 0;
        $maxFailures = $this->config['max_consecutive_failures'];

        foreach ($connections as $conn) {
            $id = (int) $conn['id'];
            $userId = (int) $conn['user_id'];
            $previousFailures = (int) ($conn['consecutive_failures'] ?? 0);

            try {
                $result = $this->syncService->sync($id, $userId);

                // The connection was reserved by someone else — a manual sync,
                // or a sibling worker. No work was done, so it is neither a
                // success to reward nor a failure to punish: leave the failure
                // streak alone and let the next tick pick it up.
                if (($result['status'] ?? null) === SyncStatus::SKIPPED->value) {
                    $alreadySyncing++;
                    continue;
                }

                $this->connectionRepo->resetFailures($id);
                $success++;
            } catch (BrokerDailyBudgetException $e) {
                // Our own decision to stop asking, taken to protect the trading
                // account — not a defect of the connection. Same treatment as a
                // broker's frequency ban: leave the failure streak alone and
                // let the counter roll over at UTC midnight. Already logged
                // once by the sync service, with the spend and the cap.
                $deferred++;
            } catch (BrokerRateLimitException $e) {
                // A broker frequency ban is NOT a connection failure: the
                // connector paces requests to avoid it, and the next cron run
                // resumes once the ban lifts. Do NOT touch the failure counter
                // or trip the breaker — that would deactivate a perfectly
                // healthy connection just for being throttled. Leave the
                // failure streak as-is (a real prior failure stays pending).
                $deferred++;
            } catch (Throwable $e) {
                $this->connectionRepo->incrementFailures($id);
                $failed++;

                $streak = $previousFailures + 1;
                $trippedBreaker = $streak >= $maxFailures;

                if ($trippedBreaker) {
                    $this->connectionRepo->markError($id, $e->getMessage());
                    $deactivated++;
                }

                // Nothing but the counters used to survive this block. The
                // exception reached no one, so a connection failing on every
                // single pass stayed invisible in the container stream — the
                // shape observed in the test environment, where the cause could
                // only be read back from sync_logs. The streak and the breaker
                // go out with it: deactivation is the moment a user silently
                // stops being synced, and it is what one greps for.
                ErrorLogger::logThrowable('broker-sync', 'connection_sync_failed', $e, [
                    'connection_id' => $id,
                    'consecutive_failures' => $streak,
                    'deactivated' => $trippedBreaker,
                ]);
            }
        }

        return [
            'skipped' => false,
            'total_active' => $totalActive,
            'processed' => count($connections),
            'success' => $success,
            'failed' => $failed,
            'deferred' => $deferred,
            'already_syncing' => $alreadySyncing,
            'deactivated' => $deactivated,
            'interval_minutes' => $intervalMinutes,
        ];
    }

    /**
     * Rotate the due list by this worker's index.
     *
     * Every worker of a parallel run fetches the same list. Walking it in the
     * same order means they all pile onto the first connection, and each loser
     * burns a failed reservation on every entry before reaching free work.
     * Rotating spreads the starting points; it never skips anything, so a
     * worker that finds everything taken still walks the whole list.
     *
     * @param  array<int, array<string, mixed>> $connections
     * @return array<int, array<string, mixed>>
     */
    private function stagger(array $connections): array
    {
        $offset = (int) ($this->config['worker_index'] ?? 0);
        $count = count($connections);

        if ($offset <= 0 || $count === 0) {
            return $connections;
        }

        $offset %= $count;
        if ($offset === 0) {
            return $connections;
        }

        return array_merge(array_slice($connections, $offset), array_slice($connections, 0, $offset));
    }
}
