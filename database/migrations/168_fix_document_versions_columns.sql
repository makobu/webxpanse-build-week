-- Fix: Add current_version to documents and create document_versions table if missing.
-- Migration 032 may not have been applied on all environments.
-- This migration is fully idempotent and safe to run multiple times.

-- 1. Create document_versions table if it doesn't already exist.
CREATE TABLE IF NOT EXISTS `document_versions` (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    version_number INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100),
    description TEXT,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_id (document_id),
    INDEX idx_version_number (document_id, version_number),
    INDEX idx_uploaded_by (uploaded_by),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_document_version (document_id, version_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add current_version to documents table if the column is missing.
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'documents'
      AND column_name = 'current_version'
);
SET @add_col_sql := IF(
    @col_exists = 0,
    'ALTER TABLE documents ADD COLUMN current_version INT DEFAULT 1',
    'SET @noop := 1'
);
PREPARE add_col_stmt FROM @add_col_sql;
EXECUTE add_col_stmt;
DEALLOCATE PREPARE add_col_stmt;

-- 3. Add index on current_version if the column was just added (and index doesn't exist).
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'documents'
      AND index_name = 'idx_current_version'
);
SET @add_idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE documents ADD INDEX idx_current_version (current_version)',
    'SET @noop := 1'
);
PREPARE add_idx_stmt FROM @add_idx_sql;
EXECUTE add_idx_stmt;
DEALLOCATE PREPARE add_idx_stmt;

-- 4. Backfill current_version = 1 for any existing rows that have NULL.
UPDATE documents SET current_version = 1 WHERE current_version IS NULL;
