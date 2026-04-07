-- 2026-04-01 Incoming freshman and current college support
-- Safe to run multiple times (uses INFORMATION_SCHEMA checks).

SET @db_name = DATABASE();

-- student_data additions
SET @has_applicant_type = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'applicant_type'
);
SET @sql = IF(
    @has_applicant_type = 0,
    "ALTER TABLE student_data ADD COLUMN applicant_type VARCHAR(50) NULL",
    "SELECT 'student_data.applicant_type already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_shs_school = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'shs_school'
);
SET @sql = IF(
    @has_shs_school = 0,
    "ALTER TABLE student_data ADD COLUMN shs_school VARCHAR(150) NULL",
    "SELECT 'student_data.shs_school already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_shs_strand = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'shs_strand'
);
SET @sql = IF(
    @has_shs_strand = 0,
    "ALTER TABLE student_data ADD COLUMN shs_strand VARCHAR(80) NULL",
    "SELECT 'student_data.shs_strand already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_shs_graduation_year = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'shs_graduation_year'
);
SET @sql = IF(
    @has_shs_graduation_year = 0,
    "ALTER TABLE student_data ADD COLUMN shs_graduation_year SMALLINT UNSIGNED NULL",
    "SELECT 'student_data.shs_graduation_year already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_shs_average = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'shs_average'
);
SET @sql = IF(
    @has_shs_average = 0,
    "ALTER TABLE student_data ADD COLUMN shs_average DECIMAL(5,2) NULL",
    "SELECT 'student_data.shs_average already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_admission_status = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'admission_status'
);
SET @sql = IF(
    @has_admission_status = 0,
    "ALTER TABLE student_data ADD COLUMN admission_status VARCHAR(40) NULL",
    "SELECT 'student_data.admission_status already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_target_college = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'target_college'
);
SET @sql = IF(
    @has_target_college = 0,
    "ALTER TABLE student_data ADD COLUMN target_college VARCHAR(150) NULL",
    "SELECT 'student_data.target_college already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_target_course = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'target_course'
);
SET @sql = IF(
    @has_target_course = 0,
    "ALTER TABLE student_data ADD COLUMN target_course VARCHAR(150) NULL",
    "SELECT 'student_data.target_course already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_year_level = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'year_level'
);
SET @sql = IF(
    @has_year_level = 0,
    "ALTER TABLE student_data ADD COLUMN year_level VARCHAR(40) NULL",
    "SELECT 'student_data.year_level already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_enrollment_status = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'enrollment_status'
);
SET @sql = IF(
    @has_enrollment_status = 0,
    "ALTER TABLE student_data ADD COLUMN enrollment_status VARCHAR(40) NULL",
    "SELECT 'student_data.enrollment_status already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_academic_standing = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'student_data'
      AND COLUMN_NAME = 'academic_standing'
);
SET @sql = IF(
    @has_academic_standing = 0,
    "ALTER TABLE student_data ADD COLUMN academic_standing VARCHAR(40) NULL",
    "SELECT 'student_data.academic_standing already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- scholarship_data additions
SET @has_target_applicant_type = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND COLUMN_NAME = 'target_applicant_type'
);
SET @sql = IF(
    @has_target_applicant_type = 0,
    "ALTER TABLE scholarship_data ADD COLUMN target_applicant_type VARCHAR(50) NOT NULL DEFAULT 'all'",
    "SELECT 'scholarship_data.target_applicant_type already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_target_year_level = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND COLUMN_NAME = 'target_year_level'
);
SET @sql = IF(
    @has_target_year_level = 0,
    "ALTER TABLE scholarship_data ADD COLUMN target_year_level VARCHAR(40) NOT NULL DEFAULT 'any'",
    "SELECT 'scholarship_data.target_year_level already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_required_admission_status = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND COLUMN_NAME = 'required_admission_status'
);
SET @sql = IF(
    @has_required_admission_status = 0,
    "ALTER TABLE scholarship_data ADD COLUMN required_admission_status VARCHAR(40) NOT NULL DEFAULT 'any'",
    "SELECT 'scholarship_data.required_admission_status already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_target_strand = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND COLUMN_NAME = 'target_strand'
);
SET @sql = IF(
    @has_target_strand = 0,
    "ALTER TABLE scholarship_data ADD COLUMN target_strand VARCHAR(80) NULL",
    "SELECT 'scholarship_data.target_strand already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helpful indexes for scholarship filters
SET @has_idx_target_applicant_type = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND INDEX_NAME = 'idx_scholarship_data_target_applicant_type'
);
SET @sql = IF(
    @has_idx_target_applicant_type = 0,
    "ALTER TABLE scholarship_data ADD KEY idx_scholarship_data_target_applicant_type (target_applicant_type)",
    "SELECT 'idx_scholarship_data_target_applicant_type already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_target_year_level = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND INDEX_NAME = 'idx_scholarship_data_target_year_level'
);
SET @sql = IF(
    @has_idx_target_year_level = 0,
    "ALTER TABLE scholarship_data ADD KEY idx_scholarship_data_target_year_level (target_year_level)",
    "SELECT 'idx_scholarship_data_target_year_level already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_required_admission_status = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_data'
      AND INDEX_NAME = 'idx_scholarship_data_required_admission_status'
);
SET @sql = IF(
    @has_idx_required_admission_status = 0,
    "ALTER TABLE scholarship_data ADD KEY idx_scholarship_data_required_admission_status (required_admission_status)",
    "SELECT 'idx_scholarship_data_required_admission_status already exists' AS note"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill defaults for older rows
UPDATE scholarship_data
SET target_applicant_type = 'all'
WHERE target_applicant_type IS NULL OR target_applicant_type = '';

UPDATE scholarship_data
SET target_year_level = 'any'
WHERE target_year_level IS NULL OR target_year_level = '';

UPDATE scholarship_data
SET required_admission_status = 'any'
WHERE required_admission_status IS NULL OR required_admission_status = '';
