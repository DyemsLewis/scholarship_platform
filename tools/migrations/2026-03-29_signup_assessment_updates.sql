-- 2026-03-29 Signup + Scholarship Assessment schema updates
-- Safe to run multiple times (uses INFORMATION_SCHEMA checks).

SET @db_name = DATABASE();

-- student_data.house_no
SET @has_house_no = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'house_no'
);
SET @sql = IF(
    @has_house_no = 0,
    'ALTER TABLE student_data ADD COLUMN house_no VARCHAR(80) NULL AFTER address',
    'SELECT ''student_data.house_no already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- student_data.street
SET @has_street = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'street'
);
SET @sql = IF(
    @has_street = 0,
    'ALTER TABLE student_data ADD COLUMN street VARCHAR(120) NULL AFTER house_no',
    'SELECT ''student_data.street already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- student_data.barangay
SET @has_barangay = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'barangay'
);
SET @sql = IF(
    @has_barangay = 0,
    'ALTER TABLE student_data ADD COLUMN barangay VARCHAR(120) NULL AFTER street',
    'SELECT ''student_data.barangay already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- student_data.city
SET @has_city = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'city'
);
SET @sql = IF(
    @has_city = 0,
    'ALTER TABLE student_data ADD COLUMN city VARCHAR(120) NULL AFTER barangay',
    'SELECT ''student_data.city already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- student_data.province
SET @has_province = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'province'
);
SET @sql = IF(
    @has_province = 0,
    'ALTER TABLE student_data ADD COLUMN province VARCHAR(120) NULL AFTER city',
    'SELECT ''student_data.province already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- scholarship_data.assessment_requirement
SET @has_assessment_requirement = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND COLUMN_NAME = 'assessment_requirement'
);
SET @sql = IF(
    @has_assessment_requirement = 0,
    'ALTER TABLE scholarship_data ADD COLUMN assessment_requirement VARCHAR(50) NULL DEFAULT ''none'' AFTER deadline',
    'SELECT ''scholarship_data.assessment_requirement already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- scholarship_data.assessment_link
SET @has_assessment_link = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND COLUMN_NAME = 'assessment_link'
);
SET @sql = IF(
    @has_assessment_link = 0,
    'ALTER TABLE scholarship_data ADD COLUMN assessment_link VARCHAR(255) NULL AFTER assessment_requirement',
    'SELECT ''scholarship_data.assessment_link already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- scholarship_data.assessment_details
SET @has_assessment_details = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND COLUMN_NAME = 'assessment_details'
);
SET @sql = IF(
    @has_assessment_details = 0,
    'ALTER TABLE scholarship_data ADD COLUMN assessment_details TEXT NULL AFTER assessment_link',
    'SELECT ''scholarship_data.assessment_details already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
