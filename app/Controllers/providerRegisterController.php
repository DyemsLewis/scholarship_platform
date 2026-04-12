<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';

require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Config/helpers.php';
require_once __DIR__ . '/../Config/signup_verification.php';
require_once __DIR__ . '/../Config/notification_helpers.php';
require_once __DIR__ . '/../Config/password_policy.php';
require_once __DIR__ . '/../Models/User.php';
require_once __DIR__ . '/../Models/StaffAccountProfile.php';

function providerNormalizeInput($value): string
{
    return trim((string) $value);
}

function providerNormalizeNullable($value): ?string
{
    $trimmed = providerNormalizeInput($value);
    return $trimmed === '' ? null : $trimmed;
}

function providerNormalizeOrganizationType($value): string
{
    $normalized = strtolower(providerNormalizeInput($value));
    $aliases = [
        'government' => 'government_agency',
        'government_agency' => 'government_agency',
        'local_government_unit' => 'local_government_unit',
        'state_university' => 'state_university',
        'private_school' => 'private_school',
        'foundation' => 'foundation',
        'nonprofit' => 'nonprofit',
        'corporate' => 'corporate',
        'other' => 'other'
    ];

    return $aliases[$normalized] ?? $normalized;
}

function providerStoreOldInput(array $input): void
{
    $allowedKeys = [
        'username',
        'email',
        'organization_name',
        'organization_type',
        'organization_email',
        'website',
        'description',
        'contact_person_firstname',
        'contact_person_lastname',
        'contact_person_position',
        'phone_number',
        'mobile_number',
        'house_no',
        'street',
        'barangay',
        'city',
        'province',
        'zip_code',
        'latitude',
        'longitude',
        'location_name',
        'agree_terms'
    ];

    $oldInput = [];
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $input) || is_array($input[$key])) {
            continue;
        }

        $oldInput[$key] = trim((string) $input[$key]);
    }

    $_SESSION['provider_signup_old'] = $oldInput;
}

function providerRedirectWithErrors(array $errors, array $oldInput): void
{
    $_SESSION['provider_signup_errors'] = $errors;
    providerStoreOldInput($oldInput);
    header('Location: ' . normalizeAppUrl('../View/provider_signup.php'));
    exit();
}

function providerValidName(string $value): bool
{
    return $value !== '' && (bool) preg_match("/^[A-Za-z\s\-'\.]+$/", $value);
}

function providerValidUsername(string $value): bool
{
    return $value !== '' && (bool) preg_match('/^[A-Za-z0-9._-]{4,30}$/', $value);
}

function providerValidPhone(?string $value, bool $required = true): bool
{
    if ($value === null || trim($value) === '') {
        return !$required;
    }

    $normalized = trim((string) $value);
    if (!preg_match('/^[0-9+\-\s()]{7,60}$/', $normalized)) {
        return false;
    }

    return true;
}

function providerValidText(?string $value, int $maxLength = 180, bool $required = true): bool
{
    if ($value === null || trim($value) === '') {
        return !$required;
    }

    $length = function_exists('mb_strlen') ? mb_strlen(trim((string) $value)) : strlen(trim((string) $value));
    return $length <= $maxLength;
}

function providerGeocodeAddress(string $address): ?array
{
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
                CURLOPT_HTTPHEADER => ['User-Agent: ScholarshipFinder/1.0 (provider registration geocoding)']
            ]);
            $rawResponse = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'header' => "User-Agent: ScholarshipFinder/1.0 (provider registration geocoding)\r\n"
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

function providerSaveVerificationDocument(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Verification document upload failed.');
    }

    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Verification document must be 5 MB or smaller.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Verification document must be a PDF, JPG, or PNG file.');
    }

    $uploadDirectory = dirname(__DIR__, 2) . '/public/uploads/provider_verification';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create the provider verification upload directory.');
    }

    $safeBaseName = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo((string) ($file['name'] ?? 'document'), PATHINFO_FILENAME));
    $safeBaseName = trim((string) $safeBaseName, '-');
    if ($safeBaseName === '') {
        $safeBaseName = 'provider-verification';
    }

    $fileName = $safeBaseName . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $uploadDirectory . '/' . $fileName;

    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to save the verification document.');
    }

    return 'provider_verification/' . $fileName;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . normalizeAppUrl('../View/provider_signup.php'));
    exit();
}

$csrfValidation = csrfValidateRequest('provider_signup');
if (!$csrfValidation['valid']) {
    providerRedirectWithErrors([$csrfValidation['message']], $_POST);
}

$organizationTypes = ['government_agency', 'local_government_unit', 'state_university', 'private_school', 'foundation', 'nonprofit', 'corporate', 'other'];

