<?php

namespace Tests\Unit\Core;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;

class RouterMiddlewareTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testRouteWithoutMiddlewareStillWorks(): void
    {
        $this->router->get('/test', function (Request $request) {
            return Response::success(['ok' => true]);
        });

        $request = Request::create('GET', '/test');
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRouteWithMiddlewareCallsMiddlewareThenHandler(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request): void
            {
                $request->setAttribute('middleware_ran', true);
            }
        };

        $this->router->get('/test', function (Request $request) {
            return Response::success(['ran' => $request->getAttribute('middleware_ran')]);
        }, [$middleware]);

        $request = Request::create('GET', '/test');
        $response = $this->router->dispatch($request);

        $this->assertTrue($response->getBody()['data']['ran']);
    }

    public function testRouteWithMiddlewareThrowsOnFailure(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request): void
            {
                throw new HttpException('UNAUTHORIZED', 'auth.error.token_missing', null, 401);
            }
        };

        $this->router->get('/test', function (Request $request) {
            return Response::success();
        }, [$middleware]);

        $this->expectException(HttpException::class);

        $request = Request::create('GET', '/test');
        $this->router->dispatch($request);
    }

    public function testRouteWithMultipleMiddlewares(): void
    {
        $first = new class implements MiddlewareInterface {
            public function handle(Request $request): void
            {
                $request->setAttribute('first', true);
            }
        };
        $second = new class implements MiddlewareInterface {
            public function handle(Request $request): void
            {
                $request->setAttribute('second', true);
            }
        };

        $this->router->get('/test', function (Request $request) {
            return Response::success([
                'first' => $request->getAttribute('first'),
                'second' => $request->getAttribute('second'),
            ]);
        }, [$first, $second]);

        $request = Request::create('GET', '/test');
        $response = $this->router->dispatch($request);

        $this->assertTrue($response->getBody()['data']['first']);
        $this->assertTrue($response->getBody()['data']['second']);
    }

    public function testMiddlewareCanSetRequestAttributes(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(Request $request): void
            {
                $request->setAttribute('user_id', 99);
                $request->setAttribute('role', 'admin');
            }
        };

        $this->router->get('/test', function (Request $request) {
            return Response::success([
                'user_id' => $request->getAttribute('user_id'),
                'role' => $request->getAttribute('role'),
            ]);
        }, [$middleware]);

        $request = Request::create('GET', '/test');
        $response = $this->router->dispatch($request);

        $this->assertSame(99, $response->getBody()['data']['user_id']);
        $this->assertSame('admin', $response->getBody()['data']['role']);
    }
}
