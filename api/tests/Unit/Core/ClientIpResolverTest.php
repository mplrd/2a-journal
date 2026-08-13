<?php

namespace Tests\Unit\Core;

use App\Core\ClientIpResolver;
use PHPUnit\Framework\TestCase;

/**
 * Which address the API believes it is talking to.
 *
 * Production sits behind Cloudflare and then Railway's edge, so REMOTE_ADDR is
 * the last internal hop, never the visitor. Verified on 1000 lines of
 * production logs: PHP only ever saw 100.64.0.2 through 100.64.0.22 — 21
 * addresses handed out at random. Every rate limit counter was therefore
 * shared by the whole platform instead of being per visitor, which turns the
 * announced "10 logins per 15 minutes per IP" into one bucket anybody can
 * drain: roughly 210 calls to /auth/refresh would sign every active user out.
 *
 * The forwarded headers are the fix AND the danger: anyone can send them. They
 * are read only when the request actually arrives from a hop we trust.
 */
class ClientIpResolverTest extends TestCase
{
    /** Railway's internal range, the one seen in production. */
    private const RAILWAY = '100.64.0.0/10';

    // ── Without a trusted proxy, nothing is believed ────────────────

    public function testFallsBackToTheSocketAddressWhenNoProxyIsTrusted(): void
    {
        // The default configuration, and what local and test environments run:
        // no proxy in front, so the socket address is the honest answer.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], []);

        $this->assertSame('203.0.113.7', $ip);
    }

    public function testIgnoresForwardedHeadersFromAnUntrustedHop(): void
    {
        // The whole point of the allow-list. Without it, anyone could forge
        // CF-Connecting-IP, land in a bucket of their choosing and become
        // untraceable — strictly worse than the shared-bucket situation it is
        // meant to fix.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.2',
        ], [self::RAILWAY]);

        $this->assertSame('203.0.113.7', $ip);
    }

    // ── Behind a trusted proxy ──────────────────────────────────────

    public function testReadsCloudflaresHeaderFromATrustedHop(): void
    {
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], [self::RAILWAY]);

        $this->assertSame('198.51.100.1', $ip);
    }

    public function testPrefersCloudflaresHeaderOverXForwardedFor(): void
    {
        // Cloudflare is the front door and sets CF-Connecting-IP itself, so it
        // is the one header it makes no sense to second-guess.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.99',
        ], [self::RAILWAY]);

        $this->assertSame('198.51.100.1', $ip);
    }

    public function testFallsBackToXForwardedForWhenCloudflareIsAbsent(): void
    {
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
        ], [self::RAILWAY]);

        $this->assertSame('198.51.100.1', $ip);
    }

    public function testWalksXForwardedForFromTheRightPastTrustedHops(): void
    {
        // The header reads client, proxy1, proxy2 left to right. Only the
        // rightmost entries are ones our own infrastructure appended and can
        // be believed; the first untrusted one going right-to-left is as far
        // back as the chain can be trusted.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 100.64.0.9, 100.64.0.3',
        ], [self::RAILWAY]);

        $this->assertSame('198.51.100.1', $ip);
    }

    public function testDoesNotTakeAForgedEntryFromDeeperInTheChain(): void
    {
        // A client sending its own X-Forwarded-For puts a value at the far
        // left. Walking from the right stops at the first untrusted hop, which
        // is the address that actually reached our edge — not what the caller
        // claims sits behind it.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_X_FORWARDED_FOR' => '10.9.9.9, 203.0.113.7, 100.64.0.3',
        ], [self::RAILWAY]);

        $this->assertSame('203.0.113.7', $ip);
    }

    // ── Anything unusable falls back rather than guesses ────────────

    public function testFallsBackWhenTheForwardedValueIsNotAnAddress(): void
    {
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
        ], [self::RAILWAY]);

        $this->assertSame('100.64.0.14', $ip);
    }

    public function testFallsBackWhenEveryForwardedHopIsTrusted(): void
    {
        // Nothing in the chain is a visitor, so there is nothing to promote.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_X_FORWARDED_FOR' => '100.64.0.9, 100.64.0.3',
        ], [self::RAILWAY]);

        $this->assertSame('100.64.0.14', $ip);
    }

    public function testFallsBackToLoopbackWithoutASocketAddress(): void
    {
        // CLI and some test harnesses have no REMOTE_ADDR at all.
        $this->assertSame('127.0.0.1', ClientIpResolver::resolve([], []));
    }

    // ── Range matching ──────────────────────────────────────────────

    public function testMatchesAnIpv4Range(): void
    {
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.127.255.255',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], [self::RAILWAY]);

        $this->assertSame('198.51.100.1', $ip, '100.64.0.0/10 ends at 100.127.255.255');
    }

    public function testRejectsAnAddressJustOutsideTheRange(): void
    {
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.128.0.0',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], [self::RAILWAY]);

        $this->assertSame('100.128.0.0', $ip);
    }

    public function testAcceptsABareAddressAsATrustedProxy(): void
    {
        // A single host, written without a prefix.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], ['10.0.0.5']);

        $this->assertSame('198.51.100.1', $ip);
    }

    public function testMatchesAnIpv6Range(): void
    {
        // Cloudflare publishes IPv6 ranges too, so the matcher cannot be
        // IPv4-only.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '2400:cb00:0:1::5',
            'HTTP_CF_CONNECTING_IP' => '2001:db8::1',
        ], ['2400:cb00::/32']);

        $this->assertSame('2001:db8::1', $ip);
    }

    public function testDoesNotMixUpIpv4AndIpv6(): void
    {
        // A v4 socket address must never be judged against a v6 range.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], ['2400:cb00::/32']);

        $this->assertSame('203.0.113.7', $ip);
    }

    public function testIgnoresAMalformedRangeInsteadOfTrustingEverything(): void
    {
        // A typo in configuration must fail closed, not open.
        $ip = ClientIpResolver::resolve([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], ['not a range', '100.64.0.0/999']);

        $this->assertSame('100.64.0.14', $ip);
    }
}
