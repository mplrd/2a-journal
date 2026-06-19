-- Migration 032 — Élargir les colonnes devise pour les stablecoins
--
-- `users.default_currency` et `accounts.currency` étaient en VARCHAR(3) (codes
-- ISO fiat à 3 lettres). Les brokers crypto règlent en stablecoins à 4 lettres
-- (USDT, USDC) : un compte BingX doit pouvoir être dénommé en USDT pour
-- correspondre à son solde courtier (sinon l'alerte de mismatch ne peut jamais
-- être levée). On élargit à VARCHAR(10).
--
-- `symbols.currency` est laissée telle quelle (devise de cotation d'un symbol,
-- sans rapport avec le règlement broker).
--
-- Idempotent : MODIFY COLUMN vers VARCHAR(10) est rejouable sans effet de bord.

ALTER TABLE users   MODIFY COLUMN default_currency VARCHAR(10) NOT NULL DEFAULT 'EUR';
ALTER TABLE accounts MODIFY COLUMN currency        VARCHAR(10) NOT NULL DEFAULT 'EUR';
