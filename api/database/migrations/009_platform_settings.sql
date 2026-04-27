-- Migration 009 — Platform settings table
--
-- Backs the admin BO settings panel (docs/specs/admin-backoffice-v1.md § 3.3).
-- The PlatformSettingsService reads from this table first, then falls back to
-- the corresponding env var, then null. No hardcoded defaults in the resolver.
--
-- value_type drives client-side input rendering and server-side validation
-- before persistence.

CREATE TABLE IF NOT EXISTS platform_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    value_type ENUM('BOOL','INT','STRING') NOT NULL,
    description VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id INT UNSIGNED NULL,

    UNIQUE KEY uk_platform_settings_key (setting_key),
    KEY idx_platform_settings_updated_by (updated_by_user_id),
    CONSTRAINT fk_platform_settings_user FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
