ALTER TABLE applications
    ADD COLUMN IF NOT EXISTS student_response_status VARCHAR(20) DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS student_responded_at DATETIME DEFAULT NULL AFTER student_response_status;
