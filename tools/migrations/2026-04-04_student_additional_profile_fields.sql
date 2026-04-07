ALTER TABLE student_data
    ADD COLUMN IF NOT EXISTS mobile_number VARCHAR(20) DEFAULT NULL AFTER province,
    ADD COLUMN IF NOT EXISTS citizenship VARCHAR(40) DEFAULT NULL AFTER mobile_number,
    ADD COLUMN IF NOT EXISTS household_income_bracket VARCHAR(40) DEFAULT NULL AFTER citizenship,
    ADD COLUMN IF NOT EXISTS special_category VARCHAR(60) DEFAULT NULL AFTER household_income_bracket;
