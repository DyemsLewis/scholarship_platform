-- 2026-04-01 Store signup email verification codes in database
-- Safe to run multiple times (uses INFORMATION_SCHEMA checks).

SET @db_name = DATABASE();

CREATE TABLE IF NOT EXISTS signup_verifications (
    email VARCHAR(255) NOT NULL,
    code_hash VARCHAR(64) NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_at DATETIME NULL,
    expires_at DATETIME NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (email),
    KEY idx_signup_verifications_expires_at (expires_at),
    KEY idx_signup_verifications_verified (verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure required columns exist for older/partial tables
SET @has_code_hash = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND COLUMN_NAME = 'code_hash'
);
SET @sql = IF(
    @has_code_hash = 0,
    'ALTER TABLE signup_verifications ADD COLUMN code_hash VARCHAR(64) NULL AFTER email',
    'SELECT ''signup_verifications.code_hash already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_verified = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND COLUMN_NAME = 'verified'
);
SET @sql = IF(
    @has_verified = 0,
    'ALTER TABLE signup_verifications ADD COLUMN verified TINYINT(1) NOT NULL DEFAULT 0 AFTER code_hash',
    'SELECT ''signup_verifications.verified already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_attempts = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND COLUMN_NAME = 'attempts'
);
SET @sql = IF(
    @has_attempts = 0,
    'ALTER TABLE signup_verifications ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER verified',
    'SELECT ''signup_verifications.attempts already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_sent_at = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND COLUMN_NAME = 'sent_at'
);
SET @sql = IF(
    @has_sent_at = 0,
    'ALTER TABLE signup_verifications ADD COLUMN sent_at DATETIME NULL AFTER attempts',
    'SELECT ''signup_verifications.sent_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_expires_at = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND COLUMN_NAME = 'expires_at'
);
SET @sql = IF(
    @has_expires_at = 0,
    'ALTER TABLE signup_verifications ADD COLUMN expires_at DATETIME NULL AFTER sent_at',
    'SELECT ''signup_verifications.expires_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_verified_at = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND COLUMN_NAME = 'verified_at'
);
SET @sql = IF(
    @has_verified_at = 0,
    'ALTER TABLE signup_verifications ADD COLUMN verified_at DATETIME NULL AFTER expires_at',
    'SELECT ''signup_verifications.verified_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_created_at = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND COLUMN_NAME = 'created_at'
);
SET @sql = IF(
    @has_created_at = 0,
    'ALTER TABLE signup_verifications ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT ''signup_verifications.created_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_updated_at = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND COLUMN_NAME = 'updated_at'
);
SET @sql = IF(
    @has_updated_at = 0,
    'ALTER TABLE signup_verifications ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SELECT ''signup_verifications.updated_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure indexes exist
SET @has_idx_expires = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND INDEX_NAME = 'idx_signup_verifications_expires_at'
);
SET @sql = IF(
    @has_idx_expires = 0,
    'ALTER TABLE signup_verifications ADD KEY idx_signup_verifications_expires_at (expires_at)',
    'SELECT ''idx_signup_verifications_expires_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_verified = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'signup_verifications'
      AND INDEX_NAME = 'idx_signup_verifications_verified'
);
SET @sql = IF(
    @has_idx_verified = 0,
    'ALTER TABLE signup_verifications ADD KEY idx_signup_verifications_verified (verified)',
    'SELECT ''idx_signup_verifications_verified already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cleanup: remove stale rows
DELETE FROM signup_verifications
WHERE expires_at IS NOT NULL
  AND expires_at < NOW();
