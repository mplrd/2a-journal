<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

class SecurityConfigTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = require __DIR__ . '/../../../config/security.php';
    }

    public function testConfigHasHeadersKey(): void
    {
        $this->assertArrayHasKey('headers', $this->config);
        $this->assertIsArray($this->config['headers']);
    }

    public function testHeadersContainXContentTypeOptions(): void
    {
        $this->assertSame('nosniff', $this->config['headers']['X-Content-Type-Options']);
    }

    public function testHeadersContainXFrameOptions(): void
    {
        $this->assertSame('DENY', $this->config['headers']['X-Frame-Options']);
    }

    public function testHeadersContainReferrerPolicy(): void
    {
        $this->assertSame('strict-origin-when-cross-origin', $this->config['headers']['Referrer-Policy']);
    }

    public function testHeadersContainContentSecurityPolicy(): void
    {
        $this->assertArrayHasKey('Content-Security-Policy', $this->config['headers']);
        $this->assertStringContainsString("default-src 'none'", $this->config['headers']['Content-Security-Policy']);
        $this->assertStringContainsString("frame-ancestors 'none'", $this->config['headers']['Content-Security-Policy']);
    }

    public function testConfigHasRateLimitsKey(): void
    {
        $this->assertArrayHasKey('rate_limits', $this->config);
        $this->assertIsArray($this->config['rate_limits']);
    }

    // ── Trusted proxies ─────────────────────────────────────────────
    //
    // The one setting that decides whether a forwarded header is believed, so
    // it is worth pinning down rather than leaving to whoever reads the file.

    public function testTrustsRailwaysInternalRangeOutOfTheBox(): void
    {
        // Production runs behind Cloudflare and then Railway's edge, so the
        // address PHP sees is always a Railway internal one — 100.64.0.2
        // through 100.64.0.22 on 1000 lines of production logs. Shipping the
        // range as a default is what makes the fix work on deploy, with no
        // variable to create and none to forget.
        $this->assertContains('100.64.0.0/10', $this->loadWith(null)['trusted_proxies']);
    }

    public function testCloudflareRangesAreDeliberatelyAbsent(): void
    {
        // Not an oversight. PHP never sees a Cloudflare address in
        // REMOTE_ADDR: the last hop is always Railway. Carrying Cloudflare's
        // published ranges would be a list to keep up to date for nothing, and
        // every extra trusted range widens what can be believed.
        $this->assertCount(1, $this->loadWith(null)['trusted_proxies']);
    }

    public function testAnExplicitListReplacesTheDefault(): void
    {
        // The escape hatch for the day the infrastructure moves.
        $config = $this->loadWith('10.0.0.0/8, 192.168.0.0/16');

        $this->assertSame(['10.0.0.0/8', '192.168.0.0/16'], $config['trusted_proxies']);
    }

    public function testBlanksAndSpacingInTheListAreIgnored(): void
    {
        $config = $this->loadWith(' 10.0.0.0/8 ,, 172.16.0.0/12 , ');

        $this->assertSame(['10.0.0.0/8', '172.16.0.0/12'], $config['trusted_proxies']);
    }

    /**
     * Re-read the config with TRUSTED_PROXIES set to a given value, restoring
     * whatever was there before — the variable is process-wide.
     */
    private function loadWith(?string $trustedProxies): array
    {
        $previous = getenv('TRUSTED_PROXIES');

        if ($trustedProxies === null) {
            putenv('TRUSTED_PROXIES');
        } else {
            putenv("TRUSTED_PROXIES={$trustedProxies}");
        }

        try {
            return require __DIR__ . '/../../../config/security.php';
        } finally {
            if ($previous === false) {
                putenv('TRUSTED_PROXIES');
            } else {
                putenv("TRUSTED_PROXIES={$previous}");
            }
        }
    }
}
