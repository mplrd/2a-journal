<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by a connector when the broker has applied a per-endpoint frequency
 * ban that outlasts our in-process wait cap (BingX code 100410). This is NOT a
 * hard connection failure: the scheduler treats it as a deferral — the
 * connection stays ACTIVE and the next cron run retries once the ban window has
 * elapsed. The connector paces its requests to avoid tripping the limit in the
 * first place; this exception is the fallback when a ban happens anyway.
 *
 * Carries the broker-reported unblock timestamp (epoch ms) so callers can log
 * when the endpoint becomes usable again.
 */
class BrokerRateLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $unblockAtMs = null,
        private readonly ?string $endpoint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Epoch ms after which BingX says the endpoint is usable again, if known. */
    public function getUnblockAtMs(): ?int
    {
        return $this->unblockAtMs;
    }

    /** The endpoint path that was throttled, for diagnostics. */
    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }
}
