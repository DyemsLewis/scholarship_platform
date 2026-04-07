ALTER TABLE scholarship_data
    ADD COLUMN IF NOT EXISTS target_citizenship VARCHAR(40) DEFAULT NULL AFTER target_strand,
    ADD COLUMN IF NOT EXISTS target_income_bracket VARCHAR(40) DEFAULT NULL AFTER target_citizenship,
    ADD COLUMN IF NOT EXISTS target_special_category VARCHAR(60) DEFAULT NULL AFTER target_income_bracket;
