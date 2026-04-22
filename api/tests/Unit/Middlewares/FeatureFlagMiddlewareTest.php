<?php

namespace Tests\Unit\Middlewares;

use App\Core\Request;
use App\Exceptions\ForbiddenException;
use App\Middlewares\FeatureFlagMiddleware;
use PHPUnit\Framework\TestCase;

class FeatureFlagMiddlewareTest extends TestCase
{
    public function testPassesWhenFlagEnabled(): void
    {
        $middleware = new FeatureFlagMiddleware(true, 'broker.error.auto_sync_disabled');
        $request = Request::create('POST', '/broker/connections');

        $middleware->handle($request);

        $this->assertTrue(true);
    }

    public function testThrowsForbiddenWhenFlagDisabled(): void
    {
        $middleware = new FeatureFlagMiddleware(false, 'broker.error.auto_sync_disabled');
        $request = Request::create('POST', '/broker/connections');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('broker.error.auto_sync_disabled');

        $middleware->handle($request);
    }

    public function testThrowsWithDefaultMessageKeyWhenNoneProvided(): void
    {
        $middleware = new FeatureFlagMiddleware(false);
        $request = Request::create('GET', '/any');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('error.feature_disabled');

        $middleware->handle($request);
    }
}
