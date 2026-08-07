<?php

namespace Tests\Unit\Services\Process;

use App\Services\Process\ProcOpenProcessPool;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real proc_open path with trivial PHP one-liners — no broker,
 * no database. The supervisor's own tests use a fake pool, so this is the only
 * place the process handling itself is checked.
 */
class ProcOpenProcessPoolTest extends TestCase
{
    private function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    public function testReturnsNothingForNoCommands(): void
    {
        $this->assertSame([], (new ProcOpenProcessPool())->runConcurrently([]));
    }

    public function testCapturesStdoutOfEveryChildInOrder(): void
    {
        $results = (new ProcOpenProcessPool())->runConcurrently([
            $this->php('echo "first";'),
            $this->php('echo "second";'),
            $this->php('echo "third";'),
        ]);

        $this->assertCount(3, $results);
        $this->assertSame([0, 1, 2], array_keys($results));
        $this->assertStringContainsString('first', $results[0]['stdout']);
        $this->assertStringContainsString('second', $results[1]['stdout']);
        $this->assertStringContainsString('third', $results[2]['stdout']);
    }

    public function testResultsKeepTheInputOrderNotTheFinishingOrder(): void
    {
        // The slow child is first. If we returned results as they completed,
        // the supervisor would attribute a worker's counters to another.
        $results = (new ProcOpenProcessPool())->runConcurrently([
            $this->php('usleep(300000); echo "slow";'),
            $this->php('echo "fast";'),
        ]);

        $this->assertStringContainsString('slow', $results[0]['stdout']);
        $this->assertStringContainsString('fast', $results[1]['stdout']);
    }

    public function testChildrenRunConcurrentlyNotOneAfterTheOther(): void
    {
        $startedAt = microtime(true);

        (new ProcOpenProcessPool())->runConcurrently([
            $this->php('usleep(500000);'),
            $this->php('usleep(500000);'),
            $this->php('usleep(500000);'),
        ]);

        // Three half-second sleeps: sequential would be 1.5 s. Generous bound
        // so a loaded CI box doesn't turn this into a flaky test.
        $this->assertLessThan(1.4, microtime(true) - $startedAt);
    }

    public function testReportsANonZeroExitCodeAndStderr(): void
    {
        // A failed child is a result, not an exception: one worker dying must
        // not lose the counters of the others.
        $results = (new ProcOpenProcessPool())->runConcurrently([
            $this->php('fwrite(STDERR, "boom"); exit(3);'),
        ]);

        $this->assertSame(3, $results[0]['exit_code']);
        $this->assertStringContainsString('boom', $results[0]['stderr']);
    }

    public function testSurvivesAChildThatWritesMoreThanAPipeBuffer(): void
    {
        // Without draining the pipes while waiting, a child writing past the
        // OS buffer blocks forever and the pool never returns.
        $results = (new ProcOpenProcessPool())->runConcurrently([
            $this->php('echo str_repeat("x", 200000);'),
        ]);

        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame(200000, strlen(trim($results[0]['stdout'])));
    }
}
