<?php

namespace App\Exceptions;

class ForbiddenException extends HttpException
{
    public function __construct(string $messageKey)
    {
        parent::__construct('FORBIDDEN', $messageKey, null, 403);
    }
}
