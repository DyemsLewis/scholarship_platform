<?php
// Controller/registerController.php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Config/helpers.php';
require_once __DIR__ . '/../Config/signup_verification.php';
require_once __DIR__ . '/../Config/password_policy.php';
require_once __DIR__ . '/../Models/User.php';
require_once __DIR__ . '/../Models/StudentData.php';
require_once __DIR__ . '/../Models/Location.php';
require_once __DIR__ . '/../Models/UserDocument.php';
require_once __DIR__ . '/../Models/OcrService.php';

const SIGNUP_OPTIONAL_DOC_MAX_SIZE = 5242880;
const SIGNUP_OPTIONAL_DOC_ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
const SIGNUP_OPTIONAL_DOC_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

function normalizeInput($value): string {
    return trim((string) $value);
}

function normalizeNullable($value): ?string {
    $trimmed = normalizeInput($value);
    return $trimmed === '' ? null : $trimmed;
}

function normalizeMobileNumber($value): ?string {
    return normalizePhilippineMobileNumber($value);
}

function normalizeChoice($value, array $allowedValues, ?string $default = null): ?string {
    $normalized = strtolower(normalizeInput($value));
    if ($normalized === '') {
        return $default;
    }

    return in_array($normalized, $allowedValues, true) ? $normalized : null;
}

function isCollegeApplicantType(?string $applicantType): bool {
    return in_array((string) $applicantType, ['current_college', 'transferee', 'continuing_student'], true);
}

function storeSignupOldInput(array $input): void {
    $allowedKeys = [
        'firstname',
        'lastname',
        'middleinitial',
        'suffix',
        'name',
        'email',
        'birthdate',
        'age',
        'gender',
        'applicant_type',
        'school',
        'course',
        'course_other',
        'shs_school',
        'shs_strand',
        'shs_graduation_year',
        'admission_status',
        'target_college',
        'target_course',
        'year_level',
        'enrollment_status',
        'academic_standing',
        'mobile_number',
        'citizenship',
        'household_income_bracket',
        'special_category',
        'house_no',
        'street',
        'barangay',
        'city',
        'province',
        'latitude',
        'longitude',
        'location_name',
        'agree_terms'
    ];

    $oldInput = [];
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }

        $value = $input[$key];
        if (is_array($value)) {
            continue;
        }

        $oldInput[$key] = trim((string) $value);
    }

    $_SESSION['signup_old'] = $oldInput;
}

function redirectSignupWithErrors(array $errors, array $oldInput): void {
    $_SESSION['errors'] = $errors;
    storeSignupOldInput($oldInput);
    header('Location: ' . normalizeAppUrl('../View/signup.php'));
    exit();
}

function isValidNameValue(string $value): bool {
    return $value !== '' && (bool) preg_match("/^[A-Za-z\s\-'\.]+$/", $value);
}

function isValidAddressPart(?string $value, int $maxLength = 120): bool {
    if ($value === null) {
        return false;
    }
    $trimmed = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($trimmed) : strlen($trimmed);
    if ($trimmed === '' || $length > $maxLength) {
        return false;
    }
    return true;
}

function geocodeAddress(string $address): ?array {
    $candidates = [trim($address)];
    $addressParts = array_values(array_filter(array_map('trim', explode(',', $address))));
    if (count($addressParts) > 1) {
        $withoutFirstPart = implode(', ', array_slice($addressParts, 1));
        if ($withoutFirstPart !== '' && !in_array($withoutFirstPart, $candidates, true)) {
            $candidates[] = $withoutFirstPart;
        }
    }

    foreach ($candidates as $candidateAddress) {
        $query = http_build_query([
            'q' => $candidateAddress,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 1,
            'countrycodes' => 'ph'
        ]);
        $url = 'https://nominatim.openstreetmap.org/search?' . $query;

        $rawResponse = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['User-Agent: ScholarshipFinder/1.0 (registration geocoding)']
            ]);
            $rawResponse = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'header' => "User-Agent: ScholarshipFinder/1.0 (registration geocoding)\r\n"
                ]
            ]);
            $rawResponse = @file_get_contents($url, false, $context);
        }

        if (!$rawResponse) {
            continue;
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded) || empty($decoded[0]['lat']) || empty($decoded[0]['lon'])) {
            continue;
        }

        return [
            'latitude' => (float) $decoded[0]['lat'],
            'longitude' => (float) $decoded[0]['lon'],
            'display_name' => (string) ($decoded[0]['display_name'] ?? $candidateAddress)
        ];
    }

    return null;
}

