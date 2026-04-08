<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/provider_scope.php';
require_once __DIR__ . '/../Config/url_token.php';
require_once __DIR__ . '/../Config/notification_helpers.php';
require_once __DIR__ . '/../Models/ActivityLog.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to manage scholarships.');

$upload_dir = '../public/uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool {
    static $cache = [];
    $cacheKey = $tableName . '.' . $columnName;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName
    ]);

    $cache[$cacheKey] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$cacheKey];
}

function tableExists(PDO $pdo, string $tableName): bool {
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([':table_name' => $tableName]);

    $exists = ((int) $stmt->fetchColumn()) > 0;
    if ($exists) {
        $cache[$tableName] = true;
    }
    return $exists;
}

function ensureScholarshipStudentInfoColumns(PDO $pdo): void {
    $columnDefinitions = [
        'application_open_date' => "ALTER TABLE scholarship_data ADD COLUMN application_open_date DATE NULL",
        'application_process_label' => "ALTER TABLE scholarship_data ADD COLUMN application_process_label VARCHAR(150) NULL",
        'post_application_steps' => "ALTER TABLE scholarship_data ADD COLUMN post_application_steps TEXT NULL",
        'renewal_conditions' => "ALTER TABLE scholarship_data ADD COLUMN renewal_conditions TEXT NULL",
        'scholarship_restrictions' => "ALTER TABLE scholarship_data ADD COLUMN scholarship_restrictions TEXT NULL",
    ];

    foreach ($columnDefinitions as $columnName => $sql) {
        if (tableHasColumn($pdo, 'scholarship_data', $columnName)) {
            continue;
        }

        $pdo->exec($sql);
    }
}

function nullableString($value): ?string {
    $trimmed = trim((string) $value);
    return $trimmed === '' ? null : $trimmed;
}

function normalizeChoice($value, array $allowedValues, ?string $default = null): ?string {
    $normalized = strtolower(trim((string) $value));
    if ($normalized === '') {
        return $default;
    }

    return in_array($normalized, $allowedValues, true) ? $normalized : null;
}

function validateScholarshipDeadline(string $deadline, array &$errors): ?string {
    $normalized = trim($deadline);
    if ($normalized === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $normalized);
    $formatErrors = DateTime::getLastErrors();
    $hasDateErrors = is_array($formatErrors)
        && (($formatErrors['warning_count'] ?? 0) > 0 || ($formatErrors['error_count'] ?? 0) > 0);

    if (!$date || $hasDateErrors || $date->format('Y-m-d') !== $normalized) {
        $errors[] = 'Deadline must be a valid date.';
        return null;
    }

    $today = new DateTime('today');
    if ($date < $today) {
        $errors[] = 'Deadline cannot be set to a past date.';
        return null;
    }

    return $normalized;
}

function validateScholarshipCalendarDate(string $dateValue, string $label, array &$errors): ?string {
    $normalized = trim($dateValue);
    if ($normalized === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $normalized);
    $formatErrors = DateTime::getLastErrors();
    $hasDateErrors = is_array($formatErrors)
        && (($formatErrors['warning_count'] ?? 0) > 0 || ($formatErrors['error_count'] ?? 0) > 0);

    if (!$date || $hasDateErrors || $date->format('Y-m-d') !== $normalized) {
        $errors[] = $label . ' must be a valid date.';
        return null;
    }

    return $normalized;
}

function storeScholarshipOldInput(array $input): void {
    $allowedKeys = [
        'action',
        'id',
        'name',
        'description',
        'eligibility',
        'min_gwa',
        'max_gwa',
        'provider',
        'benefits',
        'application_open_date',
        'deadline',
        'status',
        'application_process_label',
        'post_application_steps',
        'renewal_conditions',
        'scholarship_restrictions',
        'target_applicant_type',
        'target_year_level',
        'required_admission_status',
        'target_strand',
        'target_citizenship',
        'target_income_bracket',
        'target_special_category',
        'address',
        'city',
        'province',
        'assessment_requirement',
        'assessment_link',
        'assessment_details',
        'remote_exam_sites',
        'requirements',
        'latitude',
        'longitude',
        'current_image',
        'remove_image'
    ];

    $payload = [];
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }

        $value = $input[$key];
        if (is_array($value)) {
            continue;
        }

        $payload[$key] = trim((string) $value);
    }

    $_SESSION['scholarship_form_old'] = $payload;
}

function clearScholarshipOldInput(): void {
    unset($_SESSION['scholarship_form_old']);
}

function redirectScholarshipFormWithErrors(string $action, int $scholarshipId, array $errors, array $oldInput): void {
    $_SESSION['errors'] = $errors;
    storeScholarshipOldInput($oldInput);

    $location = $action === 'update' && $scholarshipId > 0
        ? buildEntityUrl('../AdminView/edit_scholarship.php', 'scholarship', $scholarshipId, 'edit', ['id' => $scholarshipId])
        : '../AdminView/add_scholarship.php';

    header('Location: ' . normalizeAppUrl($location));
    exit();
}

