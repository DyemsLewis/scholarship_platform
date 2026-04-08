SET @schema_name = DATABASE();

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'scholarship_data'
          AND COLUMN_NAME = 'application_open_date'
    ),
    'SELECT 1',
    'ALTER TABLE scholarship_data ADD COLUMN application_open_date DATE NULL AFTER benefits'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'scholarship_data'
          AND COLUMN_NAME = 'application_process_label'
    ),
    'SELECT 1',
    'ALTER TABLE scholarship_data ADD COLUMN application_process_label VARCHAR(150) NULL AFTER deadline'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'scholarship_data'
          AND COLUMN_NAME = 'post_application_steps'
    ),
    'SELECT 1',
    'ALTER TABLE scholarship_data ADD COLUMN post_application_steps TEXT NULL AFTER assessment_details'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'scholarship_data'
          AND COLUMN_NAME = 'renewal_conditions'
    ),
    'SELECT 1',
    'ALTER TABLE scholarship_data ADD COLUMN renewal_conditions TEXT NULL AFTER post_application_steps'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'scholarship_data'
          AND COLUMN_NAME = 'scholarship_restrictions'
    ),
    'SELECT 1',
    'ALTER TABLE scholarship_data ADD COLUMN scholarship_restrictions TEXT NULL AFTER renewal_conditions'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