function getSignupUploadMimeType(string $tmpPath): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = (string) finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    return $mimeType;
}

function normalizeSignupUploadExtension(string $filename): string {
    return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
}

function buildSignupStoredFilename(string $prefix, string $extension): string {
    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $random = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
    }

    return $prefix . '_' . date('Ymd_His') . '_' . $random . '.' . $extension;
}

function ensureSignupDirectory(string $directory): void {
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}

function validateOptionalSignupUpload(string $fieldName, string $label, array &$errors): ?array {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => $label . ' is too large for the server settings.',
        UPLOAD_ERR_FORM_SIZE => $label . ' is too large.',
        UPLOAD_ERR_PARTIAL => $label . ' was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload folder is missing for ' . $label . '.',
        UPLOAD_ERR_CANT_WRITE => 'Unable to write ' . $label . ' to storage.',
        UPLOAD_ERR_EXTENSION => $label . ' upload was stopped by a PHP extension.'
    ];

    if ($errorCode !== UPLOAD_ERR_OK) {
        $errors[] = $uploadErrors[$errorCode] ?? ($label . ' upload failed with code ' . $errorCode . '.');
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $size = (int) ($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $errors[] = $label . ' upload could not be verified.';
        return null;
    }

    if ($size <= 0) {
        $errors[] = $label . ' appears to be empty.';
        return null;
    }

    if ($size > SIGNUP_OPTIONAL_DOC_MAX_SIZE) {
        $errors[] = $label . ' must be 5MB or smaller.';
        return null;
    }

    $extension = normalizeSignupUploadExtension($originalName);
    $mimeType = getSignupUploadMimeType($tmpName);
    if (!in_array($extension, SIGNUP_OPTIONAL_DOC_ALLOWED_EXT, true) || !in_array($mimeType, SIGNUP_OPTIONAL_DOC_ALLOWED_MIME, true)) {
        $errors[] = $label . ' must be a PDF, JPG, or PNG file.';
        return null;
    }

    return [
        'field_name' => $fieldName,
        'label' => $label,
        'tmp_name' => $tmpName,
        'original_name' => $originalName,
        'size' => $size,
        'extension' => $extension,
        'mime_type' => $mimeType
    ];
}

function signupUploadWasAttempted(string $fieldName): bool {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return false;
    }

    $file = $_FILES[$fieldName];
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $originalName = trim((string) ($file['name'] ?? ''));

    return $errorCode !== UPLOAD_ERR_NO_FILE || $originalName !== '';
}

function ensureSignupDocumentType(PDO $pdo, string $code, string $name, string $description): bool {
    try {
        $check = $pdo->prepare('SELECT code FROM document_types WHERE code = ? LIMIT 1');
        $check->execute([$code]);
        if ($check->fetchColumn()) {
            return true;
        }

        $insert = $pdo->prepare('
            INSERT INTO document_types (code, name, description, max_size, allowed_types)
            VALUES (?, ?, ?, ?, ?)
        ');

        return $insert->execute([
            $code,
            $name,
            $description,
            SIGNUP_OPTIONAL_DOC_MAX_SIZE,
            'pdf,jpg,jpeg,png'
        ]);
    } catch (Throwable $e) {
        error_log('Unable to ensure signup document type "' . $code . '": ' . $e->getMessage());
        return false;
    }
}

function signupStudentDataHasGwaColumn(PDO $pdo): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'student_data'
          AND COLUMN_NAME = 'gwa'
    ");
    $stmt->execute();
    return ((int) $stmt->fetchColumn()) > 0;
}

function signupStudentDataHasShsAverageColumn(PDO $pdo): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'student_data'
          AND COLUMN_NAME = 'shs_average'
    ");
    $stmt->execute();
    return ((int) $stmt->fetchColumn()) > 0;
}

function saveSignupUserGwa(PDO $pdo, int $userId, float $gwa): bool {
    if (!signupStudentDataHasGwaColumn($pdo)) {
        return false;
    }

    $check = $pdo->prepare('SELECT student_id FROM student_data WHERE student_id = ? LIMIT 1');
    $check->execute([$userId]);
    $exists = $check->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $update = $pdo->prepare('UPDATE student_data SET gwa = ? WHERE student_id = ?');
        return $update->execute([$gwa, $userId]);
    }

    $insert = $pdo->prepare('INSERT INTO student_data (student_id, gwa) VALUES (?, ?)');
    return $insert->execute([$userId, $gwa]);
}

