<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Config/db_config.php';

$now = date('Y-m-d H:i:s');
$defaultProviderPassword = 'ProviderDemo@123';

$providerSeed = [
    [
        'username' => 'megaworld_foundation',
        'email' => 'scholarship.megfoundation@megaworldcorp.com',
        'organization_name' => 'Megaworld Foundation, Inc.',
        'organization_type' => 'foundation',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Scholarship Program Office',
        'phone_number' => '+63 (2) 8894-6437',
        'mobile_number' => '09171234566',
        'website' => 'https://www.megaworldfoundation.com/',
        'address' => '22nd Floor, Alliance Global Tower, 11th Avenue corner 36th Street, Uptown Bonifacio',
        'house_no' => '',
        'street' => '11th Avenue corner 36th Street',
        'barangay' => 'Uptown Bonifacio',
        'city' => 'Taguig City',
        'province' => 'Metro Manila',
        'zip_code' => '1634',
        'latitude' => 14.55600000,
        'longitude' => 121.05450000,
        'location_name' => 'Alliance Global Tower, Uptown Bonifacio, Taguig City',
        'description' => 'Foundation scholarship office supporting academically deserving but financially challenged students through tuition, allowances, and career development opportunities.',
    ],
    [
        'username' => 'security_bank_fdn',
        'email' => 'customercare@securitybank.com.ph',
        'organization_name' => 'Security Bank Foundation, Inc.',
        'organization_type' => 'foundation',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Scholars for Better Communities Program',
        'phone_number' => '+63 (2) 8887-9188',
        'mobile_number' => '09171234567',
        'website' => 'https://www.securitybank.com/foundation/',
        'address' => 'Security Bank Centre, 6776 Ayala Avenue',
        'house_no' => '',
        'street' => 'Ayala Avenue',
        'barangay' => '',
        'city' => 'Makati City',
        'province' => 'Metro Manila',
        'zip_code' => '1226',
        'latitude' => 14.55750000,
        'longitude' => 121.02340000,
        'location_name' => 'Security Bank Centre, Makati City',
        'description' => 'Corporate foundation managing the Scholars for Better Communities educational assistance program for deserving college students in partner schools.',
    ],
    [
        'username' => 'bpi_foundation',
        'email' => 'bpifoundation@bpi.com.ph',
        'organization_name' => 'BPI Foundation',
        'organization_type' => 'foundation',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Special Projects Office',
        'phone_number' => '+63 (2) 8816-9288',
        'mobile_number' => '09171234568',
        'website' => 'https://www.bpifoundation.org/',
        'address' => '2/F Buendia Center, Sen. Gil Puyat Avenue',
        'house_no' => '',
        'street' => 'Sen. Gil Puyat Avenue',
        'barangay' => '',
        'city' => 'Makati City',
        'province' => 'Metro Manila',
        'zip_code' => '1200',
        'latitude' => 14.55630000,
        'longitude' => 121.01760000,
        'location_name' => 'BPI Foundation, Buendia Center, Makati City',
        'description' => 'Foundation office managing social development and scholarship initiatives including the Pagpupugay Scholarship.',
    ],
    [
        'username' => 'ayala_foundation',
        'email' => 'info@ayalafoundation.org',
        'organization_name' => 'Ayala Foundation, Inc.',
        'organization_type' => 'foundation',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Education Programs Office',
        'phone_number' => '+63 (2) 7759-8288',
        'mobile_number' => '09171234569',
        'website' => 'https://www.ayalafoundation.org/',
        'address' => '4th Floor, Makati Stock Exchange Building, 6767 Ayala Triangle, Ayala Avenue',
        'house_no' => '',
        'street' => 'Ayala Avenue',
        'barangay' => 'Ayala Triangle',
        'city' => 'Makati City',
        'province' => 'Metro Manila',
        'zip_code' => '1226',
        'latitude' => 14.55410000,
        'longitude' => 121.02470000,
        'location_name' => 'Ayala Foundation, Makati City',
        'description' => 'Social development arm of the Ayala group with education initiatives including the U-Go scholar grant for young women from financially challenged backgrounds.',
    ],
    [
        'username' => 'landbank_ilp',
        'email' => 'contactus@landbank.com',
        'organization_name' => 'Land Bank of the Philippines',
        'organization_type' => 'government_agency',
        'contact_person_firstname' => 'Scholarship',
        'contact_person_lastname' => 'Desk',
        'contact_person_position' => 'Iskolar ng LANDBANK Program Office',
        'phone_number' => '+63 (2) 8551-2200',
        'mobile_number' => '09171234570',
        'website' => 'https://www.landbank.com/',
        'address' => 'LANDBANK Plaza, 1598 M.H. Del Pilar corner Dr. J. Quintos Streets',
        'house_no' => '1598',
        'street' => 'M.H. Del Pilar corner Dr. J. Quintos Streets',
        'barangay' => 'Malate',
        'city' => 'Manila City',
        'province' => 'Metro Manila',
        'zip_code' => '1004',
        'latitude' => 14.57710000,
        'longitude' => 120.98660000,
        'location_name' => 'LANDBANK Plaza, Malate, Manila',
        'description' => 'Government financial institution administering the Iskolar ng LANDBANK Program for deserving students from mandated sectors.',
    ],
];

