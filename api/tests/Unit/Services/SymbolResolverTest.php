<?php

namespace Tests\Unit\Services;

use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;
use App\Services\SymbolResolver;
use PHPUnit\Framework\TestCase;

/**
 * Turning what a signal calls an instrument into the user's own asset
 * (docs/99-plan-instrument-cible.md).
 *
 * An ASSET is the thing traded — the DAX. A SYMBOL names it and changes from
 * one broker to the next — GER40, DE40.CASH. A TICKER is the pair, broker plus
 * symbol — EIGHTCAP:GER40. symbol_aliases exists to bridge the second to the
 * user's own, and until now only the CSV import used it.
 */
class SymbolResolverTest extends TestCase
{
    private SymbolRepository $symbolRepo;
    private SymbolAliasRepository $aliasRepo;
    private SymbolResolver $resolver;

    protected function setUp(): void
    {
        $this->symbolRepo = $this->createMock(SymbolRepository::class);
        $this->aliasRepo = $this->createMock(SymbolAliasRepository::class);
        $this->resolver = new SymbolResolver($this->symbolRepo, $this->aliasRepo);
    }

    private function asset(string $code): array
    {
        return ['id' => 1, 'code' => $code, 'name' => 'DAX 40', 'point_value' => 25.0];
    }

    public function testASymbolThatIsAlreadyTheUsersOwnResolvesToItself(): void
    {
        $this->symbolRepo->method('findByUserAndCode')
            ->willReturnMap([[7, 'DE40.CASH', $this->asset('DE40.CASH')]]);
        $this->aliasRepo->expects($this->never())->method('findAnyByBrokerSymbol');

        $this->assertSame('DE40.CASH', $this->resolver->resolve(7, 'DE40.CASH')['code']);
    }

    public function testABrokerSymbolResolvesThroughItsAlias(): void
    {
        // The whole point: GER40 and DE40.CASH are the same asset, and a plan
        // targeting the DAX must accept a signal that calls it either way.
        $this->symbolRepo->method('findByUserAndCode')->willReturnMap([
            [7, 'GER40', null],
            [7, 'DE40.CASH', $this->asset('DE40.CASH')],
        ]);
        $this->aliasRepo->method('findAnyByBrokerSymbol')
            ->willReturn(['journal_symbol' => 'DE40.CASH']);

        $this->assertSame('DE40.CASH', $this->resolver->resolve(7, 'GER40')['code']);
    }

    public function testATickerIsSplitOnItsBrokerPrefix(): void
    {
        // TradingView's {{ticker}} hands over EIGHTCAP:GER40 — broker + symbol.
        $this->symbolRepo->method('findByUserAndCode')->willReturnMap([
            [7, 'EIGHTCAP:GER40', null],
            [7, 'GER40', $this->asset('GER40')],
        ]);
        $this->aliasRepo->method('findAnyByBrokerSymbol')->willReturn(null);

        $this->assertSame('GER40', $this->resolver->resolve(7, 'EIGHTCAP:GER40')['code']);
    }

    public function testTheFullTickerWinsOverItsStrippedFormWhenBothExist(): void
    {
        // A user who registered the qualified form meant it; don't second-guess.
        $this->symbolRepo->method('findByUserAndCode')->willReturnMap([
            [7, 'EIGHTCAP:GER40', $this->asset('EIGHTCAP:GER40')],
            [7, 'GER40', $this->asset('GER40')],
        ]);

        $this->assertSame('EIGHTCAP:GER40', $this->resolver->resolve(7, 'EIGHTCAP:GER40')['code']);
    }

    public function testSurroundingSpaceAndEmptinessAreTolerated(): void
    {
        $this->symbolRepo->method('findByUserAndCode')
            ->willReturnMap([[7, 'DE40.CASH', $this->asset('DE40.CASH')]]);

        $this->assertSame('DE40.CASH', $this->resolver->resolve(7, '  DE40.CASH  ')['code']);
        $this->assertNull($this->resolver->resolve(7, '   '));
    }

    public function testAnUnknownSymbolResolvesToNothing(): void
    {
        $this->symbolRepo->method('findByUserAndCode')->willReturn(null);
        $this->aliasRepo->method('findAnyByBrokerSymbol')->willReturn(null);

        $this->assertNull($this->resolver->resolve(7, 'WHAT'));
    }

    public function testAnAliasPointingAtAnAssetTheUserDeletedResolvesToNothing(): void
    {
        // The mapping survives the asset; resolving to a row that is gone would
        // hand the caller a symbol nothing can be priced against.
        $this->symbolRepo->method('findByUserAndCode')->willReturn(null);
        $this->aliasRepo->method('findAnyByBrokerSymbol')
            ->willReturn(['journal_symbol' => 'DELETED']);

        $this->assertNull($this->resolver->resolve(7, 'GER40'));
    }
}
