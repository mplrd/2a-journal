-- Custom field definitions (per user)
CREATE TABLE IF NOT EXISTS custom_field_definitions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    field_type ENUM('BOOLEAN','TEXT','NUMBER','SELECT') NOT NULL,
    options JSON NULL DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_cfd_user_id (user_id),
    UNIQUE KEY uk_cfd_user_name (user_id, name),
    CONSTRAINT fk_cfd_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom field values (per trade)
CREATE TABLE IF NOT EXISTS custom_field_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    custom_field_id INT UNSIGNED NOT NULL,
    trade_id INT UNSIGNED NOT NULL,
    value TEXT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cfv_field_trade (custom_field_id, trade_id),
    KEY idx_cfv_trade_id (trade_id),
    CONSTRAINT fk_cfv_field FOREIGN KEY (custom_field_id) REFERENCES custom_field_definitions (id) ON DELETE CASCADE,
    CONSTRAINT fk_cfv_trade FOREIGN KEY (trade_id) REFERENCES trades (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
