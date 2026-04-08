<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Config/db_config.php';

$projectRoot = dirname(__DIR__, 2);
$publicUploadsDir = $projectRoot . '/public/uploads';
$now = date('Y-m-d H:i:s');
$defaultProviderPassword = 'ProviderDemo@123';

function cleanupUploadedFile(string $projectRoot, string $relativePath): void
{
    $normalized = trim(str_replace('\\', '/', $relativePath), '/');
    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return;
    }

    $absolutePath = $projectRoot . '/' . $normalized;
    if (!is_file($absolutePath)) {
        return;
    }

    @unlink($absolutePath);

    $parentDir = dirname($absolutePath);
    $uploadsRoot = str_replace('\\', '/', $projectRoot . '/public/uploads');
    while (is_dir($parentDir)) {
        $normalizedParent = str_replace('\\', '/', $parentDir);
        if ($normalizedParent === $uploadsRoot || strpos($normalizedParent, $uploadsRoot) !== 0) {
            break;
        }

        $items = @scandir($parentDir);
        if ($items === false || count(array_diff($items, ['.', '..'])) > 0) {
            break;
        }

        @rmdir($parentDir);
        $parentDir = dirname($parentDir);
    }
}

function fetchColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$providerSeed = [
    [
        'username' => 'dost_sei',
        'email' => 'scholarships@dost.gov.ph',
        'organization_name' => 'Department of Science and Technology - Science Education Institute',
        'organization_type' => 'government_agency',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Scholarship Program Office',
        'phone_number' => '(02) 8837-2071',
        'mobile_number' => '09171234561',
        'website' => 'https://dost.gov.ph/',
        'address' => 'Science Education Institute, DOST Complex, General Santos Avenue, Bicutan',
        'house_no' => '',
        'street' => 'General Santos Avenue',
        'barangay' => 'Upper Bicutan',
        'city' => 'Taguig City',
        'province' => 'Metro Manila',
        'zip_code' => '1631',
        'description' => 'Science and technology scholarship programs under the Science Education Institute.',
    ],
    [
        'username' => 'ched_central',
        'email' => 'info@ched.gov.ph',
        'organization_name' => 'Commission on Higher Education',
        'organization_type' => 'government_agency',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Scholarship Services Unit',
        'phone_number' => '(02) 8441-1260',
        'mobile_number' => '09171234562',
        'website' => 'https://ched.gov.ph/',
        'address' => 'Higher Education Development Center Building, C.P. Garcia Avenue, UP Campus',
        'house_no' => '',
        'street' => 'C.P. Garcia Avenue',
        'barangay' => 'UP Campus',
        'city' => 'Quezon City',
        'province' => 'Metro Manila',
        'zip_code' => '1101',
        'description' => 'National higher education policy and scholarship administration office.',
    ],
    [
        'username' => 'sm_foundation',
        'email' => 'scholarships@sm-foundation.org',
        'organization_name' => 'SM Foundation, Inc.',
        'organization_type' => 'foundation',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Education Programs Office',
        'phone_number' => '(02) 8857-0100',
        'mobile_number' => '09171234563',
        'website' => 'https://www.sm-foundation.org/',
        'address' => 'SM Foundation, One E-Com Center, Harbor Drive, Mall of Asia Complex',
        'house_no' => '',
        'street' => 'Harbor Drive',
        'barangay' => 'Mall of Asia Complex',
        'city' => 'Pasay City',
        'province' => 'Metro Manila',
        'zip_code' => '1300',
        'description' => 'Corporate foundation managing college scholarship and education assistance programs.',
    ],
    [
        'username' => 'owwa',
        'email' => 'info@owwa.gov.ph',
        'organization_name' => 'Overseas Workers Welfare Administration',
        'organization_type' => 'government_agency',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Education and Training Unit',
        'phone_number' => '(02) 8891-7601',
        'mobile_number' => '09171234564',
        'website' => 'https://owwa.gov.ph/',
        'address' => 'OWWA Center, 7th Street corner F.B. Harrison Street',
        'house_no' => '',
        'street' => 'F.B. Harrison Street',
        'barangay' => 'Barangay 76',
        'city' => 'Pasay City',
        'province' => 'Metro Manila',
        'zip_code' => '1300',
        'description' => 'Welfare and scholarship support programs for overseas Filipino workers and their dependents.',
    ],
    [
        'username' => 'upd_osg',
        'email' => 'osg.upd@up.edu.ph',
        'organization_name' => 'UP Diliman Office of Scholarships and Grants',
        'organization_type' => 'state_university',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Scholarships and Grants Office',
        'phone_number' => '(02) 8981-8500',
        'mobile_number' => '09171234565',
        'website' => 'https://upd.edu.ph/',
        'address' => 'Office of Scholarships and Grants, Quezon Hall, UP Diliman',
        'house_no' => '',
        'street' => 'University Avenue',
        'barangay' => 'UP Campus',
        'city' => 'Quezon City',
        'province' => 'Metro Manila',
        'zip_code' => '1101',
        'description' => 'University scholarship and grant assistance office for UP Diliman students.',
    ],
];

