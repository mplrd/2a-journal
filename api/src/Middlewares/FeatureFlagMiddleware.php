<?php

namespace App\Middlewares;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Exceptions\ForbiddenException;

class FeatureFlagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private bool $enabled,
        private string $messageKey = 'error.feature_disabled',
    ) {
    }

    public function handle(Request $request): void
    {
        if (!$this->enabled) {
            throw new ForbiddenException($this->messageKey);
        }
    }
}
