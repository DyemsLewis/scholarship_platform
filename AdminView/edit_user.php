<?php 
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/admin_account_options.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Models/StaffAccountProfile.php';

requireRoles(['super_admin'], '../AdminView/admin_dashboard.php', 'Only super administrators can edit account records.');
$actorRole = getCurrentSessionRole();

$userIdParam = $_GET['id'] ?? null;
if ($userIdParam === null || $userIdParam === '' || !is_numeric($userIdParam)) {
    header('Location: manage_users.php');
    exit();
}
$user_id = (int) $userIdParam;
requireValidEntityUrlToken('user', $user_id, $_GET['token'] ?? null, 'edit', 'manage_users.php', 'Invalid or expired user access link.');
if ($user_id === (int) ($_SESSION['user_id'] ?? 0)) {
    header('Location: profile.php');
    exit();
}

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

function getUserStatusOptions(PDO $pdo): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $fallback = [
        'active' => 'Active',
        'inactive' => 'Inactive'
    ];

    if (!tableHasColumn($pdo, 'users', 'status')) {
        $cached = $fallback;
        return $cached;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $type = strtolower((string) ($column['Type'] ?? ''));

        if (strpos($type, 'enum(') === 0) {
            preg_match_all("/'([^']+)'/", $type, $matches);
            $values = array_values(array_filter(array_map('trim', $matches[1] ?? [])));
            if (!empty($values)) {
                $cached = [];
                foreach ($values as $value) {
                    $cached[$value] = ucwords(str_replace('_', ' ', $value));
                }
                return $cached;
            }
        }
    } catch (Throwable $e) {
        error_log('edit_user status options error: ' . $e->getMessage());
    }

    $cached = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'pending' => 'Pending',
        'suspended' => 'Suspended'
    ];
    return $cached;
}

// Get user details - determine if user is student or admin
$studentColumns = [
    'school',
    'course',
    'firstname',
    'lastname',
    'middleinitial',
    'suffix',
    'gwa',
    'birthdate',
    'age',
    'gender'
];
$studentSelect = [];
foreach ($studentColumns as $column) {
    if (tableHasColumn($pdo, 'student_data', $column)) {
        $studentSelect[] = "sd.{$column}";
    } else {
        $studentSelect[] = "NULL AS {$column}";
    }
}

$hasStaffProfilesTable = tableExists($pdo, 'staff_profiles');
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
$staffSelect = [];
foreach ($staffColumns as $column) {
    if ($hasStaffProfilesTable && tableHasColumn($pdo, 'staff_profiles', $column)) {
        $staffSelect[] = "sp.{$column} AS staff_{$column}";
    } else {
        $staffSelect[] = "NULL AS staff_{$column}";
    }
}
$staffJoin = $hasStaffProfilesTable ? "LEFT JOIN staff_profiles sp ON u.id = sp.user_id" : "";

$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.username,
        u.email,
        u.role,
        u.status,
        u.created_at,
        " . implode(",\n        ", array_merge($studentSelect, $staffSelect)) . "
    FROM users u 
    LEFT JOIN student_data sd ON u.id = sd.student_id
    {$staffJoin}
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['error'] = 'User not found';
    header('Location: manage_users.php');
    exit();
}

