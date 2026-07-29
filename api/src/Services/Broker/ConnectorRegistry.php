<?php

namespace App\Services\Broker;

use App\Enums\BrokerProvider;

/**
 * Maps a provider value to its connector.
 *
 * BrokerSyncService still resolves connectors through its own private match()
 * — see docs/evolutions.md, it should adopt this registry so the mapping lives
 * in exactly one place.
 */
class ConnectorRegistry
{
    public function __construct(
        private ConnectorInterface $ctrader,
        private ConnectorInterface $metaApi,
        private ConnectorInterface $ouinex,
        private ConnectorInterface $bingx,
    ) {}

    public function get(string $provider): ConnectorInterface
    {
        return match (BrokerProvider::from($provider)) {
            BrokerProvider::CTRADER => $this->ctrader,
            BrokerProvider::METAAPI => $this->metaApi,
            BrokerProvider::OUINEX => $this->ouinex,
            BrokerProvider::BINGX => $this->bingx,
        };
    }
}
