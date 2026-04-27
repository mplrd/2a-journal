-- Migration 008 — User roles + suspension
--
-- Adds two columns to `users` driving the admin BO (docs/specs/admin-backoffice-v1.md):
--   * `role` ENUM('USER','ADMIN') — gate the /admin/* endpoints via RequireAdminMiddleware
--   * `suspended_at` TIMESTAMP NULL — when set, AuthService refuses login
--
-- Idempotent via INFORMATION_SCHEMA checks (same pattern as migration 006), so a
-- re-run is safe and a partial failure can be retried without manual cleanup.

SET @role_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
);
SET @sql := IF(@role_exists = 0,
    "ALTER TABLE users ADD COLUMN role ENUM('USER','ADMIN') NOT NULL DEFAULT 'USER' AFTER email",
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @suspended_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'suspended_at'
);
SET @sql := IF(@suspended_exists = 0,
    'ALTER TABLE users ADD COLUMN suspended_at TIMESTAMP NULL DEFAULT NULL AFTER role',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_role'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_users_role ON users(role)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
