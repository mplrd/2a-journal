<?php

namespace Tests\Unit\Middlewares;

use App\Core\Request;
use App\Exceptions\ForbiddenException;
use App\Middlewares\RequireAdminMiddleware;
use PHPUnit\Framework\TestCase;

class RequireAdminMiddlewareTest extends TestCase
{
    private function makeRequestWithRole(?string $role): Request
    {
        $request = Request::create('GET', '/admin/users');
        if ($role !== null) {
            $request->setAttribute('user_role', $role);
        }
        return $request;
    }

    public function testAllowsAdminUser(): void
    {
        $middleware = new RequireAdminMiddleware();
        $request = $this->makeRequestWithRole('ADMIN');

        // No exception expected
        $middleware->handle($request);

        $this->assertTrue(true);
    }

    public function testRejectsRegularUser(): void
    {
        $middleware = new RequireAdminMiddleware();
        $request = $this->makeRequestWithRole('USER');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('auth.error.admin_only');

        $middleware->handle($request);
    }

    public function testRejectsRequestWithoutRoleAttribute(): void
    {
        $middleware = new RequireAdminMiddleware();
        $request = $this->makeRequestWithRole(null);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('auth.error.admin_only');

        $middleware->handle($request);
    }
}
