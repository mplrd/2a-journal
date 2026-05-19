<?php

namespace App\Services\Broker;

/**
 * One-stop helper for the JSON-stderr log lines that broker connectors and
 * the TradingView webhook service emit on failure. Keeps a single canonical
 * shape across the four brokers so Railway log filters and grep recipes
 * stay simple:
 *
 *   {"job":"bingx","event":"request_failed","path":"/openApi/swap/v3/user/balance","canonical":"timestamp=...","clock_skew_seconds":-1,"code":100001,"msg":"..."}
 *
 * Never write the API secret, the body secret, or any credential here —
 * `meta` is meant for diagnostic crumbs (canonical strings with public
 * variables, status codes, error messages from the upstream API).
 */
class BrokerLogger
{
    public static function failure(string $job, string $event, array $meta = []): void
    {
        $entry = array_merge([
            'job' => $job,
            'event' => $event,
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $meta);

        fwrite(STDERR, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Compute the seconds between our local clock and the upstream `Date`
     * header. Returns null when the header is missing or unparseable, which
     * is itself a useful signal to surface in the log.
     */
    public static function clockSkewSeconds(?string $serverDate): ?int
    {
        if ($serverDate === null || $serverDate === '') {
            return null;
        }
        $serverTs = strtotime($serverDate);
        if ($serverTs === false) {
            return null;
        }
        return (int) microtime(true) - $serverTs;
    }
}
