<?php

namespace App\Middlewares;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Exceptions\TooManyRequestsException;
use App\Repositories\RateLimitRepository;

class RateLimitMiddleware implements MiddlewareInterface
{
    private RateLimitRepository $repo;
    private int $maxAttempts;
    private int $windowSeconds;
    private string $endpoint;

    public function __construct(RateLimitRepository $repo, int $maxAttempts, int $windowSeconds, string $endpoint)
    {
        $this->repo = $repo;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->endpoint = $endpoint;
    }

    public function handle(Request $request): void
    {
        $ip = $request->getClientIp();

        $this->repo->increment($ip, $this->endpoint, $this->windowSeconds);
        $attempts = $this->repo->getAttempts($ip, $this->endpoint, $this->windowSeconds);

        if ($attempts > $this->maxAttempts) {
            throw new TooManyRequestsException();
        }
    }
}
