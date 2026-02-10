<?php

namespace App\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    private string $errorCode;
    private string $messageKey;
    private ?string $field;
    private int $statusCode;

    public function __construct(
        string $errorCode,
        string $messageKey,
        ?string $field = null,
        int $statusCode = 400
    ) {
        parent::__construct($messageKey);
        $this->errorCode = $errorCode;
        $this->messageKey = $messageKey;
        $this->field = $field;
        $this->statusCode = $statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