$scholarshipSeed = [
    [
        'name' => 'Megaworld Foundation Scholarship Program',
        'description' => 'College scholarship support for academically deserving but financially challenged students under Megaworld Foundation partner schools and courses.',
        'eligibility' => 'Deserving students who qualify under Megaworld Foundation scholarship screening and partner school requirements.',
        'min_gwa' => null,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'Megaworld Foundation, Inc.',
        'image' => null,
        'benefits' => 'Full tuition fee support, allowances, and career opportunities after graduation.',
        'address' => '22nd Floor, Alliance Global Tower, 11th Avenue corner 36th Street, Uptown Bonifacio',
        'city' => 'Taguig City',
        'province' => 'Metro Manila',
        'deadline' => '2026-11-30',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applicants complete Megaworld Foundation documentary screening and partner school coordination before final scholarship selection.',
        'target_applicant_type' => 'all',
        'target_year_level' => 'any',
        'required_admission_status' => 'any',
        'target_strand' => null,
        'latitude' => 14.55600000,
        'longitude' => 121.05450000,
        'requirements' => ['good_moral', 'income_proof', 'recommendation', 'id'],
    ],
    [
        'name' => 'Security Bank Foundation Scholars for Better Communities Scholarship Program',
        'description' => 'Educational assistance for deserving incoming college students studying in Security Bank Foundation partner schools and priority degree programs.',
        'eligibility' => 'Incoming first-year college students in Security Bank Foundation partner schools and courses aligned with the Scholars for Better Communities program.',
        'min_gwa' => null,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'Security Bank Foundation, Inc.',
        'image' => null,
        'benefits' => 'Educational assistance, learning sessions, and development opportunities for scholars in partner institutions.',
        'address' => 'Security Bank Centre, 6776 Ayala Avenue',
        'city' => 'Makati City',
        'province' => 'Metro Manila',
        'deadline' => '2026-07-31',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applications are screened with partner schools under the Scholars for Better Communities scholarship process.',
        'target_applicant_type' => 'incoming_freshman',
        'target_year_level' => 'any',
        'required_admission_status' => 'admitted',
        'target_strand' => null,
        'latitude' => 14.55750000,
        'longitude' => 121.02340000,
        'requirements' => ['form_138', 'good_moral', 'income_proof', 'id'],
    ],
    [
        'name' => 'BPI Foundation Pagpupugay Scholarship',
        'description' => 'Scholarship support for qualified next of kin or close relatives of medical frontliners affected by COVID-19 through BPI Foundation partner schools.',
        'eligibility' => 'Qualified next of kin or close relatives of medical frontliners covered by the Pagpupugay Scholarship program and endorsed through partner schools.',
        'min_gwa' => null,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'BPI Foundation',
        'image' => null,
        'benefits' => 'Up to PHP 100,000 scholarship grant per academic year to help cover tuition and other incidental school expenses.',
        'address' => '2/F Buendia Center, Sen. Gil Puyat Avenue',
        'city' => 'Makati City',
        'province' => 'Metro Manila',
        'deadline' => '2026-08-15',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applications are endorsed through partner schools and evaluated based on eligibility, academic performance, and financial need.',
        'target_applicant_type' => 'all',
        'target_year_level' => 'any',
        'required_admission_status' => 'any',
        'target_strand' => null,
        'latitude' => 14.55630000,
        'longitude' => 121.01760000,
        'requirements' => ['birth_certificate', 'good_moral', 'income_proof', 'id'],
    ],
    [
        'name' => 'Ayala Foundation U-Go Scholar Grant',
        'description' => 'Higher education support program for promising young women from economically disadvantaged backgrounds through Ayala Foundation and U-Go.',
        'eligibility' => 'Young women from financially challenged backgrounds who show academic promise, community engagement, and leadership potential.',
        'min_gwa' => null,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'Ayala Foundation, Inc.',
        'image' => null,
        'benefits' => 'Higher education assistance and scholar support opportunities through the Ayala Foundation and U-Go partnership.',
        'address' => '4th Floor, Makati Stock Exchange Building, 6767 Ayala Triangle, Ayala Avenue',
        'city' => 'Makati City',
        'province' => 'Metro Manila',
        'deadline' => '2026-09-15',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applicants are screened based on academic promise, community engagement, and leadership indicators under the U-Go scholar grant process.',
        'target_applicant_type' => 'all',
        'target_year_level' => 'any',
        'required_admission_status' => 'any',
        'target_strand' => null,
        'latitude' => 14.55410000,
        'longitude' => 121.02470000,
        'requirements' => ['good_moral', 'income_proof', 'recommendation', 'id'],
    ],
    [
        'name' => 'Iskolar ng LANDBANK Program',
        'description' => 'Scholarship assistance for underprivileged but deserving students from LANDBANK mandated sectors, especially children or grandchildren of ARBs and small farmers or fishers.',
        'eligibility' => 'Deserving students from LANDBANK mandated sectors who qualify under the Iskolar ng LANDBANK Program screening guidelines.',
        'min_gwa' => null,
        'max_gwa' => null,
        'status' => 'active',
        'provider' => 'Land Bank of the Philippines',
        'image' => null,
        'benefits' => 'Scholarship support for eligible students selected under the Iskolar ng LANDBANK Program cycle.',
        'address' => 'LANDBANK Plaza, 1598 M.H. Del Pilar corner Dr. J. Quintos Streets',
        'city' => 'Manila City',
        'province' => 'Metro Manila',
        'deadline' => '2026-10-15',
        'assessment_requirement' => 'evaluation',
        'assessment_link' => null,
        'assessment_details' => 'Applications are screened and announced through official LANDBANK channels for each school year cycle.',
        'target_applicant_type' => 'incoming_freshman',
        'target_year_level' => 'any',
        'required_admission_status' => 'applied',
        'target_strand' => null,
        'latitude' => 14.57710000,
        'longitude' => 120.98660000,
        'requirements' => ['form_138', 'birth_certificate', 'good_moral', 'income_proof', 'id'],
    ],
];

