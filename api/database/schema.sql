-- ============================================================================
-- Trading Journal - Database Schema
-- Version: 5.3
-- Database: 2ai_tools_journal (MariaDB, utf8mb4_unicode_ci)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. USERS
-- ============================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    role ENUM('USER','ADMIN') NOT NULL DEFAULT 'USER',
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    timezone VARCHAR(50) NOT NULL DEFAULT 'Europe/Paris',
    default_currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    locale VARCHAR(5) NOT NULL DEFAULT 'fr',
    theme VARCHAR(20) NOT NULL DEFAULT 'light',
    be_threshold_percent DECIMAL(6,4) NOT NULL DEFAULT 0,
    default_page_size INT UNSIGNED NOT NULL DEFAULT 10,
    bypass_subscription TINYINT(1) NOT NULL DEFAULT 0,
    grace_period_end TIMESTAMP NULL DEFAULT NULL,
    stripe_customer_id VARCHAR(255) NULL DEFAULT NULL,
    profile_picture VARCHAR(255) NULL DEFAULT NULL,
    onboarding_completed_at TIMESTAMP NULL DEFAULT NULL,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    UNIQUE KEY uk_users_email (email),
    UNIQUE KEY uk_users_stripe_customer (stripe_customer_id),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. ACCOUNTS
