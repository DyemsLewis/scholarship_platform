<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/admin_account_options.php';
require_once __DIR__ . '/../app/Config/password_policy.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/StaffAccountProfile.php';

requireRoles(['admin', 'super_admin'], '../AdminView/admin_dashboard.php', 'Only administrators can add staff accounts.');

// Check if user is logged in as admin
$currentUserData = null;
$userId = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);

if ($userId) {
    $userModel = new User($pdo);
    // Use findOneBy to get user by id
    $currentUserData = $userModel->findOneBy('id', $userId);
}

// Only super_admin can add new admin staff accounts
if (!$currentUserData || $currentUserData['role'] !== 'super_admin') {
    $_SESSION['error'] = 'You do not have permission to add new staff accounts.';
    header('Location: manage_users.php');
    exit();
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

function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool {
    static $cache = [];
    $key = $tableName . '.' . $columnName;
    if (array_key_exists($key, $cache)) {
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

function normalizePhoneValue(string $value): string {
    return trim($value);
}

function isValidPhoneValue(string $value): bool {
    return (bool) preg_match('/^[0-9+()\-\s]{7,25}$/', $value);
}

function isValidAdminNameValue(string $value): bool {
    return $value !== '' && (bool) preg_match("/^[A-Za-z\\s\\-'.]+$/", $value);
}

function isValidAdminMiddleInitialValue(string $value): bool {
    if ($value === '') {
        return true;
    }

    return (bool) preg_match('/^[A-Za-z]$/', str_replace('.', '', $value));
}

function getRoleAccessLevel(string $role): int {
    $role = strtolower(trim($role));
    $map = [
        'admin' => 70,
        'super_admin' => 90
    ];

    return $map[$role] ?? 10;
}

function getDefaultAdminPosition(string $role): string {
    return strtolower(trim($role)) === 'super_admin'
        ? 'Super Administrator'
        : 'Scholarship Operations Administrator';
}

function getDefaultAdminDepartment(string $role): string {
    return strtolower(trim($role)) === 'super_admin'
        ? 'Executive Oversight'
        : 'Scholarship Operations';
}

function storeAddAdminOldInput(array $input): void {
    $allowedKeys = [
        'role',
        'firstname',
        'lastname',
        'middleinitial',
        'suffix',
        'phone_number',
        'office_phone',
        'position',
        'position_title',
        'department',
        'username',
        'email'
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

    $_SESSION['add_admin_old'] = $oldInput;
}

function redirectAddAdminWithErrors(array $errors, array $oldInput): void {
    $_SESSION['add_admin_errors'] = array_values($errors);
    storeAddAdminOldInput($oldInput);
    header('Location: addUsers.php');
    exit();
}

function addAdminOldValue(array $oldInput, string $key, string $default = ''): string {
    if (!array_key_exists($key, $oldInput)) {
        return $default;
    }

    return trim((string) $oldInput[$key]);
}

function addAdminSelected(array $oldInput, string $key, string $expectedValue, string $default = ''): string {
    return addAdminOldValue($oldInput, $key, $default) === $expectedValue ? 'selected' : '';
}

function addAdminMiddleInitialDisplayValue(array $oldInput): string {
    $value = addAdminOldValue($oldInput, 'middleinitial');
    if ($value === '') {
        return '';
    }

    $lettersOnly = preg_replace('/[^A-Za-z]/', '', $value) ?? '';
    return strtoupper(substr($lettersOnly, 0, 1));
}

$addAdminOld = isset($_SESSION['add_admin_old']) && is_array($_SESSION['add_admin_old']) ? $_SESSION['add_admin_old'] : [];
$formErrors = isset($_SESSION['add_admin_errors']) && is_array($_SESSION['add_admin_errors']) ? $_SESSION['add_admin_errors'] : [];
unset($_SESSION['add_admin_old'], $_SESSION['add_admin_errors']);

$currentFormRole = strtolower(trim((string) addAdminOldValue($addAdminOld, 'role', 'admin')));
$selectedAdminSuffix = addAdminOldValue($addAdminOld, 'suffix');
$selectedAdminProfile = resolveAdminPositionProfile(
    $currentFormRole,
    trim((string) addAdminOldValue(
        $addAdminOld,
        'position',
        addAdminOldValue($addAdminOld, 'position_title', getDefaultAdminPosition($currentFormRole))
    )),
    getRoleAccessLevel($currentFormRole)
);
$selectedAdminPosition = (string) $selectedAdminProfile['position'];
$selectedAdminAccessLevel = (string) $selectedAdminProfile['access_level'];
$selectedAdminPermissions = [
    'can_manage_users' => !empty($selectedAdminProfile['can_manage_users']),
    'can_manage_scholarships' => !empty($selectedAdminProfile['can_manage_scholarships']),
    'can_review_documents' => !empty($selectedAdminProfile['can_review_documents']),
    'can_view_reports' => !empty($selectedAdminProfile['can_view_reports']),
];
$adminSuffixOptions = getAdminSuffixOptions();
$adminPositionOptions = getAdminPositionOptions();
$adminAccessLevelOptions = getAdminAccessLevelOptions($currentFormRole);
$adminPositionProfiles = getAdminPositionProfiles();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfValidation = csrfValidateRequest('admin_account_management');
    if (!$csrfValidation['valid']) {
        redirectAddAdminWithErrors([$csrfValidation['message']], $_POST);
    }

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = strtolower(trim((string) ($_POST['role'] ?? 'admin')));
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $middleinitial = trim($_POST['middleinitial'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');

    $phoneNumber = normalizePhoneValue($_POST['phone_number'] ?? ($_POST['office_phone'] ?? ''));
    $positionProfile = resolveAdminPositionProfile(
        $role,
        trim($_POST['position'] ?? ($_POST['position_title'] ?? getDefaultAdminPosition($role))),
        getRoleAccessLevel($role)
    );
    $position = (string) $positionProfile['position'];
    $department = trim($_POST['department'] ?? getDefaultAdminDepartment($role));
    $accessLevel = (int) $positionProfile['access_level'];
    $canManageUsers = !empty($positionProfile['can_manage_users']) ? 1 : 0;
    $canManageScholarships = !empty($positionProfile['can_manage_scholarships']) ? 1 : 0;
    $canReviewDocuments = !empty($positionProfile['can_review_documents']) ? 1 : 0;
    $canViewReports = !empty($positionProfile['can_view_reports']) ? 1 : 0;
    
    $errors = [];

    $allowedRoles = ['admin', 'super_admin'];
    if (!in_array($role, $allowedRoles, true)) {
        $errors[] = 'Invalid role selected';
    }
    
    // Validate inputs
    if (empty($username)) {
        $errors[] = 'Username is required';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($password)) {
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

    if ($firstname === '') {
        $errors[] = 'First name is required';
    } elseif (!isValidAdminNameValue($firstname)) {
        $errors[] = 'First name should contain only letters, spaces, hyphens, apostrophes, or periods';
    }

    if ($lastname === '') {
        $errors[] = 'Last name is required';
    } elseif (!isValidAdminNameValue($lastname)) {
        $errors[] = 'Last name should contain only letters, spaces, hyphens, apostrophes, or periods';
    }

    if (!isValidAdminMiddleInitialValue($middleinitial)) {
        $errors[] = 'Middle initial must be a single letter';
    }

    if ($phoneNumber === '') $errors[] = 'Phone number is required';
    if ($position === '') $errors[] = 'Position is required';
    if ($phoneNumber !== '' && !isValidPhoneValue($phoneNumber)) {
        $errors[] = 'Phone number format is invalid';
    }

    // Check if username already exists
    $existingUser = $userModel->findOneBy('username', $username);
    if ($existingUser) {
        $errors[] = 'Username already taken';
    }
    
    // Check if email already exists
    $existingEmail = $userModel->findByEmail($email);
    if ($existingEmail) {
        $errors[] = 'Email already registered';
    }
    
    if (empty($errors)) {
        // Prepare user data
        $userData = [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'role' => $role
        ];

        try {
            $pdo->beginTransaction();
            $result = $userModel->register($userData);
            if (!$result['success']) {
                $pdo->rollBack();
                $errors = $result['errors'] ?? ['Registration failed. Please try again.'];
            } else {
                $user_id = (int) $result['user_id'];
                if (tableHasColumn($pdo, 'users', 'access_level')) {
                    $stmt = $pdo->prepare("UPDATE users SET access_level = ? WHERE id = ?");
                    $stmt->execute([$accessLevel, $user_id]);
                }
                $staffProfileModel = new StaffAccountProfile($pdo);
                $context = [
                    'role' => $role,
                    'email' => $email,
                    'access_level' => $accessLevel,
                    'created_by' => (int) ($currentUserData['id'] ?? 0),
                    'organization_name' => 'Scholarship Finder Administration',
                    'office_address' => null,
                    'city' => null,
                    'province' => null,
                    'department' => $department !== '' ? $department : 'Administration'
                ];

                $profilePayload = [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'middleinitial' => $middleinitial !== '' ? $middleinitial : null,
                    'suffix' => $suffix !== '' ? $suffix : null,
                    'phone_number' => $phoneNumber,
                    'position' => $position,
                    'department' => $department,
                    'access_level' => $accessLevel,
                    'can_manage_users' => $canManageUsers,
                    'can_manage_scholarships' => $canManageScholarships,
                    'can_review_documents' => $canReviewDocuments,
                    'can_view_reports' => $canViewReports,
                    'is_super_admin' => $role === 'super_admin' ? 1 : 0,
                ];

                $staffProfileModel->saveForUser($user_id, $role, $profilePayload, $context);

                $pdo->commit();
                $_SESSION['success'] = ucfirst($role) . ' account created successfully!';
                header('Location: manage_users.php');
                exit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        redirectAddAdminWithErrors($errors, $_POST);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Admin Account - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../public/css/style.css">
    <link rel="stylesheet" href="../AdminPublic/css/add-users.css">
</head>
<body>
    <!-- Header -->
    <?php include 'layouts/admin_header.php'; ?>

    <!-- Add Account Section -->
    <section class="signup-page">
        <div class="container">
            <div class="signup-container">
                <div class="signup-card">
                    <div class="signup-header">
                        <i class="fas fa-user-plus"></i>
                        <h1>Add Admin Account</h1>
                        <p>Create administrator and super administrator accounts</p>
                    </div>

                    <?php if (!empty($formErrors)): ?>
                        <div class="signup-errors">
                            <strong>Please fix the following issues:</strong>
                            <ul>
                                <?php foreach ($formErrors as $formError): ?>
                                    <li><?php echo htmlspecialchars((string) $formError); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Add Account Form -->
                    <form id="addUserForm" method="POST" action="" novalidate>
                        <?php echo csrfInputField('admin_account_management'); ?>
                        <!-- Account Type Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-user-shield"></i>
                                Account Type
                            </h3>
                            
                            <div class="signup-grid">
                                <div class="form-group signup-full-width">
                                    <label for="role">Role *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-shield-alt"></i>
                                        <select id="role" name="role" required>
                                            <option value="admin" <?php echo addAdminSelected($addAdminOld, 'role', 'admin', 'admin'); ?>>Administrator</option>
                                            <option value="super_admin" <?php echo addAdminSelected($addAdminOld, 'role', 'super_admin'); ?>>Super Administrator</option>
                                        </select>
                                    </div>
                                    <small class="hint">
                                        <span class="role-badge admin">Admin</span> - Daily operations and review work
                                        <span class="role-badge super-admin" style="margin-left: 10px;">Super Admin</span> - Full system governance
                                    </small>
                                </div>
                            </div>
                            
                            <div class="info-note">
                                <i class="fas fa-info-circle"></i>
                                <div>
                                    <strong>Note:</strong> Provider organizations register through the public provider signup page.
                                    This form is only for internal admin accounts.
                                </div>
                            </div>
                        </div>
                        
                        <!-- Personal Information Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-user-circle"></i>
                                Role Information
                            </h3>

                            <div id="adminIdentityFields" class="signup-grid">
                                <div class="form-group">
                                    <label for="firstname">First Name *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user"></i>
                                        <input type="text" id="firstname" name="firstname" placeholder="Enter first name" value="<?php echo htmlspecialchars(addAdminOldValue($addAdminOld, 'firstname')); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="lastname">Last Name *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user"></i>
                                        <input type="text" id="lastname" name="lastname" placeholder="Enter last name" value="<?php echo htmlspecialchars(addAdminOldValue($addAdminOld, 'lastname')); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="middleinitial">Middle Initial</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-font"></i>
                                        <input type="text" id="middleinitial" name="middleinitial" placeholder="e.g., D" maxlength="1" value="<?php echo htmlspecialchars(addAdminMiddleInitialDisplayValue($addAdminOld)); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="suffix">Suffix</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-id-badge"></i>
                                        <select id="suffix" name="suffix">
                                            <?php foreach ($adminSuffixOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $selectedAdminSuffix === $value ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Staff Profile Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-briefcase"></i>
                                Profile Details
                            </h3>

                            <div id="adminProfileFields" class="signup-grid">
                                <div class="form-group">
                                    <label for="phone_number_admin">Phone Number *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-phone"></i>
                                        <input type="text" id="phone_number_admin" name="phone_number" placeholder="e.g., +63 917 123 4567" value="<?php echo htmlspecialchars(addAdminOldValue($addAdminOld, 'phone_number', addAdminOldValue($addAdminOld, 'office_phone'))); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="position">Position *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user-shield"></i>
                                        <select id="position" name="position">
                                            <?php foreach ($adminPositionOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $selectedAdminPosition === $value ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <input type="hidden" name="department" value="<?php echo htmlspecialchars(addAdminOldValue($addAdminOld, 'department', getDefaultAdminDepartment($currentFormRole))); ?>">

                                <input type="hidden" id="access_level" name="access_level" value="<?php echo htmlspecialchars($selectedAdminAccessLevel); ?>">

                                <div class="form-group">
                                    <label>Administrative Scope</label>
                                    <div class="readonly-field">
                                        Access is assigned automatically from the selected role and position.
                                    </div>
                                </div>

                                <div class="form-group signup-full-width">
                                    <label>Responsibilities</label>
                                    <div class="admin-permissions-grid">
                                        <label class="permission-option"><input type="checkbox" name="can_manage_users" value="1" <?php echo $selectedAdminPermissions['can_manage_users'] ? 'checked' : ''; ?> disabled> <span>Accounts</span></label>
                                        <label class="permission-option"><input type="checkbox" name="can_manage_scholarships" value="1" <?php echo $selectedAdminPermissions['can_manage_scholarships'] ? 'checked' : ''; ?> disabled> <span>Scholarships</span></label>
                                        <label class="permission-option"><input type="checkbox" name="can_review_documents" value="1" <?php echo $selectedAdminPermissions['can_review_documents'] ? 'checked' : ''; ?> disabled> <span>Reviews</span></label>
                                        <label class="permission-option"><input type="checkbox" name="can_view_reports" value="1" <?php echo $selectedAdminPermissions['can_view_reports'] ? 'checked' : ''; ?> disabled> <span>Reports</span></label>
                                    </div>
                                    <small class="hint">Responsibilities follow the selected position.</small>
                                </div>
                            </div>
                        </div>

                        <!-- Account Information Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-id-card"></i>
                                Account Information
                            </h3>
                            
                            <div class="signup-grid">
                                <div class="form-group">
                                    <label for="username">Username *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-at"></i>
                                        <input type="text" id="username" name="username" placeholder="Choose a username" required value="<?php echo htmlspecialchars(addAdminOldValue($addAdminOld, 'username')); ?>">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="email">Email Address *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-envelope"></i>
                                        <input type="email" id="email" name="email" placeholder="Enter email address" required value="<?php echo htmlspecialchars(addAdminOldValue($addAdminOld, 'email')); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-shield-alt"></i>
                                Security
                            </h3>
                            
                            <div class="signup-grid">
                                <div class="form-group">
                                    <label for="password">Password *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-lock"></i>
                                        <input type="password" id="password" name="password" placeholder="Create a password" minlength="<?php echo passwordPolicyMinLength(); ?>" required>
                                    </div>
                                    <small class="hint"><?php echo htmlspecialchars(passwordPolicyHint()); ?></small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password">Confirm Password *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-lock"></i>
                                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" minlength="<?php echo passwordPolicyMinLength(); ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn1 btn-primary">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>
                        
                        <div class="form-footer">
                            <p><a href="manage_users.php"><i class="fas fa-arrow-left"></i> Back to Accounts</a></p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'layouts/admin_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../public/js/sweetalert.js"></script>
    <script src="../public/js/password-policy.js?v=<?php echo urlencode((string) (@filemtime(__DIR__ . '/../public/js/password-policy.js') ?: time())); ?>"></script>
    <?php if (!empty($formErrors)): ?>
    <div data-swal-errors data-swal-title="Account Creation Error" style="display: none;">
        <?php echo json_encode($formErrors); ?>
    </div>
    <?php endif; ?>
    <script>
        const addUserForm = document.getElementById('addUserForm');
        const firstnameInput = document.getElementById('firstname');
        const lastnameInput = document.getElementById('lastname');
        const middleinitialInput = document.getElementById('middleinitial');
        const phoneNumberInput = document.getElementById('phone_number_admin');
        const usernameInput = document.getElementById('username');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');

        function showAdminValidationError(title, text, field) {
            if (field && typeof field.focus === 'function') {
                field.focus();
            }

            Swal.fire({
                icon: 'error',
                title,
                text,
                confirmButtonColor: '#3085d6'
            });
        }

        function isValidAdminName(value) {
            return /^[A-Za-z\s\-'.]+$/.test(value);
        }

        function isValidAdminEmail(value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
        }

        function isValidAdminPhone(value) {
            return /^[0-9+()\-\s]{7,25}$/.test(value);
        }

        addUserForm.addEventListener('submit', function(e) {
            const firstname = firstnameInput.value.trim();
            const lastname = lastnameInput.value.trim();
            const middleinitial = middleinitialInput.value.trim();
            const phoneNumber = phoneNumberInput.value.trim();
            const username = usernameInput.value.trim();
            const email = emailInput.value.trim();
            const password = passwordInput.value;
            const confirm = confirmPasswordInput.value;
            const role = roleSelect.value;

            if (!firstname) {
                e.preventDefault();
                showAdminValidationError('Missing Information', 'Please enter the first name.', firstnameInput);
                return;
            }

            if (!isValidAdminName(firstname)) {
                e.preventDefault();
                showAdminValidationError('Invalid First Name', 'First name should contain only letters, spaces, hyphens, apostrophes, or periods.', firstnameInput);
                return;
            }

            if (!lastname) {
                e.preventDefault();
                showAdminValidationError('Missing Information', 'Please enter the last name.', lastnameInput);
                return;
            }

            if (!isValidAdminName(lastname)) {
                e.preventDefault();
                showAdminValidationError('Invalid Last Name', 'Last name should contain only letters, spaces, hyphens, apostrophes, or periods.', lastnameInput);
                return;
            }

            if (middleinitial && !/^[A-Za-z]$/.test(middleinitial.replace(/\./g, ''))) {
                e.preventDefault();
                showAdminValidationError('Invalid Middle Initial', 'Middle initial must be a single letter.', middleinitialInput);
                return;
            }

            if (!phoneNumber) {
                e.preventDefault();
                showAdminValidationError('Missing Information', 'Please enter the phone number.', phoneNumberInput);
                return;
            }

            if (!isValidAdminPhone(phoneNumber)) {
                e.preventDefault();
                showAdminValidationError('Invalid Phone Number', 'Phone number should contain only numbers, spaces, plus signs, parentheses, or hyphens.', phoneNumberInput);
                return;
            }

            if (!positionSelect.value) {
                e.preventDefault();
                showAdminValidationError('Missing Information', 'Please select a position.', positionSelect);
                return;
            }

            if (!username) {
                e.preventDefault();
                showAdminValidationError('Missing Information', 'Please enter a username.', usernameInput);
                return;
            }

            if (!email) {
                e.preventDefault();
                showAdminValidationError('Missing Information', 'Please enter the email address.', emailInput);
                return;
            }

            if (!isValidAdminEmail(email)) {
                e.preventDefault();
                showAdminValidationError('Invalid Email', 'Please enter a valid email address.', emailInput);
                return;
            }

            if (!password) {
                e.preventDefault();
                showAdminValidationError('Missing Information', 'Please enter a password.', passwordInput);
                return;
            }

            if (password !== confirm) {
                e.preventDefault();
                showAdminValidationError('Password Mismatch', 'Passwords do not match.', confirmPasswordInput);
                return;
            }

            const passwordValidation = window.PasswordPolicy
                ? window.PasswordPolicy.validate(password, {
                    username: username,
                    email: email,
                    firstname: firstname,
                    lastname: lastname
                })
                : { valid: password.length >= <?php echo passwordPolicyMinLength(); ?>, errors: ['<?php echo addslashes(passwordPolicyHint()); ?>'] };

            if (!passwordValidation.valid) {
                e.preventDefault();
                showAdminValidationError('Weak Password', passwordValidation.errors[0] || '<?php echo addslashes(passwordPolicyHint()); ?>', passwordInput);
                return;
            }

            if (role === 'super_admin') {
                e.preventDefault();
                Swal.fire({
                    title: 'Create Super Administrator?',
                    html: `You are about to create a <strong>Super Administrator</strong> account.<br><br>
                           Super Administrators have full system access including the ability to create, modify, and delete other admin accounts.<br><br>
                           Are you sure you want to proceed?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, create super admin',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        addUserForm.submit();
                    }
                });
            }
        });

        // Role-aware field groups
        const roleSelect = document.getElementById('role');
        const positionSelect = document.getElementById('position');
        const infoNote = document.querySelector('.info-note');
        const accessLevelInput = document.getElementById('access_level');
        const manageUsersCheckbox = document.querySelector('input[name="can_manage_users"]');
        const manageScholarshipsCheckbox = document.querySelector('input[name="can_manage_scholarships"]');
        const reviewDocumentsCheckbox = document.querySelector('input[name="can_review_documents"]');
        const viewReportsCheckbox = document.querySelector('input[name="can_view_reports"]');
        const adminPositionProfiles = <?php echo json_encode($adminPositionProfiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const defaultAdminPosition = <?php echo json_encode(getDefaultAdminPosition('admin')); ?>;

        function setDisabled(element, isDisabled) {
            if (!element) return;
            element.disabled = isDisabled;
        }

        function resolvePositionProfile(role, position) {
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

            const selectedPosition = adminPositionProfiles[position] ? position : defaultAdminPosition;
            const profile = adminPositionProfiles[selectedPosition] || adminPositionProfiles[defaultAdminPosition];

            return {
                position: selectedPosition,
                access_level: Number(profile.access_level || 70),
                can_manage_users: false,
                can_manage_scholarships: Boolean(profile.can_manage_scholarships),
                can_review_documents: Boolean(profile.can_review_documents),
                can_view_reports: Boolean(profile.can_view_reports)
            };
        }

        function updateStaffRoleUi(role) {
            const isSuperAdmin = role === 'super_admin';
            const profile = resolvePositionProfile(role, positionSelect ? positionSelect.value : '');

            if (isSuperAdmin) {
                infoNote.style.backgroundColor = '#fff3cd';
                infoNote.style.color = '#856404';
                infoNote.innerHTML = '<i class="fas fa-exclamation-triangle"></i><div><strong>Super Admin:</strong> Full system governance and full administrative responsibilities.</div>';
            } else {
                infoNote.style.backgroundColor = '#e3f2fd';
                infoNote.style.color = '#1976d2';
                infoNote.innerHTML = '<i class="fas fa-info-circle"></i><div><strong>Admin:</strong> Responsibilities are assigned from the selected position.</div>';
            }

            if (positionSelect) {
                positionSelect.value = profile.position;
                positionSelect.disabled = isSuperAdmin;
            }

            if (accessLevelInput) {
                accessLevelInput.value = String(profile.access_level);
            }

            if (manageUsersCheckbox) manageUsersCheckbox.checked = profile.can_manage_users;
            if (manageScholarshipsCheckbox) manageScholarshipsCheckbox.checked = profile.can_manage_scholarships;
            if (reviewDocumentsCheckbox) reviewDocumentsCheckbox.checked = profile.can_review_documents;
            if (viewReportsCheckbox) viewReportsCheckbox.checked = profile.can_view_reports;

            setDisabled(manageUsersCheckbox, true);
            setDisabled(manageScholarshipsCheckbox, true);
            setDisabled(reviewDocumentsCheckbox, true);
            setDisabled(viewReportsCheckbox, true);

        }

        roleSelect.addEventListener('change', function() {
            updateStaffRoleUi(this.value);
        });
        if (positionSelect) {
            positionSelect.addEventListener('change', function() {
                updateStaffRoleUi(roleSelect.value);
            });
        }

        updateStaffRoleUi(roleSelect.value);
</script>
</body>
</html>