$username = providerNormalizeInput($_POST['username'] ?? '');
$email = providerNormalizeInput($_POST['email'] ?? '');
$organizationName = providerNormalizeInput($_POST['organization_name'] ?? '');
$organizationType = providerNormalizeOrganizationType($_POST['organization_type'] ?? '');
$organizationEmail = providerNormalizeInput($_POST['organization_email'] ?? '');
$website = providerNormalizeNullable($_POST['website'] ?? '');
$description = providerNormalizeNullable($_POST['description'] ?? '');
$contactFirstName = providerNormalizeInput($_POST['contact_person_firstname'] ?? '');
$contactLastName = providerNormalizeInput($_POST['contact_person_lastname'] ?? '');
$contactPosition = providerNormalizeInput($_POST['contact_person_position'] ?? '');
$phoneNumber = providerNormalizeInput($_POST['phone_number'] ?? '');
$mobileNumber = normalizePhilippineMobileNumber($_POST['mobile_number'] ?? '');
$houseNo = providerNormalizeNullable($_POST['house_no'] ?? '');
$street = providerNormalizeInput($_POST['street'] ?? '');
$barangay = providerNormalizeInput($_POST['barangay'] ?? '');
$city = providerNormalizeInput($_POST['city'] ?? '');
$province = providerNormalizeInput($_POST['province'] ?? '');
$zipCode = providerNormalizeNullable($_POST['zip_code'] ?? '');
$latitude = providerNormalizeNullable($_POST['latitude'] ?? '');
$longitude = providerNormalizeNullable($_POST['longitude'] ?? '');
$locationName = providerNormalizeNullable($_POST['location_name'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$agreeTerms = isset($_POST['agree_terms']);

$addressParts = array_values(array_filter([$houseNo, $street, $barangay, $city, $province], static function ($value) {
    return $value !== null && trim((string) $value) !== '';
}));
$fullAddress = implode(', ', $addressParts);

$errors = [];

if (!providerValidUsername($username)) {
    $errors[] = 'Username must be 4 to 30 characters and may only use letters, numbers, dots, underscores, and hyphens.';
}

if ($email === '') {
    $errors[] = 'Login email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid login email address.';
} elseif (!isSignupEmailVerified($pdo, $email)) {
    $errors[] = 'Please verify your login email before creating a provider account.';
}

if ($organizationName === '' || !providerValidText($organizationName, 180)) {
    $errors[] = 'Organization name is required.';
}

if (!in_array($organizationType, $organizationTypes, true)) {
    $errors[] = 'Please select a valid organization type.';
}

if ($organizationEmail === '') {
    $errors[] = 'Organization email is required.';
} elseif (!filter_var($organizationEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid organization email address.';
}

if ($website !== null && !filter_var($website, FILTER_VALIDATE_URL)) {
    $errors[] = 'Website must be a valid URL.';
}

if (!providerValidName($contactFirstName)) {
    $errors[] = 'Contact person first name is required and must contain valid characters.';
}

if (!providerValidName($contactLastName)) {
    $errors[] = 'Contact person last name is required and must contain valid characters.';
}

if (!providerValidText($contactPosition, 180)) {
    $errors[] = 'Contact person position is required.';
}

if (!providerValidPhone($phoneNumber, true)) {
    $errors[] = 'Phone number is required and must be valid.';
}

if (!isValidPhilippineMobileNumber($_POST['mobile_number'] ?? '', false)) {
    $errors[] = 'Mobile number must be a valid +63 mobile number.';
}

if (!providerValidText($street, 120) || !providerValidText($barangay, 120) || !providerValidText($city, 120) || !providerValidText($province, 120)) {
    $errors[] = 'Complete address is required (Street, Barangay, City, Province).';
}

if ($zipCode !== null && !preg_match('/^[0-9A-Za-z -]{3,12}$/', $zipCode)) {
    $errors[] = 'Zip code must be valid.';
}

if ($description !== null && !providerValidText($description, 2000, false)) {
    $errors[] = 'Organization description is too long.';
}

if ($latitude !== null && $longitude !== null) {
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        $errors[] = 'Location pin coordinates are invalid.';
    } else {
        $latFloat = (float) $latitude;
        $lngFloat = (float) $longitude;
        if ($latFloat < -90 || $latFloat > 90 || $lngFloat < -180 || $lngFloat > 180) {
            $errors[] = 'Location pin coordinates are out of range.';
        } else {
            $latitude = (string) $latFloat;
            $longitude = (string) $lngFloat;
        }
    }
} elseif ($latitude !== null || $longitude !== null) {
    $errors[] = 'Please provide both latitude and longitude, or leave both empty.';
}

if ($password === '') {
    $errors[] = 'Password is required.';
} else {
    $passwordValidation = validateStrongPassword($password, [
        'username' => $username,
        'email' => $email,
        'firstname' => $contactFirstName,
        'lastname' => $contactLastName,
        'name' => $organizationName,
    ]);
    if (!$passwordValidation['valid']) {
        $errors = array_merge($errors, $passwordValidation['errors']);
    }
}

if ($password !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

if (!$agreeTerms) {
    $errors[] = 'You must agree to the provider account review policy.';
}

$userModel = new User($pdo);
if ($username !== '' && $userModel->findOneBy('username', $username)) {
    $errors[] = 'Username already taken.';
}

if ($email !== '' && $userModel->findByEmail($email)) {
    $errors[] = 'That login email is already registered.';
}

$profileModel = new StaffAccountProfile($pdo);

if (empty($errors)) {
    if (($latitude === null || $longitude === null) && $fullAddress !== '') {
        $geocoded = providerGeocodeAddress($fullAddress);
        if ($geocoded !== null) {
            $latitude = (string) $geocoded['latitude'];
            $longitude = (string) $geocoded['longitude'];
            if ($locationName === null || $locationName === '') {
                $locationName = trim((string) ($geocoded['display_name'] ?? ''));
            }
        }
    }

    if ($locationName === null && $fullAddress !== '') {
        $locationName = $fullAddress;
    }
}

if (isset($_FILES['verification_document']) && (($_FILES['verification_document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
    if (!$profileModel->supportsProviderVerificationDocumentUpload()) {
        $errors[] = $profileModel->getProviderVerificationDocumentUploadMessage();
    }
}

$uploadedDocumentPath = null;
try {
    if (empty($errors) && isset($_FILES['verification_document'])) {
        $uploadedDocumentPath = providerSaveVerificationDocument($_FILES['verification_document']);
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if (!empty($errors)) {
    if ($uploadedDocumentPath !== null) {
        $absoluteUploadPath = dirname(__DIR__) . '/public/uploads/' . $uploadedDocumentPath;
        if (is_file($absoluteUploadPath)) {
            @unlink($absoluteUploadPath);
        }
    }

    providerRedirectWithErrors($errors, $_POST);
}

try {
    $pdo->beginTransaction();

    $userResult = $userModel->register([
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'role' => 'provider',
        'status' => 'pending',
        'email_verified_at' => date('Y-m-d H:i:s')
    ]);

    if (empty($userResult['success']) || empty($userResult['user_id'])) {
        throw new RuntimeException($userResult['errors'][0] ?? 'Unable to create the provider account.');
    }

    $profileSaved = $profileModel->saveForUser((int) $userResult['user_id'], 'provider', [
        'organization_name' => $organizationName,
        'contact_person_firstname' => $contactFirstName,
        'contact_person_lastname' => $contactLastName,
        'contact_person_position' => $contactPosition,
        'phone_number' => $phoneNumber,
        'mobile_number' => $mobileNumber,
        'organization_email' => $organizationEmail,
        'website' => $website,
        'organization_type' => $organizationType,
        'address' => $fullAddress,
        'house_no' => $houseNo,
        'street' => $street,
        'barangay' => $barangay,
        'city' => $city,
        'province' => $province,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'location_name' => $locationName,
        'zip_code' => $zipCode,
        'description' => $description,
        'verification_document' => $uploadedDocumentPath,
        'is_verified' => 0,
        'verified_at' => null
    ], [
        'role' => 'provider'
    ]);

    if (!$profileSaved) {
        throw new RuntimeException('Unable to save the provider organization profile.');
    }

    if ($uploadedDocumentPath !== null) {
        $savedProfile = $profileModel->getByUserId((int) $userResult['user_id'], 'provider', [
            'role' => 'provider',
            'email' => $email
        ]);
        $savedVerificationDocument = trim((string) ($savedProfile['verification_document'] ?? ''));
        if ($savedVerificationDocument === '') {
            throw new RuntimeException('The verification document was uploaded but could not be linked to the provider profile.');
        }
    }

    $pdo->commit();
    try {
        createNotificationsForUsers(
            $pdo,
            getNotificationRecipientIdsByRoles($pdo, ['admin', 'super_admin']),
            'provider_pending_review',
            'New provider account pending review',
            'A provider account for ' . $organizationName . ' was submitted and is waiting for activation review.',
            [
                'entity_type' => 'provider_review',
                'entity_id' => (int) $userResult['user_id'],
                'link_url' => 'provider_reviews.php'
            ]
        );
    } catch (Throwable $notificationError) {
        error_log('providerRegisterController notification error: ' . $notificationError->getMessage());
    }

    clearSignupVerification($pdo, $email);
    unset($_SESSION['provider_signup_old'], $_SESSION['provider_signup_errors']);
    $_SESSION['success'] = 'Provider account created successfully. Your account is pending review before login is allowed.';
    header('Location: ' . normalizeAppUrl('../View/login.php'));
    exit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($uploadedDocumentPath !== null) {
        $absoluteUploadPath = dirname(__DIR__) . '/public/uploads/' . $uploadedDocumentPath;
        if (is_file($absoluteUploadPath)) {
            @unlink($absoluteUploadPath);
        }
    }

    providerRedirectWithErrors([$e->getMessage()], $_POST);
}
?>
