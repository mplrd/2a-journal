<?php

namespace App\Enums;

enum BrokerProvider: string
{
    case CTRADER = 'CTRADER';
    case METAAPI = 'METAAPI';
    case OUINEX = 'OUINEX';

    /**
     * Prefix applied to `positions.external_id` for rows synced from this
     * provider's positions/trades feed. Single source of truth so the diff
     * services scope themselves correctly regardless of which provider
     * triggered the sync.
     *
     *   OUINEX  → 'ouinex_'  → matches 'ouinex_<margin_position_id>'
     *   BINGX   → 'bingx_'   → matches 'bingx_<perp_position_id>'
     *   CTRADER → 'ctrader_' → matches 'ctrader_<position_id>'
     *   METAAPI → 'metaapi_' → matches 'metaapi_<position_id>'
     */
    public function externalIdPrefix(): string
    {
        return strtolower($this->value) . '_';
    }

    /**
     * Prefix applied to `positions.external_id` for rows synced from this
     * provider's pending-orders feed. Distinct from externalIdPrefix() so
     * the position diff and the order diff don't collide:
     *
     *   OUINEX → 'ouinex_order_' → matches 'ouinex_order_<order_id>'
     */
    public function orderExternalIdPrefix(): string
    {
        return strtolower($this->value) . '_order_';
    }
}
