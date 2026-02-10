<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\UnauthorizedException;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class AuthExceptionsTest extends TestCase
{
    public function testValidationExceptionDefaults(): void
    {
        $e = new ValidationException('auth.error.field_required', 'email');

        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame('VALIDATION_ERROR', $e->getErrorCode());
        $this->assertSame('auth.error.field_required', $e->getMessageKey());
        $this->assertSame('email', $e->getField());
    }

    public function testValidationExceptionWithoutField(): void
    {
        $e = new ValidationException('auth.error.invalid_email');

        $this->assertSame(422, $e->getStatusCode());
        $this->assertNull($e->getField());
    }

    public function testUnauthorizedExceptionDefaults(): void
    {
        $e = new UnauthorizedException('auth.error.token_missing');

        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('TOKEN_MISSING', $e->getErrorCode());
        $this->assertSame('auth.error.token_missing', $e->getMessageKey());
        $this->assertNull($e->getField());
    }

    public function testUnauthorizedExceptionWithCustomCode(): void
    {
        $e = new UnauthorizedException('auth.error.token_expired', 'TOKEN_EXPIRED');

        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('TOKEN_EXPIRED', $e->getErrorCode());
        $this->assertSame('auth.error.token_expired', $e->getMessageKey());
    }
}
