<?php

namespace Tests\Unit\Core;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testDispatchClosureHandler(): void
    {
        $this->router->get('/test', function (Request $request) {
            return Response::success(['msg' => 'ok']);
        });

        $request = Request::create('GET', '/test');
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getBody()['data']['msg']);
    }

    public function testDispatchControllerHandler(): void
    {
        $this->router->get('/test', [StubController::class, 'index']);

        $request = Request::create('GET', '/test');
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('from controller', $response->getBody()['data']['msg']);
    }

    public function testThrows404WhenNoRouteMatches(): void
    {
        $this->router->get('/exists', function (Request $request) {
            return Response::success();
        });

        $this->expectException(NotFoundException::class);

        $request = Request::create('GET', '/not-exists');
        $this->router->dispatch($request);
    }

    public function testThrows404WhenMethodDoesNotMatch(): void
    {
        $this->router->get('/test', function (Request $request) {
            return Response::success();
        });

        $this->expectException(NotFoundException::class);

        $request = Request::create('POST', '/test');
        $this->router->dispatch($request);
    }

    public function testRouteWithParams(): void
    {
        $this->router->get('/users/{id}', function (Request $request) {
            return Response::success(['id' => $request->getRouteParam('id')]);
        });

        $request = Request::create('GET', '/users/42');
        $response = $this->router->dispatch($request);

        $this->assertSame('42', $response->getBody()['data']['id']);
    }

    public function testRouteWithMultipleParams(): void
    {
        $this->router->get('/users/{userId}/posts/{postId}', function (Request $request) {
            return Response::success([
                'userId' => $request->getRouteParam('userId'),
                'postId' => $request->getRouteParam('postId'),
            ]);
        });

        $request = Request::create('GET', '/users/5/posts/99');
        $response = $this->router->dispatch($request);

        $this->assertSame('5', $response->getBody()['data']['userId']);
        $this->assertSame('99', $response->getBody()['data']['postId']);
    }

    public function testPostRoute(): void
    {
        $this->router->post('/users', function (Request $request) {
            return Response::success(['created' => true], null, 201);
        });

        $request = Request::create('POST', '/users');
        $response = $this->router->dispatch($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testPutRoute(): void
    {
        $this->router->put('/users/{id}', function (Request $request) {
            return Response::success(['updated' => true]);
        });

        $request = Request::create('PUT', '/users/1');
        $response = $this->router->dispatch($request);

        $this->assertTrue($response->getBody()['data']['updated']);
    }

    public function testDeleteRoute(): void
    {
        $this->router->delete('/users/{id}', function (Request $request) {
            return Response::success(['deleted' => true]);
        });

        $request = Request::create('DELETE', '/users/1');
        $response = $this->router->dispatch($request);

        $this->assertTrue($response->getBody()['data']['deleted']);
    }
}

class StubController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->jsonSuccess(['msg' => 'from controller']);
    }
}
