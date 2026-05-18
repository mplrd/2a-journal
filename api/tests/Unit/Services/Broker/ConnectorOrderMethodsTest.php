<?php

namespace Tests\Unit\Services\Broker;

use App\Exceptions\BrokerOrderException;
use App\Services\Broker\BingxConnector;
use App\Services\Broker\ConnectorInterface;
use App\Services\Broker\CtraderConnector;
use App\Services\Broker\MetaApiConnector;
use App\Services\Broker\OuinexConnector;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

/**
 * Cross-broker safety net: every connector must surface credential mistakes
 * via BrokerOrderException with provider code INVALID_CREDENTIALS, so the
 * webhook ingestion layer can mark the event FAILED cleanly without leaking
 * stack traces. Per-broker happy paths and rejection paths live in each
 * connector's dedicated test file.
 */
class ConnectorOrderMethodsTest extends TestCase
{
    public static function connectorProvider(): array
    {
        return [
            'ctrader' => [new CtraderConnector([])],
            'metaapi' => [new MetaApiConnector(new Client(), 'https://mt-client-api')],
            'ouinex' => [new OuinexConnector(new Client(), 'https://api.ouinex.test/graphql')],
            'bingx' => [new BingxConnector(new Client(), 'https://open-api.bingx.com')],
        ];
    }

    /** @dataProvider connectorProvider */
    public function testPlaceOrderRaisesInvalidCredentialsWhenCredsAreEmpty(ConnectorInterface $connector): void
    {
        try {
            $connector->placeOrder([], ['symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 1.0]);
            $this->fail('Expected BrokerOrderException');
        } catch (BrokerOrderException $e) {
            $this->assertSame('INVALID_CREDENTIALS', $e->getProviderCode());
        }
    }
}
