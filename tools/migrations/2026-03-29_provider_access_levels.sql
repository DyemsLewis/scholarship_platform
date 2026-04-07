-- 2026-03-29 Provider role + access level support
-- Safe to run multiple times (uses INFORMATION_SCHEMA checks).

SET @db_name = DATABASE();

-- Ensure users.role supports provider
SET @has_users_role = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
);

SET @users_role_type = (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
    LIMIT 1
);

SET @sql = IF(
    @has_users_role = 0,
    'SELECT ''users.role column not found'' AS note',
    IF(
        @users_role_type LIKE '%provider%',
        'SELECT ''users.role already includes provider'' AS note',
        'ALTER TABLE users MODIFY COLUMN role ENUM(''super_admin'',''admin'',''provider'',''student'') DEFAULT ''student'''
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add users.access_level (optional, numeric role level helper)
SET @has_access_level = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'access_level'
);

SET @sql = IF(
    @has_access_level = 0,
    'ALTER TABLE users ADD COLUMN access_level TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER role',
    'SELECT ''users.access_level already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill and normalize access levels based on role
UPDATE users
SET access_level = CASE LOWER(COALESCE(role, 'student'))
    WHEN 'super_admin' THEN 90
    WHEN 'admin' THEN 70
    WHEN 'provider' THEN 40
    ELSE 10
END;

-- Index for faster role/access filtering
SET @has_idx_access_level = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_access_level'
);

SET @sql = IF(
    @has_idx_access_level = 0,
    'ALTER TABLE users ADD KEY idx_users_access_level (access_level)',
    'SELECT ''idx_users_access_level already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

