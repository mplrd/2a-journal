<?php

namespace Tests\Integration;

use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

class FeaturesTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $router = new Router();
        require __DIR__ . '/../../config/routes.php';
        $this->router = $router;
    }

    public function testFeaturesEndpointReturnsFlags(): void
    {
        $request = Request::create('GET', '/features');
        $response = $this->router->dispatch($request);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('broker_auto_sync', $body['data']);
        $this->assertIsBool($body['data']['broker_auto_sync']);
    }

    public function testFeaturesEndpointIsPublic(): void
    {
        $request = Request::create('GET', '/features');
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
