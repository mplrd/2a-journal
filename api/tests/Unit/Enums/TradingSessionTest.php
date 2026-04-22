<?php

namespace Tests\Unit\Enums;

use App\Enums\TradingSession;
use PHPUnit\Framework\TestCase;

class TradingSessionTest extends TestCase
{
    // ── ASIA: Tokyo 09:00 – 15:00 (Asia/Tokyo, no DST) ────────

    public function testClassifyAsiaSessionWinter(): void
    {
        // 2026-01-15 03:00 UTC = 12:00 Tokyo (JST = UTC+9, no DST)
        $dt = new \DateTime('2026-01-15 03:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::ASIA, TradingSession::classify($dt));
    }

    public function testClassifyAsiaSessionSummer(): void
    {
        // 2026-07-15 03:00 UTC = 12:00 Tokyo (still UTC+9, no DST in Japan)
        $dt = new \DateTime('2026-07-15 03:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::ASIA, TradingSession::classify($dt));
    }

    public function testClassifyAsiaSessionOpening(): void
    {
        // 2026-01-15 00:00 UTC = 09:00 Tokyo
        $dt = new \DateTime('2026-01-15 00:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::ASIA, TradingSession::classify($dt));
    }

    public function testClassifyAsiaSessionClosing(): void
    {
        // 2026-01-15 05:59 UTC = 14:59 Tokyo (still in session)
        $dt = new \DateTime('2026-01-15 05:59:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::ASIA, TradingSession::classify($dt));
    }

    public function testClassifyAsiaSessionClosed(): void
    {
        // 2026-01-15 06:00 UTC = 15:00 Tokyo (session ended)
        $dt = new \DateTime('2026-01-15 06:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::OFF, TradingSession::classify($dt));
    }

    // ── EUROPE: Paris 08:00 – 16:30 (Europe/Paris, DST) ───────

    public function testClassifyEuropeSessionWinter(): void
    {
        // 2026-01-15 10:00 UTC = 11:00 CET (UTC+1, winter) — US not open yet
        $dt = new \DateTime('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE, TradingSession::classify($dt));
    }

    public function testClassifyEuropeSessionSummer(): void
    {
        // 2026-07-15 10:00 UTC = 12:00 CEST (UTC+2, summer) — US not open yet
        $dt = new \DateTime('2026-07-15 10:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE, TradingSession::classify($dt));
    }

    public function testClassifyEuropeOpeningWinter(): void
    {
        // 2026-01-15 07:00 UTC = 08:00 CET (session opens)
        $dt = new \DateTime('2026-01-15 07:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE, TradingSession::classify($dt));
    }

    public function testClassifyEuropeOpeningSummer(): void
    {
        // 2026-07-15 06:00 UTC = 08:00 CEST (session opens, DST active)
        $dt = new \DateTime('2026-07-15 06:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE, TradingSession::classify($dt));
    }

    public function testClassifyEuropeBeforeUsOpenWinter(): void
    {
        // 2026-01-15 13:00 UTC = 14:00 CET (Europe only, US not yet open at 09:30 EST)
        $dt = new \DateTime('2026-01-15 13:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE, TradingSession::classify($dt));
    }

    public function testClassifyEuropeBeforeUsOpenSummer(): void
    {
        // 2026-07-15 12:00 UTC = 14:00 CEST (Europe only, US not yet open)
        $dt = new \DateTime('2026-07-15 12:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE, TradingSession::classify($dt));
    }

    // ── US: New York 09:30 – 16:00 (America/New_York, DST) ────

    public function testClassifyUsAfterEuropeCloseWinter(): void
    {
        // 2026-01-15 16:00 UTC = 11:00 EST — Europe closed at 15:30 UTC (16:30 CET)
        // so 16:00 UTC = after Europe close → US only
        $dt = new \DateTime('2026-01-15 16:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::US, TradingSession::classify($dt));
    }

    public function testClassifyUsAfterEuropeCloseSummer(): void
    {
        // 2026-07-15 15:00 UTC = 11:00 EDT — Europe closed at 14:30 UTC (16:30 CEST)
        // so 15:00 UTC = after Europe close → US only
        $dt = new \DateTime('2026-07-15 15:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::US, TradingSession::classify($dt));
    }

    public function testClassifyUsClosingWinter(): void
    {
        // 2026-01-15 20:59 UTC = 15:59 EST (still in session)
        $dt = new \DateTime('2026-01-15 20:59:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::US, TradingSession::classify($dt));
    }

    public function testClassifyUsClosedWinter(): void
    {
        // 2026-01-15 21:00 UTC = 16:00 EST (session ended)
        $dt = new \DateTime('2026-01-15 21:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::OFF, TradingSession::classify($dt));
    }

    public function testClassifyUsClosedSummer(): void
    {
        // 2026-07-15 20:00 UTC = 16:00 EDT (session ended)
        $dt = new \DateTime('2026-07-15 20:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::OFF, TradingSession::classify($dt));
    }

    // ── EUROPE_US overlap ───────────────────────────────────

    public function testClassifyOverlapEuropeUsWinter(): void
    {
        // 2026-01-15 14:30 UTC = 15:30 CET (Europe open) AND 09:30 EST (US opens)
        $dt = new \DateTime('2026-01-15 14:30:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE_US, TradingSession::classify($dt));
    }

    public function testClassifyOverlapEuropeUsSummer(): void
    {
        // 2026-07-15 13:30 UTC = 15:30 CEST (Europe open) AND 09:30 EDT (US opens)
        $dt = new \DateTime('2026-07-15 13:30:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE_US, TradingSession::classify($dt));
    }

    public function testClassifyOverlapEndWinter(): void
    {
        // 2026-01-15 15:29 UTC = 16:29 CET (Europe still open) AND 10:29 EST (US open)
        // → still overlap
        $dt = new \DateTime('2026-01-15 15:29:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE_US, TradingSession::classify($dt));
    }

    public function testClassifyOverlapEndsSummer(): void
    {
        // 2026-07-15 14:29 UTC = 16:29 CEST (Europe still open) AND 10:29 EDT (US open)
        // → still overlap
        $dt = new \DateTime('2026-07-15 14:29:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE_US, TradingSession::classify($dt));
    }

    public function testClassifyOverlapJustEndedWinter(): void
    {
        // 2026-01-15 15:30 UTC = 16:30 CET (Europe closes) AND 10:30 EST (US still open)
        // → US only
        $dt = new \DateTime('2026-01-15 15:30:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::US, TradingSession::classify($dt));
    }

    // ── OFF session ──────────────────────────────────────────

    public function testClassifyOffSession(): void
    {
        // 2026-01-15 22:00 UTC = 07:00 Tokyo, 23:00 CET, 17:00 EST → all closed
        $dt = new \DateTime('2026-01-15 22:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::OFF, TradingSession::classify($dt));
    }

    public function testClassifyWeekend(): void
    {
        // 2026-01-17 is Saturday, 10:00 UTC — classified by hour, not day
        $dt = new \DateTime('2026-01-17 10:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE, TradingSession::classify($dt));
    }

    // ── DST transition edge cases ────────────────────────────

    public function testClassifyEuropeDstTransitionDay(): void
    {
        // 2026-03-29 is CET→CEST switch day in Europe
        // 06:00 UTC = 08:00 CEST (DST just started, session should open)
        $dt = new \DateTime('2026-03-29 06:00:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE, TradingSession::classify($dt));
    }

    public function testClassifyUsDstTransitionDay(): void
    {
        // 2026-03-08 is EST→EDT switch day in US
        // 13:30 UTC = 09:30 EDT (DST just started, US opens)
        // Paris is still CET (not yet switched), 13:30 UTC = 14:30 CET → Europe open
        // → overlap
        $dt = new \DateTime('2026-03-08 13:30:00', new \DateTimeZone('UTC'));
        $this->assertSame(TradingSession::EUROPE_US, TradingSession::classify($dt));
    }

    // ── getSessionDefinitions ────────────────────────────────

    public function testGetSessionDefinitionsReturnsAllSessions(): void
    {
        $defs = TradingSession::getSessionDefinitions();

        $this->assertArrayHasKey('ASIA', $defs);
        $this->assertArrayHasKey('EUROPE', $defs);
        $this->assertArrayHasKey('US', $defs);
        $this->assertCount(3, $defs);

        $this->assertSame('Asia/Tokyo', $defs['ASIA']['timezone']);
        $this->assertSame('Europe/Paris', $defs['EUROPE']['timezone']);
        $this->assertSame('America/New_York', $defs['US']['timezone']);
    }
}
