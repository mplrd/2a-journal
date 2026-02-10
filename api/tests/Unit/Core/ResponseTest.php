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
