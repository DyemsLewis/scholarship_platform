-- 2026-03-29 GWA issue reporting + remote exam FK updates
-- Safe to run multiple times (uses INFORMATION_SCHEMA checks).

SET @db_name = DATABASE();

-- scholarship_remote_exam_locations table (for remote examination sites)
SET @has_remote_exam_table = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_remote_exam_locations'
);
SET @sql = IF(
    @has_remote_exam_table = 0,
    'CREATE TABLE scholarship_remote_exam_locations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scholarship_id INT UNSIGNED NOT NULL,
        site_name VARCHAR(150) NULL,
        address VARCHAR(255) NULL,
        city VARCHAR(120) NULL,
        province VARCHAR(120) NULL,
        latitude DECIMAL(10,8) NULL,
        longitude DECIMAL(11,8) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT ''scholarship_remote_exam_locations already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_remote_exam_index = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'scholarship_remote_exam_locations'
      AND INDEX_NAME = 'idx_remote_exam_scholarship'
);
SET @sql = IF(
    @has_remote_exam_index = 0,
    'ALTER TABLE scholarship_remote_exam_locations
     ADD KEY idx_remote_exam_scholarship (scholarship_id)',
    'SELECT ''idx_remote_exam_scholarship already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- gwa_issue_reports table (manual review reports for OCR-extracted GWA)
SET @has_gwa_issue_table = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'gwa_issue_reports'
);
SET @sql = IF(
    @has_gwa_issue_table = 0,
    'CREATE TABLE gwa_issue_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        document_id INT UNSIGNED NULL,
        extracted_gwa DECIMAL(4,2) NULL,
        reported_gwa DECIMAL(4,2) NULL,
        raw_ocr_value DECIMAL(6,2) NULL,
        reason_code VARCHAR(50) NOT NULL,
        details TEXT NULL,
        status ENUM(''pending'',''reviewed'',''resolved'',''rejected'') NOT NULL DEFAULT ''pending'',
        admin_notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT ''gwa_issue_reports already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_gwa_issue_idx_user = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'gwa_issue_reports'
      AND INDEX_NAME = 'idx_gwa_issue_user'
);
SET @sql = IF(
    @has_gwa_issue_idx_user = 0,
    'ALTER TABLE gwa_issue_reports
     ADD KEY idx_gwa_issue_user (user_id, created_at)',
    'SELECT ''idx_gwa_issue_user already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_gwa_issue_idx_status = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'gwa_issue_reports'
      AND INDEX_NAME = 'idx_gwa_issue_status'
);
SET @sql = IF(
    @has_gwa_issue_idx_status = 0,
    'ALTER TABLE gwa_issue_reports
     ADD KEY idx_gwa_issue_status (status, created_at)',
    'SELECT ''idx_gwa_issue_status already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_gwa_issue_idx_document = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'gwa_issue_reports'
      AND INDEX_NAME = 'idx_gwa_issue_document'
);
SET @sql = IF(
    @has_gwa_issue_idx_document = 0,
    'ALTER TABLE gwa_issue_reports
     ADD KEY idx_gwa_issue_document (document_id)',
    'SELECT ''idx_gwa_issue_document already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- NOTE:
-- Foreign keys are intentionally not added here because legacy dumps often
-- mix signed and unsigned INT id columns. The app logic works without these
-- FKs, and this keeps migration runs safe across existing databases.
