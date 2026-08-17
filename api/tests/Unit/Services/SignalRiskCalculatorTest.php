<?php

namespace Tests\Unit\Services;

use App\Repositories\AccountRepository;
use App\Repositories\SymbolAccountSettingsRepository;
use App\Services\SignalRiskCalculator;
use App\Services\SymbolResolver;
use PHPUnit\Framework\TestCase;

/**
 * Monetary risk of a signal as a percentage of the account capital, for the
 * trading-plan risk filters (docs/83-trading-plans.md).
 *
 * The account is the one dependency this class reads without owning: it takes
 * an id and looks it up. Everything it returns is a function of that account's
 * capital, so an id belonging to someone else turns the calculator into a way
 * of reading their capital — the reason strings quote the percentage to three
 * decimals, and size and stop are caller-controlled, so one answer inverts.
 * The caller is expected to have checked; this checks again.
 */
class SignalRiskCalculatorTest extends TestCase
{
    private SymbolResolver $resolver;
    private SymbolAccountSettingsRepository $settingsRepo;
    private AccountRepository $accountRepo;
    private SignalRiskCalculator $calculator;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(SymbolResolver::class);
        $this->settingsRepo = $this->createMock(SymbolAccountSettingsRepository::class);
        $this->accountRepo = $this->createMock(AccountRepository::class);
        $this->calculator = new SignalRiskCalculator(
            $this->resolver,
            $this->settingsRepo,
            $this->accountRepo,
        );

        $this->resolver->method('resolve')->willReturn(['id' => 5, 'code' => 'DAX', 'point_value' => 1]);
        $this->settingsRepo->method('findBySymbolAndAccount')->willReturn(null);
    }

    private function account(int $ownerId, float $capital = 10000.0): array
    {
        return ['id' => 100, 'user_id' => $ownerId, 'current_capital' => $capital];
    }

    public function testItPricesTheRiskAgainstTheAccountCapital(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->account(1));

        // 2 × 50 × 1 = 100 risked on a 10 000 capital.
        $this->assertSame(1.0, $this->calculator->computePercent(1, 100, 'DAX', 2.0, 50.0));
    }

    public function testAnAccountBelongingToSomeoneElseIsNotPriced(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->account(2));

        $this->assertNull($this->calculator->computePercent(1, 100, 'DAX', 2.0, 50.0));
    }

    public function testAnAccountThatDoesNotExistIsNotPriced(): void
    {
        $this->accountRepo->method('findById')->willReturn(null);

        $this->assertNull($this->calculator->computePercent(1, 100, 'DAX', 2.0, 50.0));
    }

    /**
     * The per-account point value is a setting of the target account, so it must
     * not be read before that account is known to be the caller's — otherwise a
     * foreign id still probes symbol_account_settings on the way to being
     * refused.
     */
    public function testAForeignAccountIsRefusedBeforeItsSettingsAreRead(): void
    {
        $settingsRepo = $this->createMock(SymbolAccountSettingsRepository::class);
        $settingsRepo->expects($this->never())->method('findBySymbolAndAccount');
        $this->accountRepo->method('findById')->willReturn($this->account(2));

        $calculator = new SignalRiskCalculator($this->resolver, $settingsRepo, $this->accountRepo);

        $this->assertNull($calculator->computePercent(1, 100, 'DAX', 2.0, 50.0));
    }

    public function testABlownAccountIsNotPriced(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->account(1, 0.0));

        $this->assertNull($this->calculator->computePercent(1, 100, 'DAX', 2.0, 50.0));
    }

    public function testASignalWithoutAStopIsNotPriced(): void
    {
        $this->assertNull($this->calculator->computePercent(1, 100, 'DAX', 2.0, 0.0));
    }
}
