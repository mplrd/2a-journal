<?php

namespace App\Core;

use Throwable;

/**
 * Turns an unhandled throwable into one JSON line on stderr.
 *
 * The gap this fills: `public/index.php` caught every `Throwable`, answered
 * `INTERNAL_ERROR` and wrote nothing — not to error_log, not to BrokerLogger.
 * With APP_DEBUG off, which is production, the exception was gone for good. A
 * daily-P&L endpoint answered 500 for days across production and the test
 * environment while 1721 tests stayed green, and the only way to learn why was
 * to replay the call with APP_DEBUG=true — a mode that hands the cause to the
 * *client*, so it can never be left on.
 *
 * The client response deliberately does not change: it stays generic, with no
 * detail, which is the right property. What was missing is the server-side
 * record, and that is all this adds.
 *
 * Same shape and same sink as {@see \App\Services\Broker\BrokerLogger}, so one
 * grep recipe covers both. `error_log()` is the portable stderr-ish sink: under
 * the CLI it goes to STDERR, under an HTTP SAPI it lands in the SAPI error log,
 * which Railway captures into the container stream. STDERR-the-constant only
 * exists in the CLI SAPI and would fatal under HTTP.
 */
class ErrorLogger
{
    /**
     * Frames kept per throwable.
     *
     * A runaway recursion produces thousands, and an over-long line is worse
     * than a short one: log shippers truncate it, and a truncated JSON line
     * parses as nothing at all. Twenty frames reach the application code that
     * matters in every stack seen so far.
     */
    public const MAX_TRACE_FRAMES = 20;

    /** Wrapped causes followed. Three levels past the wrapper is already generous. */
    public const MAX_PREVIOUS = 3;

    /**
     * Route prefixes whose next path segment is a credential, not an
     * identifier.
     *
     * `/webhooks/tradingview/{token}` is the one case in the router: that token
     * is what authorises a caller to place orders, and it travels in the path,
     * so dropping the query string is not enough. Every other route parameter
     * is a numeric id, and those are exactly what makes a log line worth
     * reading — redacting them wholesale would trade one defect for another.
     *
     * Matched anywhere in the path, not only at its start: Apache serves the
     * API under an `/api` alias locally and the router strips it, but the raw
     * REQUEST_URI still carries it.
     */
    private const SECRET_PATH_PREFIXES = ['/webhooks/tradingview/'];

    /** @param array<string, mixed> $context */
    public static function logThrowable(string $job, string $event, Throwable $e, array $context = []): void
    {
        $entry = array_merge(
            [
                'job' => $job,
                'event' => $event,
                'ts' => gmdate('Y-m-d\TH:i:s\Z'),
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ],
            self::trace($e),
            self::previous($e),
            $context,
        );

        // A message carrying invalid UTF-8 — a binary blob echoed back by a
        // driver, say — would make json_encode() return false and cost us the
        // whole line. Substituting is worth more than losing the diagnostic.
        error_log((string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Turn a raw REQUEST_URI into something safe to write down: no query
     * string, and no credential carried by the path itself.
     */
    public static function redactPath(?string $uri): string
    {
        $path = strtok((string) $uri, '?');
        if ($path === false || $path === '') {
            return '';
        }

        foreach (self::SECRET_PATH_PREFIXES as $prefix) {
            $at = strpos($path, $prefix);
            if ($at !== false) {
                return substr($path, 0, $at + strlen($prefix)) . '***';
            }
        }

        return $path;
    }

    /**
     * Rebuild the stack by hand rather than calling getTraceAsString().
     *
     * That helper renders call arguments inline, so a password handed to a
     * method ends up in the log the moment an exception crosses it. PHP hides
     * arguments by default (`zend.exception_ignore_args=1`), but the setting is
     * writable and a container that flips it must not turn the error log into a
     * credential dump. Reading only file, line, class and function makes the
     * guarantee ours instead of the runtime's.
     *
     * @return array{trace: list<string>, trace_dropped?: int}
     */
    private static function trace(Throwable $e): array
    {
        $frames = $e->getTrace();
        $kept = array_slice($frames, 0, self::MAX_TRACE_FRAMES);

        $out = ['trace' => array_map(self::formatFrame(...), $kept)];

        $dropped = count($frames) - count($kept);
        if ($dropped > 0) {
            $out['trace_dropped'] = $dropped;
        }

        return $out;
    }

    /** @param array<string, mixed> $frame */
    private static function formatFrame(array $frame): string
    {
        // A frame for an internal call carries no file or line.
        $where = ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? 0);
        $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');

        return rtrim($where . ' ' . $call);
    }

    /**
     * The wrapper says what failed, the cause says why: a PDOException folded
     * into a domain exception holds the SQLSTATE that explains the whole
     * incident. Losing it defeats the point of logging at all.
     *
     * @return array{previous?: list<array{class: string, message: string, file: string, line: int}>}
     */
    private static function previous(Throwable $e): array
    {
        $chain = [];
        $cause = $e->getPrevious();

        while ($cause !== null && count($chain) < self::MAX_PREVIOUS) {
            $chain[] = [
                'class' => $cause::class,
                'message' => $cause->getMessage(),
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ];
            $cause = $cause->getPrevious();
        }

        return $chain === [] ? [] : ['previous' => $chain];
    }
}
