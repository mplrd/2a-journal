-- Migration 030 — Notebook (carnet de notes)
--
-- Adds the personal notebook module (docs/73-carnet-notes.md): a free-form space
-- where a user keeps trading notes (REX, points of attention, setup screenshots)
-- organised under their OWN categories. 2A never analyses these notes — they are
-- purely a user-facing memory aid.
--
--   - note_categories: user-defined buckets (Money Management, Trading setup,
--     Position management, …). Per-user, soft-deleted. Unlike `setups.category`
--     (a fixed ENUM), these are fully custom rows the user creates/renames/deletes.
--     A NULL category on a note means "Autre" (uncategorised) — always available
--     without a row.
--   - notes: one row per note. `note_date` is mandatory (the date the user
--     attaches to the observation), `is_pinned` surfaces the note on the
--     dashboard home. `category_id` is SET NULL when its category is removed, so
--     the note falls back to "Autre" instead of disappearing.
--   - note_attachments: images attached to a note (e.g. a chart screenshot).
--     Files live OUTSIDE the public web root (api/storage/uploads/notes/) and are
--     streamed through an authenticated endpoint; the DB only stores metadata.
--
-- Additive only — all three tables are new. Idempotent under the entrypoint
-- migrate.php loop (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS note_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_note_categories_user (user_id),
    UNIQUE KEY uk_note_categories_user_label (user_id, label),
    CONSTRAINT fk_note_categories_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL DEFAULT NULL,
    title VARCHAR(150) NULL DEFAULT NULL,
    content TEXT NOT NULL,
    note_date DATE NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_notes_user (user_id),
    KEY idx_notes_category (category_id),
    KEY idx_notes_user_pinned (user_id, is_pinned),
    CONSTRAINT fk_notes_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_category FOREIGN KEY (category_id)
        REFERENCES note_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS note_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id BIGINT UNSIGNED NOT NULL,
    stored_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_note_attachment_note (note_id),
    CONSTRAINT fk_note_attachment_note FOREIGN KEY (note_id)
        REFERENCES notes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
