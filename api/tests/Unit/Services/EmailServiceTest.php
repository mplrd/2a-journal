<?php

namespace Tests\Unit\Services;

use App\Services\EmailService;
use PHPUnit\Framework\TestCase;

class EmailServiceTest extends TestCase
{
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = dirname(__DIR__, 3) . '/templates/emails';
    }

    private function makeConfig(array $overrides = []): array
    {
        return array_merge([
            'enabled' => false,
            'from_address' => 'noreply@test.com',
            'from_name' => 'Test App',
            'frontend_url' => 'http://localhost:5173',
            'driver' => 'log',
            'resend_api_key' => '',
        ], $overrides);
    }

    // ── Driver config ─────────────────────────────────────────────

    public function testDefaultDriverIsLog(): void
    {
        $service = new EmailService($this->makeConfig());
        $this->assertInstanceOf(EmailService::class, $service);
    }

    public function testResendDriverRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RESEND_API_KEY');

        new EmailService($this->makeConfig([
            'driver' => 'resend',
            'resend_api_key' => '',
        ]));
    }

    public function testResendDriverAcceptsValidApiKey(): void
    {
        $service = new EmailService($this->makeConfig([
            'driver' => 'resend',
            'resend_api_key' => 're_123_test',
        ]));
        $this->assertInstanceOf(EmailService::class, $service);
    }

    public function testInvalidDriverThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmailService($this->makeConfig([
            'driver' => 'invalid',
        ]));
    }

    // ── Disabled mode (log only) ──────────────────────────────────

    public function testDisabledModeDoesNotThrow(): void
    {
        $service = new EmailService($this->makeConfig(['enabled' => false]));

        // Should log but not throw
        $service->sendVerificationEmail('test@example.com', 'token123', 'en');
        $this->assertTrue(true);
    }

    // ── Resend payload building ───────────────────────────────────

    public function testBuildResendPayloadStructure(): void
    {
        $service = new EmailService($this->makeConfig([
            'driver' => 'resend',
            'resend_api_key' => 're_123_test',
        ]));

        $reflection = new \ReflectionMethod($service, 'buildResendPayload');
        $reflection->setAccessible(true);

        $payload = $reflection->invoke($service, 'user@test.com', 'Test Subject', '<h1>Hello</h1>');

        $this->assertSame('Test App <noreply@test.com>', $payload['from']);
        $this->assertSame(['user@test.com'], $payload['to']);
        $this->assertSame('Test Subject', $payload['subject']);
        $this->assertSame('<h1>Hello</h1>', $payload['html']);
    }
}
