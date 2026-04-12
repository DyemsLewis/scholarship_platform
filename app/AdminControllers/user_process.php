<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Config/url_token.php';
require_once __DIR__ . '/../Config/admin_account_options.php';
require_once __DIR__ . '/../Config/SmtpMailer.php';
require_once __DIR__ . '/../Models/ActivityLog.php';
require_once __DIR__ . '/../Models/StaffAccountProfile.php';

requireRoles(['admin', 'super_admin'], '../View/index.php', 'Only administrators can manage account-related actions.');

$actorRole = getCurrentSessionRole();
$mailConfig = require __DIR__ . '/../Config/mail_config.php';

function tableExists(PDO $pdo, string $tableName): bool {
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$tableName]);
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
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    $cache[$key] = ((int) $stmt->fetchColumn()) > 0;

    return $cache[$key];
}

function getSupportedUserStatuses(PDO $pdo): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $fallback = ['active', 'inactive'];
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
            $statuses = array_values(array_filter(array_map('trim', $matches[1] ?? [])));
            $cached = !empty($statuses) ? $statuses : $fallback;
            return $cached;
        }
    } catch (Throwable $e) {
        error_log('getSupportedUserStatuses error: ' . $e->getMessage());
    }

    $cached = ['active', 'inactive', 'pending', 'suspended'];
    return $cached;
}

function getTargetUserRole(PDO $pdo, int $userId): ?string {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return normalizeUserRole($row['role'] ?? 'student');
}

function getTargetUserSnapshot(PDO $pdo, int $userId): ?array {
    $hasStaffProfilesTable = tableExists($pdo, 'staff_profiles');
    $staffJoin = $hasStaffProfilesTable ? "LEFT JOIN staff_profiles sp ON u.id = sp.user_id" : "";
    $nameExpression = $hasStaffProfilesTable
        ? "CONCAT(COALESCE(sd.firstname, sp.firstname, ''), ' ', COALESCE(sd.lastname, sp.lastname, ''))"
        : "CONCAT(COALESCE(sd.firstname, ''), ' ', COALESCE(sd.lastname, ''))";

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.username,
            u.email,
            u.status,
            u.role,
            {$nameExpression} AS full_name
        FROM users u
        LEFT JOIN student_data sd ON u.id = sd.student_id
        {$staffJoin}
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $displayName = trim((string) ($row['full_name'] ?? ''));
    if ($displayName === '') {
        $displayName = (string) ($row['username'] ?? 'User');
    }

    $role = normalizeUserRole($row['role'] ?? 'student');
    if (in_array($role, ['provider', 'admin', 'super_admin'], true)) {
        $staffProfileModel = new StaffAccountProfile($pdo);
        $profile = $staffProfileModel->getByUserId($userId, $role, $row);
        if ($profile) {
            $displayName = $staffProfileModel->buildDisplayName($role, $profile, $row);
        }
    }

    $row['display_name'] = $displayName;
    return $row;
}

function canActorManageRole(string $actorRole, string $targetRole): bool {
    return $actorRole === 'super_admin';
}

function canActorReviewProviderAccount(string $actorRole, string $targetRole): bool {
    return in_array($actorRole, ['admin', 'super_admin'], true) && $targetRole === 'provider';
}

function denyUserManagementAction(string $message): void {
    $_SESSION['error'] = $message;
    $redirectTarget = strtolower(trim((string) ($_POST['redirect_target'] ?? $_GET['redirect_target'] ?? '')));
    if (in_array($redirectTarget, ['provider_review', 'provider_reviews'], true)) {
        header('Location: ' . normalizeAppUrl('../AdminView/provider_reviews.php'));
        exit();
    }
    header('Location: ' . normalizeAppUrl('../AdminView/manage_users.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    denyUserManagementAction('Invalid request method.');
}

$csrfValidation = csrfValidateRequest('admin_account_management');
if (!$csrfValidation['valid']) {
    denyUserManagementAction($csrfValidation['message']);
}

function requireUserActionToken(int $userId, string $intent): void {
    $token = $_POST['entity_token'] ?? $_GET['token'] ?? null;
    if (!isValidEntityUrlToken('user', $userId, $token, $intent)) {
        denyUserManagementAction('Invalid or expired access token.');
    }
}

function resolveUserManagementRedirect(int $userId, ?string $targetRole = null): string {
    $redirectTarget = strtolower(trim((string) ($_POST['redirect_target'] ?? $_GET['redirect_target'] ?? '')));

    if ($targetRole === 'provider') {
        if ($redirectTarget === 'provider_review') {
            return buildEntityUrl('../AdminView/view_provider.php', 'user', $userId, 'view', ['id' => $userId]);
        }

        if ($redirectTarget === 'provider_reviews') {
            return '../AdminView/provider_reviews.php';
        }
    }

    return '../AdminView/manage_users.php';
}

function staffProfilesHasColumn(PDO $pdo, string $columnName): bool {
    if (!tableExists($pdo, 'staff_profiles')) {
        return false;
    }

    static $cache = [];
    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'staff_profiles'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$columnName]);
    $cache[$columnName] = ((int) $stmt->fetchColumn()) > 0;

    return $cache[$columnName];
}

