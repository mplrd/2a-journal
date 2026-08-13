<?php

namespace App\Core;

/**
 * The visitor's address, as opposed to the last hop that reached us.
 *
 * Production sits behind Cloudflare and then Railway's edge, so REMOTE_ADDR is
 * an internal address. Checked on 1000 lines of production logs: PHP only ever
 * saw 100.64.0.2 through 100.64.0.22 — 21 addresses handed out at random,
 * never the caller's. Everything keyed on it was therefore keyed on nothing:
 * the rate limiter's "10 logins per 15 minutes per IP" was one bucket shared
 * by the whole platform, which an attacker can drain on purpose — roughly 210
 * calls to /auth/refresh in a window would sign every active user out.
 *
 * The forwarded headers are both the fix and the hazard, since anyone can send
 * them. They are read ONLY when the request reached us from a hop we trust.
 * Without that allow-list a caller could forge CF-Connecting-IP, pick whichever
 * bucket it liked and become untraceable — strictly worse than the shared
 * bucket. With no trusted range configured, this returns REMOTE_ADDR and
 * nothing changes: that is the default, and what local and test environments
 * run.
 */
class ClientIpResolver
{
    /**
     * Cloudflare sets this itself on every request it forwards and strips any
     * copy the caller sent, so behind Cloudflare it is the authority. Checked
     * before X-Forwarded-For, which is a chain anyone may have prepended to.
     */
    private const CLOUDFLARE_HEADER = 'HTTP_CF_CONNECTING_IP';

    private const FORWARDED_FOR_HEADER = 'HTTP_X_FORWARDED_FOR';

    /**
     * @param array<string, mixed> $server     $_SERVER, or an equivalent map
     * @param list<string>         $trustedProxies CIDR ranges or bare addresses
     */
    public static function resolve(array $server, array $trustedProxies): string
    {
        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remoteAddr === '') {
            // CLI and some test harnesses have no socket address at all.
            return '127.0.0.1';
        }

        if (!self::isTrusted($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        $forwarded = self::fromCloudflare($server) ?? self::fromForwardedFor($server, $trustedProxies);

        return $forwarded ?? $remoteAddr;
    }

    private static function fromCloudflare(array $server): ?string
    {
        $candidate = trim((string) ($server[self::CLOUDFLARE_HEADER] ?? ''));

        return self::isIpAddress($candidate) ? $candidate : null;
    }

    /**
     * Walk X-Forwarded-For right to left and stop at the first hop we do not
     * trust.
     *
     * The header reads `client, proxy1, proxy2`: only the rightmost entries
     * were appended by infrastructure we control. Reading the leftmost value —
     * the obvious implementation — takes whatever the caller wrote there,
     * which is the forgery this whole class exists to prevent.
     *
     * @param list<string> $trustedProxies
     */
    private static function fromForwardedFor(array $server, array $trustedProxies): ?string
    {
        $raw = (string) ($server[self::FORWARDED_FOR_HEADER] ?? '');
        if ($raw === '') {
            return null;
        }

        $hops = array_map('trim', explode(',', $raw));

        foreach (array_reverse($hops) as $hop) {
            if (!self::isIpAddress($hop)) {
                // A garbled entry breaks the chain of custody: everything
                // further left is unverifiable.
                return null;
            }
            if (!self::isTrusted($hop, $trustedProxies)) {
                return $hop;
            }
        }

        // Every hop belongs to us, so none of them is the visitor.
        return null;
    }

    /** @param list<string> $trustedProxies */
    private static function isTrusted(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $range) {
            if (self::inRange($ip, trim((string) $range))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an address falls inside a CIDR range, or equals a bare address.
     *
     * Compares packed binary, so IPv4 and IPv6 go through the same path and a
     * v4 address can never match a v6 range: inet_pton yields 4 bytes against
     * 16, and the length check rejects the pair outright.
     *
     * A malformed range matches nothing. Configuration typos have to fail
     * closed — a range that accidentally trusted everything would hand the
     * rate limiter to anyone who sends a header.
     */
    private static function inRange(string $ip, string $range): bool
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }

        if (!str_contains($range, '/')) {
            $packedRange = @inet_pton($range);

            return $packedRange !== false && $packedRange === $packedIp;
        }

        [$subnet, $prefix] = explode('/', $range, 2);

        $packedSubnet = @inet_pton($subnet);
        if ($packedSubnet === false || strlen($packedSubnet) !== strlen($packedIp)) {
            return false;
        }

        if (!ctype_digit($prefix)) {
            return false;
        }

        $prefixBits = (int) $prefix;
        $maxBits = strlen($packedIp) * 8;
        if ($prefixBits < 0 || $prefixBits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($prefixBits, 8);
        if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedSubnet, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefixBits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        // Compare only the leading bits of the byte the prefix stops inside.
        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedSubnet[$wholeBytes]) & $mask);
    }

    private static function isIpAddress(string $value): bool
    {
        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
