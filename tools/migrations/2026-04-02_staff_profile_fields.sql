-- Dedicated staff profile table for admin/provider/super_admin accounts.
-- Safe to run multiple times in MariaDB 10.4+.

CREATE TABLE IF NOT EXISTS staff_profiles (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    firstname VARCHAR(255) NOT NULL,
    lastname VARCHAR(255) NOT NULL,
    middleinitial VARCHAR(10) DEFAULT NULL,
    suffix VARCHAR(20) DEFAULT NULL,
    organization_name VARCHAR(180) NOT NULL,
    organization_type VARCHAR(80) NOT NULL,
    department VARCHAR(180) DEFAULT NULL,
    position_title VARCHAR(180) NOT NULL,
    staff_id_no VARCHAR(80) DEFAULT NULL,
    office_phone VARCHAR(60) DEFAULT NULL,
    office_address VARCHAR(255) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    province VARCHAR(120) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    responsibility_scope TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_staff_profiles_user_id (user_id),
    KEY idx_staff_profiles_org (organization_name),
    KEY idx_staff_profiles_org_type (organization_type),
    KEY idx_staff_profiles_location (province, city),
    CONSTRAINT fk_staff_profiles_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