function denyScholarshipAction(string $message): void
{
    $_SESSION['error'] = $message;
    header('Location: ' . normalizeAppUrl('../AdminView/manage_scholarships.php'));
    exit();
}

function requireScholarshipActionToken(int $scholarshipId, string $intent): void
{
    $token = $_POST['entity_token'] ?? $_GET['token'] ?? null;
    if (!isValidEntityUrlToken('scholarship', $scholarshipId, $token, $intent)) {
        denyScholarshipAction('Invalid or expired access token.');
    }
}

function uploadImage(array $file) {
    global $upload_dir;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return ['error' => ['Upload failed with error code: ' . $file['error']]];
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        return ['error' => ['File size must be less than 2MB']];
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedExtensions, true)) {
        return ['error' => ['Only JPG, JPEG, PNG, and GIF files are allowed']];
    }

    $check = @getimagesize($file['tmp_name']);
    if ($check === false) {
        return ['error' => ['File is not a valid image']];
    }

    $newFileName = uniqid('', true) . '_' . time() . '.' . $fileExt;
    $uploadPath = $upload_dir . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return ['error' => ['Failed to upload file']];
    }

    return $newFileName;
}

function deleteImage(?string $filename): bool {
    global $upload_dir;
    $name = trim((string) $filename);
    if ($name === '') {
        return false;
    }

    $filePath = $upload_dir . $name;
    if (is_file($filePath)) {
        return unlink($filePath);
    }
    return false;
}

function parseCoordinates(string $latitudeRaw, string $longitudeRaw, array &$errors): array {
    $hasLatitude = $latitudeRaw !== '';
    $hasLongitude = $longitudeRaw !== '';

    if ($hasLatitude xor $hasLongitude) {
        $errors[] = 'Please provide both latitude and longitude, or leave both empty.';
        return [null, null];
    }

    if (!$hasLatitude && !$hasLongitude) {
        return [null, null];
    }

    if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
        $errors[] = 'Invalid coordinate format.';
        return [null, null];
    }

    $latitude = (float) $latitudeRaw;
    $longitude = (float) $longitudeRaw;

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        $errors[] = 'Coordinates are out of range.';
        return [null, null];
    }

    return [$latitude, $longitude];
}

function geocodeScholarshipAddress(string $address, string $city, string $province): ?array {
    $parts = array_filter([
        trim($address),
        trim($city),
        trim($province),
        'Philippines'
    ], function ($value) {
        return $value !== '';
    });

    if (empty($parts)) {
        return null;
    }

    $query = implode(', ', $parts);
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 1,
        'countrycodes' => 'ph'
    ]);

    $response = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ScholarshipFinder/1.0 (scholarship location geocoding)'
            ]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "User-Agent: ScholarshipFinder/1.0 (scholarship location geocoding)\r\n"
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
    }

    if (!$response) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded[0]['lat']) || empty($decoded[0]['lon'])) {
        return null;
    }

    return [
        'latitude' => (float) $decoded[0]['lat'],
        'longitude' => (float) $decoded[0]['lon']
    ];
}

