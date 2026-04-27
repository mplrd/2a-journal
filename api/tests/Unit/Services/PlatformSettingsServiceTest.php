<?php

namespace Tests\Unit\Services;

use App\Repositories\PlatformSettingsRepository;
use App\Services\PlatformSettingsService;
use PHPUnit\Framework\TestCase;

class PlatformSettingsServiceTest extends TestCase
{
    private PlatformSettingsRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(PlatformSettingsRepository::class);

        // Clear all known env vars so tests don't pick up the dev .env values
        putenv('BROKER_AUTO_SYNC_ENABLED');
        putenv('BROKER_SYNC_INTERVAL_MINUTES');
        putenv('BROKER_SYNC_MAX_FAILURES');
    }

    protected function tearDown(): void
    {
        putenv('BROKER_AUTO_SYNC_ENABLED');
        putenv('BROKER_SYNC_INTERVAL_MINUTES');
        putenv('BROKER_SYNC_MAX_FAILURES');
    }

    public function testResolveReturnsNullWhenBothSourcesAbsent(): void
    {
        $this->repo->method('get')->willReturn(null);

        $service = new PlatformSettingsService($this->repo);
        $this->assertNull($service->resolve('broker_sync_interval_minutes'));
    }

    public function testResolveFallsBackToEnvVarWhenDbAbsent(): void
    {
        $this->repo->method('get')->willReturn(null);
        putenv('BROKER_SYNC_INTERVAL_MINUTES=15');

        $service = new PlatformSettingsService($this->repo);
        $this->assertSame(15, $service->resolve('broker_sync_interval_minutes'));
    }

    public function testResolvePrefersDbValueOverEnv(): void
    {
        $this->repo->method('get')->willReturn([
            'setting_value' => '30',
            'value_type' => 'INT',
        ]);
        putenv('BROKER_SYNC_INTERVAL_MINUTES=15');

        $service = new PlatformSettingsService($this->repo);
        $this->assertSame(30, $service->resolve('broker_sync_interval_minutes'));
    }

    public function testResolveCoercesBoolType(): void
    {
        $this->repo->method('get')->willReturn([
            'setting_value' => 'true',
            'value_type' => 'BOOL',
        ]);

        $service = new PlatformSettingsService($this->repo);
        $this->assertTrue($service->resolve('broker_auto_sync_enabled'));
    }

    public function testResolveCoercesIntType(): void
    {
        $this->repo->method('get')->willReturn([
            'setting_value' => '7',
            'value_type' => 'INT',
        ]);

        $service = new PlatformSettingsService($this->repo);
        $this->assertSame(7, $service->resolve('broker_sync_max_failures'));
    }

    public function testResolveReturnsNullForUnknownKey(): void
    {
        $service = new PlatformSettingsService($this->repo);
        $this->assertNull($service->resolve('unknown_key'));
    }

    public function testListReturnsAllKnownSettingsWithCurrentValueAndSource(): void
    {
        $this->repo->method('list')->willReturn([
            ['setting_key' => 'broker_auto_sync_enabled', 'setting_value' => 'false', 'value_type' => 'BOOL', 'description' => null, 'updated_at' => '2026-04-27 10:00:00', 'updated_by_user_id' => null],
        ]);
        putenv('BROKER_SYNC_INTERVAL_MINUTES=15');

        $service = new PlatformSettingsService($this->repo);
        $list = $service->list();

        $byKey = [];
        foreach ($list as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        // Whitelisted settings should appear regardless of DB state
        $this->assertArrayHasKey('broker_auto_sync_enabled', $byKey);
        $this->assertArrayHasKey('broker_sync_interval_minutes', $byKey);
        $this->assertArrayHasKey('broker_sync_max_failures', $byKey);

        // DB-backed entry: value=false, source=db
        $this->assertFalse($byKey['broker_auto_sync_enabled']['value']);
        $this->assertSame('db', $byKey['broker_auto_sync_enabled']['source']);

        // Env-backed entry: value=15, source=env
        $this->assertSame(15, $byKey['broker_sync_interval_minutes']['value']);
        $this->assertSame('env', $byKey['broker_sync_interval_minutes']['source']);

        // Neither DB nor env → value=null, source=default
        $this->assertNull($byKey['broker_sync_max_failures']['value']);
        $this->assertSame('default', $byKey['broker_sync_max_failures']['source']);
    }

    public function testUpdateValidatesBoolType(): void
    {
        $this->repo->expects($this->never())->method('upsert');

        $service = new PlatformSettingsService($this->repo);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $service->update('broker_auto_sync_enabled', 'maybe', 1);
    }

    public function testUpdateValidatesIntType(): void
    {
        $this->repo->expects($this->never())->method('upsert');

        $service = new PlatformSettingsService($this->repo);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $service->update('broker_sync_interval_minutes', 'not-a-number', 1);
    }

    public function testUpdateRejectsUnknownKey(): void
    {
        $this->repo->expects($this->never())->method('upsert');

        $service = new PlatformSettingsService($this->repo);

        $this->expectException(\App\Exceptions\ValidationException::class);
        $service->update('unknown_key', '42', 1);
    }

    public function testUpdateUpsertsValueWithAdminUserId(): void
    {
        $this->repo->expects($this->once())
            ->method('upsert')
            ->with('broker_sync_interval_minutes', '20', 'INT', $this->anything(), 42);

        $service = new PlatformSettingsService($this->repo);
        $service->update('broker_sync_interval_minutes', '20', 42);
    }
}
