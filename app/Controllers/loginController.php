<?php
// Controller/loginController.php
require_once __DIR__ . '/../Config/init.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/security.php';

function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool {
    static $cache = [];
    $key = $tableName . '.' . $columnName;
    if (isset($cache[$key])) {
        return $cache[$key];
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

    $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$key];
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
    $cache[$tableName] = ((int) $stmt->fetchColumn()) > 0;

    return $cache[$tableName];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../View/login.php');
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$loginRateLimitBucket = securityBuildRateLimitBucket('user_login', $email);
$loginRateLimitStatus = securityGetRateLimitStatus($loginRateLimitBucket, 8, 900);

if ($loginRateLimitStatus['blocked']) {
    $_SESSION['error'] = 'Too many login attempts. Please wait ' . (int) $loginRateLimitStatus['retry_after'] . ' seconds before trying again.';
    redirect('../View/login.php');
}

if ($email === '' || $password === '') {
    $_SESSION['error'] = 'Please fill in all fields';
    redirect('../View/login.php');
}

$selectColumns = [
    'u.*',
    'sl.latitude',
    'sl.longitude'
];

$studentDataColumns = ['firstname', 'lastname', 'middleinitial', 'course', 'gwa', 'school', 'age', 'birthdate', 'gender'];
foreach ($studentDataColumns as $column) {
    if (tableHasColumn($pdo, 'student_data', $column)) {
        $selectColumns[] = "sd.{$column}";
    } else {
        $selectColumns[] = "NULL AS {$column}";
    }
}

$optionalStudentDataColumns = [
    'suffix',
    'address',
    'house_no',
    'street',
    'barangay',
    'city',
    'province',
    'mobile_number',
    'citizenship',
    'household_income_bracket',
    'special_category',
    'profile_image_path'
];
foreach ($optionalStudentDataColumns as $column) {
    if (tableHasColumn($pdo, 'student_data', $column)) {
        $selectColumns[] = "sd.{$column}";
    }
}

$hasStaffProfilesTable = tableExists($pdo, 'staff_profiles');
$staffJoin = '';
if ($hasStaffProfilesTable) {
    $staffColumns = [
        'firstname',
        'lastname',
        'middleinitial',
        'suffix',
        'organization_name',
        'organization_type',
        'department',
        'position_title',
        'staff_id_no',
        'office_phone',
        'office_address',
        'city',
        'province',
        'website',
        'responsibility_scope'
    ];

    foreach ($staffColumns as $column) {
        if (tableHasColumn($pdo, 'staff_profiles', $column)) {
            $selectColumns[] = "sp.{$column} AS staff_{$column}";
        }
    }

    $staffJoin = "LEFT JOIN staff_profiles sp ON u.id = sp.user_id";
}

if (tableHasColumn($pdo, 'student_location', 'location_name')) {
    $selectColumns[] = 'sl.location_name';
}

$sql = "
    SELECT " . implode(",\n           ", $selectColumns) . "
    FROM users u
    LEFT JOIN student_data sd ON u.id = sd.student_id
    LEFT JOIN student_location sl ON u.id = sl.student_id
    {$staffJoin}
    WHERE u.email = :email
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':email', $email);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    securityRegisterRateLimitAttempt($loginRateLimitBucket, 900);
    $_SESSION['error'] = 'Invalid email or password';
    redirect('../View/login.php');
}

$accountStatus = strtolower(trim((string) ($user['status'] ?? 'active')));
if ($accountStatus !== 'active') {
    securityClearRateLimit($loginRateLimitBucket);
    $statusMessageMap = [
        'pending' => 'Your account is pending review. Please wait for approval before signing in.',
        'inactive' => 'Your account is inactive. Please contact the support team for assistance.',
        'suspended' => 'Your account has been suspended. Please contact the support team for assistance.'
    ];

    $_SESSION['error'] = $statusMessageMap[$accountStatus] ?? 'Your account is not allowed to sign in at the moment.';
    redirect('../View/login.php');
}

securityClearRateLimit($loginRateLimitBucket);
session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['user_username'] = $user['username'] ?? 'User';
$_SESSION['user_email'] = $user['email'] ?? '';
syncRoleSessionMeta($user['role'] ?? 'student');

$isStaffRole = isRoleIn(['provider', 'admin', 'super_admin']);
$sessionFirstName = $user['firstname'] ?? '';
$sessionLastName = $user['lastname'] ?? '';
$sessionMiddleInitial = $user['middleinitial'] ?? '';
$sessionSuffix = $user['suffix'] ?? '';
$staffProfile = null;

