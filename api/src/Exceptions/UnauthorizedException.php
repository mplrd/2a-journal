<?php

namespace App\Exceptions;

class UnauthorizedException extends HttpException
{
    public function __construct(string $messageKey, string $errorCode = 'TOKEN_MISSING')
    {
        parent::__construct($errorCode, $messageKey, null, 401);
    }
}
