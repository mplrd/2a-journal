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
 * Sanity check that every connector satisfies the new outbound-order surface
 * of ConnectorInterface. Real broker integrations replace these stubs one at a
 * time; until then, the webhook ingestion layer relies on the NOT_IMPLEMENTED
 * provider code to mark the event FAILED cleanly.
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
    public function testPlaceOrderStubThrowsNotImplemented(ConnectorInterface $connector): void
    {
        try {
            $connector->placeOrder([], ['symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 1.0]);
            $this->fail('Expected BrokerOrderException');
        } catch (BrokerOrderException $e) {
            $this->assertSame('NOT_IMPLEMENTED', $e->getProviderCode());
        }
    }

    /** @dataProvider connectorProvider */
    public function testCancelOrderStubThrowsNotImplemented(ConnectorInterface $connector): void
    {
        try {
            $connector->cancelOrder([], 'ord-1');
            $this->fail('Expected BrokerOrderException');
        } catch (BrokerOrderException $e) {
            $this->assertSame('NOT_IMPLEMENTED', $e->getProviderCode());
        }
    }

    /** @dataProvider connectorProvider */
    public function testClosePositionStubThrowsNotImplemented(ConnectorInterface $connector): void
    {
        try {
            $connector->closePosition([], 'pos-1');
            $this->fail('Expected BrokerOrderException');
        } catch (BrokerOrderException $e) {
            $this->assertSame('NOT_IMPLEMENTED', $e->getProviderCode());
        }
    }
}