function buildScholarshipDataPayload(PDO $pdo, array $input, ?string $image): array {
    $payload = [
        'provider' => nullableString($input['provider'] ?? ''),
        'benefits' => nullableString($input['benefits'] ?? ''),
        'application_open_date' => nullableString($input['application_open_date'] ?? ''),
        'deadline' => nullableString($input['deadline'] ?? ''),
        'image' => nullableString($image),
        'application_process_label' => nullableString($input['application_process_label'] ?? ''),
        'post_application_steps' => nullableString($input['post_application_steps'] ?? ''),
        'renewal_conditions' => nullableString($input['renewal_conditions'] ?? ''),
        'scholarship_restrictions' => nullableString($input['scholarship_restrictions'] ?? ''),
    ];

    if (tableHasColumn($pdo, 'scholarship_data', 'address')) {
        $payload['address'] = nullableString($input['address'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'city')) {
        $payload['city'] = nullableString($input['city'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'province')) {
        $payload['province'] = nullableString($input['province'] ?? '');
    }

    if (tableHasColumn($pdo, 'scholarship_data', 'assessment_requirement')) {
        $value = strtolower(trim((string) ($input['assessment_requirement'] ?? 'none')));
        $allowed = ['none', 'online_exam', 'remote_examination', 'assessment', 'evaluation'];
        if (!in_array($value, $allowed, true)) {
            $value = 'none';
        }
        $payload['assessment_requirement'] = $value;
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'assessment_link')) {
        $payload['assessment_link'] = nullableString($input['assessment_link'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'assessment_details')) {
        $payload['assessment_details'] = nullableString($input['assessment_details'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'target_applicant_type')) {
        $payload['target_applicant_type'] = nullableString($input['target_applicant_type'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'target_year_level')) {
        $payload['target_year_level'] = nullableString($input['target_year_level'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'required_admission_status')) {
        $payload['required_admission_status'] = nullableString($input['required_admission_status'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'target_strand')) {
        $payload['target_strand'] = nullableString($input['target_strand'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'target_citizenship')) {
        $payload['target_citizenship'] = nullableString($input['target_citizenship'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'target_income_bracket')) {
        $payload['target_income_bracket'] = nullableString($input['target_income_bracket'] ?? '');
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'target_special_category')) {
        $payload['target_special_category'] = nullableString($input['target_special_category'] ?? '');
    }
    if (array_key_exists('review_status', $input) && tableHasColumn($pdo, 'scholarship_data', 'review_status')) {
        $payload['review_status'] = strtolower(trim((string) $input['review_status'])) ?: 'approved';
    }
    if (array_key_exists('review_notes', $input) && tableHasColumn($pdo, 'scholarship_data', 'review_notes')) {
        $payload['review_notes'] = nullableString($input['review_notes'] ?? '');
    }
    if (array_key_exists('reviewed_by_user_id', $input) && tableHasColumn($pdo, 'scholarship_data', 'reviewed_by_user_id')) {
        $payload['reviewed_by_user_id'] = isset($input['reviewed_by_user_id']) && $input['reviewed_by_user_id'] !== ''
            ? (int) $input['reviewed_by_user_id']
            : null;
    }
    if (array_key_exists('reviewed_at', $input) && tableHasColumn($pdo, 'scholarship_data', 'reviewed_at')) {
        $payload['reviewed_at'] = nullableString($input['reviewed_at'] ?? '');
    }

    return $payload;
}

function scholarshipReviewWorkflowReady(PDO $pdo): bool {
    return tableHasColumn($pdo, 'scholarship_data', 'review_status');
}

function scholarshipReviewRedirect(string $target, int $scholarshipId = 0): void
{
    if ($target === 'edit' && $scholarshipId > 0) {
        header('Location: ' . normalizeAppUrl(buildEntityUrl('../AdminView/edit_scholarship.php', 'scholarship', $scholarshipId, 'edit', ['id' => $scholarshipId])));
        exit();
    }

    header('Location: ' . normalizeAppUrl('../AdminView/scholarship_reviews.php'));
    exit();
}

function getScholarshipReviewState(PDO $pdo, int $scholarshipId): array
{
    if (!scholarshipReviewWorkflowReady($pdo)) {
        return [
            'review_status' => null,
            'review_notes' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null
        ];
    }

    $selectParts = ["COALESCE(sd.review_status, 'approved') AS review_status"];
    if (tableHasColumn($pdo, 'scholarship_data', 'review_notes')) {
        $selectParts[] = 'sd.review_notes';
    } else {
        $selectParts[] = 'NULL AS review_notes';
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'reviewed_by_user_id')) {
        $selectParts[] = 'sd.reviewed_by_user_id';
    } else {
        $selectParts[] = 'NULL AS reviewed_by_user_id';
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'reviewed_at')) {
        $selectParts[] = 'sd.reviewed_at';
    } else {
        $selectParts[] = 'NULL AS reviewed_at';
    }

    $stmt = $pdo->prepare("
        SELECT " . implode(', ', $selectParts) . "
        FROM scholarships s
        LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$scholarshipId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [
        'review_status' => 'approved',
        'review_notes' => null,
        'reviewed_by_user_id' => null,
        'reviewed_at' => null
    ];
}

function buildScholarshipReviewPayload(PDO $pdo, string $reviewStatus, ?string $reviewNotes, ?int $reviewedByUserId, ?string $reviewedAt): array
{
    $payload = [];

    if (tableHasColumn($pdo, 'scholarship_data', 'review_status')) {
        $payload['review_status'] = $reviewStatus;
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'review_notes')) {
        $payload['review_notes'] = $reviewNotes;
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'reviewed_by_user_id')) {
        $payload['reviewed_by_user_id'] = $reviewedByUserId;
    }
    if (tableHasColumn($pdo, 'scholarship_data', 'reviewed_at')) {
        $payload['reviewed_at'] = $reviewedAt;
    }

    return $payload;
}

function upsertScholarshipData(PDO $pdo, int $scholarshipId, array $payload): void {
    $stmt = $pdo->prepare("SELECT id FROM scholarship_data WHERE scholarship_id = ? LIMIT 1");
    $stmt->execute([$scholarshipId]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $set = [];
        $params = [];
        foreach ($payload as $column => $value) {
            $set[] = "{$column} = ?";
            $params[] = $value;
        }
        $params[] = $scholarshipId;

        $sql = "UPDATE scholarship_data SET " . implode(', ', $set) . " WHERE scholarship_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return;
    }

    $columns = array_merge(['scholarship_id'], array_keys($payload));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $params = array_merge([$scholarshipId], array_values($payload));

    $sql = "INSERT INTO scholarship_data (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function upsertScholarshipLocation(PDO $pdo, int $scholarshipId, ?float $latitude, ?float $longitude): void {
    $stmt = $pdo->prepare("SELECT id FROM scholarship_location WHERE scholarship_id = ? LIMIT 1");
    $stmt->execute([$scholarshipId]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($latitude !== null && $longitude !== null) {
        if ($exists) {
            $stmt = $pdo->prepare("UPDATE scholarship_location SET latitude = ?, longitude = ? WHERE scholarship_id = ?");
            $stmt->execute([$latitude, $longitude, $scholarshipId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO scholarship_location (scholarship_id, latitude, longitude) VALUES (?, ?, ?)");
            $stmt->execute([$scholarshipId, $latitude, $longitude]);
        }
        return;
    }

    if ($exists) {
        $stmt = $pdo->prepare("DELETE FROM scholarship_location WHERE scholarship_id = ?");
        $stmt->execute([$scholarshipId]);
    }
}

function ensureRemoteExamLocationsTable(PDO $pdo): void {
    if (tableExists($pdo, 'scholarship_remote_exam_locations')) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scholarship_remote_exam_locations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scholarship_id INT UNSIGNED NOT NULL,
            site_name VARCHAR(150) NULL,
            address VARCHAR(255) NULL,
            city VARCHAR(120) NULL,
            province VARCHAR(120) NULL,
            latitude DECIMAL(10,8) NULL,
            longitude DECIMAL(11,8) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_remote_exam_scholarship (scholarship_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function normalizeRemoteExamSites(string $json): array {
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $normalized = [];
    foreach ($decoded as $site) {
        if (!is_array($site)) {
            continue;
        }

        $siteName = nullableString($site['site_name'] ?? '');
        $address = nullableString($site['address'] ?? '');
        $city = nullableString($site['city'] ?? '');
        $province = nullableString($site['province'] ?? '');

        $latRaw = trim((string) ($site['latitude'] ?? ''));
        $lngRaw = trim((string) ($site['longitude'] ?? ''));
        $latitude = null;
        $longitude = null;

        if ($latRaw !== '' && $lngRaw !== '' && is_numeric($latRaw) && is_numeric($lngRaw)) {
            $latValue = (float) $latRaw;
            $lngValue = (float) $lngRaw;
            if ($latValue >= -90 && $latValue <= 90 && $lngValue >= -180 && $lngValue <= 180) {
                $latitude = $latValue;
                $longitude = $lngValue;
            }
        }

        if ($latitude === null && $longitude === null && ($address !== null || $city !== null || $province !== null)) {
            $geocoded = geocodeScholarshipAddress($address ?? '', $city ?? '', $province ?? '');
            if ($geocoded) {
                $latitude = $geocoded['latitude'];
                $longitude = $geocoded['longitude'];
            }
        }

        if ($siteName === null && $address === null && $city === null && $province === null && $latitude === null && $longitude === null) {
            continue;
        }

        $normalized[] = [
            'site_name' => $siteName,
            'address' => $address,
            'city' => $city,
            'province' => $province,
            'latitude' => $latitude,
            'longitude' => $longitude
        ];
    }

    return $normalized;
}

function upsertRemoteExamLocations(PDO $pdo, int $scholarshipId, string $assessmentRequirement, string $sitesJson): void {
    if (!tableExists($pdo, 'scholarship_remote_exam_locations')) {
        return;
    }

    $deleteStmt = $pdo->prepare("DELETE FROM scholarship_remote_exam_locations WHERE scholarship_id = ?");
    $deleteStmt->execute([$scholarshipId]);

    if ($assessmentRequirement !== 'remote_examination') {
        return;
    }

    $sites = normalizeRemoteExamSites($sitesJson);
    if (empty($sites)) {
        return;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO scholarship_remote_exam_locations
            (scholarship_id, site_name, address, city, province, latitude, longitude)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($sites as $site) {
        $insertStmt->execute([
            $scholarshipId,
            $site['site_name'],
            $site['address'],
            $site['city'],
            $site['province'],
            $site['latitude'],
            $site['longitude']
        ]);
    }
}

function updateDocumentRequirements(PDO $pdo, int $scholarshipId, string $requirementsJson): bool {
    try {
        $requirements = json_decode($requirementsJson, true);
        if (!is_array($requirements)) {
            $requirements = [];
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM document_requirements WHERE scholarship_id = ?");
        $stmt->execute([$scholarshipId]);

        $insertStmt = $pdo->prepare("
            INSERT INTO document_requirements (scholarship_id, document_type, is_required, description)
            VALUES (?, ?, 1, ?)
        ");
        $descStmt = $pdo->prepare("SELECT description FROM document_types WHERE code = ?");

        foreach ($requirements as $docType) {
            $descStmt->execute([$docType]);
            $docInfo = $descStmt->fetch(PDO::FETCH_ASSOC);
            $description = $docInfo['description'] ?? null;
            $insertStmt->execute([$scholarshipId, $docType, $description]);
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error updating document requirements: " . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . normalizeAppUrl('../AdminView/manage_scholarships.php'));
    exit();
}

$action = $_POST['action'] ?? '';

if ($action === 'approve_review' || $action === 'reject_review') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        $_SESSION['error'] = 'Invalid scholarship review request.';
        scholarshipReviewRedirect((string) ($_POST['redirect_target'] ?? 'reviews'));
    }

    requireScholarshipActionToken($id, 'review');
    if (!canAccessScholarshipApprovals()) {
        denyScholarshipAction('You do not have permission to review scholarship submissions.');
    }

    if (!scholarshipReviewWorkflowReady($pdo)) {
        $_SESSION['error'] = 'Scholarship review workflow is not ready. Run the scholarship review migration first.';
        scholarshipReviewRedirect((string) ($_POST['redirect_target'] ?? 'reviews'), $id);
    }

    $reviewStatus = $action === 'approve_review' ? 'approved' : 'rejected';
    $publicationStatus = $action === 'approve_review' ? 'active' : 'inactive';
    $reviewMessage = $action === 'approve_review'
        ? 'Approved a provider-submitted scholarship.'
        : 'Rejected a provider-submitted scholarship.';
    $successMessage = $action === 'approve_review'
        ? 'Scholarship approved and published.'
        : 'Scholarship review marked as rejected.';

    try {
        $stmt = $pdo->prepare("
            SELECT s.name, sd.provider
            FROM scholarships s
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $scholarshipDetails = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$scholarshipDetails) {
            throw new RuntimeException('Scholarship not found.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE scholarships SET status = ? WHERE id = ?");
        $stmt->execute([$publicationStatus, $id]);

        upsertScholarshipData($pdo, $id, buildScholarshipReviewPayload(
            $pdo,
            $reviewStatus,
            null,
            (int) ($_SESSION['user_id'] ?? 0),
            date('Y-m-d H:i:s')
        ));

        $pdo->commit();

        $activityLog = new ActivityLog($pdo);
        $activityLog->log($action === 'approve_review' ? 'approve' : 'reject', 'scholarship_review', $reviewMessage, [
            'entity_id' => $id,
            'entity_name' => (string) ($scholarshipDetails['name'] ?? 'Scholarship'),
            'details' => [
                'provider' => (string) ($scholarshipDetails['provider'] ?? ''),
                'review_status' => $reviewStatus,
                'publication_status' => $publicationStatus
            ]
        ]);

        createNotificationsForUsers(
            $pdo,
            getProviderNotificationRecipientIds($pdo, (string) ($scholarshipDetails['provider'] ?? '')),
            $action === 'approve_review' ? 'scholarship_review_approved' : 'scholarship_review_rejected',
            $action === 'approve_review' ? 'Scholarship approved' : 'Scholarship review update',
            $action === 'approve_review'
                ? 'Your scholarship submission for ' . ((string) ($scholarshipDetails['name'] ?? 'this scholarship')) . ' has been approved and published.'
                : 'Your scholarship submission for ' . ((string) ($scholarshipDetails['name'] ?? 'this scholarship')) . ' was not approved.',
            [
                'entity_type' => 'scholarship',
                'entity_id' => $id,
                'link_url' => 'manage_scholarships.php'
            ]
        );

        $_SESSION['success'] = $successMessage;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Failed to update scholarship review: ' . $e->getMessage();
    }

    scholarshipReviewRedirect((string) ($_POST['redirect_target'] ?? 'reviews'), $id);
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        $_SESSION['error'] = 'Invalid scholarship ID.';
        header('Location: ' . normalizeAppUrl('../AdminView/manage_scholarships.php'));
        exit();
    }
    if (getCurrentSessionRole() !== 'super_admin') {
        denyScholarshipAction('Only super administrators can delete scholarship records.');
    }
    requireScholarshipActionToken($id, 'delete');
    if (!providerCanAccessScholarship($pdo, $id)) {
        denyScholarshipAction('You can only delete scholarships posted by your organization.');
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                s.name,
                s.status,
                sd.provider,
                sd.deadline,
                sd.image
            FROM scholarships s
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $scholarshipDetails = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare("DELETE FROM scholarships WHERE id = ?");
        $stmt->execute([$id]);

        if (!empty($scholarshipDetails['image'])) {
            deleteImage($scholarshipDetails['image']);
        }

        if ($stmt->rowCount() > 0) {
            $activityLog = new ActivityLog($pdo);
            $activityLog->log('delete', 'scholarship', 'Deleted a scholarship record.', [
                'entity_id' => $id,
                'entity_name' => (string) ($scholarshipDetails['name'] ?? 'Scholarship'),
                'details' => [
                    'provider' => (string) ($scholarshipDetails['provider'] ?? ''),
                    'status' => (string) ($scholarshipDetails['status'] ?? ''),
                    'deadline' => (string) ($scholarshipDetails['deadline'] ?? '')
                ]
            ]);
        }

        $_SESSION['success'] = 'Scholarship deleted successfully';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Failed to delete scholarship: ' . $e->getMessage();
    }

    header('Location: ' . normalizeAppUrl('../AdminView/manage_scholarships.php'));
    exit();
}

$isUpdate = ($action === 'update');
$scholarshipId = $isUpdate ? (int) ($_POST['id'] ?? 0) : 0;

$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$eligibility = trim((string) ($_POST['eligibility'] ?? ''));
$minGwaRaw = trim((string) ($_POST['min_gwa'] ?? ''));
$minGwa = $minGwaRaw === '' ? null : (float) $minGwaRaw;
$benefits = trim((string) ($_POST['benefits'] ?? ''));
$applicationOpenDate = trim((string) ($_POST['application_open_date'] ?? ''));
$deadline = trim((string) ($_POST['deadline'] ?? ''));
$provider = trim((string) ($_POST['provider'] ?? ''));
$status = trim((string) ($_POST['status'] ?? 'active'));
$applicationProcessLabel = trim((string) ($_POST['application_process_label'] ?? ''));
$postApplicationSteps = trim((string) ($_POST['post_application_steps'] ?? ''));
$renewalConditions = trim((string) ($_POST['renewal_conditions'] ?? ''));
$scholarshipRestrictions = trim((string) ($_POST['scholarship_restrictions'] ?? ''));
$allowedTargetApplicantTypes = ['all', 'incoming_freshman', 'current_college', 'transferee', 'continuing_student'];
$allowedTargetYearLevels = ['any', '1st_year', '2nd_year', '3rd_year', '4th_year', '5th_year_plus'];
$allowedAdmissionStatuses = ['any', 'not_yet_applied', 'applied', 'admitted', 'enrolled'];
$allowedTargetCitizenships = ['all', 'filipino', 'dual_citizen', 'permanent_resident', 'other'];
$allowedIncomeBrackets = ['any', 'below_10000', '10000_20000', '20001_40000', '40001_80000', 'above_80000'];
$allowedSpecialCategories = ['any', 'pwd', 'indigenous_peoples', 'solo_parent_dependent', 'working_student', 'child_of_ofw', 'four_ps_beneficiary', 'orphan'];
$targetApplicantType = normalizeChoice($_POST['target_applicant_type'] ?? 'all', $allowedTargetApplicantTypes, 'all');
$targetYearLevel = normalizeChoice($_POST['target_year_level'] ?? 'any', $allowedTargetYearLevels, 'any');
$requiredAdmissionStatus = normalizeChoice($_POST['required_admission_status'] ?? 'any', $allowedAdmissionStatuses, 'any');
$targetStrand = trim((string) ($_POST['target_strand'] ?? ''));
$targetCitizenship = normalizeChoice($_POST['target_citizenship'] ?? 'all', $allowedTargetCitizenships, 'all');
$targetIncomeBracket = normalizeChoice($_POST['target_income_bracket'] ?? 'any', $allowedIncomeBrackets, 'any');
$targetSpecialCategory = normalizeChoice($_POST['target_special_category'] ?? 'any', $allowedSpecialCategories, 'any');

$address = trim((string) ($_POST['address'] ?? ''));
$city = trim((string) ($_POST['city'] ?? ''));
$province = trim((string) ($_POST['province'] ?? ''));
$assessmentRequirement = trim((string) ($_POST['assessment_requirement'] ?? 'none'));
$assessmentLink = trim((string) ($_POST['assessment_link'] ?? ''));
$assessmentDetails = trim((string) ($_POST['assessment_details'] ?? ''));
$remoteExamSitesJson = (string) ($_POST['remote_exam_sites'] ?? '[]');

$latitudeRaw = trim((string) ($_POST['latitude'] ?? ''));
$longitudeRaw = trim((string) ($_POST['longitude'] ?? ''));

$errors = [];
if ($isUpdate && $scholarshipId <= 0) {
    $errors[] = 'Invalid scholarship ID.';
}
if ($isUpdate && $scholarshipId > 0) {
    requireScholarshipActionToken($scholarshipId, 'update');
    if (!providerCanAccessScholarship($pdo, $scholarshipId)) {
        denyScholarshipAction('You can only update scholarships posted by your organization.');
    }
}

$providerScope = getCurrentProviderScope($pdo);
$currentRole = getCurrentSessionRole();
$actorUserId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$isProviderActor = ($currentRole === 'provider');
$reviewWorkflowReady = scholarshipReviewWorkflowReady($pdo);
$existingReviewState = $isUpdate && $reviewWorkflowReady ? getScholarshipReviewState($pdo, $scholarshipId) : [
    'review_status' => null,
    'review_notes' => null,
    'reviewed_by_user_id' => null,
    'reviewed_at' => null
];
$existingReviewStatus = strtolower(trim((string) ($existingReviewState['review_status'] ?? '')));
$reviewStatusForSave = null;
$reviewNotesForSave = null;
$reviewedByUserIdForSave = null;
$reviewedAtForSave = null;

if (!empty($providerScope['is_provider'])) {
    $provider = trim((string) ($providerScope['organization_name'] ?? ''));
    $_POST['provider'] = $provider;
    if ($provider === '') {
        $errors[] = 'Complete your provider organization profile before managing scholarships.';
    }
}
if ($name === '') {
    $errors[] = 'Scholarship name is required';
}
if ($deadline === '') {
    $errors[] = 'Deadline is required';
} else {
    $validatedDeadline = validateScholarshipDeadline($deadline, $errors);
    if ($validatedDeadline !== null) {
        $deadline = $validatedDeadline;
    }
}
$validatedOpenDate = validateScholarshipCalendarDate($applicationOpenDate, 'Application opening date', $errors);
if ($validatedOpenDate !== null) {
    $applicationOpenDate = $validatedOpenDate;
}
if ($applicationOpenDate !== '' && $deadline !== '' && empty($errors) && strcmp($applicationOpenDate, $deadline) > 0) {
    $errors[] = 'Application opening date cannot be later than the deadline.';
}
if ($provider === '') {
    $errors[] = 'Provider is required';
}
if ($status !== 'active' && $status !== 'inactive') {
    $status = 'active';
}

if ($reviewWorkflowReady) {
    if ($isProviderActor && !$isUpdate) {
        $status = 'inactive';
        $reviewStatusForSave = 'pending';
        $reviewNotesForSave = null;
        $reviewedByUserIdForSave = null;
        $reviewedAtForSave = null;
    } elseif ($isProviderActor && in_array($existingReviewStatus, ['pending', 'rejected'], true)) {
        $status = 'inactive';
        $reviewStatusForSave = 'pending';
        $reviewNotesForSave = null;
        $reviewedByUserIdForSave = null;
        $reviewedAtForSave = null;
    } elseif (!$isProviderActor && !$isUpdate) {
        $reviewStatusForSave = 'approved';
        $reviewNotesForSave = null;
        $reviewedByUserIdForSave = $actorUserId > 0 ? $actorUserId : null;
        $reviewedAtForSave = date('Y-m-d H:i:s');
    }
}

if ($minGwa !== null && ($minGwa < 1 || $minGwa > 5)) {
    $errors[] = 'Minimum GWA must be between 1.00 and 5.00';
}
if ($targetApplicantType === null) {
    $errors[] = 'Target applicant type is invalid';
}
if ($targetYearLevel === null) {
    $errors[] = 'Target year level is invalid';
}
if ($requiredAdmissionStatus === null) {
    $errors[] = 'Required admission status is invalid';
}
if ($targetStrand !== '' && (function_exists('mb_strlen') ? mb_strlen($targetStrand) : strlen($targetStrand)) > 120) {
    $errors[] = 'Target SHS strand is too long';
}
if ($targetCitizenship === null) {
    $errors[] = 'Target citizenship is invalid';
}
if ($targetIncomeBracket === null) {
    $errors[] = 'Target household income bracket is invalid';
}
if ($targetSpecialCategory === null) {
    $errors[] = 'Target special category is invalid';
}
if ($applicationProcessLabel !== '' && (function_exists('mb_strlen') ? mb_strlen($applicationProcessLabel) : strlen($applicationProcessLabel)) > 150) {
    $errors[] = 'Application process label is too long.';
}

[$latitude, $longitude] = parseCoordinates($latitudeRaw, $longitudeRaw, $errors);

if (empty($errors) && $latitude === null && $longitude === null) {
    $geocoded = geocodeScholarshipAddress($address, $city, $province);
    if ($geocoded) {
        $latitude = $geocoded['latitude'];
        $longitude = $geocoded['longitude'];
    }
}

$imageFromUpload = null;
if (isset($_FILES['provider_image']) && $_FILES['provider_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadResult = uploadImage($_FILES['provider_image']);
    if (is_array($uploadResult) && isset($uploadResult['error'])) {
        $errors = array_merge($errors, $uploadResult['error']);
    } else {
        $imageFromUpload = $uploadResult;
    }
}

if (!empty($errors)) {
    redirectScholarshipFormWithErrors($action, $scholarshipId, $errors, $_POST);
}

if (strtolower($assessmentRequirement) === 'remote_examination') {
    ensureRemoteExamLocationsTable($pdo);
}

try {
    $pdo->beginTransaction();
    ensureScholarshipStudentInfoColumns($pdo);

    if ($isUpdate) {
        $stmt = $pdo->prepare("
            UPDATE scholarships
            SET name = ?, description = ?, eligibility = ?, max_gwa = NULL, min_gwa = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $description, $eligibility, $minGwa, $status, $scholarshipId]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO scholarships (name, description, eligibility, max_gwa, min_gwa, status)
            VALUES (?, ?, ?, NULL, ?, ?)
        ");
        $stmt->execute([$name, $description, $eligibility, $minGwa, $status]);
        $scholarshipId = (int) $pdo->lastInsertId();
    }

    $currentImage = nullableString($_POST['current_image'] ?? '');
    $removeImage = isset($_POST['remove_image']);
    $finalImage = $currentImage;

    if ($imageFromUpload !== null) {
        if ($currentImage !== null && $currentImage !== $imageFromUpload) {
            deleteImage($currentImage);
        }
        $finalImage = $imageFromUpload;
    } elseif ($removeImage) {
        if ($currentImage !== null) {
            deleteImage($currentImage);
        }
        $finalImage = null;
    }

    $scholarshipDataInput = [
        'provider' => $provider,
        'benefits' => $benefits,
        'application_open_date' => $applicationOpenDate,
        'deadline' => $deadline,
        'application_process_label' => $applicationProcessLabel,
        'post_application_steps' => $postApplicationSteps,
        'renewal_conditions' => $renewalConditions,
        'scholarship_restrictions' => $scholarshipRestrictions,
        'target_applicant_type' => $targetApplicantType,
        'target_year_level' => $targetYearLevel,
        'required_admission_status' => $requiredAdmissionStatus,
        'target_strand' => $targetStrand,
        'target_citizenship' => $targetCitizenship,
        'target_income_bracket' => $targetIncomeBracket,
        'target_special_category' => $targetSpecialCategory,
        'address' => $address,
        'city' => $city,
        'province' => $province,
        'assessment_requirement' => $assessmentRequirement,
        'assessment_link' => $assessmentLink,
        'assessment_details' => $assessmentDetails
    ];

    if ($reviewStatusForSave !== null) {
        $scholarshipDataInput['review_status'] = $reviewStatusForSave;
        $scholarshipDataInput['review_notes'] = $reviewNotesForSave;
        $scholarshipDataInput['reviewed_by_user_id'] = $reviewedByUserIdForSave;
        $scholarshipDataInput['reviewed_at'] = $reviewedAtForSave;
    }

    $payload = buildScholarshipDataPayload($pdo, $scholarshipDataInput, $finalImage);

    upsertScholarshipData($pdo, $scholarshipId, $payload);
    upsertScholarshipLocation($pdo, $scholarshipId, $latitude, $longitude);
    upsertRemoteExamLocations($pdo, $scholarshipId, strtolower($assessmentRequirement), $remoteExamSitesJson);

    $pdo->commit();

    $requirementsJson = $_POST['requirements'] ?? '[]';
    updateDocumentRequirements($pdo, $scholarshipId, $requirementsJson);

    $requirementsCount = 0;
    $decodedRequirements = json_decode((string) $requirementsJson, true);
    if (is_array($decodedRequirements)) {
        $requirementsCount = count($decodedRequirements);
    }

    $remoteExamCount = 0;
    $decodedRemoteSites = json_decode($remoteExamSitesJson, true);
    if (is_array($decodedRemoteSites)) {
        $remoteExamCount = count(array_filter($decodedRemoteSites, function ($site) {
            return is_array($site) && trim((string) ($site['site_name'] ?? '')) !== '';
        }));
    }

    $activityLog = new ActivityLog($pdo);
    $activityLog->log($isUpdate ? 'update' : 'create', 'scholarship', $isUpdate ? 'Updated scholarship details.' : 'Created a new scholarship.', [
        'entity_id' => $scholarshipId,
        'entity_name' => $name,
        'details' => [
            'provider' => $provider,
            'application_open_date' => $applicationOpenDate !== '' ? $applicationOpenDate : null,
            'status' => $status,
            'deadline' => $deadline,
            'minimum_gwa' => $minGwa,
            'application_process_label' => $applicationProcessLabel !== '' ? $applicationProcessLabel : null,
            'target_applicant_type' => $targetApplicantType,
            'target_year_level' => $targetYearLevel,
            'required_admission_status' => $requiredAdmissionStatus,
            'target_citizenship' => $targetCitizenship,
            'target_income_bracket' => $targetIncomeBracket,
            'target_special_category' => $targetSpecialCategory,
            'assessment_requirement' => $assessmentRequirement,
            'required_documents' => $requirementsCount,
            'remote_exam_sites' => $remoteExamCount,
            'review_status' => $reviewStatusForSave ?? ($existingReviewStatus !== '' ? $existingReviewStatus : null)
        ]
    ]);

    if ($reviewWorkflowReady && $isProviderActor && (!$isUpdate || in_array($existingReviewStatus, ['pending', 'rejected'], true))) {
        createNotificationsForUsers(
            $pdo,
            getNotificationRecipientIdsByRoles($pdo, ['admin', 'super_admin']),
            'scholarship_pending_review',
            'Scholarship waiting for review',
            'A provider submitted scholarship changes for ' . $name . ' and it is waiting for review.',
            [
                'entity_type' => 'scholarship_review',
                'entity_id' => $scholarshipId,
                'link_url' => 'scholarship_reviews.php'
            ]
        );
    }

    clearScholarshipOldInput();

    if ($reviewWorkflowReady && $isProviderActor && !$isUpdate) {
        $_SESSION['success'] = 'Scholarship submitted for admin review. It will go live after approval.';
    } elseif ($reviewWorkflowReady && $isProviderActor && in_array($existingReviewStatus, ['pending', 'rejected'], true)) {
        $_SESSION['success'] = 'Scholarship changes submitted for admin review.';
    } else {
        $_SESSION['success'] = $isUpdate ? 'Scholarship updated successfully' : 'Scholarship added successfully';
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($imageFromUpload !== null) {
        deleteImage($imageFromUpload);
    }

    redirectScholarshipFormWithErrors($action, $scholarshipId, [
        ($isUpdate ? 'Failed to update scholarship: ' : 'Failed to add scholarship: ') . $e->getMessage()
    ], $_POST);
}

header('Location: ' . normalizeAppUrl('../AdminView/manage_scholarships.php'));
exit();
?>
