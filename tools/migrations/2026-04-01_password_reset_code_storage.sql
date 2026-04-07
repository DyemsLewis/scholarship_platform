-- 2026-04-01 Store password reset codes in users table
-- Safe to run multiple times (uses INFORMATION_SCHEMA checks).

SET @db_name = DATABASE();

-- users.password_reset_code_hash
SET @has_password_reset_code_hash = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_reset_code_hash'
);

SET @sql = IF(
    @has_password_reset_code_hash = 0,
    'ALTER TABLE users ADD COLUMN password_reset_code_hash VARCHAR(64) NULL AFTER password',
    'SELECT ''users.password_reset_code_hash already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.password_reset_attempts
SET @has_password_reset_attempts = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_reset_attempts'
);

SET @sql = IF(
    @has_password_reset_attempts = 0,
    'ALTER TABLE users ADD COLUMN password_reset_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER password_reset_code_hash',
    'SELECT ''users.password_reset_attempts already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.password_reset_sent_at
SET @has_password_reset_sent_at = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_reset_sent_at'
);

SET @sql = IF(
    @has_password_reset_sent_at = 0,
    'ALTER TABLE users ADD COLUMN password_reset_sent_at DATETIME NULL AFTER password_reset_attempts',
    'SELECT ''users.password_reset_sent_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.password_reset_expires_at
SET @has_password_reset_expires_at = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_reset_expires_at'
);

SET @sql = IF(
    @has_password_reset_expires_at = 0,
    'ALTER TABLE users ADD COLUMN password_reset_expires_at DATETIME NULL AFTER password_reset_sent_at',
    'SELECT ''users.password_reset_expires_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Optional index for cleanup/check queries
SET @has_idx_password_reset_expires = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_password_reset_expires_at'
);

SET @sql = IF(
    @has_idx_password_reset_expires = 0,
    'ALTER TABLE users ADD KEY idx_users_password_reset_expires_at (password_reset_expires_at)',
    'SELECT ''idx_users_password_reset_expires_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Clear stale expired reset codes
UPDATE users
SET
    password_reset_code_hash = NULL,
    password_reset_attempts = 0,
    password_reset_sent_at = NULL,
    password_reset_expires_at = NULL
WHERE password_reset_expires_at IS NOT NULL
  AND password_reset_expires_at < NOW();
