<?php

namespace App\Exceptions;

class TooManyRequestsException extends HttpException
{
    public function __construct(string $messageKey = 'error.rate_limit_exceeded')
    {
        parent::__construct('TOO_MANY_REQUESTS', $messageKey, null, 429);
    }
}
