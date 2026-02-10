<?php

namespace App\Exceptions;

class ValidationException extends HttpException
{
    public function __construct(string $messageKey, ?string $field = null)
    {
        parent::__construct('VALIDATION_ERROR', $messageKey, $field, 422);
    }
}