// Prefer dedicated staff profile values for non-student accounts.
if (($user['role'] ?? '') !== 'student') {
    if (trim((string) ($user['firstname'] ?? '')) === '' && trim((string) ($user['staff_firstname'] ?? '')) !== '') {
        $user['firstname'] = $user['staff_firstname'];
    }
    if (trim((string) ($user['lastname'] ?? '')) === '' && trim((string) ($user['staff_lastname'] ?? '')) !== '') {
        $user['lastname'] = $user['staff_lastname'];
    }
    if (trim((string) ($user['middleinitial'] ?? '')) === '' && trim((string) ($user['staff_middleinitial'] ?? '')) !== '') {
        $user['middleinitial'] = $user['staff_middleinitial'];
    }
    foreach (['suffix', 'organization_name', 'organization_type', 'department', 'position_title', 'staff_id_no', 'office_phone', 'office_address', 'city', 'province', 'website', 'responsibility_scope'] as $field) {
        $staffField = 'staff_' . $field;
        if (trim((string) ($user[$field] ?? '')) === '' && trim((string) ($user[$staffField] ?? '')) !== '') {
            $user[$field] = $user[$staffField];
        }
    }

    $staffProfileModel = new StaffAccountProfile($pdo);
    $canonicalProfile = $staffProfileModel->getByUserId((int) $user['id'], (string) ($user['role'] ?? ''), $user) ?? [];

    if (($user['role'] ?? '') === 'provider') {
        $user['contact_person_firstname'] = trim((string) ($canonicalProfile['contact_person_firstname'] ?? $user['firstname'] ?? ''));
        $user['contact_person_lastname'] = trim((string) ($canonicalProfile['contact_person_lastname'] ?? $user['lastname'] ?? ''));
        $user['contact_person_position'] = trim((string) ($canonicalProfile['contact_person_position'] ?? $user['position_title'] ?? ''));
        $user['phone_number'] = trim((string) ($canonicalProfile['phone_number'] ?? $user['office_phone'] ?? ''));
        $user['mobile_number'] = trim((string) ($canonicalProfile['mobile_number'] ?? ''));
        $user['organization_email'] = trim((string) ($canonicalProfile['organization_email'] ?? $user['email'] ?? ''));
        $user['organization_name'] = trim((string) ($canonicalProfile['organization_name'] ?? $user['organization_name'] ?? ''));
        $user['organization_type'] = trim((string) ($canonicalProfile['organization_type'] ?? $user['organization_type'] ?? ''));
        $user['address'] = trim((string) ($canonicalProfile['address'] ?? $user['office_address'] ?? ''));
        $user['house_no'] = trim((string) ($canonicalProfile['house_no'] ?? ''));
        $user['street'] = trim((string) ($canonicalProfile['street'] ?? ''));
        $user['barangay'] = trim((string) ($canonicalProfile['barangay'] ?? ''));
        $user['city'] = trim((string) ($canonicalProfile['city'] ?? $user['city'] ?? ''));
        $user['province'] = trim((string) ($canonicalProfile['province'] ?? $user['province'] ?? ''));
        $user['zip_code'] = trim((string) ($canonicalProfile['zip_code'] ?? ''));
        $user['description'] = trim((string) ($canonicalProfile['description'] ?? $user['responsibility_scope'] ?? ''));

        // Legacy-compatible aliases for existing summary/display logic.
        $user['firstname'] = $user['contact_person_firstname'];
        $user['lastname'] = $user['contact_person_lastname'];
        $user['position_title'] = $user['contact_person_position'];
        $user['office_phone'] = $user['phone_number'];
        $user['office_address'] = $user['address'];
        $user['responsibility_scope'] = $user['description'];
    } else {
        $user['firstname'] = trim((string) ($canonicalProfile['firstname'] ?? $user['firstname'] ?? ''));
        $user['lastname'] = trim((string) ($canonicalProfile['lastname'] ?? $user['lastname'] ?? ''));
        $user['middleinitial'] = trim((string) ($canonicalProfile['middleinitial'] ?? $user['middleinitial'] ?? ''));
        $user['suffix'] = trim((string) ($canonicalProfile['suffix'] ?? $user['suffix'] ?? ''));
        $user['phone_number'] = trim((string) ($canonicalProfile['phone_number'] ?? $user['office_phone'] ?? ''));
        $user['position'] = trim((string) ($canonicalProfile['position'] ?? $user['position_title'] ?? ''));
        $user['department'] = trim((string) ($canonicalProfile['department'] ?? $user['department'] ?? ''));
        $user['access_level'] = (int) ($canonicalProfile['access_level'] ?? ($user['access_level'] ?? ($user['role'] === 'super_admin' ? 90 : 70)));
        $user['can_manage_users'] = (int) ($canonicalProfile['can_manage_users'] ?? ($user['role'] === 'super_admin' ? 1 : 0));
        $user['can_manage_scholarships'] = (int) ($canonicalProfile['can_manage_scholarships'] ?? 1);
        $user['can_review_documents'] = (int) ($canonicalProfile['can_review_documents'] ?? 1);
        $user['can_view_reports'] = (int) ($canonicalProfile['can_view_reports'] ?? ($user['role'] === 'super_admin' ? 1 : 0));
        $user['notes'] = trim((string) ($canonicalProfile['notes'] ?? $user['responsibility_scope'] ?? ''));

        // Legacy-compatible aliases for existing summary/display logic.
        $user['position_title'] = $user['position'];
        $user['office_phone'] = $user['phone_number'];
        $user['responsibility_scope'] = $user['notes'];
    }
}