$scholarshipSeed = [
    [
        'name' => 'DOST-SEI Undergraduate Scholarship',
        'description' => 'National scholarship for incoming college students entering priority science and technology degree programs.',
        'eligibility' => 'Incoming freshmen entering priority science and technology programs in recognized higher education institutions.',
        'min_gwa' => 1.75,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'Department of Science and Technology - Science Education Institute',
        'image' => '69ba7a895018b_1773828745.png',
        'benefits' => 'Tuition subsidy, book allowance, transportation allowance, uniform allowance, and monthly living allowance',
        'address' => 'Science Education Institute, DOST Complex, General Santos Avenue, Bicutan',
        'city' => 'Taguig City',
        'province' => 'Metro Manila',
        'deadline' => '2026-10-31',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applicants complete the annual DOST-SEI screening process and follow the posted evaluation schedule.',
        'target_applicant_type' => 'incoming_freshman',
        'target_year_level' => 'any',
        'required_admission_status' => 'applied',
        'target_strand' => 'STEM',
        'latitude' => 14.48600000,
        'longitude' => 121.04490000,
        'requirements' => ['form_138', 'birth_certificate', 'good_moral', 'recommendation', 'id'],
    ],
    [
        'name' => 'DOST-SEI Junior Level Science Scholarships',
        'description' => 'Scholarship support for current college students in priority science and technology programs entering the junior level.',
        'eligibility' => 'Current college students in priority science and technology degree programs preparing to enter the junior level.',
        'min_gwa' => 2.00,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'Department of Science and Technology - Science Education Institute',
        'image' => '69ba7a895018b_1773828745.png',
        'benefits' => 'Tuition subsidy, monthly stipend, book allowance, and research support for eligible junior-level scholars',
        'address' => 'Science Education Institute, DOST Complex, General Santos Avenue, Bicutan',
        'city' => 'Taguig City',
        'province' => 'Metro Manila',
        'deadline' => '2026-11-15',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applicants undergo documentary evaluation based on the published DOST-SEI junior-level scholarship guidelines.',
        'target_applicant_type' => 'current_college',
        'target_year_level' => '2nd_year',
        'required_admission_status' => 'enrolled',
        'target_strand' => null,
        'latitude' => 14.48600000,
        'longitude' => 121.04490000,
        'requirements' => ['grades', 'enrollment', 'good_moral', 'recommendation', 'id'],
    ],
    [
        'name' => 'CHED Merit Scholarship Program',
        'description' => 'Merit-based national scholarship support for incoming first-year college students in recognized programs.',
        'eligibility' => 'Incoming first-year college students with strong academic standing entering recognized CHED programs.',
        'min_gwa' => 2.00,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'Commission on Higher Education',
        'image' => '69ba76584f604_1773827672.jpg',
        'benefits' => 'Tuition assistance, stipend support, and book or connectivity allowance depending on approved award type',
        'address' => 'Higher Education Development Center Building, C.P. Garcia Avenue, UP Campus',
        'city' => 'Quezon City',
        'province' => 'Metro Manila',
        'deadline' => '2026-09-30',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applications are reviewed based on submitted scholarship requirements and CHED regional screening.',
        'target_applicant_type' => 'incoming_freshman',
        'target_year_level' => 'any',
        'required_admission_status' => 'admitted',
        'target_strand' => null,
        'latitude' => 14.65070000,
        'longitude' => 121.04900000,
        'requirements' => ['form_138', 'birth_certificate', 'good_moral', 'income_proof', 'citizenship_proof'],
    ],
    [
        'name' => 'SM Foundation College Scholarship Program',
        'description' => 'College scholarship support for qualified incoming freshmen in SM Foundation partner schools and priority programs.',
        'eligibility' => 'Incoming first-year college students with strong academic records and documented financial need.',
        'min_gwa' => 2.00,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'SM Foundation, Inc.',
        'image' => '69ba79cc5f173_1773828556.png',
        'benefits' => 'Tuition subsidy, monthly stipend, and book or transportation support based on scholarship coverage',
        'address' => 'SM Foundation, One E-Com Center, Harbor Drive, Mall of Asia Complex',
        'city' => 'Pasay City',
        'province' => 'Metro Manila',
        'deadline' => '2026-08-31',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applications undergo documentary evaluation and provider screening based on the annual SM Foundation process.',
        'target_applicant_type' => 'incoming_freshman',
        'target_year_level' => 'any',
        'required_admission_status' => 'admitted',
        'target_strand' => null,
        'latitude' => 14.53540000,
        'longitude' => 120.98280000,
        'requirements' => ['form_138', 'birth_certificate', 'good_moral', 'income_proof'],
    ],
    [
        'name' => 'OWWA Education for Development Scholarship Program',
        'description' => 'Scholarship support for qualified dependents of OFWs pursuing college education in approved programs.',
        'eligibility' => 'Qualified college applicants or continuing college students under the OWWA education scholarship guidelines.',
        'min_gwa' => 2.25,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'Overseas Workers Welfare Administration',
        'image' => null,
        'benefits' => 'Education assistance grant and support for qualified beneficiaries under the OWWA scholarship cycle',
        'address' => 'OWWA Center, 7th Street corner F.B. Harrison Street',
        'city' => 'Pasay City',
        'province' => 'Metro Manila',
        'deadline' => '2026-09-15',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applications are evaluated through the OWWA scholarship screening and documentary validation process.',
        'target_applicant_type' => 'current_college',
        'target_year_level' => 'any',
        'required_admission_status' => 'enrolled',
        'target_strand' => null,
        'latitude' => 14.53740000,
        'longitude' => 120.99210000,
        'requirements' => ['grades', 'birth_certificate', 'good_moral', 'id'],
    ],
    [
        'name' => 'UP Diliman Grants-in-Aid Program',
        'description' => 'Need-based scholarship assistance for eligible UP Diliman students through the Office of Scholarships and Grants.',
        'eligibility' => 'Current UP Diliman students needing financial assistance and maintaining eligibility under university scholarship rules.',
        'min_gwa' => 2.50,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'UP Diliman Office of Scholarships and Grants',
        'image' => '69ba7a7b08623_1773828731.png',
        'benefits' => 'Financial assistance, tuition-related support, and student grant support subject to available program slots',
        'address' => 'Office of Scholarships and Grants, Quezon Hall, UP Diliman',
        'city' => 'Quezon City',
        'province' => 'Metro Manila',
        'deadline' => '2026-08-15',
        'assessment_requirement' => 'none',
        'assessment_link' => null,
        'assessment_details' => 'Applications are checked through the Office of Scholarships and Grants based on documented financial need and student standing.',
        'target_applicant_type' => 'current_college',
        'target_year_level' => 'any',
        'required_admission_status' => 'enrolled',
        'target_strand' => null,
        'latitude' => 14.65490000,
        'longitude' => 121.06850000,
        'requirements' => ['grades', 'enrollment', 'income_proof', 'id'],
    ],
];

