SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

DELETE FROM activity_logs;
DELETE FROM applications;
DELETE FROM gwa_issue_reports;
DELETE FROM signup_verifications;
DELETE FROM upload_history;
DELETE FROM user_documents;
DELETE FROM student_location;
DELETE FROM student_data;
DELETE FROM users WHERE email <> 'jaimslouis@gmail.com';
UPDATE users
SET role = 'super_admin', status = 'active'
WHERE email = 'jaimslouis@gmail.com';

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE activity_logs AUTO_INCREMENT = 1;
ALTER TABLE applications AUTO_INCREMENT = 1;
ALTER TABLE gwa_issue_reports AUTO_INCREMENT = 1;
ALTER TABLE signup_verifications AUTO_INCREMENT = 1;
ALTER TABLE upload_history AUTO_INCREMENT = 1;
ALTER TABLE user_documents AUTO_INCREMENT = 1;
ALTER TABLE users AUTO_INCREMENT = 2;