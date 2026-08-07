<?php

namespace App\Services\Broker;

use App\Enums\SyncStatus;
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
    /** @param array{auto_sync_enabled: bool, sync_interval_minutes: int, max_consecutive_failures: int} $config */
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
        $connections = $this->connectionRepo->findDueForAutoSync($intervalMinutes);

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

                if ($previousFailures + 1 >= $maxFailures) {
                    $this->connectionRepo->markError($id, $e->getMessage());
                    $deactivated++;
                }
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
}