function syncProviderVerificationStatus(PDO $pdo, int $userId, string $status): void {
    if (!tableExists($pdo, 'provider_data') || !tableHasColumn($pdo, 'provider_data', 'user_id')) {
        return;
    }

    $hasVerifiedColumn = tableHasColumn($pdo, 'provider_data', 'is_verified');
    $hasVerifiedAtColumn = tableHasColumn($pdo, 'provider_data', 'verified_at');

    if (!$hasVerifiedColumn && !$hasVerifiedAtColumn) {
        return;
    }

    $updateParts = [];
    $params = [];

    if ($status === 'active') {
        if ($hasVerifiedColumn) {
            $updateParts[] = 'is_verified = 1';
        }
        if ($hasVerifiedAtColumn) {
            $updateParts[] = 'verified_at = NOW()';
        }
    } else {
        if ($hasVerifiedColumn) {
            $updateParts[] = 'is_verified = 0';
        }
    }

    if (empty($updateParts)) {
        return;
    }

    $params[] = $userId;
    $stmt = $pdo->prepare("
        UPDATE provider_data
        SET " . implode(', ', $updateParts) . "
        WHERE user_id = ?
    ");
    $stmt->execute($params);
}

function shouldSendProviderActivationEmail(?array $targetUser, string $newStatus): bool {
    if (!$targetUser) {
        return false;
    }

    $previousStatus = strtolower(trim((string) ($targetUser['status'] ?? '')));
    return strtolower(trim($newStatus)) === 'active' && $previousStatus !== 'active';
}

function buildProviderActivationEmail(array $providerDetails): array {
    $providerName = trim((string) ($providerDetails['display_name'] ?? ''));
    if ($providerName === '') {
        $providerName = trim((string) ($providerDetails['username'] ?? ''));
    }
    if ($providerName === '') {
        $providerName = 'Provider';
    }

    $subject = 'Scholarship Finder Provider Account Activated';
    $body = "Hello {$providerName},\n\n"
        . "Your provider account has been approved and activated in Scholarship Finder.\n\n"
        . "You can now sign in and access provider features, including scholarship posting, application review, and provider account management.\n\n"
        . "What you can do next:\n"
        . "1. Log in to your provider account.\n"
        . "2. Review your organization profile and contact details.\n"
        . "3. Post or manage scholarship programs.\n"
        . "4. Start reviewing applications submitted to your scholarships.\n\n"
        . "If you were not expecting this account activation, please contact the Scholarship Finder administrator immediately.\n\n"
        . "Thank you,\nScholarship Finder";

    return [$subject, $body];
}

function sendProviderActivationEmail(array $mailConfig, array $providerDetails): array {
    $recipientEmail = trim((string) ($providerDetails['email'] ?? ''));
    if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'The provider does not have a valid email address on file.'
        ];
    }

    if (empty($mailConfig['configured'])) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Email notifications are not configured on this server.'
        ];
    }

    [$subject, $body] = buildProviderActivationEmail($providerDetails);
    $mailer = new SmtpMailer($mailConfig);
    $sendResult = $mailer->send($recipientEmail, $subject, wordwrap($body, 70));

    return [
        'success' => !empty($sendResult['success']),
        'skipped' => false,
        'error' => $sendResult['error'] ?? null
    ];
}

