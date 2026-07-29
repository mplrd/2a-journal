<?php

namespace App\Enums;

/**
 * Which cTrader server a connection talks to. The Open API exposes two
 * distinct hosts and a trading account only exists on one of them: a demo
 * account authenticated against the live host fails at account auth, and vice
 * versa. Stored per connection (in the encrypted credentials blob) rather than
 * read from a global env var, so demo and live accounts can coexist.
 */
enum CtraderEnvironment: string
{
    case LIVE = 'LIVE';
    case DEMO = 'DEMO';

    public function wsHost(): string
    {
        return match ($this) {
            self::LIVE => 'live.ctraderapi.com',
            self::DEMO => 'demo.ctraderapi.com',
        };
    }
}