$editUserOld = isset($_SESSION['edit_user_old']) && is_array($_SESSION['edit_user_old'])
    ? $_SESSION['edit_user_old']
    : [];
if ((int) ($editUserOld['user_id'] ?? 0) !== (int) $user_id) {
    $editUserOld = [];
}
unset($_SESSION['edit_user_old']);

function editUserValue(array $oldInput, array $user, string $key, string $default = ''): string
{
    if (array_key_exists($key, $oldInput)) {
        return trim((string) $oldInput[$key]);
    }

    if (array_key_exists($key, $user) && $user[$key] !== null) {
        return trim((string) $user[$key]);
    }

    return $default;
}

function editUserMiddleInitialValue(array $oldInput, array $user): string
{
    $value = editUserValue($oldInput, $user, 'middleinitial');
    if ($value === '') {
        return '';
    }

    $lettersOnly = preg_replace('/[^A-Za-z]/', '', $value) ?? '';
    return strtoupper(substr($lettersOnly, 0, 1));
}

function editUserCheckboxValue(array $oldInput, array $user, string $key): bool
{
    if (array_key_exists($key, $oldInput)) {
        return in_array(strtolower(trim((string) $oldInput[$key])), ['1', 'true', 'yes', 'on'], true);
    }

    return !empty($user[$key]);
}

// Determine if this is a student or staff
$isStudent = ($user['role'] === 'student');
$isProvider = ($user['role'] === 'provider');
$roleLabel = $user['role'] === 'super_admin' ? 'Super Admin' : ($isProvider ? 'Provider' : ucfirst((string) $user['role']));
$roleIcon = $isStudent ? 'fa-graduation-cap' : ($isProvider ? 'fa-building' : 'fa-user-shield');

$displayName = trim(editUserValue($editUserOld, $user, 'firstname') . ' ' . editUserValue($editUserOld, $user, 'lastname'));
if (editUserValue($editUserOld, $user, 'suffix') !== '') {
    $displayName .= ' ' . editUserValue($editUserOld, $user, 'suffix');
}
if ($displayName === '') {
    $displayName = editUserValue($editUserOld, $user, 'username');
}
if (empty($displayName)) {
    $displayName = $user['username'];
}

