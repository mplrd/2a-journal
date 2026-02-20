-- ============================================================================
-- Trading Journal - Database Schema
-- Version: 5.1
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
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    timezone VARCHAR(50) NOT NULL DEFAULT 'Europe/Paris',
    default_currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    locale VARCHAR(5) NOT NULL DEFAULT 'fr',
    theme VARCHAR(20) NOT NULL DEFAULT 'light',
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    UNIQUE KEY uk_users_email (email)
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
    type ENUM('INDEX','FOREX','CRYPTO','STOCK','COMMODITY') NOT NULL,
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
-- 4. POSITIONS (base table: shared fields for orders & trades)
-- ============================================================================
CREATE TABLE IF NOT EXISTS positions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    direction ENUM('BUY','SELL') NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    entry_price DECIMAL(15,5) NOT NULL,
    size DECIMAL(10,4) NOT NULL,
    setup VARCHAR(255) NOT NULL,
    sl_points DECIMAL(10,2) NOT NULL,
    sl_price DECIMAL(15,5) NOT NULL,
    be_points DECIMAL(10,2) NULL DEFAULT NULL,
    be_price DECIMAL(15,5) NULL DEFAULT NULL,
    be_size DECIMAL(10,4) NULL DEFAULT NULL,
    targets JSON NULL DEFAULT NULL,
    notes TEXT NULL,
    position_type ENUM('ORDER','TRADE') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_positions_user_id (user_id),
    KEY idx_positions_account_id (account_id),
    KEY idx_positions_symbol (symbol),
    KEY idx_positions_type (position_type),
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
    remaining_size DECIMAL(10,4) NOT NULL,
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
    size DECIMAL(10,4) NOT NULL,
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
-- 11. RATE_LIMITS (brute-force protection)
-- ============================================================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    endpoint VARCHAR(100) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_rate_limits_ip_endpoint (ip, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
