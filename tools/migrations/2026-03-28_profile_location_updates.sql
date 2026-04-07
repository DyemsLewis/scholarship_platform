-- 2026-03-28 Profile + Location schema updates
-- Safe to run multiple times (uses INFORMATION_SCHEMA checks).

SET @db_name = DATABASE();

-- student_data.suffix
SET @has_suffix = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'suffix'
);
SET @sql = IF(
    @has_suffix = 0,
    'ALTER TABLE student_data ADD COLUMN suffix VARCHAR(20) NULL AFTER middleinitial',
    'SELECT ''student_data.suffix already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- student_data.address
SET @has_address = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'address'
);
SET @sql = IF(
    @has_address = 0,
    'ALTER TABLE student_data ADD COLUMN address VARCHAR(255) NULL AFTER school',
    'SELECT ''student_data.address already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- student_data.parent_background
SET @has_parent_background = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'parent_background'
);
SET @sql = IF(
    @has_parent_background = 0,
    'ALTER TABLE student_data ADD COLUMN parent_background TEXT NULL AFTER address',
    'SELECT ''student_data.parent_background already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- student_location.location_name
SET @has_location_name = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_location'
      AND COLUMN_NAME = 'location_name'
);
SET @sql = IF(
    @has_location_name = 0,
    'ALTER TABLE student_location ADD COLUMN location_name VARCHAR(255) NULL AFTER longitude',
    'SELECT ''student_location.location_name already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Optional future change:
-- If you want pure address-based storage (without forcing 0,0 fallback),
-- you may make latitude/longitude nullable after reviewing map logic.
-- ALTER TABLE student_location MODIFY latitude DECIMAL(10,8) NULL;
-- ALTER TABLE student_location MODIFY longitude DECIMAL(11,8) NULL;