function saveSignupUserShsAverage(PDO $pdo, int $userId, float $shsAverage): bool {
    if (!signupStudentDataHasShsAverageColumn($pdo)) {
        return false;
    }

    if ($shsAverage <= 0 || $shsAverage > 100) {
        return false;
    }

    $formattedAverage = number_format($shsAverage, 2, '.', '');
    $check = $pdo->prepare('SELECT student_id FROM student_data WHERE student_id = ? LIMIT 1');
    $check->execute([$userId]);
    $exists = $check->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $update = $pdo->prepare('UPDATE student_data SET shs_average = ? WHERE student_id = ?');
        return $update->execute([$formattedAverage, $userId]);
    }

    $insert = $pdo->prepare('INSERT INTO student_data (student_id, shs_average) VALUES (?, ?)');
    return $insert->execute([$userId, $formattedAverage]);
}

function saveSignupDocumentUpload(
    PDO $pdo,
    UserDocument $documentModel,
    int $userId,
    string $documentType,
    array $upload,
    string $storedPrefix,
    string $documentName,
    string $documentDescription
): array {
    try {
        if (!ensureSignupDocumentType($pdo, $documentType, $documentName, $documentDescription)) {
            return [
                'success' => false,
                'message' => $upload['label'] . ' could not be saved because document storage is not ready.'
            ];
        }

        $docAbsoluteDir = __DIR__ . '/../public/uploads/documents/' . $userId . '/' . $documentType . '/';
        ensureSignupDirectory($docAbsoluteDir);

        $storedFilename = buildSignupStoredFilename($storedPrefix, $upload['extension']);
        $storedAbsolutePath = $docAbsoluteDir . $storedFilename;
        if (!move_uploaded_file($upload['tmp_name'], $storedAbsolutePath)) {
            return [
                'success' => false,
                'message' => $upload['label'] . ' was received but could not be stored.'
            ];
        }

        $relativeFilePath = 'public/uploads/documents/' . $userId . '/' . $documentType . '/' . $storedFilename;
        $saved = $documentModel->uploadDocument($userId, $documentType, [
            'file_name' => $upload['original_name'],
            'file_path' => $relativeFilePath,
            'file_size' => $upload['size'],
            'mime_type' => $upload['mime_type']
        ]);

        if (!$saved) {
            if (file_exists($storedAbsolutePath)) {
                @unlink($storedAbsolutePath);
            }

            return [
                'success' => false,
                'message' => $upload['label'] . ' was uploaded but could not be added to your documents.'
            ];
        }

        return [
            'success' => true,
            'absolute_path' => $storedAbsolutePath,
            'relative_path' => $relativeFilePath
        ];
    } catch (Throwable $e) {
        error_log('Failed saving signup document "' . $documentType . '": ' . $e->getMessage());
        return [
            'success' => false,
            'message' => $upload['label'] . ' could not be saved right now.'
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . normalizeAppUrl('../View/signup.php'));
    exit();
}

$csrfValidation = csrfValidateRequest('student_signup');
if (!$csrfValidation['valid']) {
    redirectSignupWithErrors([$csrfValidation['message']], $_POST);
}

$firstname = normalizeInput($_POST['firstname'] ?? '');
$lastname = normalizeInput($_POST['lastname'] ?? '');
$middleinitial = normalizeInput($_POST['middleinitial'] ?? '');
$suffix = normalizeNullable($_POST['suffix'] ?? '');
$username = normalizeInput($_POST['name'] ?? '');
$email = normalizeInput($_POST['email'] ?? '');
$birthdate = normalizeInput($_POST['birthdate'] ?? '');
$allowedGenders = ['male', 'female', 'other', 'prefer_not_to_say'];
$allowedApplicantTypes = ['incoming_freshman', 'current_college', 'transferee', 'continuing_student'];
$allowedAdmissionStatuses = ['not_yet_applied', 'applied', 'admitted', 'enrolled'];
$allowedYearLevels = ['1st_year', '2nd_year', '3rd_year', '4th_year', '5th_year_plus'];
$allowedEnrollmentStatuses = ['currently_enrolled', 'regular', 'irregular', 'leave_of_absence'];
$allowedAcademicStandings = ['good_standing', 'deans_list', 'probationary', 'graduating'];
$allowedCitizenships = ['filipino', 'dual_citizen', 'permanent_resident', 'other'];
$allowedIncomeBrackets = ['below_10000', '10000_20000', '20001_40000', '40001_80000', 'above_80000', 'prefer_not_to_say'];
$allowedSpecialCategories = ['none', 'pwd', 'indigenous_peoples', 'solo_parent_dependent', 'working_student', 'child_of_ofw', 'four_ps_beneficiary', 'orphan'];

$applicantType = normalizeChoice($_POST['applicant_type'] ?? '', $allowedApplicantTypes);
$gender = normalizeChoice($_POST['gender'] ?? '', $allowedGenders);
$school = normalizeInput($_POST['school'] ?? '');
if ($applicantType === 'incoming_freshman') {
    $school = null;
}

$courseInput = normalizeInput($_POST['course'] ?? '');
$courseOther = normalizeInput($_POST['course_other'] ?? '');
$course = strtolower($courseInput) === 'other' ? $courseOther : $courseInput;
$targetCourse = $course;

$shsSchool = normalizeNullable($_POST['shs_school'] ?? '');
$shsStrand = normalizeNullable($_POST['shs_strand'] ?? '');
$shsGraduationYear = normalizeNullable($_POST['shs_graduation_year'] ?? '');
$shsAverage = null;
$admissionStatus = normalizeChoice($_POST['admission_status'] ?? '', $allowedAdmissionStatuses);
$targetCollege = normalizeNullable($_POST['target_college'] ?? '');
$yearLevel = normalizeChoice($_POST['year_level'] ?? '', $allowedYearLevels);
$enrollmentStatus = normalizeChoice($_POST['enrollment_status'] ?? '', $allowedEnrollmentStatuses);
$academicStanding = normalizeChoice($_POST['academic_standing'] ?? '', $allowedAcademicStandings);
$mobileNumber = normalizeMobileNumber($_POST['mobile_number'] ?? '');
$citizenship = normalizeChoice($_POST['citizenship'] ?? '', $allowedCitizenships);
$householdIncomeBracket = normalizeChoice($_POST['household_income_bracket'] ?? '', $allowedIncomeBrackets);
$specialCategory = normalizeChoice($_POST['special_category'] ?? '', $allowedSpecialCategories);

if ($admissionStatus === null && isCollegeApplicantType($applicantType)) {
    $admissionStatus = 'enrolled';
}

$password = (string) ($_POST['password'] ?? '');
$confirm_password = (string) ($_POST['confirm_password'] ?? '');

$houseNo = normalizeNullable($_POST['house_no'] ?? '');
$street = normalizeNullable($_POST['street'] ?? '');
$barangay = normalizeNullable($_POST['barangay'] ?? '');
$city = normalizeNullable($_POST['city'] ?? '');
$province = normalizeNullable($_POST['province'] ?? '');

$addressParts = array_values(array_filter([$houseNo, $street, $barangay, $city, $province], function ($value) {
    return $value !== null && $value !== '';
}));
$address = !empty($addressParts) ? implode(', ', $addressParts) : '';

$latitude = normalizeNullable($_POST['latitude'] ?? '');
$longitude = normalizeNullable($_POST['longitude'] ?? '');
$location_name = normalizeNullable($_POST['location_name'] ?? '');

$errors = [];
$torUpload = validateOptionalSignupUpload('signup_tor_file', 'TOR / grade report', $errors);
$form138Upload = validateOptionalSignupUpload('signup_form138_file', 'Form 138', $errors);
$citizenshipProofUpload = validateOptionalSignupUpload('signup_citizenship_file', 'Citizenship / residency proof', $errors);
$incomeProofUpload = validateOptionalSignupUpload('signup_income_file', 'Household income proof', $errors);
$specialCategoryProofUpload = validateOptionalSignupUpload('signup_special_category_file', 'Special category proof', $errors);

if ($firstname === '') {
    $errors[] = 'First name is required';
} elseif (!isValidNameValue($firstname)) {
    $errors[] = 'First name contains invalid characters';
}
if ($lastname === '') {
    $errors[] = 'Last name is required';
} elseif (!isValidNameValue($lastname)) {
    $errors[] = 'Last name contains invalid characters';
}
if ($middleinitial !== '' && !preg_match('/^[A-Za-z]$/', $middleinitial)) {
    $errors[] = 'Middle initial must be a single letter';
}
if ($suffix !== null) {
    $allowedSuffixes = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];
    if (!in_array($suffix, $allowedSuffixes, true)) {
        $errors[] = 'Invalid suffix selected';
    }
}
if ($username === '') {
    $errors[] = 'Username is required';
}
if ($email === '') {
    $errors[] = 'Email is required';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Invalid email format';
} elseif (!isSignupEmailVerified($pdo, $email)) {
    $errors[] = 'Please verify your email address before creating an account';
}
if ($birthdate === '') {
    $errors[] = 'Birthdate is required';
}
if ($gender === null) {
    $errors[] = 'Please select your gender';
}
if ($applicantType === null) {
    $errors[] = 'Please select your applicant type';
}
if ($applicantType !== 'incoming_freshman' && ($school === null || $school === '')) {
    $errors[] = 'School information is required';
}
if ($course === '') {
    $errors[] = 'Course/Program is required';
}
if (strtolower($courseInput) === 'other' && $courseOther === '') {
    $errors[] = 'Please specify your course/program';
}
if ($applicantType === 'incoming_freshman') {
    if ($shsSchool === null) {
        $errors[] = 'Senior high school name is required for incoming freshmen';
    }
    if ($shsStrand === null) {
        $errors[] = 'Senior high school strand is required for incoming freshmen';
    }
    if ($shsGraduationYear === null) {
        $errors[] = 'Senior high school graduation year is required for incoming freshmen';
    }
    if ($admissionStatus === null) {
        $errors[] = 'Admission status is required for incoming freshmen';
    }
    if ($targetCollege === null) {
        $errors[] = 'Preferred college or university is required for incoming freshmen';
    }
} elseif ($applicantType !== null) {
    if ($yearLevel === null) {
        $errors[] = 'Year level is required for current college applicants';
    }
    if ($enrollmentStatus === null) {
        $errors[] = 'Enrollment status is required for current college applicants';
    }
}
if ($password === '') {
    $errors[] = 'Password is required';
}
if ($password !== $confirm_password) {
    $errors[] = 'Passwords do not match';
}
if ($password !== '') {
    $passwordValidation = validateStrongPassword($password, [
        'username' => $username,
        'email' => $email,
        'firstname' => $firstname,
        'lastname' => $lastname,
    ]);
    if (!$passwordValidation['valid']) {
        $errors = array_merge($errors, $passwordValidation['errors']);
    }
}
if (!isValidAddressPart($houseNo, 80) || !isValidAddressPart($street, 120) || !isValidAddressPart($barangay, 120) || !isValidAddressPart($city, 120) || !isValidAddressPart($province, 120)) {
    $errors[] = 'Complete address is required (House #, Street, Barangay, City, Province)';
}
if ($mobileNumber !== null) {
    if (!preg_match('/^\+639\d{9}$/', $mobileNumber)) {
        $errors[] = 'Mobile number must be a valid +63 mobile number';
    }
}
if (normalizeInput($_POST['citizenship'] ?? '') !== '' && $citizenship === null) {
    $errors[] = 'Invalid citizenship selected';
}
if (normalizeInput($_POST['household_income_bracket'] ?? '') !== '' && $householdIncomeBracket === null) {
    $errors[] = 'Invalid household income bracket selected';
}
if (normalizeInput($_POST['special_category'] ?? '') !== '' && $specialCategory === null) {
    $errors[] = 'Invalid scholarship category selected';
}
if ($citizenship !== null && $citizenshipProofUpload === null && !signupUploadWasAttempted('signup_citizenship_file')) {
    $errors[] = 'Citizenship / residency proof is required when citizenship is selected';
}
if ($householdIncomeBracket !== null && $householdIncomeBracket !== 'prefer_not_to_say' && $incomeProofUpload === null && !signupUploadWasAttempted('signup_income_file')) {
    $errors[] = 'Household income proof is required when an income bracket is selected';
}
if ($specialCategory !== null && $specialCategory !== 'none' && $specialCategoryProofUpload === null && !signupUploadWasAttempted('signup_special_category_file')) {
    $errors[] = 'Special category proof is required when a scholarship category is selected';
}
foreach ([
    'school' => $school,
    'course/program' => $course,
    'senior high school' => $shsSchool,
    'senior high school strand' => $shsStrand,
    'preferred college' => $targetCollege,
    'target course' => $targetCourse
 ] as $label => $value) {
    if ($value !== null && $value !== '') {
        $length = function_exists('mb_strlen') ? mb_strlen((string) $value) : strlen((string) $value);
        if ($length > 150) {
            $errors[] = ucfirst($label) . ' is too long';
        }
    }
}

