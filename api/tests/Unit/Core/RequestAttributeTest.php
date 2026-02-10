<?php

namespace Tests\Unit\Core;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

class RequestAttributeTest extends TestCase
{
    public function testSetAndGetAttribute(): void
    {
        $request = Request::create('GET', '/test');
        $request->setAttribute('user_id', 42);

        $this->assertSame(42, $request->getAttribute('user_id'));
    }

    public function testGetAttributeReturnsNullByDefault(): void
    {
        $request = Request::create('GET', '/test');

        $this->assertNull($request->getAttribute('missing'));
    }

    public function testGetAttributeReturnsCustomDefault(): void
    {
        $request = Request::create('GET', '/test');

        $this->assertSame('fallback', $request->getAttribute('missing', 'fallback'));
    }
}
