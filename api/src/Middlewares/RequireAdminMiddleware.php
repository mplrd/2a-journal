<?php

namespace App\Middlewares;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Enums\UserRole;
use App\Exceptions\ForbiddenException;

/**
 * Gate a route to ADMIN users only. Must be chained AFTER AuthMiddleware
 * which sets the `user_role` attribute from the JWT claim.
 */
class RequireAdminMiddleware implements MiddlewareInterface
{
    public function handle(Request $request): void
    {
        $role = $request->getAttribute('user_role');
        if ($role !== UserRole::ADMIN->value) {
            throw new ForbiddenException('auth.error.admin_only');
        }
    }
}
