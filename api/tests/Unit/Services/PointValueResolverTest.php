<?php

namespace Tests\Unit\Services;

use App\Repositories\SymbolAccountSettingsRepository;
use App\Services\PointValueResolver;
use App\Services\SymbolResolver;
use PHPUnit\Framework\TestCase;

class PointValueResolverTest extends TestCase
{
    private SymbolResolver $symbolResolver;
    private SymbolAccountSettingsRepository $settingsRepo;
    private PointValueResolver $resolver;

    protected function setUp(): void
    {
        $this->symbolResolver = $this->createMock(SymbolResolver::class);
        $this->settingsRepo = $this->createMock(SymbolAccountSettingsRepository::class);
        $this->resolver = new PointValueResolver($this->symbolResolver, $this->settingsRepo);
    }

    public function testResolvePrefersThePerAccountSetting(): void
    {
        // The same DAX is worth 1 EUR/pt on a prop firm and 25 elsewhere. That
        // is what symbol_account_settings is for, and it wins over the asset's
        // own default — same precedence as SignalRiskCalculator.
        $this->symbolResolver->method('resolve')->willReturn(['id' => 7, 'point_value' => '1.00000']);
        $this->settingsRepo->method('findBySymbolAndAccount')->willReturn(['point_value' => '25.00000']);

        $this->assertSame(25.0, $this->resolver->resolve(1, 'GER40', 100));
    }

    public function testResolveFallsBackToTheAssetDefault(): void
    {
        $this->symbolResolver->method('resolve')->willReturn(['id' => 7, 'point_value' => '20.00000']);
        $this->settingsRepo->method('findBySymbolAndAccount')->willReturn(null);

        $this->assertSame(20.0, $this->resolver->resolve(1, 'GER40', 100));
    }

    public function testResolveReturnsOneWhenTheAssetIsUnknown(): void
    {
        // An asset the user deleted, or a CSV import that never registered one.
        // 1 reproduces exactly what the P&L computed before point values entered
        // it, so an unknown asset degrades to the old behaviour instead of
        // zeroing a trade — never to "unknown", which has no meaning for money.
        $this->symbolResolver->method('resolve')->willReturn(null);
        $this->settingsRepo->expects($this->never())->method('findBySymbolAndAccount');

        $this->assertSame(1.0, $this->resolver->resolve(1, 'WHATEVER', 100));
    }

    public function testResolveReturnsOneWhenTheStoredValueIsNotPositive(): void
    {
        // Both columns are NOT NULL DEFAULT 1, so this only ever guards a
        // hand-edited row. A zero would silently flatten every P&L to 0.
        $this->symbolResolver->method('resolve')->willReturn(['id' => 7, 'point_value' => '0.00000']);
        $this->settingsRepo->method('findBySymbolAndAccount')->willReturn(null);

        $this->assertSame(1.0, $this->resolver->resolve(1, 'GER40', 100));
    }

    public function testResolveReturnsOneWhenTheAccountSettingIsNotPositive(): void
    {
        $this->symbolResolver->method('resolve')->willReturn(['id' => 7, 'point_value' => '25.00000']);
        $this->settingsRepo->method('findBySymbolAndAccount')->willReturn(['point_value' => '-3.00000']);

        $this->assertSame(1.0, $this->resolver->resolve(1, 'GER40', 100));
    }

    public function testResolveReturnsOneWhenTheSymbolIsBlank(): void
    {
        $this->symbolResolver->expects($this->never())->method('resolve');

        $this->assertSame(1.0, $this->resolver->resolve(1, '   ', 100));
    }
}
