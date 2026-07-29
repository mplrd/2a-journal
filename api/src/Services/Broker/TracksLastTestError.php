<?php

namespace App\Services\Broker;

/**
 * Shared implementation of ConnectorInterface::getLastTestError().
 *
 * testConnection() returns a bare bool, which tells the user nothing: a
 * rotated cTrader clientSecret and an unreachable host both surface as
 * "false". Connectors record the underlying reason here so the API can hand
 * the broker's own words back to the user ("CH_CLIENT_AUTH_FAILURE - wrong
 * clientSecret") instead of a generic failure.
 */
trait TracksLastTestError
{
    private ?string $lastTestError = null;

    public function getLastTestError(): ?string
    {
        return $this->lastTestError;
    }

    /** Record a failed connection test and return false, for `return $this->failedTest($e);`. */
    private function failedTest(\Throwable $e): bool
    {
        $this->lastTestError = $e->getMessage();
        return false;
    }

    private function clearTestError(): void
    {
        $this->lastTestError = null;
    }
}
