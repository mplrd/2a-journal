<?php

namespace App\Middlewares;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Exceptions\UnauthorizedException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware implements MiddlewareInterface
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function handle(Request $request): void
    {
        $header = $request->getHeader('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            throw new UnauthorizedException('auth.error.token_missing', 'TOKEN_MISSING');
        }

        $token = substr($header, 7);

        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
            $request->setAttribute('user_id', (int)$decoded->sub);
        } catch (ExpiredException) {
            throw new UnauthorizedException('auth.error.token_expired', 'TOKEN_EXPIRED');
        } catch (\Throwable) {
            throw new UnauthorizedException('auth.error.token_invalid', 'TOKEN_INVALID');
        }
    }
}
