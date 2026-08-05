<?php

namespace App\Services\Broker;

/**
 * Lets a connector build its DealNormalizer on the timezone the journal's
 * DATETIME columns are expressed in.
 *
 * Those columns hold LOCAL wall-clock time — the trade form stores whatever the
 * user typed into the date picker. Brokers, on the other hand, all report
 * instants (epoch ms or ISO-8601 with an offset), and the connectors used to
 * render them as UTC. A trade opened at 07:29 in Paris was therefore journalled
 * as 05:29 and sat two hours away from every hand-entered trade beside it.
 *
 * BrokerSyncService calls setTimezone() with `users.timezone` at the start of a
 * run. A connector that never receives one keeps writing UTC, i.e. exactly the
 * previous behaviour.
 */
trait NormalizesInUserTimezone
{
    private ?string $journalTimezone = null;

    /** @param string|null $timezone IANA name, e.g. 'Europe/Paris'. */
    public function setTimezone(?string $timezone): void
    {
        $this->journalTimezone = $timezone;
    }

    /**
     * A normalizer bound to the current timezone. Built per call rather than
     * cached so setTimezone() can never be shadowed by an instance created
     * before it — connectors are long-lived and reused across syncs.
     */
    protected function normalizer(): DealNormalizer
    {
        return new DealNormalizer($this->journalTimezone);
    }
}
