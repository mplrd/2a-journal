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

    public function testGetClientIpDefaultsTo127(): void
    {
        $request = Request::create('GET', '/test');

        $this->assertSame('127.0.0.1', $request->getClientIp());
    }

    // ── capture() and the real client address ───────────────────────

    public function testCaptureIgnoresForwardedHeadersWhenNoProxyIsTrusted(): void
    {
        // The default, and what local and test environments run: no proxy in
        // front, so a forwarded header is just something a caller sent.
        $this->withServer([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], function () {
            $this->assertSame('203.0.113.7', Request::capture()->getClientIp());
        });
    }

    public function testCaptureUsesTheForwardedAddressFromATrustedProxy(): void
    {
        // Wiring check: the ranges reach ClientIpResolver, which has its own
        // suite for the matching rules.
        $this->withServer([
            'REMOTE_ADDR' => '100.64.0.14',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        ], function () {
            $this->assertSame(
                '198.51.100.1',
                Request::capture(['100.64.0.0/10'])->getClientIp(),
            );
        });
    }

    /**
     * capture() reads superglobals, so swap $_SERVER for the call and put the
     * original back whatever happens.
     *
     * @param array<string, mixed> $server
     */
    private function withServer(array $server, callable $run): void
    {
        $original = $_SERVER;
        $_SERVER = $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'];

        try {
            $run();
        } finally {
            $_SERVER = $original;
        }
    }
}
