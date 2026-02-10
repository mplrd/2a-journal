<?php

namespace Tests\Unit\Core;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testCreateSetsMethodAndUri(): void
    {
        $request = Request::create('POST', '/users');

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/users', $request->getUri());
    }

    public function testGetBodyReturnsAllData(): void
    {
        $request = Request::create('POST', '/users', ['name' => 'John', 'email' => 'john@test.com']);

        $this->assertSame(['name' => 'John', 'email' => 'john@test.com'], $request->getBody());
    }

    public function testGetBodyReturnsSingleKey(): void
    {
        $request = Request::create('POST', '/users', ['name' => 'John']);

        $this->assertSame('John', $request->getBody('name'));
    }

    public function testGetBodyReturnsDefaultForMissingKey(): void
    {
        $request = Request::create('POST', '/users', []);

        $this->assertNull($request->getBody('name'));
        $this->assertSame('default', $request->getBody('name', 'default'));
    }

    public function testGetQueryReturnsAllParams(): void
    {
        $request = Request::create('GET', '/users', [], ['page' => '1', 'limit' => '10']);

        $this->assertSame(['page' => '1', 'limit' => '10'], $request->getQuery());
    }

    public function testGetQueryReturnsSingleParam(): void
    {
        $request = Request::create('GET', '/users', [], ['page' => '1']);

        $this->assertSame('1', $request->getQuery('page'));
    }

    public function testGetQueryReturnsDefaultForMissingParam(): void
    {
        $request = Request::create('GET', '/users');

        $this->assertNull($request->getQuery('page'));
        $this->assertSame('1', $request->getQuery('page', '1'));
    }

    public function testGetHeaderNormalizesName(): void
    {
        $request = Request::create('GET', '/users', [], [], ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $request->getHeader('Content-Type'));
        $this->assertSame('application/json', $request->getHeader('CONTENT-TYPE'));
        $this->assertSame('application/json', $request->getHeader('content_type'));
    }

    public function testGetHeaderReturnsNullForMissing(): void
    {
        $request = Request::create('GET', '/users');

        $this->assertNull($request->getHeader('Authorization'));
    }

    public function testRouteParams(): void
    {
        $request = Request::create('GET', '/users/42');
        $request->setRouteParams(['id' => '42']);

        $this->assertSame(['id' => '42'], $request->getRouteParams());
        $this->assertSame('42', $request->getRouteParam('id'));
        $this->assertNull($request->getRouteParam('missing'));
        $this->assertSame('default', $request->getRouteParam('missing', 'default'));
    }
}
