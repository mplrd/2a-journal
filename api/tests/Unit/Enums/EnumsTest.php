<?php

namespace Tests\Unit\Enums;

use App\Enums\AccountMode;
use App\Enums\AccountType;
use App\Enums\Direction;
use App\Enums\ExitType;
use App\Enums\OrderStatus;
use App\Enums\TradeStatus;
use App\Enums\TriggerType;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function testOrderStatusCases(): void
    {
        $cases = OrderStatus::cases();
        $this->assertCount(4, $cases);
        $this->assertSame('PENDING', OrderStatus::PENDING->value);
        $this->assertSame('EXECUTED', OrderStatus::EXECUTED->value);
        $this->assertSame('CANCELLED', OrderStatus::CANCELLED->value);
        $this->assertSame('EXPIRED', OrderStatus::EXPIRED->value);
    }

    public function testOrderStatusFromAndTryFrom(): void
    {
        $this->assertSame(OrderStatus::PENDING, OrderStatus::from('PENDING'));
        $this->assertSame(OrderStatus::EXECUTED, OrderStatus::tryFrom('EXECUTED'));
        $this->assertNull(OrderStatus::tryFrom('INVALID'));
    }

    public function testTradeStatusCases(): void
    {
        $cases = TradeStatus::cases();
        $this->assertCount(3, $cases);
        $this->assertSame('OPEN', TradeStatus::OPEN->value);
        $this->assertSame('SECURED', TradeStatus::SECURED->value);
        $this->assertSame('CLOSED', TradeStatus::CLOSED->value);
    }

    public function testTradeStatusFromAndTryFrom(): void
    {
        $this->assertSame(TradeStatus::OPEN, TradeStatus::from('OPEN'));
        $this->assertNull(TradeStatus::tryFrom('INVALID'));
    }

    public function testExitTypeCases(): void
    {
        $cases = ExitType::cases();
        $this->assertCount(4, $cases);
        $this->assertSame('BE', ExitType::BE->value);
        $this->assertSame('TP', ExitType::TP->value);
        $this->assertSame('SL', ExitType::SL->value);
        $this->assertSame('MANUAL', ExitType::MANUAL->value);
    }

    public function testExitTypeFromAndTryFrom(): void
    {
        $this->assertSame(ExitType::TP, ExitType::from('TP'));
        $this->assertNull(ExitType::tryFrom('INVALID'));
    }

    public function testDirectionCases(): void
    {
        $cases = Direction::cases();
        $this->assertCount(2, $cases);
        $this->assertSame('BUY', Direction::BUY->value);
        $this->assertSame('SELL', Direction::SELL->value);
    }

    public function testDirectionFromAndTryFrom(): void
    {
        $this->assertSame(Direction::BUY, Direction::from('BUY'));
        $this->assertNull(Direction::tryFrom('INVALID'));
    }

    public function testAccountTypeCases(): void
    {
        $cases = AccountType::cases();
        $this->assertCount(2, $cases);
        $this->assertSame('BROKER', AccountType::BROKER->value);
        $this->assertSame('PROPFIRM', AccountType::PROPFIRM->value);
    }

    public function testAccountTypeFromAndTryFrom(): void
    {
        $this->assertSame(AccountType::BROKER, AccountType::from('BROKER'));
        $this->assertNull(AccountType::tryFrom('INVALID'));
    }

    public function testAccountModeCases(): void
    {
        $cases = AccountMode::cases();
        $this->assertCount(4, $cases);
        $this->assertSame('DEMO', AccountMode::DEMO->value);
        $this->assertSame('LIVE', AccountMode::LIVE->value);
        $this->assertSame('CHALLENGE', AccountMode::CHALLENGE->value);
        $this->assertSame('FUNDED', AccountMode::FUNDED->value);
    }

    public function testAccountModeFromAndTryFrom(): void
    {
        $this->assertSame(AccountMode::LIVE, AccountMode::from('LIVE'));
        $this->assertNull(AccountMode::tryFrom('INVALID'));
    }

    public function testTriggerTypeCases(): void
    {
        $cases = TriggerType::cases();
        $this->assertCount(4, $cases);
        $this->assertSame('MANUAL', TriggerType::MANUAL->value);
        $this->assertSame('SYSTEM', TriggerType::SYSTEM->value);
        $this->assertSame('WEBHOOK', TriggerType::WEBHOOK->value);
        $this->assertSame('BROKER_API', TriggerType::BROKER_API->value);
    }

    public function testTriggerTypeFromAndTryFrom(): void
    {
        $this->assertSame(TriggerType::WEBHOOK, TriggerType::from('WEBHOOK'));
        $this->assertNull(TriggerType::tryFrom('INVALID'));
    }
}
