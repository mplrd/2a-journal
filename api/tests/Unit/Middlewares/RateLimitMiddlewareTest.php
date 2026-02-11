<?php

namespace Tests\Unit\Middlewares;

use App\Core\Request;
use App\Exceptions\TooManyRequestsException;
use App\Middlewares\RateLimitMiddleware;
use App\Repositories\RateLimitRepository;
use PHPUnit\Framework\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    private RateLimitRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(RateLimitRepository::class);
    }

    public function testAllowsRequestUnderLimit(): void
    {
        $this->repo->method('getAttempts')->willReturn(5);
        $this->repo->expects($this->once())->method('increment');

        $middleware = new RateLimitMiddleware($this->repo, 10, 900, '/auth/login');
        $request = Request::create('POST', '/auth/login');

        $middleware->handle($request);

        // No exception = pass
        $this->assertTrue(true);
    }

    public function testAllowsRequestAtExactLimit(): void
    {
        $this->repo->method('getAttempts')->willReturn(10);
        $this->repo->expects($this->once())->method('increment');

        $middleware = new RateLimitMiddleware($this->repo, 10, 900, '/auth/login');
        $request = Request::create('POST', '/auth/login');

        $middleware->handle($request);

        // 10th attempt is still allowed (limit is 10)
        $this->assertTrue(true);
    }

    public function testBlocksRequestOneOverLimit(): void
    {
        $this->repo->method('getAttempts')->willReturn(11);
        $this->repo->expects($this->once())->method('increment');

        $middleware = new RateLimitMiddleware($this->repo, 10, 900, '/auth/login');
        $request = Request::create('POST', '/auth/login');

        $this->expectException(TooManyRequestsException::class);
        $this->expectExceptionMessage('error.rate_limit_exceeded');

        $middleware->handle($request);
    }

    public function testBlocksRequestOverLimit(): void
    {
        $this->repo->method('getAttempts')->willReturn(15);
        $this->repo->expects($this->once())->method('increment');

        $middleware = new RateLimitMiddleware($this->repo, 10, 900, '/auth/login');
        $request = Request::create('POST', '/auth/login');

        $this->expectException(TooManyRequestsException::class);

        $middleware->handle($request);
    }

    public function testUsesClientIpFromRequest(): void
    {
        $this->repo->expects($this->once())
            ->method('increment')
            ->with('127.0.0.1', '/auth/login', 900);
        $this->repo->method('getAttempts')
            ->with('127.0.0.1', '/auth/login', 900)
            ->willReturn(0);

        $middleware = new RateLimitMiddleware($this->repo, 10, 900, '/auth/login');
        $request = Request::create('POST', '/auth/login');

        $middleware->handle($request);

        $this->assertTrue(true);
    }

    public function testDifferentEndpointsUseConfiguredEndpoint(): void
    {
        $this->repo->expects($this->once())
            ->method('increment')
            ->with('127.0.0.1', '/auth/register', 900);
        $this->repo->method('getAttempts')->willReturn(0);

        $middleware = new RateLimitMiddleware($this->repo, 5, 900, '/auth/register');
        $request = Request::create('POST', '/auth/register');

        $middleware->handle($request);

        $this->assertTrue(true);
    }
}