try {
    $providerUserIds = $pdo->query("SELECT id FROM users WHERE role = 'provider'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $studentUserIds = $pdo->query("SELECT id FROM users WHERE role = 'student'")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $oldDocumentPaths = $pdo->query("SELECT file_path FROM user_documents")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $oldScholarshipImages = $pdo->query("SELECT image FROM scholarship_data WHERE image IS NOT NULL AND image <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $oldProviderLogos = fetchColumnExists($pdo, 'provider_data', 'logo')
        ? ($pdo->query("SELECT logo FROM provider_data WHERE logo IS NOT NULL AND logo <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: [])
        : [];
    $oldVerificationFiles = fetchColumnExists($pdo, 'provider_data', 'verification_document')
        ? ($pdo->query("SELECT verification_document FROM provider_data WHERE verification_document IS NOT NULL AND verification_document <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: [])
        : [];

    $pdo->exec("DELETE FROM applications");
    $pdo->exec("DELETE FROM document_requirements");
    $pdo->exec("DELETE FROM scholarship_remote_exam_locations");
    $pdo->exec("DELETE FROM scholarship_location");
    $pdo->exec("DELETE FROM scholarship_data");
    $pdo->exec("DELETE FROM scholarships");
    $pdo->exec("DELETE FROM user_documents");
    $pdo->exec("DELETE FROM upload_history");
    $pdo->exec("DELETE FROM gwa_issue_reports");
    $pdo->exec("DELETE FROM student_location");
    $pdo->exec("DELETE FROM student_data");
    $pdo->exec("DELETE FROM activity_logs");
    $pdo->exec("DELETE FROM signup_verifications");

    if (!empty($providerUserIds)) {
        $providerIdList = implode(',', array_map('intval', $providerUserIds));
        $pdo->exec("DELETE FROM provider_data WHERE user_id IN ($providerIdList)");
        $pdo->exec("DELETE FROM staff_profiles WHERE user_id IN ($providerIdList)");
    }

    if (!empty($studentUserIds)) {
        $studentIdList = implode(',', array_map('intval', $studentUserIds));
        $pdo->exec("DELETE FROM users WHERE id IN ($studentIdList)");
    }

    if (!empty($providerUserIds)) {
        $providerIdList = implode(',', array_map('intval', $providerUserIds));
        $pdo->exec("DELETE FROM users WHERE id IN ($providerIdList)");
    }

    $pdo->exec("ALTER TABLE scholarships AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE scholarship_data AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE scholarship_location AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE scholarship_remote_exam_locations AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE document_requirements AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE provider_data AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE student_data AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE student_location AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE user_documents AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE upload_history AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE activity_logs AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE applications AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE gwa_issue_reports AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE staff_profiles AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 2");

    $insertUser = $pdo->prepare("
        INSERT INTO users (
            username, email, password, created_at, role, access_level, status, email_verified_at, updated_at
        ) VALUES (
            :username, :email, :password, :created_at, 'provider', :access_level, 'active', :email_verified_at, :updated_at
        )
    ");

    $insertProvider = $pdo->prepare("
        INSERT INTO provider_data (
            user_id, organization_name, contact_person_firstname, contact_person_lastname, contact_person_position,
            phone_number, mobile_number, organization_email, website, organization_type, address, house_no, street,
            barangay, city, province, zip_code, description, logo, verification_document, is_verified, verified_at,
            created_at, updated_at
        ) VALUES (
            :user_id, :organization_name, :contact_person_firstname, :contact_person_lastname, :contact_person_position,
            :phone_number, :mobile_number, :organization_email, :website, :organization_type, :address, :house_no, :street,
            :barangay, :city, :province, :zip_code, :description, NULL, NULL, 1, :verified_at, :created_at, :updated_at
        )
    ");

    $providerIdsByOrg = [];

    foreach ($providerSeed as $provider) {
        $insertUser->execute([
            ':username' => $provider['username'],
            ':email' => $provider['email'],
            ':password' => password_hash($defaultProviderPassword, PASSWORD_DEFAULT),
            ':created_at' => $now,
            ':access_level' => 30,
            ':email_verified_at' => $now,
            ':updated_at' => $now,
        ]);

        $userId = (int) $pdo->lastInsertId();
        $providerIdsByOrg[$provider['organization_name']] = $userId;

        $insertProvider->execute([
            ':user_id' => $userId,
            ':organization_name' => $provider['organization_name'],
            ':contact_person_firstname' => $provider['contact_person_firstname'],
            ':contact_person_lastname' => $provider['contact_person_lastname'],
            ':contact_person_position' => $provider['contact_person_position'],
            ':phone_number' => $provider['phone_number'],
            ':mobile_number' => $provider['mobile_number'],
            ':organization_email' => $provider['email'],
            ':website' => $provider['website'],
            ':organization_type' => $provider['organization_type'],
            ':address' => $provider['address'],
            ':house_no' => $provider['house_no'],
            ':street' => $provider['street'],
            ':barangay' => $provider['barangay'],
            ':city' => $provider['city'],
            ':province' => $provider['province'],
            ':zip_code' => $provider['zip_code'],
            ':description' => $provider['description'],
            ':verified_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $insertScholarship = $pdo->prepare("
        INSERT INTO scholarships (
            name, description, eligibility, min_gwa, max_gwa, status, created_at, updated_at
        ) VALUES (
            :name, :description, :eligibility, :min_gwa, :max_gwa, :status, :created_at, :updated_at
        )
    ");

    $insertScholarshipData = $pdo->prepare("
        INSERT INTO scholarship_data (
            scholarship_id, image, provider, benefits, address, city, province, deadline,
            assessment_requirement, assessment_link, assessment_details, created_at, updated_at,
            target_applicant_type, target_year_level, required_admission_status, target_strand,
            review_status, review_notes, reviewed_by_user_id, reviewed_at
        ) VALUES (
            :scholarship_id, :image, :provider, :benefits, :address, :city, :province, :deadline,
            :assessment_requirement, :assessment_link, :assessment_details, :created_at, :updated_at,
            :target_applicant_type, :target_year_level, :required_admission_status, :target_strand,
            'approved', NULL, :reviewed_by_user_id, :reviewed_at
        )
    ");

    $insertScholarshipLocation = $pdo->prepare("
        INSERT INTO scholarship_location (scholarship_id, latitude, longitude)
        VALUES (:scholarship_id, :latitude, :longitude)
    ");

    $insertDocumentRequirement = $pdo->prepare("
        INSERT INTO document_requirements (scholarship_id, document_type, is_required, description)
        VALUES (:scholarship_id, :document_type, 1, NULL)
    ");

    foreach ($scholarshipSeed as $scholarship) {
        $insertScholarship->execute([
            ':name' => $scholarship['name'],
            ':description' => $scholarship['description'],
            ':eligibility' => $scholarship['eligibility'],
            ':min_gwa' => $scholarship['min_gwa'],
            ':max_gwa' => $scholarship['max_gwa'],
            ':status' => $scholarship['status'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $scholarshipId = (int) $pdo->lastInsertId();

        $insertScholarshipData->execute([
            ':scholarship_id' => $scholarshipId,
            ':image' => $scholarship['image'],
            ':provider' => $scholarship['provider'],
            ':benefits' => $scholarship['benefits'],
            ':address' => $scholarship['address'],
            ':city' => $scholarship['city'],
            ':province' => $scholarship['province'],
            ':deadline' => $scholarship['deadline'],
            ':assessment_requirement' => $scholarship['assessment_requirement'],
            ':assessment_link' => $scholarship['assessment_link'],
            ':assessment_details' => $scholarship['assessment_details'],
            ':created_at' => $now,
            ':updated_at' => $now,
            ':target_applicant_type' => $scholarship['target_applicant_type'],
            ':target_year_level' => $scholarship['target_year_level'],
            ':required_admission_status' => $scholarship['required_admission_status'],
            ':target_strand' => $scholarship['target_strand'],
            ':reviewed_by_user_id' => 1,
            ':reviewed_at' => $now,
        ]);

        $insertScholarshipLocation->execute([
            ':scholarship_id' => $scholarshipId,
            ':latitude' => $scholarship['latitude'],
            ':longitude' => $scholarship['longitude'],
        ]);

        foreach ($scholarship['requirements'] as $documentType) {
            $insertDocumentRequirement->execute([
                ':scholarship_id' => $scholarshipId,
                ':document_type' => $documentType,
            ]);
        }
    }

    $reusedFiles = array_filter(array_map(
        static fn(array $item): string => 'public/uploads/' . ltrim((string) ($item['image'] ?? ''), '/'),
        array_filter($scholarshipSeed, static fn(array $item): bool => trim((string) ($item['image'] ?? '')) !== '')
    ));

    foreach ($oldDocumentPaths as $path) {
        cleanupUploadedFile($projectRoot, (string) $path);
    }

    foreach (array_merge($oldScholarshipImages, $oldProviderLogos, $oldVerificationFiles) as $path) {
        $relativePath = 'public/uploads/' . ltrim((string) $path, '/');
        if (in_array($relativePath, $reusedFiles, true)) {
            continue;
        }
        cleanupUploadedFile($projectRoot, $relativePath);
    }

    $summary = [
        'providers_seeded' => count($providerSeed),
        'scholarships_seeded' => count($scholarshipSeed),
        'default_provider_password' => $defaultProviderPassword,
        'provider_usernames' => array_column($providerSeed, 'username'),
    ];

    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
