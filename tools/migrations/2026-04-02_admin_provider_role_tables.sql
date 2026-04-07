-- Split legacy staff profile storage into dedicated provider/admin role tables.
-- Safe for MariaDB 10.4+ and MySQL variants that support IF NOT EXISTS.

ALTER TABLE users
    MODIFY COLUMN status ENUM('inactive', 'active', 'suspended', 'pending') DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS last_login DATETIME NULL AFTER email_verified_at,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_login;

CREATE TABLE IF NOT EXISTS provider_data (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    organization_name VARCHAR(180) NOT NULL,
    contact_person_firstname VARCHAR(120) NOT NULL,
    contact_person_lastname VARCHAR(120) NOT NULL,
    contact_person_position VARCHAR(180) DEFAULT NULL,
    phone_number VARCHAR(60) NOT NULL,
    mobile_number VARCHAR(60) DEFAULT NULL,
    organization_email VARCHAR(180) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    organization_type VARCHAR(80) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    house_no VARCHAR(80) DEFAULT NULL,
    street VARCHAR(120) DEFAULT NULL,
    barangay VARCHAR(120) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    province VARCHAR(120) DEFAULT NULL,
    zip_code VARCHAR(20) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    logo VARCHAR(255) DEFAULT NULL,
    verification_document VARCHAR(255) DEFAULT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_provider_data_user_id (user_id),
    KEY idx_provider_data_org (organization_name),
    KEY idx_provider_data_type (organization_type),
    KEY idx_provider_data_location (province, city),
    CONSTRAINT fk_provider_data_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS admin_data (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    firstname VARCHAR(120) NOT NULL,
    lastname VARCHAR(120) NOT NULL,
    middleinitial VARCHAR(10) DEFAULT NULL,
    suffix VARCHAR(20) DEFAULT NULL,
    phone_number VARCHAR(60) DEFAULT NULL,
    position VARCHAR(180) DEFAULT NULL,
    department VARCHAR(180) DEFAULT NULL,
    profile_photo VARCHAR(255) DEFAULT NULL,
    access_level TINYINT(3) UNSIGNED NOT NULL DEFAULT 40,
    can_manage_users TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_scholarships TINYINT(1) NOT NULL DEFAULT 1,
    can_review_documents TINYINT(1) NOT NULL DEFAULT 1,
    can_view_reports TINYINT(1) NOT NULL DEFAULT 0,
    is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT(11) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admin_data_user_id (user_id),
    KEY idx_admin_data_department (department),
    KEY idx_admin_data_position (position),
    KEY idx_admin_data_created_by (created_by),
    CONSTRAINT fk_admin_data_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_admin_data_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional one-time migration from legacy staff_profiles into the new role tables.
INSERT INTO provider_data (
    user_id,
    organization_name,
    contact_person_firstname,
    contact_person_lastname,
    contact_person_position,
    phone_number,
    organization_email,
    website,
    organization_type,
    address,
    city,
    province,
    description,
    is_verified,
    verified_at
)
SELECT
    u.id,
    COALESCE(NULLIF(sp.organization_name, ''), CONCAT('Provider ', u.username)),
    COALESCE(NULLIF(sp.firstname, ''), u.username),
    COALESCE(NULLIF(sp.lastname, ''), 'Contact'),
    NULLIF(sp.position_title, ''),
    COALESCE(NULLIF(sp.office_phone, ''), '+63 000 000 0000'),
    u.email,
    NULLIF(sp.website, ''),
    NULLIF(sp.organization_type, ''),
    NULLIF(sp.office_address, ''),
    NULLIF(sp.city, ''),
    NULLIF(sp.province, ''),
    NULLIF(sp.responsibility_scope, ''),
    CASE WHEN u.status = 'active' THEN 1 ELSE 0 END,
    CASE WHEN u.status = 'active' THEN NOW() ELSE NULL END
FROM users u
INNER JOIN staff_profiles sp ON sp.user_id = u.id
WHERE u.role = 'provider'
ON DUPLICATE KEY UPDATE
    organization_name = VALUES(organization_name),
    contact_person_firstname = VALUES(contact_person_firstname),
    contact_person_lastname = VALUES(contact_person_lastname),
    contact_person_position = VALUES(contact_person_position),
    phone_number = VALUES(phone_number),
    organization_email = VALUES(organization_email),
    website = VALUES(website),
    organization_type = VALUES(organization_type),
    address = VALUES(address),
    city = VALUES(city),
    province = VALUES(province),
    description = VALUES(description);

INSERT INTO admin_data (
    user_id,
    firstname,
    lastname,
    middleinitial,
    suffix,
    phone_number,
    position,
    department,
    access_level,
    can_manage_users,
    can_manage_scholarships,
    can_review_documents,
    can_view_reports,
    is_super_admin,
    notes
)
SELECT
    u.id,
    COALESCE(NULLIF(sp.firstname, ''), u.username),
    COALESCE(NULLIF(sp.lastname, ''), 'Admin'),
    NULLIF(sp.middleinitial, ''),
    NULLIF(sp.suffix, ''),
    NULLIF(sp.office_phone, ''),
    NULLIF(sp.position_title, ''),
    NULLIF(sp.department, ''),
    COALESCE(u.access_level, CASE WHEN u.role = 'super_admin' THEN 90 ELSE 70 END),
    CASE WHEN u.role IN ('admin', 'super_admin') THEN 1 ELSE 0 END,
    1,
    1,
    CASE WHEN u.role IN ('admin', 'super_admin') THEN 1 ELSE 0 END,
    CASE WHEN u.role = 'super_admin' THEN 1 ELSE 0 END,
    NULLIF(sp.responsibility_scope, '')
FROM users u
INNER JOIN staff_profiles sp ON sp.user_id = u.id
WHERE u.role IN ('admin', 'super_admin')
ON DUPLICATE KEY UPDATE
    firstname = VALUES(firstname),
    lastname = VALUES(lastname),
    middleinitial = VALUES(middleinitial),
    suffix = VALUES(suffix),
    phone_number = VALUES(phone_number),
    position = VALUES(position),
    department = VALUES(department),
    access_level = VALUES(access_level),
    can_manage_users = VALUES(can_manage_users),
    can_manage_scholarships = VALUES(can_manage_scholarships),
    can_review_documents = VALUES(can_review_documents),
    can_view_reports = VALUES(can_view_reports),
    is_super_admin = VALUES(is_super_admin),
    notes = VALUES(notes);