if ($isStaffRole) {
    $staffProfileModel = new StaffAccountProfile($pdo);
    $staffProfile = $staffProfileModel->getByUserId((int) $user['id'], (string) ($user['role'] ?? ''), $user);
    syncStaffPermissionSessionMeta((string) ($user['role'] ?? ''), $staffProfile ?? []);

    if (($user['role'] ?? '') === 'provider') {
        $sessionFirstName = $staffProfile['contact_person_firstname'] ?? '';
        $sessionLastName = $staffProfile['contact_person_lastname'] ?? '';
        $sessionMiddleInitial = '';
        $sessionSuffix = '';
    } else {
        $sessionFirstName = $staffProfile['firstname'] ?? $sessionFirstName;
        $sessionLastName = $staffProfile['lastname'] ?? $sessionLastName;
        $sessionMiddleInitial = $staffProfile['middleinitial'] ?? $sessionMiddleInitial;
        $sessionSuffix = $staffProfile['suffix'] ?? $sessionSuffix;
    }
} else {
    unset($_SESSION['staff_permissions']);
}

$displayName = $user['username'] ?? 'User';
if ($isStaffRole && $staffProfile) {
    $displayName = $staffProfileModel->buildDisplayName((string) ($user['role'] ?? ''), $staffProfile, $user);
} elseif (!empty($sessionFirstName) || !empty($sessionLastName)) {
    $displayName = trim($sessionFirstName . ' ' . $sessionLastName);
    if ($displayName === '') {
        $displayName = $user['username'] ?? 'User';
    }
}
$_SESSION['user_display_name'] = $displayName;

$_SESSION['user_firstname'] = $sessionFirstName;
$_SESSION['user_lastname'] = $sessionLastName;
$_SESSION['user_middleinitial'] = $sessionMiddleInitial;
$_SESSION['user_suffix'] = $sessionSuffix;
$_SESSION['user_school'] = $user['school'] ?? '';
$_SESSION['user_course'] = $user['course'] ?? '';
$_SESSION['user_gwa'] = $user['gwa'] ?? null;
$_SESSION['user_age'] = $user['age'] ?? null;
$_SESSION['user_birthdate'] = $user['birthdate'] ?? null;
$_SESSION['user_gender'] = $user['gender'] ?? '';
$_SESSION['user_address'] = $user['address'] ?? '';
$_SESSION['user_house_no'] = $user['house_no'] ?? '';
$_SESSION['user_street'] = $user['street'] ?? '';
$_SESSION['user_barangay'] = $user['barangay'] ?? '';
$_SESSION['user_city'] = $user['city'] ?? '';
$_SESSION['user_province'] = $user['province'] ?? '';
$_SESSION['user_mobile_number'] = formatPhilippineMobileNumber($user['mobile_number'] ?? '');
$_SESSION['user_citizenship'] = $user['citizenship'] ?? '';
$_SESSION['user_household_income_bracket'] = $user['household_income_bracket'] ?? '';
$_SESSION['user_special_category'] = $user['special_category'] ?? '';
$_SESSION['user_profile_image_path'] = $user['profile_image_path'] ?? '';

$_SESSION['user_latitude'] = $user['latitude'] ?? null;
$_SESSION['user_longitude'] = $user['longitude'] ?? null;
$_SESSION['user_location_name'] = $user['location_name'] ?? '';
$_SESSION['user_created_at'] = $user['created_at'] ?? null;

if ($isStaffRole) {
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_username'] = $user['username'] ?? 'Staff';
    $_SESSION['admin_email'] = $user['email'] ?? '';
    $_SESSION['admin_role'] = getCurrentSessionRole();
    $_SESSION['admin_name'] = $displayName;
}

if (tableHasColumn($pdo, 'users', 'last_login')) {
    try {
        $updateLoginStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateLoginStmt->execute([(int) $user['id']]);
    } catch (Throwable $e) {
        error_log('last_login update error: ' . $e->getMessage());
    }
}

$_SESSION['success'] = 'Login successful! Welcome back!';

try {
    $activityLog = new ActivityLog($pdo);
    $activityLog->log('login', 'authentication', 'User logged in successfully.', [
        'entity_id' => (int) $user['id'],
        'entity_name' => $displayName,
        'target_user_id' => (int) $user['id'],
        'target_name' => $displayName,
        'details' => [
            'email' => $user['email'] ?? '',
            'role' => getCurrentSessionRole()
        ]
    ]);
} catch (Throwable $e) {
    error_log('login activity log error: ' . $e->getMessage());
}

if ($isStaffRole) {
    redirect('../AdminView/admin_dashboard.php');
}
redirect('../View/index.php');
?>
