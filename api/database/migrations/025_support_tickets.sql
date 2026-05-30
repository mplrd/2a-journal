-- Migration 025 — Support tickets
--
-- Adds the storage for the in-app support module (docs/NN-support.md):
--   - support_tickets: one row per request opened by a user. A ticket carries a
--     `type` (SUPPORT / BUG / FEATURE), a lifecycle `status`, an admin-set
--     `priority`, a short `subject` and timestamps. `closed_at` is stamped when
--     the status moves to CLOSED.
--   - support_ticket_messages: the discussion thread. One row per message, from
--     either the creator or an admin. `author_is_admin` snapshots the side at
--     write-time so the thread renders correctly even if the author account is
--     later deleted (author_id then becomes NULL via SET NULL).
--   - support_ticket_attachments: images attached to a ticket / message. Files
--     live OUTSIDE the public web root (api/storage/uploads/tickets/) and are
--     streamed through an authenticated endpoint, so the DB only stores metadata.
--
-- Additive only — all three tables are new. Idempotent under repeated deploy via
-- the entrypoint migrate.php loop (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('SUPPORT','BUG','FEATURE') NOT NULL,
    status ENUM('OPEN','IN_PROGRESS','WAITING_USER','RESOLVED','CLOSED') NOT NULL DEFAULT 'OPEN',
    priority ENUM('LOW','NORMAL','HIGH') NOT NULL DEFAULT 'NORMAL',
    subject VARCHAR(200) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_support_ticket_user (user_id),
    KEY idx_support_ticket_status (status),
    KEY idx_support_ticket_type (type),
    CONSTRAINT fk_support_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NULL,
    author_is_admin TINYINT(1) NOT NULL DEFAULT 0,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_support_message_ticket (ticket_id),
    CONSTRAINT fk_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_message_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    stored_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_support_attachment_ticket (ticket_id),
    CONSTRAINT fk_support_attachment_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_attachment_message FOREIGN KEY (message_id) REFERENCES support_ticket_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