function storeEditUserOldInput(array $input): void {
    $allowedKeys = [
        'user_id',
        'username',
        'email',
        'status',
        'firstname',
        'lastname',
        'middleinitial',
        'suffix',
        'gender',
        'gwa',
        'school',
        'course',
        'organization_name',
        'organization_type',
        'contact_person_firstname',
        'contact_person_lastname',
        'contact_person_position',
        'phone_number',
        'mobile_number',
        'organization_email',
        'address',
        'house_no',
        'street',
        'barangay',
        'city',
        'province',
        'zip_code',
        'description',
        'department',
        'position',
        'access_level',
        'can_manage_users',
        'can_manage_scholarships',
        'can_review_documents',
        'can_view_reports',
        'position_title',
        'staff_id_no',
        'office_phone',
        'office_address',
        'website',
        'responsibility_scope'
    ];

    $payload = [];
    $checkboxKeys = [
        'can_manage_users',
        'can_manage_scholarships',
        'can_review_documents',
        'can_view_reports'
    ];

    foreach ($allowedKeys as $key) {
        if (in_array($key, $checkboxKeys, true)) {
            $payload[$key] = array_key_exists($key, $input) ? '1' : '0';
            continue;
        }

        if (!array_key_exists($key, $input)) {
            continue;
        }

        $value = $input[$key];
        if (is_array($value)) {
            continue;
        }

        $payload[$key] = trim((string) $value);
    }

    $_SESSION['edit_user_old'] = $payload;
}

function clearEditUserOldInput(): void {
    unset($_SESSION['edit_user_old']);
}

