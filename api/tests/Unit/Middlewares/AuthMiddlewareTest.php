<?php

namespace Tests\Unit\Middlewares;

use App\Core\Request;
use App\Exceptions\UnauthorizedException;
use App\Middlewares\AuthMiddleware;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

class AuthMiddlewareTest extends TestCase
{
    private AuthMiddleware $middleware;
    private string $secret = 'test-secret-key-for-testing-only';

    protected function setUp(): void
    {
        $this->middleware = new AuthMiddleware($this->secret);
    }

    public function testThrowsWhenNoAuthorizationHeader(): void
    {
        $request = Request::create('GET', '/auth/me');

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.token_missing');

        $this->middleware->handle($request);
    }

    public function testThrowsWhenBearerPrefixMissing(): void
    {
        $request = Request::create('GET', '/auth/me', [], [], ['Authorization' => 'just-a-token']);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.token_missing');

        $this->middleware->handle($request);
    }

    public function testThrowsWhenTokenIsInvalid(): void
    {
        $request = Request::create('GET', '/auth/me', [], [], ['Authorization' => 'Bearer invalid.token.here']);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.token_invalid');

        $this->middleware->handle($request);
    }

    public function testThrowsWhenTokenIsExpired(): void
    {
        $payload = [
            'sub' => 1,
            'iat' => time() - 3600,
            'exp' => time() - 1800,
        ];
        $token = JWT::encode($payload, $this->secret, 'HS256');

        $request = Request::create('GET', '/auth/me', [], [], ['Authorization' => "Bearer $token"]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.token_expired');

        $this->middleware->handle($request);
    }

    public function testSetsUserIdOnRequest(): void
    {
        $payload = [
            'sub' => 42,
            'iat' => time(),
            'exp' => time() + 900,
        ];
        $token = JWT::encode($payload, $this->secret, 'HS256');

        $request = Request::create('GET', '/auth/me', [], [], ['Authorization' => "Bearer $token"]);

        $this->middleware->handle($request);

        $this->assertSame(42, $request->getAttribute('user_id'));
    }

    public function testThrowsWhenTokenSignedWithWrongSecret(): void
    {
        $payload = [
            'sub' => 1,
            'iat' => time(),
            'exp' => time() + 900,
        ];
        $token = JWT::encode($payload, 'wrong-secret', 'HS256');

        $request = Request::create('GET', '/auth/me', [], [], ['Authorization' => "Bearer $token"]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('auth.error.token_invalid');

        $this->middleware->handle($request);
    }
}
