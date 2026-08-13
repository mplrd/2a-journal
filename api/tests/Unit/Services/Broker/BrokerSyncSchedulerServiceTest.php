<?php

namespace Tests\Unit\Services\Broker;

use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Repositories\BrokerConnectionRepository;
use App\Services\Broker\BrokerSyncSchedulerService;
use App\Services\Broker\BrokerSyncService;
use App\Exceptions\BrokerRateLimitException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BrokerSyncSchedulerServiceTest extends TestCase
{
    private BrokerConnectionRepository $connectionRepo;
    private BrokerSyncService $syncService;

    protected function setUp(): void
    {
        $this->connectionRepo = $this->createMock(BrokerConnectionRepository::class);
        $this->syncService = $this->createMock(BrokerSyncService::class);
    }

    private function makeScheduler(array $configOverrides = []): BrokerSyncSchedulerService
    {
        $config = array_merge([
            'auto_sync_enabled' => true,
            'sync_interval_minutes' => 15,
            'max_consecutive_failures' => 3,
        ], $configOverrides);

        return new BrokerSyncSchedulerService(
            $this->connectionRepo,
            $this->syncService,
            $config
        );
    }

    private function connectionRow(int $id, int $userId, int $consecutiveFailures = 0): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'account_id' => 1000 + $id,
            'provider' => BrokerProvider::METAAPI->value,
            'status' => ConnectionStatus::ACTIVE->value,
            'consecutive_failures' => $consecutiveFailures,
            'last_sync_at' => null,
        ];
    }

    // ── No-op when disabled ─────────────────────────────────────

    public function testReturnsSkippedWhenAutoSyncDisabled(): void
    {
        $scheduler = $this->makeScheduler(['auto_sync_enabled' => false]);

        $this->connectionRepo->expects($this->never())->method('findDueForAutoSync');
        $this->connectionRepo->expects($this->never())->method('countActive');
        $this->syncService->expects($this->never())->method('sync');

        $result = $scheduler->runDueConnections();

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['deactivated']);
        $this->assertSame(0, $result['total_active']);
        $this->assertSame(15, $result['interval_minutes']);
    }

    // ── Happy path: all succeed ─────────────────────────────────

    public function testAllConnectionsSucceedResetsFailures(): void
    {
        $scheduler = $this->makeScheduler();

        $conn1 = $this->connectionRow(1, 10);
        $conn2 = $this->connectionRow(2, 20);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$conn1, $conn2]);
        $this->connectionRepo->method('countActive')->willReturn(2);

        $this->syncService->expects($this->exactly(2))->method('sync');

        $this->connectionRepo->expects($this->exactly(2))->method('resetFailures');
        $this->connectionRepo->expects($this->never())->method('incrementFailures');
        $this->connectionRepo->expects($this->never())->method('markError');

        $result = $scheduler->runDueConnections();

        $this->assertFalse($result['skipped']);
        $this->assertSame(2, $result['total_active']);
        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['success']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['deactivated']);
        $this->assertSame(15, $result['interval_minutes']);
    }

    // ── Mix: 1 ok + 1 non-fatal fail ────────────────────────────

    public function testMixedResultIncrementsOnlyFailedAndKeepsOthersSynced(): void
    {
        $scheduler = $this->makeScheduler();

        $connOk = $this->connectionRow(1, 10, 0);
        $connKo = $this->connectionRow(2, 20, 0); // first failure

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$connOk, $connKo]);
        $this->connectionRepo->method('countActive')->willReturn(5); // 2 due + 3 too-recent

        $this->syncService->method('sync')->willReturnCallback(function (int $id) {
            if ($id === 2) {
                throw new RuntimeException('broker timeout');
            }
            return ['status' => 'SUCCESS'];
        });

        $this->connectionRepo->expects($this->once())->method('resetFailures')->with(1);
        $this->connectionRepo->expects($this->once())->method('incrementFailures')->with(2);
        $this->connectionRepo->expects($this->never())->method('markError');

        // A failure now writes a diagnostic line; swallow it so it does not
        // land in PHPUnit's own output, where it would read as an error.
        $result = null;
        $this->captureErrorLog(function () use ($scheduler, &$result) {
            $result = $scheduler->runDueConnections();
        });

        $this->assertSame(5, $result['total_active']);
        $this->assertSame(2, $result['processed']);
        $this->assertSame(1, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['deactivated']);
    }

    // ── Circuit breaker: 3rd consecutive failure deactivates ───

    public function testReachingMaxConsecutiveFailuresMarksError(): void
    {
        $scheduler = $this->makeScheduler();

        // Connection already has 2 failures, this call will be the 3rd
        $connKo = $this->connectionRow(7, 10, 2);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$connKo]);
        $this->connectionRepo->method('countActive')->willReturn(1);

        $this->syncService->method('sync')->willThrowException(new RuntimeException('oauth expired'));

        $this->connectionRepo->expects($this->once())->method('incrementFailures')->with(7);
        $this->connectionRepo->expects($this->once())
            ->method('markError')
            ->with(7, $this->stringContains('oauth expired'));

        $result = null;
        $this->captureErrorLog(function () use ($scheduler, &$result) {
            $result = $scheduler->runDueConnections();
        });

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['deactivated']);
    }

    // ── Rate-limit deferral is NOT a failure ───────────────────

    public function testRateLimitExceptionDefersWithoutTrippingBreaker(): void
    {
        $scheduler = $this->makeScheduler();

        // Already at the failure threshold: a NORMAL exception here would
        // deactivate. A rate-limit deferral must not — the next cron resumes
        // once BingX's frequency ban lifts.
        $connRl = $this->connectionRow(9, 30, 2);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$connRl]);
        $this->connectionRepo->method('countActive')->willReturn(1);

        $this->syncService->method('sync')->willThrowException(
            new BrokerRateLimitException('throttled', 1781512502872, '/openApi/swap/v2/trade/allOrders')
        );

        $this->connectionRepo->expects($this->never())->method('incrementFailures');
        $this->connectionRepo->expects($this->never())->method('markError');
        $this->connectionRepo->expects($this->never())->method('resetFailures');

        $result = $scheduler->runDueConnections();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['deactivated']);
        $this->assertSame(1, $result['deferred']);
    }

    // ── Spending the daily budget is a pause, not a fault ───────

    public function testDailyBudgetExhaustionDefersWithoutTrippingBreaker(): void
    {
        // Refusing to sync is our own decision, taken to protect the trading
        // account — FTMO disabled a real one for request volume. Counting it as
        // a failure would eventually deactivate a perfectly healthy connection
        // for doing exactly what it was told.
        $scheduler = $this->makeScheduler();

        // Already at the failure threshold: a normal exception here would
        // deactivate.
        $conn = $this->connectionRow(4, 20, 2);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$conn]);
        $this->connectionRepo->method('countActive')->willReturn(1);
        $this->syncService->method('sync')->willThrowException(
            new \App\Exceptions\BrokerDailyBudgetException('Daily request budget spent (1500/1500)', 1500, 1500)
        );

        $this->connectionRepo->expects($this->never())->method('incrementFailures');
        $this->connectionRepo->expects($this->never())->method('markError');
        $this->connectionRepo->expects($this->never())->method('resetFailures');

        $lines = $this->captureErrorLog(function () use ($scheduler, &$result) {
            $result = $scheduler->runDueConnections();
        });

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['deactivated']);
        $this->assertSame(1, $result['deferred']);

        // And it must not be logged as a connection failure either.
        $this->assertSame([], $lines);
    }

    // ── Already syncing: reservation held elsewhere ─────────────

    public function testConnectionAlreadySyncingIsCountedApart(): void
    {
        // A worker that finds the connection reserved has done no work: it is
        // neither a success (nothing was imported) nor a failure (nothing broke).
        $scheduler = $this->makeScheduler();

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$this->connectionRow(1, 10)]);
        $this->connectionRepo->method('countActive')->willReturn(1);
        $this->syncService->method('sync')->willReturn(['status' => \App\Enums\SyncStatus::SKIPPED->value]);

        $this->connectionRepo->expects($this->never())->method('resetFailures');
        $this->connectionRepo->expects($this->never())->method('incrementFailures');

        $result = $scheduler->runDueConnections();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['already_syncing']);
        $this->assertSame(0, $result['success']);
        $this->assertSame(0, $result['failed']);
    }

    // ── Worker offset: staggering the scan ──────────────────────

    public function testWorkerOffsetStartsTheScanFurtherDownTheDueList(): void
    {
        // Every worker fetches the same due list. Without a per-worker offset
        // they all pile onto connection #1, and each one burns a failed claim
        // per entry before reaching free work.
        $scheduler = $this->makeScheduler(['worker_index' => 2]);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([
            $this->connectionRow(1, 10),
            $this->connectionRow(2, 20),
            $this->connectionRow(3, 30),
            $this->connectionRow(4, 40),
        ]);
        $this->connectionRepo->method('countActive')->willReturn(4);

        $order = [];
        $this->syncService->method('sync')->willReturnCallback(function (int $id) use (&$order) {
            $order[] = $id;
            return [];
        });

        $result = $scheduler->runDueConnections();

        $this->assertSame([3, 4, 1, 2], $order);
        // Rotating is not skipping: every due connection is still attempted.
        $this->assertSame(4, $result['processed']);
    }

    public function testWorkerOffsetLargerThanTheListWrapsAround(): void
    {
        $scheduler = $this->makeScheduler(['worker_index' => 7]);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([
            $this->connectionRow(1, 10),
            $this->connectionRow(2, 20),
        ]);
        $this->connectionRepo->method('countActive')->willReturn(2);

        $order = [];
        $this->syncService->method('sync')->willReturnCallback(function (int $id) use (&$order) {
            $order[] = $id;
            return [];
        });

        $scheduler->runDueConnections();

        $this->assertSame([2, 1], $order);
    }

    public function testDefaultsToNoOffsetWhenRunningAlone(): void
    {
        $scheduler = $this->makeScheduler();

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([
            $this->connectionRow(1, 10),
            $this->connectionRow(2, 20),
        ]);
        $this->connectionRepo->method('countActive')->willReturn(2);

        $order = [];
        $this->syncService->method('sync')->willReturnCallback(function (int $id) use (&$order) {
            $order[] = $id;
            return [];
        });

        $scheduler->runDueConnections();

        $this->assertSame([1, 2], $order);
    }

    // ── Empty: nothing due ──────────────────────────────────────

    public function testNothingDueReturnsZeros(): void
    {
        $scheduler = $this->makeScheduler();

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([]);
        $this->connectionRepo->method('countActive')->willReturn(0);
        $this->syncService->expects($this->never())->method('sync');

        $result = $scheduler->runDueConnections();

        $this->assertFalse($result['skipped']);
        $this->assertSame(0, $result['total_active']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(15, $result['interval_minutes']);
    }

    // ── Interval from config reflected in output ────────────────

    public function testIntervalMinutesReflectsConfig(): void
    {
        $scheduler = $this->makeScheduler(['sync_interval_minutes' => 42]);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([]);
        $this->connectionRepo->method('countActive')->willReturn(0);

        $result = $scheduler->runDueConnections();

        $this->assertSame(42, $result['interval_minutes']);
    }

    // ── A failed connection has to say why ──────────────────────

    public function testLogsTheCauseOfAConnectionThatFailedToSync(): void
    {
        // The defect this closes: the catch below only bumped a counter. The
        // exception message went nowhere, so a connection failing on every
        // single pass was invisible in the container stream — observed in the
        // test environment, where a cTrader connection logged a FAILED sync
        // every 20 minutes and the reason could only be read from the database.
        $scheduler = $this->makeScheduler();

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$this->connectionRow(20, 2)]);
        $this->connectionRepo->method('countActive')->willReturn(1);
        $this->syncService->method('sync')->willThrowException(
            new RuntimeException('cTrader token refresh failed')
        );

        $lines = $this->captureErrorLog(fn() => $scheduler->runDueConnections());

        $this->assertCount(1, $lines);
        $entry = json_decode($lines[0], true);

        $this->assertSame('broker-sync', $entry['job']);
        $this->assertSame('connection_sync_failed', $entry['event']);
        $this->assertSame(20, $entry['connection_id']);
        $this->assertSame('cTrader token refresh failed', $entry['message']);
        $this->assertSame(RuntimeException::class, $entry['class']);
    }

    public function testSaysWhetherTheFailureTrippedTheBreaker(): void
    {
        // Deactivation is the consequence worth grepping for: it is the moment
        // a user silently stops being synced.
        $scheduler = $this->makeScheduler(['max_consecutive_failures' => 3]);

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$this->connectionRow(7, 2, 2)]);
        $this->connectionRepo->method('countActive')->willReturn(1);
        $this->syncService->method('sync')->willThrowException(new RuntimeException('oauth expired'));

        $lines = $this->captureErrorLog(fn() => $scheduler->runDueConnections());
        $entry = json_decode($lines[0], true);

        $this->assertTrue($entry['deactivated']);
        $this->assertSame(3, $entry['consecutive_failures']);
    }

    public function testWritesNothingWhenEveryConnectionSucceeds(): void
    {
        // This runs every minute. A nominal pass must not add a single line.
        $scheduler = $this->makeScheduler();

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$this->connectionRow(1, 10)]);
        $this->connectionRepo->method('countActive')->willReturn(1);
        $this->syncService->method('sync')->willReturn([]);

        $lines = $this->captureErrorLog(fn() => $scheduler->runDueConnections());

        $this->assertSame([], $lines);
    }

    public function testDoesNotLogARateLimitDeferralAsAFailure(): void
    {
        // A frequency ban is expected pacing, not a defect: logging it as a
        // failure every pass would train the reader to ignore the channel.
        $scheduler = $this->makeScheduler();

        $this->connectionRepo->method('findDueForAutoSync')->willReturn([$this->connectionRow(1, 10)]);
        $this->connectionRepo->method('countActive')->willReturn(1);
        $this->syncService->method('sync')->willThrowException(
            new BrokerRateLimitException('throttled', 1781512502872, '/openApi/swap/v2/trade/allOrders')
        );

        $lines = $this->captureErrorLog(fn() => $scheduler->runDueConnections());

        $this->assertSame([], $lines);
    }

    /**
     * ErrorLogger writes through error_log(). Point it at a file for the
     * duration of the call and read back what landed, stripping the
     * "[date UTC] " prefix error_log() adds when the sink is a file (it does
     * not when the sink is stderr, which is production) and the trailing \r
     * left by Windows line endings.
     *
     * @return list<string>
     */
    private function captureErrorLog(callable $run): array
    {
        $file = tempnam(sys_get_temp_dir(), 'schedulerlog');
        $previous = ini_get('error_log');
        ini_set('error_log', $file);

        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $contents = file_get_contents($file) ?: '';
        unlink($file);

        return array_values(array_map(
            fn($l) => preg_replace('/^\[[^\]]+\]\s*/', '', rtrim($l, "\r")),
            array_filter(explode("\n", $contents), fn($l) => trim($l) !== ''),
        ));
    }
}