if ($shsGraduationYear !== null) {
    if (!ctype_digit($shsGraduationYear)) {
        $errors[] = 'Senior high school graduation year must be a valid year';
    } else {
        $graduationYearValue = (int) $shsGraduationYear;
        $currentYear = (int) date('Y') + 6;
        if ($graduationYearValue < 2000 || $graduationYearValue > $currentYear) {
            $errors[] = 'Senior high school graduation year is out of range';
        }
    }
}

if ($shsAverage !== null) {
    if (!is_numeric($shsAverage)) {
        $errors[] = 'Senior high school average must be a valid number';
    } else {
        $shsAverageValue = (float) $shsAverage;
        if ($shsAverageValue < 60 || $shsAverageValue > 100) {
            $errors[] = 'Senior high school average must be between 60 and 100';
        } else {
            $shsAverage = number_format($shsAverageValue, 2, '.', '');
        }
    }
}

$age = 0;
if ($birthdate !== '') {
    try {
        $birthDateObj = new DateTime($birthdate);
        $today = new DateTime();
        $age = $today->diff($birthDateObj)->y;
        if ($birthDateObj > $today) {
            $errors[] = 'Birthdate cannot be in the future';
        }
        if ($age < 15) {
            $errors[] = 'You must be at least 15 years old to register';
        }
        if ($age > 100) {
            $errors[] = 'Please enter a valid birthdate';
        }
    } catch (Exception $e) {
        $errors[] = 'Invalid birthdate format';
    }
}

