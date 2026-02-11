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
}