-- ============================================================================
CREATE TABLE IF NOT EXISTS accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    account_type ENUM('BROKER_DEMO','BROKER_LIVE','PROP_FIRM') NOT NULL DEFAULT 'BROKER_DEMO',
    stage ENUM('CHALLENGE','VERIFICATION','FUNDED') NULL DEFAULT NULL,
    broker VARCHAR(100) NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    initial_capital DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    current_capital DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    max_drawdown DECIMAL(10,2) NULL DEFAULT NULL,
    daily_drawdown DECIMAL(10,2) NULL DEFAULT NULL,
    profit_target DECIMAL(10,2) NULL DEFAULT NULL,
    profit_split DECIMAL(5,2) NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    KEY idx_accounts_user_id (user_id),
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. SYMBOLS (per-user assets)
-- ============================================================================
CREATE TABLE IF NOT EXISTS symbols (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('INDEX','FOREX','CRYPTO','STOCK','COMMODITY','OTHER') NOT NULL DEFAULT 'OTHER',
    point_value DECIMAL(10,5) NOT NULL DEFAULT 1.00000,
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    KEY idx_symbols_user_id (user_id),
    UNIQUE KEY uk_symbols_user_code (user_id, code),
    CONSTRAINT fk_symbols_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3b. SETUPS (per-user trading setups dictionary)
-- ============================================================================
CREATE TABLE IF NOT EXISTS setups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    category ENUM('timeframe','pattern','context') NOT NULL DEFAULT 'pattern',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_setups_user_id (user_id),
    UNIQUE KEY uk_setups_user_label (user_id, label),
    CONSTRAINT fk_setups_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. POSITIONS (base table: shared fields for orders & trades)
-- ============================================================================
CREATE TABLE IF NOT EXISTS positions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    direction ENUM('BUY','SELL') NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    entry_price DECIMAL(15,5) NOT NULL,
    size DECIMAL(10,5) NOT NULL,
    setup TEXT NULL DEFAULT NULL,
    sl_points DECIMAL(10,2) NULL DEFAULT NULL,
    sl_price DECIMAL(15,5) NULL DEFAULT NULL,
    be_points DECIMAL(10,2) NULL DEFAULT NULL,
    be_price DECIMAL(15,5) NULL DEFAULT NULL,
    be_size DECIMAL(10,5) NULL DEFAULT NULL,
    targets JSON NULL DEFAULT NULL,
    notes TEXT NULL,
    import_batch_id INT UNSIGNED NULL DEFAULT NULL,
    external_id VARCHAR(128) NULL DEFAULT NULL,
    position_type ENUM('ORDER','TRADE') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_positions_user_id (user_id),
    KEY idx_positions_account_id (account_id),
    KEY idx_positions_symbol (symbol),
    KEY idx_positions_type (position_type),
    KEY idx_positions_import_batch (import_batch_id),
    CONSTRAINT fk_positions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_positions_account FOREIGN KEY (account_id)
        REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. ORDERS (extends positions for pending orders)
-- ============================================================================
CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL DEFAULT NULL,
    status ENUM('PENDING','EXECUTED','CANCELLED','EXPIRED') NOT NULL DEFAULT 'PENDING',

    UNIQUE KEY uk_orders_position (position_id),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_position FOREIGN KEY (position_id)
        REFERENCES positions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. TRADES (extends positions for active/closed trades)
-- ============================================================================
CREATE TABLE IF NOT EXISTS trades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id INT UNSIGNED NOT NULL,
    source_order_id INT UNSIGNED NULL DEFAULT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL DEFAULT NULL,
    remaining_size DECIMAL(10,5) NOT NULL,
    be_reached TINYINT(1) NOT NULL DEFAULT 0,
    avg_exit_price DECIMAL(15,5) NULL DEFAULT NULL,
    pnl DECIMAL(15,2) NULL DEFAULT NULL,
    pnl_percent DECIMAL(8,4) NULL DEFAULT NULL,
    risk_reward DECIMAL(8,4) NULL DEFAULT NULL,
    duration_minutes INT UNSIGNED NULL DEFAULT NULL,
    status ENUM('OPEN','SECURED','CLOSED') NOT NULL DEFAULT 'OPEN',
    exit_type ENUM('BE','TP','SL','MANUAL') NULL DEFAULT NULL,

    UNIQUE KEY uk_trades_position (position_id),
    KEY idx_trades_source_order (source_order_id),
    KEY idx_trades_status (status),
    KEY idx_trades_opened_at (opened_at),
    KEY idx_trades_closed_at (closed_at),
    CONSTRAINT fk_trades_position FOREIGN KEY (position_id)
        REFERENCES positions (id) ON DELETE CASCADE,
    CONSTRAINT fk_trades_source_order FOREIGN KEY (source_order_id)
        REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. PARTIAL_EXITS
-- ============================================================================
CREATE TABLE IF NOT EXISTS partial_exits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trade_id INT UNSIGNED NOT NULL,
    exited_at DATETIME NOT NULL,
    exit_price DECIMAL(15,5) NOT NULL,
    size DECIMAL(10,5) NOT NULL,
    exit_type ENUM('BE','TP','SL','MANUAL') NOT NULL,
    target_id VARCHAR(36) NULL DEFAULT NULL,
    pnl DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_partial_exits_trade (trade_id),
    CONSTRAINT fk_partial_exits_trade FOREIGN KEY (trade_id)
        REFERENCES trades (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. STATUS_HISTORY (audit trail)
-- ============================================================================
CREATE TABLE IF NOT EXISTS status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('ORDER','TRADE','ACCOUNT','POSITION') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    previous_status VARCHAR(50) NULL DEFAULT NULL,
    new_status VARCHAR(50) NOT NULL,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    trigger_type ENUM('MANUAL','SYSTEM','WEBHOOK','BROKER_API') NOT NULL DEFAULT 'MANUAL',
    details JSON NULL DEFAULT NULL,

    KEY idx_status_history_entity (entity_type, entity_id),
    KEY idx_status_history_user (user_id),
    CONSTRAINT fk_status_history_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. SHARE_LINKS
-- ============================================================================
CREATE TABLE IF NOT EXISTS share_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    hide_sl TINYINT(1) NOT NULL DEFAULT 0,
    hide_size TINYINT(1) NOT NULL DEFAULT 0,
    hide_account TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_share_links_token (token),
    KEY idx_share_links_position (position_id),
    CONSTRAINT fk_share_links_position FOREIGN KEY (position_id)
        REFERENCES positions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. REFRESH_TOKENS (JWT auth)
-- ============================================================================
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_refresh_tokens_token (token),
    KEY idx_refresh_tokens_user (user_id),
    KEY idx_refresh_tokens_expires (expires_at),
    CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. EMAIL_VERIFICATION_TOKENS
-- ============================================================================
CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_evt_token (token),
    KEY idx_evt_user (user_id),
    CONSTRAINT fk_evt_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. PASSWORD_RESET_TOKENS
-- ============================================================================
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_prt_token (token),
    KEY idx_prt_user (user_id),
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12bis. SSO_CODES (cross-SPA one-time auth codes, see migration 011)
-- ============================================================================
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

-- ============================================================================
-- 13. RATE_LIMITS (brute-force protection)
-- ============================================================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(100) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_rate_limits_ip_endpoint (ip, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 14. IMPORT_BATCHES (import history audit trail)
-- ============================================================================
CREATE TABLE IF NOT EXISTS import_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    broker_template VARCHAR(50) NULL DEFAULT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_positions INT UNSIGNED NOT NULL DEFAULT 0,
    imported_trades INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_duplicates INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_errors INT UNSIGNED NOT NULL DEFAULT 0,
    error_log JSON NULL DEFAULT NULL,
    status ENUM('PENDING','PROCESSING','COMPLETED','FAILED','ROLLED_BACK') NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,

    KEY idx_import_batches_user (user_id),
    KEY idx_import_batches_account (account_id),
    CONSTRAINT fk_import_batches_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_import_batches_account FOREIGN KEY (account_id)
        REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 15. SYMBOL_ALIASES (broker symbol → journal symbol mapping)
-- ============================================================================
CREATE TABLE IF NOT EXISTS symbol_aliases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    broker_symbol VARCHAR(100) NOT NULL,
    journal_symbol VARCHAR(50) NOT NULL,
    broker_template VARCHAR(50) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_symbol_aliases_user_broker (user_id, broker_symbol, broker_template),
    KEY idx_symbol_aliases_user (user_id),
    CONSTRAINT fk_symbol_aliases_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 16. CUSTOM_FIELD_DEFINITIONS (user-defined fields for trades)
-- ============================================================================
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
    CONSTRAINT fk_cfd_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 17. CUSTOM_FIELD_VALUES (values per trade)
-- ============================================================================
CREATE TABLE IF NOT EXISTS custom_field_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    custom_field_id INT UNSIGNED NOT NULL,
    trade_id INT UNSIGNED NOT NULL,
    value TEXT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_cfv_field_trade (custom_field_id, trade_id),
    KEY idx_cfv_trade_id (trade_id),
    CONSTRAINT fk_cfv_field FOREIGN KEY (custom_field_id)
        REFERENCES custom_field_definitions (id) ON DELETE CASCADE,
    CONSTRAINT fk_cfv_trade FOREIGN KEY (trade_id)
        REFERENCES trades (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. BROKER_CONNECTIONS (API credentials per account)
CREATE TABLE IF NOT EXISTS broker_connections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    provider ENUM('CTRADER','METAAPI') NOT NULL,
    status ENUM('PENDING','ACTIVE','ERROR','REVOKED') NOT NULL DEFAULT 'PENDING',
    credentials_encrypted TEXT NOT NULL,
    credentials_iv VARCHAR(32) NOT NULL,
    last_sync_at TIMESTAMP NULL DEFAULT NULL,
    last_sync_status ENUM('SUCCESS','PARTIAL','FAILED') NULL DEFAULT NULL,
    last_sync_error TEXT NULL DEFAULT NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    sync_cursor VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_broker_conn_account (account_id),
    KEY idx_broker_conn_user (user_id),
    CONSTRAINT fk_broker_conn_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_broker_conn_account FOREIGN KEY (account_id)
        REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. SYNC_LOGS (audit trail per sync execution)
CREATE TABLE IF NOT EXISTS sync_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    broker_connection_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    import_batch_id INT UNSIGNED NULL DEFAULT NULL,
    status ENUM('STARTED','SUCCESS','PARTIAL','FAILED') NOT NULL,
    deals_fetched INT UNSIGNED NOT NULL DEFAULT 0,
    deals_imported INT UNSIGNED NOT NULL DEFAULT 0,
    deals_skipped INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL DEFAULT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,

    KEY idx_sync_logs_conn (broker_connection_id),
    CONSTRAINT fk_sync_logs_conn FOREIGN KEY (broker_connection_id)
        REFERENCES broker_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 20. SUBSCRIPTIONS (Stripe billing, 1-1 with users)
-- ============================================================================
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    stripe_subscription_id VARCHAR(255) NOT NULL,
    status ENUM('incomplete','incomplete_expired','trialing','active','past_due','canceled','unpaid','paused') NOT NULL,
    current_period_end TIMESTAMP NULL DEFAULT NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_subscriptions_user (user_id),
    UNIQUE KEY uk_subscriptions_stripe (stripe_subscription_id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 21. STRIPE_WEBHOOK_EVENTS (idempotency ledger)
-- ============================================================================
CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_webhook_stripe_event (stripe_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 22. SYMBOL_ACCOUNT_SETTINGS (point_value + default lot size per (symbol, account))
-- ============================================================================
CREATE TABLE IF NOT EXISTS symbol_account_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    point_value DECIMAL(10,5) NOT NULL DEFAULT 1.00000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_sas_symbol_account (symbol_id, account_id),
    KEY idx_sas_symbol (symbol_id),
    KEY idx_sas_account (account_id),
    CONSTRAINT fk_sas_symbol FOREIGN KEY (symbol_id) REFERENCES symbols (id) ON DELETE CASCADE,
    CONSTRAINT fk_sas_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 22. PLATFORM_SETTINGS (admin BO — runtime-editable app config)
-- ============================================================================
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

SET FOREIGN_KEY_CHECKS = 1;
