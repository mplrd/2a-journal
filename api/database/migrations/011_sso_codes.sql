-- Migration 011 — SSO one-time codes
--
-- Backs cross-SPA SSO (docs/34-sso-cross-spa.md). When an authenticated user
-- on the user SPA clicks the "Aller à l'admin" link, the SPA requests a
-- short-lived code, redirects to the admin SPA with `?code=xxx`, and the
-- admin SPA exchanges it for tokens — skipping a manual re-login.
--
-- Only the SHA-256 hash is stored: the plaintext code never lives in the DB,
-- so a database leak doesn't yield reusable session tickets. TTL is 30s.
-- used_at is set on exchange to prevent replay.

CREATE TABLE IF NOT EXISTS sso_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_hash CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_sso_codes_hash (code_hash),
    KEY idx_sso_codes_user (user_id),
    KEY idx_sso_codes_expires (expires_at),
    CONSTRAINT fk_sso_codes_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