if ($latitude !== null && $longitude !== null) {
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        $errors[] = 'Invalid coordinate format';
    } else {
        $latFloat = (float) $latitude;
        $lngFloat = (float) $longitude;
        if ($latFloat < -90 || $latFloat > 90 || $lngFloat < -180 || $lngFloat > 180) {
            $errors[] = 'Coordinates are out of range';
        }
        $latitude = (string) $latFloat;
        $longitude = (string) $lngFloat;
    }
} elseif ($latitude !== null || $longitude !== null) {
    $errors[] = 'Please provide both latitude and longitude, or leave both empty';
}

if (!empty($errors)) {
    redirectSignupWithErrors($errors, $_POST);
}

$userModel = new User($pdo);
$existingUser = $userModel->findOneBy('username', $username);
if ($existingUser) {
    $errors[] = 'Username already taken';
}
$existingEmail = $userModel->findByEmail($email);
if ($existingEmail) {
    $errors[] = 'Email already registered';
}

if (!empty($errors)) {
    redirectSignupWithErrors($errors, $_POST);
}

if (($latitude === null || $longitude === null) && $address !== '') {
    $geocoded = geocodeAddress($address);
    if ($geocoded) {
        $latitude = (string) $geocoded['latitude'];
        $longitude = (string) $geocoded['longitude'];
        if ($location_name === null) {
            $location_name = $geocoded['display_name'];
        }
    }
}

