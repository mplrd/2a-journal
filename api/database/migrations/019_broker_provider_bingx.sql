-- Migration 019 — Add BINGX to broker_connections.provider ENUM (Phase 1)
--
-- Additive only: existing rows are untouched, and MariaDB's MODIFY COLUMN
-- semantics keep the migration idempotent on re-runs. Scope of the Phase 1
-- BingX connector is USDT-M Perpetual Futures only; Coin-M and Standard
-- Contracts will need a follow-up branch (closed-position synthesis from
-- order fills, similar to the Ouinex spot Phase 2 chantier).

ALTER TABLE broker_connections
    MODIFY COLUMN provider ENUM('CTRADER','METAAPI','OUINEX','BINGX') NOT NULL;
