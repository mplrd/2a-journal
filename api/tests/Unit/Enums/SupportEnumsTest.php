<?php

namespace Tests\Unit\Enums;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use PHPUnit\Framework\TestCase;

class SupportEnumsTest extends TestCase
{
    public function testTicketTypeCases(): void
    {
        $cases = TicketType::cases();
        $this->assertCount(3, $cases);
        $this->assertSame('SUPPORT', TicketType::SUPPORT->value);
        $this->assertSame('BUG', TicketType::BUG->value);
        $this->assertSame('FEATURE', TicketType::FEATURE->value);
    }

    public function testTicketTypeFromAndTryFrom(): void
    {
        $this->assertSame(TicketType::BUG, TicketType::from('BUG'));
        $this->assertSame(TicketType::FEATURE, TicketType::tryFrom('FEATURE'));
        $this->assertNull(TicketType::tryFrom('INVALID'));
    }

    public function testTicketStatusCases(): void
    {
        $cases = TicketStatus::cases();
        $this->assertCount(5, $cases);
        $this->assertSame('OPEN', TicketStatus::OPEN->value);
        $this->assertSame('IN_PROGRESS', TicketStatus::IN_PROGRESS->value);
        $this->assertSame('WAITING_USER', TicketStatus::WAITING_USER->value);
        $this->assertSame('RESOLVED', TicketStatus::RESOLVED->value);
        $this->assertSame('CLOSED', TicketStatus::CLOSED->value);
    }

    public function testTicketStatusFromAndTryFrom(): void
    {
        $this->assertSame(TicketStatus::OPEN, TicketStatus::from('OPEN'));
        $this->assertSame(TicketStatus::CLOSED, TicketStatus::tryFrom('CLOSED'));
        $this->assertNull(TicketStatus::tryFrom('INVALID'));
    }

    public function testTicketPriorityCases(): void
    {
        $cases = TicketPriority::cases();
        $this->assertCount(3, $cases);
        $this->assertSame('LOW', TicketPriority::LOW->value);
        $this->assertSame('NORMAL', TicketPriority::NORMAL->value);
        $this->assertSame('HIGH', TicketPriority::HIGH->value);
    }

    public function testTicketPriorityFromAndTryFrom(): void
    {
        $this->assertSame(TicketPriority::NORMAL, TicketPriority::from('NORMAL'));
        $this->assertSame(TicketPriority::HIGH, TicketPriority::tryFrom('HIGH'));
        $this->assertNull(TicketPriority::tryFrom('INVALID'));
    }
}
