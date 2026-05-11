-- Migration 018 — Add OUINEX to broker_connections.provider ENUM (E-02 Phase 1)
--
-- Adds the 'OUINEX' value to the provider ENUM so we can persist Ouinex
-- broker connections alongside cTrader and MetaApi. Additive only: existing
-- rows are untouched (their provider keeps its current value), and the
-- migration is idempotent under MariaDB's MODIFY COLUMN semantics — re-running
-- it just re-applies the same definition.

ALTER TABLE broker_connections
    MODIFY COLUMN provider ENUM('CTRADER','METAAPI','OUINEX') NOT NULL;
