<?php

namespace Tests\Unit\Core;

use App\Core\ErrorLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorLoggerTest extends TestCase
{
    /**
     * ErrorLogger writes through error_log(), the same portable stderr-ish
     * sink BrokerLogger uses. Point it at a file for the duration of the call
     * and read back what landed.
     *
     * Writing to a FILE makes error_log() prepend "[date UTC] " to every line —
     * a prefix that does not exist when the same call goes to stderr, which is
     * where it goes in production. Strip it, along with the trailing \r left by
     * Windows line endings.
     *
     * @return list<string>
     */
    private function captureErrorLog(callable $run): array
    {
        $file = tempnam(sys_get_temp_dir(), 'errorlog');
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

    /** @return array<string, mixed> */
    private function logOne(\Throwable $e, array $context = []): array
    {
        $lines = $this->captureErrorLog(
            fn() => ErrorLogger::logThrowable('api', 'unhandled_exception', $e, $context),
        );

        $this->assertCount(1, $lines, 'one throwable must produce exactly one line');

        $decoded = json_decode($lines[0], true);
        $this->assertIsArray($decoded, 'the line must be valid JSON: ' . $lines[0]);

        return $decoded;
    }

    // ── What an unhandled throwable has to leave behind ─────────────

    public function testWritesTheIdentityOfTheThrowable(): void
    {
        // The defect this closes: index.php caught every Throwable and wrote
        // nothing at all, so a 500 in production was lost for good. It took
        // replaying the call with APP_DEBUG=true to find out that a GROUP BY
        // was rejected by MySQL — a fix that cost days because the cause never
        // reached the container stream.
        $e = new RuntimeException('boom');
        $entry = $this->logOne($e);

        $this->assertSame('api', $entry['job']);
        $this->assertSame('unhandled_exception', $entry['event']);
        $this->assertSame(RuntimeException::class, $entry['class']);
        $this->assertSame('boom', $entry['message']);
        $this->assertSame($e->getFile(), $entry['file']);
        $this->assertSame($e->getLine(), $entry['line']);
        $this->assertArrayHasKey('ts', $entry);
    }

    public function testCarriesTheCallerContext(): void
    {
        $entry = $this->logOne(new RuntimeException('boom'), [
            'method' => 'GET',
            'path' => '/stats/daily',
        ]);

        $this->assertSame('GET', $entry['method']);
        $this->assertSame('/stats/daily', $entry['path']);
    }

    public function testReportsTheCallSiteChainAsATrace(): void
    {
        $entry = $this->logOne($this->throwFromANestedCall());

        $this->assertIsArray($entry['trace']);
        $this->assertNotEmpty($entry['trace']);
        $this->assertStringContainsString('throwFromANestedCall', implode("\n", $entry['trace']));
    }

    // ── A log line must never become a credential leak ──────────────

    public function testNeverWritesTheArgumentsOfATraceFrame(): void
    {
        // getTraceAsString() renders call arguments inline, so a password
        // passed to a method lands in the log the moment an exception crosses
        // it. PHP hides them by default (zend.exception_ignore_args=1), but a
        // container that flips that INI setting must not turn our error log
        // into a credential dump — so the frames are rebuilt by hand instead
        // of trusting the runtime.
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $e = $this->throwWithASecretArgument('hunter2-do-not-log');
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }

        $entry = $this->logOne($e);

        $this->assertStringNotContainsString(
            'hunter2-do-not-log',
            json_encode($entry),
            'call arguments must never reach the log',
        );
    }

    public function testCapsTheNumberOfFramesItKeeps(): void
    {
        // A deep stack must not flood the container stream, and Railway drops
        // over-long lines outright — a truncated line is a lost diagnostic.
        $entry = $this->logOne($this->throwFromDepth(60));

        $this->assertLessThanOrEqual(ErrorLogger::MAX_TRACE_FRAMES, count($entry['trace']));
        $this->assertArrayHasKey('trace_dropped', $entry);
        $this->assertGreaterThan(0, $entry['trace_dropped']);
    }

    public function testKeepsEveryFrameOfAShallowStackAndSaysNothingWasDropped(): void
    {
        $entry = $this->logOne(new RuntimeException('boom'));

        $this->assertArrayNotHasKey('trace_dropped', $entry);
    }

    // ── The cause usually hides in the previous exception ───────────

    public function testUnwrapsThePreviousExceptions(): void
    {
        // A PDOException wrapped in a domain exception is the common shape:
        // the wrapper says "stats failed", the cause says which SQL and why.
        $root = new RuntimeException('SQLSTATE[42000]: rejected by ONLY_FULL_GROUP_BY');
        $wrapper = new RuntimeException('daily pnl failed', 0, $root);

        $entry = $this->logOne($wrapper);

        $this->assertSame('daily pnl failed', $entry['message']);
        $this->assertCount(1, $entry['previous']);
        $this->assertSame(
            'SQLSTATE[42000]: rejected by ONLY_FULL_GROUP_BY',
            $entry['previous'][0]['message'],
        );
        $this->assertSame(RuntimeException::class, $entry['previous'][0]['class']);
    }

    public function testStopsUnwrappingAtAReasonableDepth(): void
    {
        $e = new RuntimeException('root');
        for ($i = 0; $i < 10; $i++) {
            $e = new RuntimeException("wrap {$i}", 0, $e);
        }

        $entry = $this->logOne($e);

        $this->assertLessThanOrEqual(ErrorLogger::MAX_PREVIOUS, count($entry['previous']));
    }

    // ── The logged path must not become a secret leak ───────────────

    public function testDropsTheQueryStringOfAPath(): void
    {
        // Share keys, one-time email tokens and reset codes travel there.
        $this->assertSame('/stats/daily', ErrorLogger::redactPath('/stats/daily?from=2026-01-01&token=abc'));
    }

    public function testRedactsTheTradingViewWebhookToken(): void
    {
        // That token is not an identifier, it is the credential that lets a
        // caller place orders. It sits in the path, so stripping the query
        // string is not enough.
        $this->assertSame(
            '/webhooks/tradingview/***',
            ErrorLogger::redactPath('/webhooks/tradingview/9f3c1d55-secret'),
        );
    }

    public function testRedactsTheWebhookTokenBehindTheApiPrefix(): void
    {
        // Apache serves the API under an /api alias locally and the router
        // strips it; the raw REQUEST_URI still carries it.
        $this->assertSame(
            '/api/webhooks/tradingview/***',
            ErrorLogger::redactPath('/api/webhooks/tradingview/9f3c1d55-secret?x=1'),
        );
    }

    public function testLeavesAnOrdinaryPathAlone(): void
    {
        // Numeric ids are what makes a log line useful. Redacting everything
        // would trade one defect for another.
        $this->assertSame('/trades/1042/close', ErrorLogger::redactPath('/trades/1042/close'));
    }

    public function testHandlesAMissingUri(): void
    {
        $this->assertSame('', ErrorLogger::redactPath(null));
        $this->assertSame('', ErrorLogger::redactPath(''));
    }

    // ── The client response must stay untouched ─────────────────────

    public function testWritesNothingToStandardOutput(): void
    {
        // index.php calls this while building an HTTP response: a stray echo
        // would land in the JSON body sent to the client.
        $this->expectOutputString('');

        $this->captureErrorLog(
            fn() => ErrorLogger::logThrowable('api', 'unhandled_exception', new RuntimeException('boom')),
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────

    private function throwFromANestedCall(): \Throwable
    {
        try {
            throw new RuntimeException('boom');
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private function throwWithASecretArgument(string $secret): \Throwable
    {
        try {
            throw new RuntimeException('boom');
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private function throwFromDepth(int $depth): \Throwable
    {
        if ($depth <= 0) {
            try {
                throw new RuntimeException('deep');
            } catch (\Throwable $e) {
                return $e;
            }
        }

        return $this->throwFromDepth($depth - 1);
    }
}