if ($location_name === null && $address !== '') {
    $location_name = $address;
}

$userData = [
    'username' => $username,
    'email' => $email,
    'password' => $password,
    'role' => 'student',
    'status' => 'active',
    'created_at' => date('Y-m-d H:i:s')
];

try {
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    $result = $userModel->register($userData);
    if (!$result['success']) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirectSignupWithErrors($result['errors'] ?? ['Registration failed. Please try again.'], $_POST);
    }

    $user_id = (int) $result['user_id'];

    $studentDataModel = new StudentData($pdo);
    $studentData = [
        'firstname' => $firstname,
        'lastname' => $lastname,
        'middleinitial' => $middleinitial !== '' ? strtoupper($middleinitial) : '',
        'suffix' => $suffix,
        'birthdate' => $birthdate,
        'age' => $age,
        'gender' => $gender,
        'applicant_type' => $applicantType,
        'course' => $course,
        'school' => $school,
        'shs_school' => $shsSchool,
        'shs_strand' => $shsStrand,
        'shs_graduation_year' => $shsGraduationYear,
        'admission_status' => $admissionStatus,
        'target_college' => $targetCollege,
        'target_course' => $targetCourse,
        'year_level' => $yearLevel,
        'enrollment_status' => $enrollmentStatus,
    'academic_standing' => $academicStanding,
    'mobile_number' => $mobileNumber,
    'citizenship' => $citizenship,
    'household_income_bracket' => $householdIncomeBracket,
    'special_category' => $specialCategory,
    'address' => $address,
        'house_no' => $houseNo,
        'street' => $street,
        'barangay' => $barangay,
        'city' => $city,
        'province' => $province
    ];

    if (!$studentDataModel->saveStudentData($user_id, $studentData)) {
        throw new RuntimeException('Failed to save student details.');
    }

    $latToSave = null;
    $lngToSave = null;
    if ($latitude !== null && $longitude !== null) {
        $latToSave = (float) $latitude;
        $lngToSave = (float) $longitude;
    } elseif ($address !== '') {
        // Keep a fallback row for schemas where coordinates are NOT NULL.
        $latToSave = 0.0;
        $lngToSave = 0.0;
    }

    if ($latToSave !== null && $lngToSave !== null) {
        $locationModel = new Location($pdo);
        if (!$locationModel->saveLocation($user_id, $latToSave, $lngToSave, $location_name)) {
            throw new RuntimeException('Failed to save location details.');
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Registration failed: ' . $e->getMessage());
    redirectSignupWithErrors(['Registration failed. Please try again.'], $_POST);
}

$signupUploadWarnings = [];

ensureSignupDocumentType($pdo, 'grades', 'Transcript of Records', 'Official transcript of records or grade slip.');
ensureSignupDocumentType($pdo, 'form_138', 'Form 138', 'Senior high school report card / Form 138.');
ensureSignupDocumentType($pdo, 'citizenship_proof', 'Citizenship / Residency Proof', 'Birth certificate, passport, or residency document supporting citizenship or residency details.');
ensureSignupDocumentType($pdo, 'income_proof', 'Household Income Proof', 'Income certificate, certificate of indigency, payslip, ITR, or similar household income supporting document.');
ensureSignupDocumentType($pdo, 'special_category_proof', 'Special Category Proof', 'Supporting document for PWD, solo parent, IP, 4Ps, OFW, orphan, or other scholarship category claims.');

if (!empty($torUpload) || !empty($form138Upload) || !empty($citizenshipProofUpload) || !empty($incomeProofUpload) || !empty($specialCategoryProofUpload)) {
    $documentModel = new UserDocument($pdo);

    if (!empty($torUpload)) {
        $torSaveResult = saveSignupDocumentUpload(
            $pdo,
            $documentModel,
            $user_id,
            'grades',
            $torUpload,
            'tor',
            'Transcript of Records',
            'Official transcript of records or grade slip uploaded during signup.'
        );

        if (!($torSaveResult['success'] ?? false)) {
            $signupUploadWarnings[] = (string) ($torSaveResult['message'] ?? 'TOR could not be saved right now.');
        } else {
            try {
                $ocrService = new OcrService();
                $ocrResult = $ocrService->processDocument(
                    (string) $torSaveResult['absolute_path'],
                    (string) $torUpload['mime_type'],
                    (string) $torUpload['original_name']
                );

                if (($ocrResult['success'] ?? false) && isset($ocrResult['final_gwa']) && is_numeric($ocrResult['final_gwa'])) {
                    $finalGwa = (float) $ocrResult['final_gwa'];
                    if (saveSignupUserGwa($pdo, $user_id, $finalGwa)) {
                        $signupUploadWarnings[] = 'Detected GWA: ' . number_format($finalGwa, 2) . '.';
                    } else {
                        $signupUploadWarnings[] = 'The uploaded TOR was scanned, but the detected GWA could not be saved yet.';
                    }
                } elseif ($ocrResult['success'] ?? false) {
                    $signupUploadWarnings[] = 'No GWA was detected from the uploaded TOR. You can upload a clearer copy later.';
                } else {
                    $signupUploadWarnings[] = 'OCR could not be completed for the uploaded TOR right now.';
                }
            } catch (Throwable $e) {
                error_log('Signup TOR OCR failed: ' . $e->getMessage());
                $signupUploadWarnings[] = 'OCR could not be completed for the uploaded TOR right now.';
            }
        }
    }

    if (!empty($form138Upload)) {
        $form138SaveResult = saveSignupDocumentUpload(
            $pdo,
            $documentModel,
            $user_id,
            'form_138',
            $form138Upload,
            'form138',
            'Form 138',
            'Senior high school report card / Form 138 uploaded during signup.'
        );

        if (!($form138SaveResult['success'] ?? false)) {
            $signupUploadWarnings[] = (string) ($form138SaveResult['message'] ?? 'Form 138 could not be saved right now.');
        } elseif (!empty($form138SaveResult['absolute_path'])) {
            try {
                $ocrService = new OcrService();
                $ocrResult = $ocrService->processDocument(
                    (string) $form138SaveResult['absolute_path'],
                    (string) $form138Upload['mime_type'],
                    (string) $form138Upload['original_name']
                );

                if (($ocrResult['success'] ?? false) && isset($ocrResult['final_gwa']) && is_numeric($ocrResult['final_gwa'])) {
                    $finalGwa = (float) $ocrResult['final_gwa'];
                    $rawAcademicValue = isset($ocrResult['raw_gwa']) && is_numeric($ocrResult['raw_gwa'])
                        ? (float) $ocrResult['raw_gwa']
                        : $finalGwa;

                    if (saveSignupUserGwa($pdo, $user_id, $finalGwa)) {
                        if ($rawAcademicValue > 0 && $rawAcademicValue <= 100) {
                            saveSignupUserShsAverage($pdo, $user_id, $rawAcademicValue);
                        }

                        if ($rawAcademicValue >= 60) {
                            $signupUploadWarnings[] = 'Detected SHS average: ' . number_format($rawAcademicValue, 2) . ' (normalized to ' . number_format($finalGwa, 2) . ' for scholarship eligibility).';
                        } else {
                            $signupUploadWarnings[] = 'Detected academic score from Form 138: ' . number_format($finalGwa, 2) . '.';
                        }
                    } else {
                        $signupUploadWarnings[] = 'The uploaded Form 138 was scanned, but the detected academic score could not be saved yet.';
                    }
                } elseif ($ocrResult['success'] ?? false) {
                    $signupUploadWarnings[] = 'No academic score was detected from the uploaded Form 138. You can upload a clearer copy later.';
                } else {
                    $signupUploadWarnings[] = 'Scanner could not be completed for the uploaded Form 138 right now.';
                }
            } catch (Throwable $e) {
                error_log('Signup Form 138 scan failed: ' . $e->getMessage());
                $signupUploadWarnings[] = 'Scanner could not be completed for the uploaded Form 138 right now.';
            }
        }
    }

    if (!empty($citizenshipProofUpload)) {
        $citizenshipProofSaveResult = saveSignupDocumentUpload(
            $pdo,
            $documentModel,
            $user_id,
            'citizenship_proof',
            $citizenshipProofUpload,
            'citizenship',
            'Citizenship / Residency Proof',
            'Citizenship or residency supporting document uploaded during signup.'
        );

        if (!($citizenshipProofSaveResult['success'] ?? false)) {
            $signupUploadWarnings[] = (string) ($citizenshipProofSaveResult['message'] ?? 'Citizenship proof could not be saved right now.');
        }
    }

    if (!empty($incomeProofUpload)) {
        $incomeProofSaveResult = saveSignupDocumentUpload(
            $pdo,
            $documentModel,
            $user_id,
            'income_proof',
            $incomeProofUpload,
            'income',
            'Household Income Proof',
            'Household income supporting document uploaded during signup.'
        );

        if (!($incomeProofSaveResult['success'] ?? false)) {
            $signupUploadWarnings[] = (string) ($incomeProofSaveResult['message'] ?? 'Household income proof could not be saved right now.');
        }
    }

    if (!empty($specialCategoryProofUpload)) {
        $specialCategoryProofSaveResult = saveSignupDocumentUpload(
            $pdo,
            $documentModel,
            $user_id,
            'special_category_proof',
            $specialCategoryProofUpload,
            'special_category',
            'Special Category Proof',
            'Special scholarship category supporting document uploaded during signup.'
        );

        if (!($specialCategoryProofSaveResult['success'] ?? false)) {
            $signupUploadWarnings[] = (string) ($specialCategoryProofSaveResult['message'] ?? 'Special category proof could not be saved right now.');
        }
    }
}

clearSignupVerification($pdo, $email);
clearSignupVerificationSession($email);
unset($_SESSION['signup_old']);

$successMessageParts = ['Registration successful! Welcome to Scholarship Finder! You can now login.'];
if (!empty($signupUploadWarnings)) {
    $successMessageParts[] = implode(' ', $signupUploadWarnings);
}

$_SESSION['success'] = trim(implode(' ', $successMessageParts));
header('Location: ' . normalizeAppUrl('../View/login.php'));
exit();
?>
