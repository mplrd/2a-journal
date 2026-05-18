<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class BrokerOrderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $providerCode = 'UNKNOWN',
        private readonly array $providerPayload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }

    public function getProviderPayload(): array
    {
        return $this->providerPayload;
    }
}
