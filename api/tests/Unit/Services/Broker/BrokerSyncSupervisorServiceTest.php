<?php

namespace Tests\Unit\Services\Broker;

use App\Repositories\BrokerConnectionRepository;
use App\Services\Broker\BrokerSyncSupervisorService;
use App\Services\Process\ProcessPoolInterface;
use PHPUnit\Framework\TestCase;

class BrokerSyncSupervisorServiceTest extends TestCase
{
    private BrokerConnectionRepository $connectionRepo;
    private FakeProcessPool $pool;

    protected function setUp(): void
    {
        $this->connectionRepo = $this->createMock(BrokerConnectionRepository::class);
        $this->pool = new FakeProcessPool();
    }

    private function makeSupervisor(): BrokerSyncSupervisorService
    {
        return new BrokerSyncSupervisorService(
            $this->pool,
            $this->connectionRepo,
            '/usr/local/bin/php',
            '/app/api/cli/sync-brokers.php',
        );
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'auto_sync_enabled' => true,
            'sync_interval_minutes' => 15,
            'workers' => 4,
        ], $overrides);
    }

    /** A worker run that reports the given counters on stdout. */
    private function workerSummary(array $counters = []): array
    {
        return [
            'exit_code' => 0,
            'stdout' => json_encode(array_merge([
                'job' => 'broker-sync',
                'status' => 'ok',
                'role' => 'worker',
                'skipped' => false,
                'total_active' => 10,
                'processed' => 6,
                'success' => 0,
                'failed' => 0,
                'deferred' => 0,
                'already_syncing' => 0,
                'deactivated' => 0,
                'interval_minutes' => 15,
            ], $counters)),
            'stderr' => '',
        ];
    }

    // ── Nothing to do: never pay for a process ──────────────────

    public function testDisabledFlagSpawnsNoWorker(): void
    {
        $this->connectionRepo->expects($this->never())->method('countDueForAutoSync');

        $result = $this->makeSupervisor()->run($this->config(['auto_sync_enabled' => false]));

        $this->assertSame([], $this->pool->commands);
        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['workers']);
    }

    public function testNothingDueSpawnsNoWorker(): void
    {
        // Four PHP processes booted every minute to find nothing would cost
        // more than the work itself.
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(0);
        $this->connectionRepo->method('countActive')->willReturn(3);

        $result = $this->makeSupervisor()->run($this->config());

        $this->assertSame([], $this->pool->commands);
        $this->assertFalse($result['skipped']);
        $this->assertSame(0, $result['workers']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(3, $result['total_active']);
    }

    // ── Sizing the pool ─────────────────────────────────────────

    public function testSpawnsTheConfiguredNumberOfWorkers(): void
    {
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(10);
        $this->connectionRepo->method('countActive')->willReturn(10);
        $this->pool->results = array_fill(0, 3, $this->workerSummary());

        $result = $this->makeSupervisor()->run($this->config(['workers' => 3]));

        $this->assertCount(3, $this->pool->commands);
        $this->assertSame(3, $result['workers']);
    }

    public function testNeverSpawnsMoreWorkersThanThereIsWork(): void
    {
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(2);
        $this->connectionRepo->method('countActive')->willReturn(9);
        $this->pool->results = array_fill(0, 2, $this->workerSummary());

        $this->makeSupervisor()->run($this->config(['workers' => 8]));

        $this->assertCount(2, $this->pool->commands);
    }

    public function testClampsAnAbsurdWorkerCount(): void
    {
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(500);
        $this->connectionRepo->method('countActive')->willReturn(500);
        $this->pool->results = array_fill(0, 16, $this->workerSummary());

        $this->makeSupervisor()->run($this->config(['workers' => 999]));

        $this->assertCount(16, $this->pool->commands);
    }

    public function testFallsBackToASingleWorkerOnANonsenseCount(): void
    {
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(5);
        $this->connectionRepo->method('countActive')->willReturn(5);
        $this->pool->results = [$this->workerSummary()];

        $this->makeSupervisor()->run($this->config(['workers' => 0]));

        $this->assertCount(1, $this->pool->commands);
    }

    // ── What each child is told ─────────────────────────────────

    public function testEachWorkerIsGivenItsOwnIndex(): void
    {
        // The index is what staggers the scan: without it every worker walks
        // the due list in the same order and burns a claim attempt per entry
        // before finding free work.
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(10);
        $this->connectionRepo->method('countActive')->willReturn(10);
        $this->pool->results = array_fill(0, 3, $this->workerSummary());

        $this->makeSupervisor()->run($this->config(['workers' => 3]));

        foreach ($this->pool->commands as $i => $command) {
            $this->assertSame('/usr/local/bin/php', $command[0]);
            $this->assertSame('/app/api/cli/sync-brokers.php', $command[1]);
            $this->assertContains('--worker', $command);
            $this->assertContains("--worker-index={$i}", $command);
        }
    }

    // ── Aggregation ─────────────────────────────────────────────

    public function testSumsTheCountersAcrossWorkers(): void
    {
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(6);
        $this->connectionRepo->method('countActive')->willReturn(10);
        $this->pool->results = [
            $this->workerSummary(['success' => 3, 'already_syncing' => 2]),
            $this->workerSummary(['success' => 1, 'failed' => 1, 'deactivated' => 1, 'already_syncing' => 4]),
        ];

        $result = $this->makeSupervisor()->run($this->config(['workers' => 2]));

        $this->assertSame(4, $result['success']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['deactivated']);
        $this->assertSame(6, $result['already_syncing']);
    }

    public function testProcessedIsTheDueListNotTheSumOfWorkerViews(): void
    {
        // Every worker sees the same due list, so summing `processed` would
        // report six connections as twelve.
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(6);
        $this->connectionRepo->method('countActive')->willReturn(10);
        $this->pool->results = [
            $this->workerSummary(['processed' => 6]),
            $this->workerSummary(['processed' => 6]),
        ];

        $result = $this->makeSupervisor()->run($this->config(['workers' => 2]));

        $this->assertSame(6, $result['processed']);
    }

    public function testCountsAWorkerThatDiedOrPrintedGarbage(): void
    {
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(6);
        $this->connectionRepo->method('countActive')->willReturn(10);
        $this->pool->results = [
            $this->workerSummary(['success' => 2]),
            ['exit_code' => 255, 'stdout' => '', 'stderr' => 'PHP Fatal error: out of memory'],
            ['exit_code' => 0, 'stdout' => 'not json at all', 'stderr' => ''],
        ];

        $result = $this->makeSupervisor()->run($this->config(['workers' => 3]));

        $this->assertSame(2, $result['success']);
        $this->assertSame(2, $result['worker_errors']);
    }

    public function testReadsTheSummaryEvenWhenTheChildAlsoPrintedNoise(): void
    {
        // A PHP notice ahead of the JSON line must not lose the whole run.
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(6);
        $this->connectionRepo->method('countActive')->willReturn(10);
        $summary = $this->workerSummary(['success' => 5]);
        $summary['stdout'] = "Deprecated: something\n" . $summary['stdout'] . "\n";
        $this->pool->results = [$summary];

        $result = $this->makeSupervisor()->run($this->config(['workers' => 1]));

        $this->assertSame(5, $result['success']);
        $this->assertSame(0, $result['worker_errors']);
    }

    public function testReportsHowLongTheRunTook(): void
    {
        // The number that warns before a run starts overflowing its interval.
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(1);
        $this->connectionRepo->method('countActive')->willReturn(1);
        $this->pool->results = [$this->workerSummary()];

        $result = $this->makeSupervisor()->run($this->config(['workers' => 1]));

        $this->assertIsInt($result['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $result['duration_ms']);
    }
}

/** Records the commands it was asked to run and replays canned results. */
class FakeProcessPool implements ProcessPoolInterface
{
    /** @var array<int, string[]> */
    public array $commands = [];

    /** @var array<int, array{exit_code: int, stdout: string, stderr: string}> */
    public array $results = [];

    public function runConcurrently(array $commands): array
    {
        $this->commands = $commands;

        return $this->results;
    }
}