$statusValue = editUserValue($editUserOld, $user, 'status', 'active');
$statusOptions = getUserStatusOptions($pdo);
$organizationValue = editUserValue($editUserOld, $user, 'organization_name');
$positionValue = editUserValue($editUserOld, $user, 'position_title');
$organizationTypeValue = editUserValue($editUserOld, $user, 'organization_type');
$scopeValue = editUserValue($editUserOld, $user, 'responsibility_scope');
$adminSuffixOptions = getAdminSuffixOptions();
$adminPositionOptions = getAdminPositionOptions();
$adminDepartmentOptions = getAdminDepartmentOptions();
$selectedAdminPosition = editUserValue(
    $editUserOld,
    $user,
    'position',
    $positionValue !== '' ? $positionValue : getDefaultAdminPositionLabel((string) ($user['role'] ?? 'admin'))
);
$selectedAdminProfile = resolveAdminPositionProfile(
    (string) ($user['role'] ?? 'admin'),
    $selectedAdminPosition,
    (int) ($user['access_level'] ?? ($user['role'] === 'super_admin' ? 90 : 70))
);
$selectedAdminPosition = (string) $selectedAdminProfile['position'];
$adminAccessLevelOptions = getAdminAccessLevelOptions((string) ($user['role'] ?? 'admin'));
$adminAccessLevelValue = (string) $selectedAdminProfile['access_level'];
$adminPermissionState = [
    'can_manage_users' => !empty($selectedAdminProfile['can_manage_users']),
    'can_manage_scholarships' => !empty($selectedAdminProfile['can_manage_scholarships']),
    'can_review_documents' => !empty($selectedAdminProfile['can_review_documents']),
    'can_view_reports' => !empty($selectedAdminProfile['can_view_reports'])
];
$adminPositionProfiles = getAdminPositionProfiles();
$organizationTypeOptions = [
    'internal_administration' => 'Internal Administration',
    'government_agency' => 'Government Agency',
    'local_government_unit' => 'Local Government Unit',
    'state_university' => 'State University / College',
    'private_school' => 'Private School / University',
    'foundation' => 'Foundation',
    'nonprofit' => 'Nonprofit / NGO',
    'corporate' => 'Corporate Sponsor',
    'other' => 'Other'
];
$genderOptions = [
    '' => 'Select gender',
    'male' => 'Male',
    'female' => 'Female',
    'other' => 'Other',
    'prefer_not_to_say' => 'Prefer not to say'
];
$editUsersCssVersion = @filemtime(__DIR__ . '/../AdminPublic/css/edit-users.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Account - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../AdminPublic/css/manage-users.css">
    <link rel="stylesheet" href="../AdminPublic/css/mng-usr.css">
    <link rel="stylesheet" href="../AdminPublic/css/edit-users.css?v=<?php echo urlencode((string) $editUsersCssVersion); ?>">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard edit-user-page">
        <div class="container">
            <div class="page-header">
                <div>
                    <h1>
                        <i class="fas fa-user-pen"></i>
                        Edit Account
                    </h1>
                    <p>Update account information and administrative responsibilities</p>
                </div>
                <a href="manage_users.php" class="btn-add back-link-modern">
                    <i class="fas fa-arrow-left"></i>
                    Back to Accounts
                </a>
            </div>
            
            <!-- Display success/error messages -->
            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['errors']) && is_array($_SESSION['errors'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <ul style="margin: 0.5rem 0 0 1.25rem;">
                    <?php foreach($_SESSION['errors'] as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; unset($_SESSION['errors']); ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- User Edit Form -->
            <div class="form-section">
                    <form method="POST" action="../app/AdminControllers/user_process.php">
                        <input type="hidden" name="action" value="update">
                        <?php echo csrfInputField('admin_account_management'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars(buildEntityUrlToken('user', (int) $user['id'], 'update')); ?>">

                        <div class="edit-user-layout">
                            <div class="edit-user-main">
                                <div class="form-card-modern">
                                    <div class="card-header">
                                        <h3><i class="fas fa-id-card"></i> Account Information</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="edit-user-intro">
                                            <div>
                                                <h2><?php echo htmlspecialchars($displayName); ?></h2>
                                                <p>Review account details and update the information below.</p>
                                            </div>
                                            <div class="user-meta-grid">
                                                <div class="meta-card">
                                                    <span class="meta-label">Username</span>
                                                    <strong><?php echo htmlspecialchars((string) ($user['username'] ?? 'Not set')); ?></strong>
                                                </div>
                                                <div class="meta-card">
                                                    <span class="meta-label">Joined</span>
                                                    <strong><?php echo date('M d, Y', strtotime($user['created_at'])); ?></strong>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if($isStudent): ?>
                                        <!-- Student Form - with firstname, lastname, etc. -->
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="firstname">First Name *</label>
                                                <input type="text" id="firstname" name="firstname" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'firstname')); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="lastname">Last Name *</label>
                                                <input type="text" id="lastname" name="lastname" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'lastname')); ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="middleinitial">Middle Initial</label>
                                            <input type="text" id="middleinitial" name="middleinitial" 
                                                   value="<?php echo htmlspecialchars(editUserMiddleInitialValue($editUserOld, $user)); ?>" 
                                                   maxlength="1" placeholder="e.g., M">
                                        </div>

                                        <div class="form-group">
                                            <label for="suffix">Suffix</label>
                                            <select id="suffix" name="suffix">
                                                <?php foreach ($adminSuffixOptions as $value => $label): ?>
                                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo editUserValue($editUserOld, $user, 'suffix') === $value ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($label); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="username">Username *</label>
                                            <input type="text" id="username" name="username" 
                                                   value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'username')); ?>" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="email">Email Address *</label>
                                            <input type="email" id="email" name="email" 
                                                   value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'email')); ?>" required>
                                        </div>
                                        
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="school">School/University *</label>
                                                <input type="text" id="school" name="school" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'school')); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="course">Course/Program *</label>
                                                <input type="text" id="course" name="course" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'course')); ?>" required>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="gender">Gender</label>
                                            <select id="gender" name="gender">
                                                <?php foreach ($genderOptions as $value => $label): ?>
                                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo editUserValue($editUserOld, $user, 'gender') === $value ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($label); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="gwa">GWA</label>
                                            <input type="number" id="gwa" name="gwa"
                                                   value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'gwa')); ?>"
                                                   min="1.00" max="5.00" step="0.01" placeholder="e.g., 1.75">
                                            <small class="field-note">Use the current verified GWA value. Leave blank if no GWA is available yet.</small>
                                        </div>
                                        
                                        <?php else: ?>
                                        <?php if($isProvider): ?>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="organization_name">Organization Name *</label>
                                                <input type="text" id="organization_name" name="organization_name" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'organization_name')); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="organization_type">Organization Type *</label>
                                                <select id="organization_type" name="organization_type" required>
                                                    <option value="">Select organization type</option>
                                                    <?php foreach ($organizationTypeOptions as $value => $label): ?>
                                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $organizationTypeValue === $value ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($label); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="contact_person_firstname">Contact First Name *</label>
                                                <input type="text" id="contact_person_firstname" name="contact_person_firstname" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'contact_person_firstname', editUserValue($editUserOld, $user, 'firstname'))); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="contact_person_lastname">Contact Last Name *</label>
                                                <input type="text" id="contact_person_lastname" name="contact_person_lastname" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'contact_person_lastname', editUserValue($editUserOld, $user, 'lastname'))); ?>" required>
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="contact_person_position">Contact Position *</label>
                                                <input type="text" id="contact_person_position" name="contact_person_position" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'contact_person_position', editUserValue($editUserOld, $user, 'position_title'))); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="phone_number">Phone Number *</label>
                                                <input type="text" id="phone_number" name="phone_number" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'phone_number', editUserValue($editUserOld, $user, 'office_phone'))); ?>" required>
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="mobile_number">Mobile Number</label>
                                                <input type="text" id="mobile_number" name="mobile_number" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'mobile_number')); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="organization_email">Organization Email *</label>
                                                <input type="email" id="organization_email" name="organization_email" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'organization_email', editUserValue($editUserOld, $user, 'email'))); ?>" required>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="address">Address *</label>
                                            <input type="text" id="address" name="address" 
                                                   value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'address', editUserValue($editUserOld, $user, 'office_address'))); ?>" required>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="house_no">House No.</label>
                                                <input type="text" id="house_no" name="house_no" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'house_no')); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="street">Street</label>
                                                <input type="text" id="street" name="street" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'street')); ?>">
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="barangay">Barangay</label>
                                                <input type="text" id="barangay" name="barangay" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'barangay')); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label for="zip_code">Zip Code</label>
                                                <input type="text" id="zip_code" name="zip_code" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'zip_code')); ?>">
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="city">City / Municipality *</label>
                                                <input type="text" id="city" name="city" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'city')); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="province">Province *</label>
                                                <input type="text" id="province" name="province" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'province')); ?>" required>
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="website">Official Website</label>
                                                <input type="url" id="website" name="website" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'website')); ?>"
                                                       placeholder="https://example.org">
                                            </div>
                                            <div class="form-group">
                                                <label for="staff_id_no">Internal Reference</label>
                                                <input type="text" id="staff_id_no" name="staff_id_no" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'staff_id_no')); ?>">
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="description">Description / Scholarship Coverage</label>
                                            <textarea id="description" name="description" rows="5" placeholder="Describe the organization, scholarship coverage, or supported partner schools"><?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'description', $scopeValue)); ?></textarea>
                                        </div>
                                        <?php else: ?>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="firstname">First Name *</label>
                                                <input type="text" id="firstname" name="firstname" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'firstname')); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="lastname">Last Name *</label>
                                                <input type="text" id="lastname" name="lastname" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'lastname')); ?>" required>
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="middleinitial">Middle Initial</label>
                                                <input type="text" id="middleinitial" name="middleinitial" 
                                                       value="<?php echo htmlspecialchars(editUserMiddleInitialValue($editUserOld, $user)); ?>" 
                                                       maxlength="1" placeholder="e.g., M">
                                            </div>
                                            <div class="form-group">
                                                <label for="suffix">Suffix</label>
                                                <select id="suffix" name="suffix">
                                                    <?php foreach ($adminSuffixOptions as $value => $label): ?>
                                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo editUserValue($editUserOld, $user, 'suffix') === $value ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($label); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <div class="form-group">
                                                <label for="phone_number">Phone Number *</label>
                                                <input type="text" id="phone_number" name="phone_number" 
                                                       value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'phone_number', editUserValue($editUserOld, $user, 'office_phone'))); ?>" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="position">Position *</label>
                                                <select id="position" name="position" required>
                                                    <?php foreach ($adminPositionOptions as $value => $label): ?>
                                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $selectedAdminPosition === $value ? 'selected' : ''; ?> <?php echo $user['role'] === 'super_admin' && $value !== 'Super Administrator' ? 'disabled' : ''; ?>>
                                                        <?php echo htmlspecialchars($label); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-row">
                                            <input type="hidden" id="department" name="department" value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'department', getDefaultAdminDepartmentLabel((string) ($user['role'] ?? 'admin')))); ?>">
                                            <input type="hidden" id="access_level" name="access_level" value="<?php echo htmlspecialchars($adminAccessLevelValue); ?>">
                                            <div class="form-group">
                                                <label>Administrative Scope</label>
                                                <div class="readonly-field">Access is assigned automatically from the selected role and position.</div>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label>Responsibilities</label>
                                            <div class="admin-permissions-grid">
                                                <label class="permission-option"><input type="checkbox" name="can_manage_users" value="1" <?php echo $adminPermissionState['can_manage_users'] ? 'checked' : ''; ?> disabled> <span>Accounts</span></label>
                                                <label class="permission-option"><input type="checkbox" name="can_manage_scholarships" value="1" <?php echo $adminPermissionState['can_manage_scholarships'] ? 'checked' : ''; ?> disabled> <span>Scholarships</span></label>
                                                <label class="permission-option"><input type="checkbox" name="can_review_documents" value="1" <?php echo $adminPermissionState['can_review_documents'] ? 'checked' : ''; ?> disabled> <span>Reviews</span></label>
                                                <label class="permission-option"><input type="checkbox" name="can_view_reports" value="1" <?php echo $adminPermissionState['can_view_reports'] ? 'checked' : ''; ?> disabled> <span>Reports</span></label>
                                            </div>
                                            <small class="field-note">Responsibilities follow the selected position.</small>
                                        </div>

                                        <?php endif; ?>

                                        <div class="form-group">
                                            <label for="username">Username *</label>
                                            <input type="text" id="username" name="username" 
                                                   value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'username')); ?>" required>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="email">Email Address *</label>
                                            <input type="email" id="email" name="email" 
                                                   value="<?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'email')); ?>" required>
                                        </div>

                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="edit-user-sidebar">
                                <div class="form-card-modern">
                                    <div class="card-header">
                                        <h3><i class="fas fa-circle-user"></i> Account Summary</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="summary-avatar"><?php echo htmlspecialchars(strtoupper(substr($displayName, 0, 1))); ?></div>
                                        <div class="summary-name"><?php echo htmlspecialchars($displayName); ?></div>
                                        <div class="summary-email"><?php echo htmlspecialchars(editUserValue($editUserOld, $user, 'email')); ?></div>
                                        <?php if(!$isStudent): ?>
                                        <?php
                                            $summaryPrimary = $isProvider
                                                ? editUserValue($editUserOld, $user, 'organization_name')
                                                : editUserValue($editUserOld, $user, 'position', editUserValue($editUserOld, $user, 'position_title'));
                                            $summarySecondary = $isProvider
                                                ? editUserValue($editUserOld, $user, 'contact_person_position', editUserValue($editUserOld, $user, 'position_title'))
                                                : ($statusOptions[$statusValue] ?? ucwords(str_replace('_', ' ', $statusValue)));
                                            $summaryLocation = trim(editUserValue($editUserOld, $user, 'city') . ', ' . editUserValue($editUserOld, $user, 'province'), ' ,');
                                        ?>
                                        <div class="field-note summary-field-note">
                                            <strong><?php echo $isProvider ? 'Organization' : 'Position'; ?>:</strong> <?php echo htmlspecialchars($summaryPrimary !== '' ? $summaryPrimary : 'Not set'); ?><br>
                                            <strong><?php echo $isProvider ? 'Contact Position' : 'Status'; ?>:</strong> <?php echo htmlspecialchars($summarySecondary !== '' ? $summarySecondary : 'Not set'); ?><br>
                                            <strong><?php echo $isProvider ? 'Location' : 'Username'; ?>:</strong> <?php echo htmlspecialchars($isProvider ? ($summaryLocation !== '' ? $summaryLocation : 'Not set') : editUserValue($editUserOld, $user, 'username')); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="form-card-modern">
                                    <div class="card-header">
                                        <h3><i class="fas fa-toggle-on"></i> Account Status</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group form-group-compact">
                                            <label for="status">Account Status</label>
                                            <?php if ($isProvider): ?>
                                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusValue); ?>">
                                            <div class="status-readonly-panel">
                                                <span class="status-readonly-pill status-<?php echo htmlspecialchars($statusValue); ?>">
                                                    <?php echo htmlspecialchars($statusOptions[$statusValue] ?? ucwords(str_replace('_', ' ', $statusValue))); ?>
                                                </span>
                                                <small class="field-note">Provider activation is handled from <strong>Reviews &gt; Providers</strong>.</small>
                                            </div>
                                            <?php else: ?>
                                            <select id="status" name="status">
                                                <?php foreach ($statusOptions as $optionValue => $optionLabel): ?>
                                                <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo $statusValue === $optionValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($optionLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="field-note">Use <strong>Pending</strong> for accounts awaiting review and <strong>Active</strong> after approval.</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-actions-modern">
                                    <button type="submit" class="btn-modern btn-primary-modern">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                    <a href="manage_users.php" class="btn-modern btn-outline-modern">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
            </div>
        </div>
    </section>

    <?php include 'layouts/admin_footer.php'; ?>
    <?php if (!$isStudent && !$isProvider): ?>
    <script>
        (function() {
            const positionSelect = document.getElementById('position');
            const accessLevelInput = document.getElementById('access_level');
            const checkboxes = {
                can_manage_users: document.querySelector('input[name="can_manage_users"]'),
                can_manage_scholarships: document.querySelector('input[name="can_manage_scholarships"]'),
                can_review_documents: document.querySelector('input[name="can_review_documents"]'),
                can_view_reports: document.querySelector('input[name="can_view_reports"]')
            };
            const adminPositionProfiles = <?php echo json_encode($adminPositionProfiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            const role = <?php echo json_encode((string) ($user['role'] ?? 'admin')); ?>;
            const defaultPosition = <?php echo json_encode(getDefaultAdminPositionLabel((string) ($user['role'] ?? 'admin'))); ?>;

            function resolvePositionProfile(position) {
                if (role === 'super_admin') {
                    return {
                        position: 'Super Administrator',
                        access_level: 90,
                        can_manage_users: true,
                        can_manage_scholarships: true,
                        can_review_documents: true,
                        can_view_reports: true
                    };
                }

                const selectedPosition = adminPositionProfiles[position] ? position : defaultPosition;
                const profile = adminPositionProfiles[selectedPosition] || adminPositionProfiles[defaultPosition];

                return {
                    position: selectedPosition,
                    access_level: Number(profile.access_level || 70),
                    can_manage_users: false,
                    can_manage_scholarships: Boolean(profile.can_manage_scholarships),
                    can_review_documents: Boolean(profile.can_review_documents),
                    can_view_reports: Boolean(profile.can_view_reports)
                };
            }

            function syncPositionResponsibilities() {
                if (!positionSelect || !accessLevelInput) {
                    return;
                }

                const profile = resolvePositionProfile(positionSelect.value);
                positionSelect.value = profile.position;
                accessLevelInput.value = String(profile.access_level);

                Object.keys(checkboxes).forEach((key) => {
                    if (checkboxes[key]) {
                        checkboxes[key].checked = Boolean(profile[key]);
                    }
                });
            }

            if (positionSelect) {
                positionSelect.addEventListener('change', syncPositionResponsibilities);
            }

            syncPositionResponsibilities();
        })();
    </script>
    <?php endif; ?>
</body>
</html>