function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    $cache[$key] = ((int) $stmt->fetchColumn()) > 0;

    return $cache[$key];
}

function ensureProviderAccount(PDO $pdo, array $provider, string $password, string $now): int
{
    $providerUserId = null;

    $stmt = $pdo->prepare('SELECT user_id FROM provider_data WHERE organization_name = ? LIMIT 1');
    $stmt->execute([$provider['organization_name']]);
    $providerUserId = $stmt->fetchColumn();

    if (!$providerUserId) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$provider['username'], $provider['email']]);
        $providerUserId = $stmt->fetchColumn();
    }

    if ($providerUserId) {
        $providerUserId = (int) $providerUserId;
        $stmt = $pdo->prepare("
            UPDATE users
            SET username = ?, email = ?, role = 'provider', access_level = 30, status = 'active',
                email_verified_at = COALESCE(email_verified_at, ?), updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $provider['username'],
            $provider['email'],
            $now,
            $now,
            $providerUserId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO users (
                username, email, password, created_at, role, access_level, status, email_verified_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, 'provider', 30, 'active', ?, ?
            )
        ");
        $stmt->execute([
            $provider['username'],
            $provider['email'],
            password_hash($password, PASSWORD_DEFAULT),
            $now,
            $now,
            $now,
        ]);
        $providerUserId = (int) $pdo->lastInsertId();
    }

    $providerColumns = [
        'organization_name', 'contact_person_firstname', 'contact_person_lastname', 'contact_person_position',
        'phone_number', 'mobile_number', 'organization_email', 'website', 'organization_type', 'address',
        'house_no', 'street', 'barangay', 'city', 'province', 'zip_code', 'description',
        'is_verified', 'verified_at', 'updated_at'
    ];

    if (tableHasColumn($pdo, 'provider_data', 'latitude')) {
        $providerColumns[] = 'latitude';
    }
    if (tableHasColumn($pdo, 'provider_data', 'longitude')) {
        $providerColumns[] = 'longitude';
    }
    if (tableHasColumn($pdo, 'provider_data', 'location_name')) {
        $providerColumns[] = 'location_name';
    }
    if (tableHasColumn($pdo, 'provider_data', 'created_at')) {
        $providerColumns[] = 'created_at';
    }

    $existingProviderStmt = $pdo->prepare('SELECT id FROM provider_data WHERE user_id = ? LIMIT 1');
    $existingProviderStmt->execute([$providerUserId]);
    $providerDataId = $existingProviderStmt->fetchColumn();

    if ($providerDataId) {
        $assignments = [];
        $values = [];
        foreach ($providerColumns as $column) {
            $assignments[] = $column . ' = ?';
            if ($column === 'organization_email') {
                $values[] = $provider['email'];
            } elseif ($column === 'is_verified') {
                $values[] = 1;
            } elseif ($column === 'verified_at' || $column === 'created_at' || $column === 'updated_at') {
                $values[] = $now;
            } else {
                $values[] = $provider[$column] ?? null;
            }
        }
        $values[] = $providerUserId;

        $stmt = $pdo->prepare('UPDATE provider_data SET ' . implode(', ', $assignments) . ' WHERE user_id = ?');
        $stmt->execute($values);
    } else {
        $insertColumns = array_merge(['user_id'], $providerColumns);
        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $values = [$providerUserId];

        foreach ($providerColumns as $column) {
            if ($column === 'organization_email') {
                $values[] = $provider['email'];
            } elseif ($column === 'is_verified') {
                $values[] = 1;
            } elseif ($column === 'verified_at' || $column === 'created_at' || $column === 'updated_at') {
                $values[] = $now;
            } else {
                $values[] = $provider[$column] ?? null;
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO provider_data (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute($values);
    }

    return $providerUserId;
}

function ensureScholarshipRecord(PDO $pdo, array $scholarship, int $reviewedByUserId, string $now): int
{
    $stmt = $pdo->prepare('SELECT id FROM scholarships WHERE name = ? LIMIT 1');
    $stmt->execute([$scholarship['name']]);
    $scholarshipId = $stmt->fetchColumn();

    if ($scholarshipId) {
        $scholarshipId = (int) $scholarshipId;
        $stmt = $pdo->prepare("
            UPDATE scholarships
            SET description = ?, eligibility = ?, min_gwa = ?, max_gwa = ?, status = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $scholarship['description'],
            $scholarship['eligibility'],
            $scholarship['min_gwa'],
            $scholarship['max_gwa'],
            $scholarship['status'],
            $now,
            $scholarshipId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO scholarships (name, description, eligibility, min_gwa, max_gwa, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $scholarship['name'],
            $scholarship['description'],
            $scholarship['eligibility'],
            $scholarship['min_gwa'],
            $scholarship['max_gwa'],
            $scholarship['status'],
            $now,
            $now,
        ]);
        $scholarshipId = (int) $pdo->lastInsertId();
    }

    $dataColumns = [
        'image', 'provider', 'benefits', 'address', 'city', 'province', 'deadline',
        'assessment_requirement', 'assessment_link', 'assessment_details', 'created_at', 'updated_at',
        'target_applicant_type', 'target_year_level', 'required_admission_status', 'target_strand',
        'review_status', 'review_notes', 'reviewed_by_user_id', 'reviewed_at'
    ];

    $optionalDataColumns = [
        'target_citizenship' => 'all',
        'target_income_bracket' => 'any',
        'target_special_category' => 'any',
    ];

    foreach ($optionalDataColumns as $column => $defaultValue) {
        if (tableHasColumn($pdo, 'scholarship_data', $column)) {
            $dataColumns[] = $column;
            if (!array_key_exists($column, $scholarship)) {
                $scholarship[$column] = $defaultValue;
            }
        }
    }

    $dataValues = [];
    foreach ($dataColumns as $column) {
        if ($column === 'created_at' || $column === 'updated_at' || $column === 'reviewed_at') {
            $dataValues[$column] = $now;
        } elseif ($column === 'review_status') {
            $dataValues[$column] = 'approved';
        } elseif ($column === 'review_notes') {
            $dataValues[$column] = null;
        } elseif ($column === 'reviewed_by_user_id') {
            $dataValues[$column] = $reviewedByUserId;
        } else {
            $dataValues[$column] = $scholarship[$column] ?? null;
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM scholarship_data WHERE scholarship_id = ? LIMIT 1');
    $stmt->execute([$scholarshipId]);
    $scholarshipDataId = $stmt->fetchColumn();

    if ($scholarshipDataId) {
        $assignments = [];
        $values = [];
        foreach ($dataColumns as $column) {
            $assignments[] = $column . ' = ?';
            $values[] = $dataValues[$column];
        }
        $values[] = $scholarshipId;
        $stmt = $pdo->prepare('UPDATE scholarship_data SET ' . implode(', ', $assignments) . ' WHERE scholarship_id = ?');
        $stmt->execute($values);
    } else {
        $insertColumns = array_merge(['scholarship_id'], $dataColumns);
        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $values = [$scholarshipId];
        foreach ($dataColumns as $column) {
            $values[] = $dataValues[$column];
        }
        $stmt = $pdo->prepare(
            'INSERT INTO scholarship_data (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute($values);
    }

    $stmt = $pdo->prepare('SELECT id FROM scholarship_location WHERE scholarship_id = ? LIMIT 1');
    $stmt->execute([$scholarshipId]);
    $locationId = $stmt->fetchColumn();
    if ($locationId) {
        $stmt = $pdo->prepare('UPDATE scholarship_location SET latitude = ?, longitude = ? WHERE scholarship_id = ?');
        $stmt->execute([$scholarship['latitude'], $scholarship['longitude'], $scholarshipId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO scholarship_location (scholarship_id, latitude, longitude) VALUES (?, ?, ?)');
        $stmt->execute([$scholarshipId, $scholarship['latitude'], $scholarship['longitude']]);
    }

    $stmt = $pdo->prepare('DELETE FROM document_requirements WHERE scholarship_id = ?');
    $stmt->execute([$scholarshipId]);

    $insertRequirement = $pdo->prepare(
        'INSERT INTO document_requirements (scholarship_id, document_type, is_required, description) VALUES (?, ?, 1, NULL)'
    );
    foreach ($scholarship['requirements'] as $requirement) {
        $insertRequirement->execute([$scholarshipId, $requirement]);
    }

    return $scholarshipId;
}

try {
    $pdo->beginTransaction();

    $reviewedByUserId = (int) ($pdo->query("
        SELECT id
        FROM users
        WHERE role IN ('super_admin', 'admin')
        ORDER BY FIELD(role, 'super_admin', 'admin'), id
        LIMIT 1
    ")->fetchColumn() ?: 1);

    foreach ($providerSeed as $provider) {
        ensureProviderAccount($pdo, $provider, $defaultProviderPassword, $now);
    }

    foreach ($scholarshipSeed as $scholarship) {
        ensureScholarshipRecord($pdo, $scholarship, $reviewedByUserId, $now);
    }

    $pdo->commit();

    echo "Added or updated " . count($providerSeed) . " provider accounts and " . count($scholarshipSeed) . " scholarships.\n";
    echo "Default provider password: {$defaultProviderPassword}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
