<?php

namespace Tests\Unit\Core;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testSuccessReturnsCorrectFormat(): void
    {
        $response = Response::success(['foo' => 'bar']);

        $body = $response->getBody();
        $this->assertTrue($body['success']);
        $this->assertSame(['foo' => 'bar'], $body['data']);
        $this->assertArrayNotHasKey('meta', $body);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSuccessWithMeta(): void
    {
        $response = Response::success(['foo' => 'bar'], ['page' => 1]);

        $body = $response->getBody();
        $this->assertTrue($body['success']);
        $this->assertSame(['page' => 1], $body['meta']);
    }

    public function testSuccessWithCustomStatusCode(): void
    {
        $response = Response::success([], null, 201);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testSuccessCarriesAnAbsentPayloadAsNull(): void
    {
        // "Found nothing, and that is a normal answer" is a real case — asking
        // an account whether it has a broker connection, for one. The typed
        // array parameter turned it into a TypeError, so a 200 with no payload
        // came out as a 500: GET /broker/connections?account_id=N failed for
        // every account before its first connection, for four months.
        //
        // Null, not []: an empty array is truthy in JavaScript, so the client
        // would read "no connection" as "here is a connection".
        $response = Response::success(null);

        $body = $response->getBody();
        $this->assertTrue($body['success']);
        $this->assertNull($body['data']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"data":null', json_encode($body));
    }

    public function testErrorReturnsCorrectFormat(): void
    {
        $response = Response::error('VALIDATION_ERROR', 'error.validation', 'email', 422);

        $body = $response->getBody();
        $this->assertFalse($body['success']);
        $this->assertSame('VALIDATION_ERROR', $body['error']['code']);
        $this->assertSame('error.validation', $body['error']['message_key']);
        $this->assertSame('email', $body['error']['field']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testErrorWithoutField(): void
    {
        $response = Response::error('NOT_FOUND', 'error.not_found', null, 404);

        $body = $response->getBody();
        $this->assertFalse($body['success']);
        $this->assertArrayNotHasKey('field', $body['error']);
    }

    public function testErrorDefaultsTo400(): void
    {
        $response = Response::error('BAD_REQUEST', 'error.bad_request');

        $this->assertSame(400, $response->getStatusCode());
    }
}
