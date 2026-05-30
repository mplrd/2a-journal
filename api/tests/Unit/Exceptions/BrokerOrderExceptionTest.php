<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\BrokerOrderException;
use PHPUnit\Framework\TestCase;

class BrokerOrderExceptionTest extends TestCase
{
    public function testCarriesProviderCodeAndPayload(): void
    {
        $exception = new BrokerOrderException(
            'broker rejected',
            'INSUFFICIENT_MARGIN',
            ['retCode' => 31104, 'retMsg' => 'margin not enough'],
        );

        $this->assertSame('broker rejected', $exception->getMessage());
        $this->assertSame('INSUFFICIENT_MARGIN', $exception->getProviderCode());
        $this->assertSame(31104, $exception->getProviderPayload()['retCode']);
    }

    public function testDefaultsToUnknownProviderCode(): void
    {
        $exception = new BrokerOrderException('boom');

        $this->assertSame('UNKNOWN', $exception->getProviderCode());
        $this->assertSame([], $exception->getProviderPayload());
    }
}
