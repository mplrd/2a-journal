<?php

namespace Tests\Integration;

use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

class HealthCheckTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $router = new Router();
        require __DIR__ . '/../../config/routes.php';
        $this->router = $router;
    }

    public function testHealthCheckReturnsSuccess(): void
    {
        $request = Request::create('GET', '/health');
        $response = $this->router->dispatch($request);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('ok', $body['data']['status']);
        $this->assertArrayHasKey('timestamp', $body['data']);
        $this->assertArrayHasKey('version', $body['data']);
    }

    public function testPostHealthReturns404(): void
    {
        $this->expectException(\App\Exceptions\NotFoundException::class);

        $request = Request::create('POST', '/health');
        $this->router->dispatch($request);
    }
}
