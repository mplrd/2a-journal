<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown before a sync when the connection has already spent its daily request
 * allowance at the broker (evolution #22).
 *
 * This is NOT a connection failure. It is our own decision to stop asking, and
 * the scheduler treats it exactly like a rate-limit deferral: the connection
 * stays ACTIVE, no failure is recorded, the breaker is left alone, and the run
 * resumes on its own when the counter rolls over at UTC midnight. Counting a
 * self-imposed pause as a failure would eventually deactivate a perfectly
 * healthy connection.
 *
 * The reason the budget exists at all: FTMO disabled a real trading account on
 * 2026-08-07 for exceeding roughly 2 000 server requests in a day, with no EA
 * attached — only the journal's read-only sync. Losing a funded account is the
 * one consequence in this backlog that cannot be undone by a fix.
 *
 * Carries the spend and the cap so the caller can say how far over it is
 * without querying again.
 */
class BrokerDailyBudgetException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $spent,
        private readonly int $budget,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Requests already spent today on this connection. */
    public function getSpent(): int
    {
        return $this->spent;
    }

    /** The daily cap that was reached. */
    public function getBudget(): int
    {
        return $this->budget;
    }
}