function redirectBackToEditUser(int $userId, array $errors = [], ?string $errorMessage = null, array $oldInput = []): void {
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }
    if ($errorMessage !== null && $errorMessage !== '') {
        $_SESSION['error'] = $errorMessage;
    }
    if (!empty($oldInput)) {
        storeEditUserOldInput($oldInput);
    }

    header('Location: ' . normalizeAppUrl(buildEntityUrl('../AdminView/edit_user.php', 'user', $userId, 'edit', ['id' => $userId])));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Update user status
    if ($action == 'update_status') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $status = strtolower(trim((string) ($_POST['status'] ?? '')));
        $allowedStatuses = getSupportedUserStatuses($pdo);
        $redirectAfterAction = '../AdminView/manage_users.php';

        if ($id <= 0) {
            denyUserManagementAction('Invalid user selected.');
        }
        requireUserActionToken($id, 'update_status');

        $targetRole = getTargetUserRole($pdo, $id);
        if ($targetRole === null) {
            denyUserManagementAction('Target user not found.');
        }
        $redirectAfterAction = resolveUserManagementRedirect($id, $targetRole);
        if (!canActorManageRole($actorRole, $targetRole) && !canActorReviewProviderAccount($actorRole, $targetRole)) {
            denyUserManagementAction($targetRole === 'provider'
                ? 'Only authorized administrators can review provider accounts.'
                : 'Only super administrators can manage account records.');
        }
        
        if (!in_array($status, $allowedStatuses, true)) {
            $_SESSION['error'] = 'Invalid status';
        } else {
            $targetUser = getTargetUserSnapshot($pdo, $id);
            $stmt = $pdo->prepare("UPDATE users SET status=? WHERE id=?");
            if ($stmt->execute([$status, $id])) {
                $activationEmailNotice = null;
                if ($targetRole === 'provider') {
                    syncProviderVerificationStatus($pdo, $id, $status);
                    if (shouldSendProviderActivationEmail($targetUser, $status)) {
                        $activationEmailNotice = sendProviderActivationEmail($mailConfig, $targetUser);
                        if (empty($activationEmailNotice['success'])) {
                            error_log('Failed to send provider activation email for user #' . $id . ': ' . ($activationEmailNotice['error'] ?? 'Unknown error'));
                        }
                    }
                }
                if ($targetUser) {
                    $activityLog = new ActivityLog($pdo);
                    $activityLog->log('update_status', 'user', 'Updated a user account status.', [
                        'entity_id' => $id,
                        'entity_name' => (string) ($targetUser['display_name'] ?? $targetUser['username'] ?? 'User'),
                        'target_user_id' => $id,
                        'target_name' => (string) ($targetUser['display_name'] ?? $targetUser['username'] ?? 'User'),
                        'details' => [
                            'email' => (string) ($targetUser['email'] ?? ''),
                            'role' => (string) ($targetUser['role'] ?? ''),
                            'new_status' => $status
                        ]
                    ]);
                }
                if ($targetRole === 'provider' && $status === 'active') {
                    if ($activationEmailNotice && !empty($activationEmailNotice['success'])) {
                        $_SESSION['success'] = 'Provider account activated and email notification sent successfully';
                    } elseif ($activationEmailNotice && empty($activationEmailNotice['success'])) {
                        $_SESSION['success'] = 'Provider account activated successfully. Email notification could not be sent.';
                    } else {
                        $_SESSION['success'] = 'Provider account activated successfully';
                    }
                } else {
                    $_SESSION['success'] = 'User status updated successfully';
                }
            } else {
                $_SESSION['error'] = 'Failed to update user status';
            }
        }
        header('Location: ' . normalizeAppUrl($redirectAfterAction));
        exit();
    }
    
    // Delete user
    elseif ($action == 'delete') {
        $id = (int) ($_POST['user_id'] ?? 0);

        if ($id <= 0) {
            denyUserManagementAction('Invalid user selected.');
        }
        requireUserActionToken($id, 'delete');
        if ($id === (int) ($_SESSION['user_id'] ?? 0)) {
            denyUserManagementAction('You cannot delete your own account.');
        }

        $targetRole = getTargetUserRole($pdo, $id);
        if ($targetRole === null) {
            denyUserManagementAction('Target user not found.');
        }
        $redirectAfterAction = resolveUserManagementRedirect($id, $targetRole);
        if (!canActorManageRole($actorRole, $targetRole)) {
            denyUserManagementAction('Only super administrators can manage account records.');
        }
        $targetUser = getTargetUserSnapshot($pdo, $id);
        
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
            if ($stmt->execute([$id])) {
                if ($targetUser) {
                    $activityLog = new ActivityLog($pdo);
                    $activityLog->log('delete', 'user', 'Deleted a user account.', [
                        'entity_id' => $id,
                        'entity_name' => (string) ($targetUser['display_name'] ?? $targetUser['username'] ?? 'User'),
                        'target_user_id' => $id,
                        'target_name' => (string) ($targetUser['display_name'] ?? $targetUser['username'] ?? 'User'),
                        'details' => [
                            'email' => (string) ($targetUser['email'] ?? ''),
                            'role' => (string) ($targetUser['role'] ?? '')
                        ]
                    ]);
                }
                $_SESSION['success'] = 'User deleted successfully';
            } else {
                $_SESSION['error'] = 'Failed to delete user';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = 'Failed to delete user: ' . $e->getMessage();
        }
        header('Location: ' . normalizeAppUrl('../AdminView/manage_users.php'));
        exit();
    }
    
    // Update user details
    elseif ($action == 'update') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $status = strtolower(trim((string) ($_POST['status'] ?? 'active')));
        $allowedStatuses = getSupportedUserStatuses($pdo);

        if ($id <= 0) {
            denyUserManagementAction('Invalid user selected.');
        }
        requireUserActionToken($id, 'update');

        $targetRole = getTargetUserRole($pdo, $id);
        if ($targetRole === null) {
            denyUserManagementAction('Target user not found.');
        }
        $redirectAfterAction = resolveUserManagementRedirect($id, $targetRole);
        if (!canActorManageRole($actorRole, $targetRole)) {
            denyUserManagementAction('Only super administrators can manage account records.');
        }
        
        $errors = [];
        
        if (empty($username)) $errors[] = 'Username is required';
        if (empty($email)) $errors[] = 'Email is required';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
        if (!in_array($status, $allowedStatuses, true)) $errors[] = 'Invalid status selected';
        
        // Check if user is student based on current role.
        $isStudent = ($targetRole === 'student');
        $isProviderRole = ($targetRole === 'provider');
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');
        $middleinitial = trim($_POST['middleinitial'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $allowedSuffixes = array_keys(getAdminSuffixOptions());
        if (!in_array($suffix, $allowedSuffixes, true)) {
            $errors[] = 'Invalid suffix selected';
        }

        if ($isStudent) {
            $school = trim($_POST['school'] ?? '');
            $course = trim($_POST['course'] ?? '');
            $gender = trim((string) ($_POST['gender'] ?? ''));
            $allowedGenders = ['', 'male', 'female', 'other', 'prefer_not_to_say'];
            $gwaInput = trim((string) ($_POST['gwa'] ?? ''));
            $gwa = null;
            
            if (empty($school)) $errors[] = 'School/University is required';
            if (empty($course)) $errors[] = 'Course/Program is required';
            if (!in_array($gender, $allowedGenders, true)) {
                $errors[] = 'Invalid gender selected';
            }
            if ($gwaInput !== '') {
                if (!is_numeric($gwaInput)) {
                    $errors[] = 'GWA must be a valid number';
                } else {
                    $gwa = round((float) $gwaInput, 2);
                    if ($gwa < 1.00 || $gwa > 5.00) {
                        $errors[] = 'GWA must be between 1.00 and 5.00';
                    }
                }
            }
        } else {
            $organizationName = trim($_POST['organization_name'] ?? '');
            $organizationType = trim($_POST['organization_type'] ?? '');
            $contactPersonFirstName = trim($_POST['contact_person_firstname'] ?? $firstname);
            $contactPersonLastName = trim($_POST['contact_person_lastname'] ?? $lastname);
            $contactPersonPosition = trim($_POST['contact_person_position'] ?? ($_POST['position_title'] ?? ''));
            $phoneNumber = trim($_POST['phone_number'] ?? ($_POST['office_phone'] ?? ''));
            $mobileNumber = formatPhilippineMobileNumber($_POST['mobile_number'] ?? '');
            $organizationEmail = trim($_POST['organization_email'] ?? $email);
            $website = trim($_POST['website'] ?? '');
            $address = trim($_POST['address'] ?? ($_POST['office_address'] ?? ''));
            $houseNo = trim($_POST['house_no'] ?? '');
            $street = trim($_POST['street'] ?? '');
            $barangay = trim($_POST['barangay'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $province = trim($_POST['province'] ?? '');
            $zipCode = trim($_POST['zip_code'] ?? '');
            $description = trim($_POST['description'] ?? ($_POST['responsibility_scope'] ?? ''));

            $positionProfile = resolveAdminPositionProfile(
                $targetRole,
                trim($_POST['position'] ?? ($_POST['position_title'] ?? getDefaultAdminPositionLabel($targetRole))),
                ($targetRole === 'super_admin' ? 90 : ($targetRole === 'admin' ? 70 : 40))
            );
            $position = (string) $positionProfile['position'];
            $department = trim($_POST['department'] ?? getDefaultAdminDepartmentLabel($targetRole));
            $accessLevel = (int) $positionProfile['access_level'];
            $canManageUsers = !empty($positionProfile['can_manage_users']) ? 1 : 0;
            $canManageScholarships = !empty($positionProfile['can_manage_scholarships']) ? 1 : 0;
            $canReviewDocuments = !empty($positionProfile['can_review_documents']) ? 1 : 0;
            $canViewReports = !empty($positionProfile['can_view_reports']) ? 1 : 0;
            $staffIdNo = trim($_POST['staff_id_no'] ?? '');

            if ($isProviderRole) {
                if ($organizationName === '') $errors[] = 'Organization name is required';
                if ($contactPersonFirstName === '') $errors[] = 'Contact person first name is required';
                if ($contactPersonLastName === '') $errors[] = 'Contact person last name is required';
                if ($contactPersonPosition === '') $errors[] = 'Contact person position is required';
                if ($phoneNumber === '') $errors[] = 'Phone number is required';
                if ($organizationEmail === '') $errors[] = 'Organization email is required';
                if ($organizationType === '') $errors[] = 'Organization type is required';
                if ($city === '') $errors[] = 'City is required';
                if ($province === '') $errors[] = 'Province is required';

                if ($address === '') {
                    $address = implode(', ', array_filter([$houseNo, $street, $barangay, $city, $province]));
                }
                if ($address === '') $errors[] = 'Provider address is required';

                if ($phoneNumber !== '' && !preg_match('/^[0-9+()\\-\\s]{7,25}$/', $phoneNumber)) {
                    $errors[] = 'Phone number format is invalid';
                }
                if (!isValidPhilippineMobileNumber($_POST['mobile_number'] ?? '', false)) {
                    $errors[] = 'Mobile number must be a valid +63 mobile number';
                }
                if ($organizationEmail !== '' && !filter_var($organizationEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Organization email format is invalid';
                }
                if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
                    $errors[] = 'Website must be a valid URL';
                }
            } else {
                if ($firstname === '') $errors[] = 'First name is required';
                if ($lastname === '') $errors[] = 'Last name is required';
                if ($phoneNumber === '') $errors[] = 'Phone number is required';
                if ($position === '') $errors[] = 'Position is required';
                if ($middleinitial !== '' && !preg_match('/^[A-Za-z]$/', str_replace('.', '', $middleinitial))) {
                    $errors[] = 'Middle initial must be a single letter';
                }
                if ($phoneNumber !== '' && !preg_match('/^[0-9+()\\-\\s]{7,25}$/', $phoneNumber)) {
                    $errors[] = 'Phone number format is invalid';
                }

            }
        }
        
        // Check if username is taken by another user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) {
            $errors[] = 'Username is already taken';
        }
        
        // Check if email is taken by another user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            $errors[] = 'Email is already registered';
        }
        
        if (empty($errors)) {
            try {
                $targetUserBefore = getTargetUserSnapshot($pdo, $id);
                $pdo->beginTransaction();
                
                // Update users table
                $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, status=? WHERE id=?");
                $stmt->execute([$username, $email, $status, $id]);
                if ($targetRole === 'provider') {
                    syncProviderVerificationStatus($pdo, $id, $status);
                }
                if (!$isStudent && tableHasColumn($pdo, 'users', 'access_level')) {
                    $stmt = $pdo->prepare("UPDATE users SET access_level = ? WHERE id = ?");
                    $stmt->execute([$isProviderRole ? 40 : $accessLevel, $id]);
                }
                
                if ($isStudent) {
                    // Format middle initial properly
                    if (!empty($middleinitial)) {
                        $middleinitial = str_replace('.', '', $middleinitial);
                        if (strlen($middleinitial) > 1) {
                            $middleinitial = substr($middleinitial, 0, 1);
                        }
                        $middleinitial = strtoupper($middleinitial) . '.';
                    }
                    
                    // Check if student_data exists
                    $stmt = $pdo->prepare("SELECT id FROM student_data WHERE student_id = ?");
                    $stmt->execute([$id]);
                    $studentData = $stmt->fetch();

                    $studentPayload = [
                        'firstname' => $firstname,
                        'lastname' => $lastname,
                        'middleinitial' => $middleinitial !== '' ? $middleinitial : null,
                        'school' => $school,
                        'course' => $course,
                    ];

                    if (tableHasColumn($pdo, 'student_data', 'suffix')) {
                        $studentPayload['suffix'] = $suffix !== '' ? $suffix : null;
                    }
                    if (tableHasColumn($pdo, 'student_data', 'gender')) {
                        $studentPayload['gender'] = $gender !== '' ? $gender : null;
                    }
                    if (tableHasColumn($pdo, 'student_data', 'gwa')) {
                        $studentPayload['gwa'] = $gwa;
                    }

                    if ($studentData) {
                        $setParts = [];
                        $params = [];
                        foreach ($studentPayload as $column => $value) {
                            $setParts[] = "{$column} = ?";
                            $params[] = $value;
                        }
                        $params[] = $id;

                        $stmt = $pdo->prepare("
                            UPDATE student_data
                            SET " . implode(', ', $setParts) . "
                            WHERE student_id = ?
                        ");
                        $stmt->execute($params);
                    } else {
                        $columns = array_keys($studentPayload);
                        $placeholders = array_fill(0, count($columns), '?');
                        $params = [$id];
                        foreach ($columns as $column) {
                            $params[] = $studentPayload[$column];
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO student_data (student_id, " . implode(', ', $columns) . ")
                            VALUES (?, " . implode(', ', $placeholders) . ")
                        ");
                        $stmt->execute($params);
                    }
                } else {
                    $staffAccountProfileModel = new StaffAccountProfile($pdo);
                    $context = [
                        'role' => $targetRole,
                        'email' => $email,
                        'access_level' => $isProviderRole ? 40 : $accessLevel,
                        'created_by' => (int) ($_SESSION['user_id'] ?? 0),
                        'organization_name' => $organizationName !== '' ? $organizationName : 'Scholarship Finder Administration',
                        'staff_id_no' => $staffIdNo !== '' ? $staffIdNo : null,
                        'office_address' => $address !== '' ? $address : null,
                        'city' => $city !== '' ? $city : null,
                        'province' => $province !== '' ? $province : null,
                        'department' => $department !== '' ? $department : 'Administration'
                    ];

                    if ($isProviderRole) {
                        $staffData = [
                            'organization_name' => $organizationName,
                            'contact_person_firstname' => $contactPersonFirstName,
                            'contact_person_lastname' => $contactPersonLastName,
                            'contact_person_position' => $contactPersonPosition,
                            'phone_number' => $phoneNumber,
                            'mobile_number' => $mobileNumber !== '' ? $mobileNumber : null,
                            'organization_email' => $organizationEmail,
                            'website' => $website !== '' ? $website : null,
                            'organization_type' => $organizationType,
                            'address' => $address,
                            'house_no' => $houseNo !== '' ? $houseNo : null,
                            'street' => $street !== '' ? $street : null,
                            'barangay' => $barangay !== '' ? $barangay : null,
                            'city' => $city,
                            'province' => $province,
                            'zip_code' => $zipCode !== '' ? $zipCode : null,
                            'description' => $description !== '' ? $description : null
                        ];
                    } else {
                        $staffData = [
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
                            'is_super_admin' => $targetRole === 'super_admin' ? 1 : 0,
                        ];
                    }

                    $staffAccountProfileModel->saveForUser($id, $targetRole, $staffData, $context);
                }
                
                $pdo->commit();
                clearEditUserOldInput();
                $activationEmailNotice = null;
                if ($targetRole === 'provider' && shouldSendProviderActivationEmail($targetUserBefore, $status)) {
                    $updatedTargetUser = getTargetUserSnapshot($pdo, $id);
                    if ($updatedTargetUser) {
                        $activationEmailNotice = sendProviderActivationEmail($mailConfig, $updatedTargetUser);
                        if (empty($activationEmailNotice['success'])) {
                            error_log('Failed to send provider activation email for user #' . $id . ' after account edit: ' . ($activationEmailNotice['error'] ?? 'Unknown error'));
                        }
                    }
                }

                if ($targetUserBefore) {
                    $activityLog = new ActivityLog($pdo);
                    $activityLog->log('update', 'user', 'Updated user account details.', [
                        'entity_id' => $id,
                        'entity_name' => (string) ($targetUserBefore['display_name'] ?? $targetUserBefore['username'] ?? $username),
                        'target_user_id' => $id,
                        'target_name' => (string) ($targetUserBefore['display_name'] ?? $targetUserBefore['username'] ?? $username),
                        'details' => [
                            'old_email' => (string) ($targetUserBefore['email'] ?? ''),
                            'new_email' => $email,
                            'new_status' => $status
                        ]
                    ]);
                }

                if ($targetRole === 'provider' && $status === 'active' && shouldSendProviderActivationEmail($targetUserBefore, $status)) {
                    if ($activationEmailNotice && !empty($activationEmailNotice['success'])) {
                        $_SESSION['success'] = 'Provider account updated and activation email sent successfully';
                    } elseif ($activationEmailNotice && empty($activationEmailNotice['success'])) {
                        $_SESSION['success'] = 'Provider account updated successfully. Activation email could not be sent.';
                    } else {
                        $_SESSION['success'] = 'Provider account updated successfully';
                    }
                } else {
                    $_SESSION['success'] = 'User updated successfully';
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                redirectBackToEditUser($id, [], 'Failed to update user: ' . $e->getMessage(), $_POST);
            }
        } else {
            redirectBackToEditUser($id, $errors, null, $_POST);
        }
        
        header('Location: ' . normalizeAppUrl($redirectAfterAction));
        exit();
    }
}

header('Location: ' . normalizeAppUrl('../AdminView/manage_users.php'));
exit();
?>
