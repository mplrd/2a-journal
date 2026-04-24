<?php

namespace App\Services\Broker;

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
     * @return array{skipped: bool, processed: int, success: int, failed: int, deactivated: int}
     */
    public function runDueConnections(): array
    {
        if (!$this->config['auto_sync_enabled']) {
            return [
                'skipped' => true,
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'deactivated' => 0,
            ];
        }

        $connections = $this->connectionRepo->findDueForAutoSync(
            $this->config['sync_interval_minutes']
        );

        $success = 0;
        $failed = 0;
        $deactivated = 0;
        $maxFailures = $this->config['max_consecutive_failures'];

        foreach ($connections as $conn) {
            $id = (int) $conn['id'];
            $userId = (int) $conn['user_id'];
            $previousFailures = (int) ($conn['consecutive_failures'] ?? 0);

            try {
                $this->syncService->sync($id, $userId);
                $this->connectionRepo->resetFailures($id);
                $success++;
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
            'processed' => count($connections),
            'success' => $success,
            'failed' => $failed,
            'deactivated' => $deactivated,
        ];
    }
}
