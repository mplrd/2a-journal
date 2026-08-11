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

    /**
     * BrokerLogger and the forwarding both write through error_log(), the
     * portable stderr-ish sink. Point it at a file for the duration of the
     * call and read back what landed.
     *
     * Writing to a FILE makes error_log() prepend "[date UTC] " to every line —
     * a prefix that does not exist when the same call goes to stderr, which is
     * where it goes in production. Strip it.
     *
     * The trailing \r goes too: the file is written with Windows line endings,
     * and splitting on "\n" alone leaves a carriage return that no terminal
     * shows and that makes an exact comparison fail on lines which look
     * identical.
     *
     * @return list<string>
     */
    private function captureErrorLog(callable $run): array
    {
        $file = tempnam(sys_get_temp_dir(), 'supervisorlog');
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

    // ── A worker's diagnostics have to reach the container stream ───

    public function testForwardsTheDiagnosticsOfAWorkerThatSucceeded(): void
    {
        // The defect this closes: the pool captures each child's stderr so the
        // summary can be read off stdout, and a successful child's capture was
        // then dropped on the floor. Everything a connector reported during a
        // working sync — take_profit_levels_unresolved, the cTrader request
        // budget, every warning — was written correctly and reached nobody.
        // Verified against the test environment on 2026-08-11: 360 scheduler
        // log lines over six hours, not one of them from a worker.
        $this->connectionRepo->method('countActive')->willReturn(2);
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(2);

        $diagnostic = '{"job":"ctrader","event":"take_profit_levels_unresolved","orders_in_payload":4}';
        $this->pool->results = [
            array_merge($this->workerSummary(), ['stderr' => $diagnostic]),
        ];

        $lines = $this->captureErrorLog(
            fn() => $this->makeSupervisor()->run($this->config(['workers' => 1])),
        );

        $this->assertContains($diagnostic, $lines);
    }

    public function testForwardsEveryLineAndNamesTheWorkerItCameFrom(): void
    {
        // Several workers run at once, so a line nobody can attribute is half
        // a diagnostic. The origin goes on its own line rather than inside the
        // child's, which must stay byte-for-byte what the connector wrote.
        $this->connectionRepo->method('countActive')->willReturn(4);
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(4);

        $this->pool->results = [
            array_merge($this->workerSummary(), ['stderr' => "first line\nsecond line"]),
            array_merge($this->workerSummary(), ['stderr' => 'from the other one']),
        ];

        $lines = $this->captureErrorLog(
            fn() => $this->makeSupervisor()->run($this->config(['workers' => 2])),
        );

        $this->assertContains('first line', $lines);
        $this->assertContains('second line', $lines);
        $this->assertContains('from the other one', $lines);
        $this->assertNotEmpty(array_filter($lines, fn($l) => str_contains($l, 'worker_index')));
    }

    public function testStaysSilentForAWorkerThatWroteNothing(): void
    {
        // A quiet run is the nominal case: it must not add a line per worker
        // to a log that already ticks every minute.
        $this->connectionRepo->method('countActive')->willReturn(2);
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(2);

        $this->pool->results = [$this->workerSummary()];

        $lines = $this->captureErrorLog(
            fn() => $this->makeSupervisor()->run($this->config(['workers' => 1])),
        );

        $this->assertSame([], $lines);
    }

    public function testStillForwardsTheDiagnosticsOfAWorkerThatDied(): void
    {
        // A dead child's stderr was the one case already reported, truncated
        // to 2000 characters because it was the only trace left. It travels
        // the same path as the rest now, and the failure line stays for the
        // exit code.
        $this->connectionRepo->method('countActive')->willReturn(2);
        $this->connectionRepo->method('countDueForAutoSync')->willReturn(2);

        $this->pool->results = [
            ['exit_code' => 255, 'stdout' => '', 'stderr' => 'PHP Fatal error: out of memory'],
        ];

        $lines = $this->captureErrorLog(
            fn() => $this->makeSupervisor()->run($this->config(['workers' => 1])),
        );

        $this->assertContains('PHP Fatal error: out of memory', $lines);
        $this->assertNotEmpty(array_filter($lines, fn($l) => str_contains($l, 'worker_failed')));
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
