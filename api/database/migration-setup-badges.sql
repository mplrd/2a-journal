-- Migration: Setup badges (multi-value)
-- Converts positions.setup from VARCHAR(255) single value to TEXT JSON array
-- Creates setups dictionary table for per-user autocomplete

-- 1. Create setups table
CREATE TABLE IF NOT EXISTS setups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_setups_user_id (user_id),
    UNIQUE KEY uk_setups_user_label (user_id, label),
    CONSTRAINT fk_setups_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Alter positions.setup from VARCHAR(255) to TEXT
ALTER TABLE positions MODIFY setup TEXT NOT NULL;

-- 3. Migrate existing data: wrap single string values into JSON arrays
UPDATE positions SET setup = CONCAT('["', REPLACE(setup, '"', '\\"'), '"]') WHERE setup NOT LIKE '[%';
