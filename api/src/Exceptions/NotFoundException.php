<?php

namespace App\Exceptions;

class NotFoundException extends HttpException
{
    public function __construct(string $messageKey = 'error.not_found')
    {
        parent::__construct('NOT_FOUND', $messageKey, null, 404);
    }
}
