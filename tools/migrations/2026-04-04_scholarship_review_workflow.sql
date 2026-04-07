ALTER TABLE scholarship_data
    ADD COLUMN IF NOT EXISTS review_status VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER target_strand,
    ADD COLUMN IF NOT EXISTS review_notes TEXT DEFAULT NULL AFTER review_status,
    ADD COLUMN IF NOT EXISTS reviewed_by_user_id INT(11) DEFAULT NULL AFTER review_notes,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by_user_id;
