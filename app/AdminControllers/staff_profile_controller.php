<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/password_policy.php';
require_once __DIR__ . '/../Models/User.php';
require_once __DIR__ . '/../Models/StaffAccountProfile.php';
require_once __DIR__ . '/../Models/ActivityLog.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_SESSION['user_id']) || !isRoleIn(['provider', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

class StaffSelfProfileController {
    private $pdo;
    private $userModel;
    private $staffAccountProfileModel;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userModel = new User($pdo);
        $this->staffAccountProfileModel = new StaffAccountProfile($pdo);
    }

    private function normalizeNullable($value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeComparable($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function scalarValuesMatch($left, $right): bool
    {
        return $this->normalizeComparable($left) === $this->normalizeComparable($right);
    }

    private function isValidMiddleInitialValue(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]$/', str_replace('.', '', $value));
    }

    private function getUserRow(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("\n            SELECT id, username, email, role, access_level, status, created_at\n            FROM users\n            WHERE id = :user_id\n            LIMIT 1\n        ");
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    private function hasProfileChanges(array $incoming, ?array $existing): bool
    {
        if (!$existing) {
            foreach ($incoming as $value) {
                if ($this->normalizeComparable($value) !== null) {
                    return true;
                }
            }
            return false;
        }

        foreach ($incoming as $key => $value) {
            if (!$this->scalarValuesMatch($value, $existing[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function validateAdminData(array $data, array $user, ?array $existing): array
    {
        $role = normalizeUserRole($user['role'] ?? 'admin');
        $payload = [
            'firstname' => trim((string) ($data['firstname'] ?? ($existing['firstname'] ?? ''))),
            'lastname' => trim((string) ($data['lastname'] ?? ($existing['lastname'] ?? ''))),
            'middleinitial' => trim((string) ($data['middleinitial'] ?? ($existing['middleinitial'] ?? ''))),
            'suffix' => trim((string) ($data['suffix'] ?? ($existing['suffix'] ?? ''))),
            'phone_number' => trim((string) ($data['phone_number'] ?? ($existing['phone_number'] ?? ''))),
            'position' => trim((string) ($data['position'] ?? ($existing['position'] ?? ''))),
            'department' => trim((string) ($data['department'] ?? ($existing['department'] ?? ''))),
            'access_level' => (int) ($existing['access_level'] ?? ($user['access_level'] ?? ($role === 'super_admin' ? 90 : 70))),
            'can_manage_users' => (int) ($existing['can_manage_users'] ?? ($role === 'super_admin' || $role === 'admin' ? 1 : 0)),
            'can_manage_scholarships' => (int) ($existing['can_manage_scholarships'] ?? 1),
            'can_review_documents' => (int) ($existing['can_review_documents'] ?? 1),
            'can_view_reports' => (int) ($existing['can_view_reports'] ?? ($role === 'super_admin' || $role === 'admin' ? 1 : 0)),
            'is_super_admin' => $role === 'super_admin' ? 1 : (int) ($existing['is_super_admin'] ?? 0)
        ];

        $errors = [];
        if ($payload['firstname'] === '') $errors[] = 'First name is required';
        if ($payload['lastname'] === '') $errors[] = 'Last name is required';
        if (!$this->isValidMiddleInitialValue($payload['middleinitial'])) $errors[] = 'Middle initial must be a single letter';
        if ($payload['phone_number'] === '') $errors[] = 'Phone number is required';
        if ($payload['phone_number'] !== '' && !preg_match('/^[0-9+()\-\s]{7,25}$/', $payload['phone_number'])) {
            $errors[] = 'Phone number format is invalid';
        }

        return [$errors, $payload];
    }

    private function validateProviderData(array $data, array $user, ?array $existing): array
    {
        $organizationTypeOptions = [
            'government_agency',
            'local_government_unit',
            'state_university',
            'private_school',
            'foundation',
            'nonprofit',
            'corporate',
            'other'
        ];

        $payload = [
            'organization_name' => trim((string) ($data['organization_name'] ?? ($existing['organization_name'] ?? ''))),
            'contact_person_firstname' => trim((string) ($data['contact_person_firstname'] ?? ($existing['contact_person_firstname'] ?? ''))),
            'contact_person_lastname' => trim((string) ($data['contact_person_lastname'] ?? ($existing['contact_person_lastname'] ?? ''))),
            'contact_person_position' => trim((string) ($data['contact_person_position'] ?? ($existing['contact_person_position'] ?? ''))),
            'phone_number' => trim((string) ($data['phone_number'] ?? ($existing['phone_number'] ?? ''))),
            'mobile_number' => trim((string) ($data['mobile_number'] ?? ($existing['mobile_number'] ?? ''))),
            'organization_email' => trim((string) ($data['organization_email'] ?? ($existing['organization_email'] ?? ($user['email'] ?? '')))),
            'website' => trim((string) ($data['website'] ?? ($existing['website'] ?? ''))),
            'organization_type' => strtolower(trim((string) ($data['organization_type'] ?? ($existing['organization_type'] ?? '')))),
            'address' => trim((string) ($data['address'] ?? ($existing['address'] ?? ''))),
            'house_no' => trim((string) ($data['house_no'] ?? ($existing['house_no'] ?? ''))),
            'street' => trim((string) ($data['street'] ?? ($existing['street'] ?? ''))),
            'barangay' => trim((string) ($data['barangay'] ?? ($existing['barangay'] ?? ''))),
            'city' => trim((string) ($data['city'] ?? ($existing['city'] ?? ''))),
            'province' => trim((string) ($data['province'] ?? ($existing['province'] ?? ''))),
            'zip_code' => trim((string) ($data['zip_code'] ?? ($existing['zip_code'] ?? ''))),
            'description' => trim((string) ($data['description'] ?? ($existing['description'] ?? ''))),
            'is_verified' => (int) ($existing['is_verified'] ?? 0),
            'verified_at' => $existing['verified_at'] ?? null,
            'logo' => trim((string) ($existing['logo'] ?? '')),
            'verification_document' => trim((string) ($existing['verification_document'] ?? ''))
        ];

        $errors = [];
        if ($payload['organization_name'] === '') $errors[] = 'Organization name is required';
        if ($payload['contact_person_firstname'] === '') $errors[] = 'Contact person first name is required';
        if ($payload['contact_person_lastname'] === '') $errors[] = 'Contact person last name is required';
        if ($payload['contact_person_position'] === '') $errors[] = 'Contact person position is required';
        if ($payload['phone_number'] === '') $errors[] = 'Phone number is required';
        if ($payload['organization_email'] === '') $errors[] = 'Organization email is required';
        if ($payload['organization_type'] === '') $errors[] = 'Organization type is required';
        if ($payload['address'] === '') $errors[] = 'Address is required';
        if ($payload['city'] === '') $errors[] = 'City or municipality is required';
        if ($payload['province'] === '') $errors[] = 'Province is required';

        if ($payload['organization_email'] !== '' && !filter_var($payload['organization_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Organization email format is invalid';
        }

        if ($payload['website'] !== '' && !filter_var($payload['website'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Website must be a valid URL';
        }

        if ($payload['phone_number'] !== '' && !preg_match('/^[0-9+()\-\s]{7,25}$/', $payload['phone_number'])) {
            $errors[] = 'Phone number format is invalid';
        }

        if ($payload['mobile_number'] !== '' && !preg_match('/^[0-9+()\-\s]{7,25}$/', $payload['mobile_number'])) {
            $errors[] = 'Mobile number format is invalid';
        }

        if ($payload['organization_type'] !== '' && !in_array($payload['organization_type'], $organizationTypeOptions, true)) {
            $errors[] = 'Organization type is invalid';
        }

        return [$errors, $payload];
    }

    private function syncSession(array $user, array $profile): void
    {
        $role = normalizeUserRole($user['role'] ?? 'provider');
        $displayName = $this->staffAccountProfileModel->buildDisplayName($role, $profile, $user);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_username'] = (string) ($user['username'] ?? 'Staff');
        $_SESSION['user_email'] = (string) ($user['email'] ?? '');
        $_SESSION['user_display_name'] = $displayName;
        $_SESSION['user_created_at'] = (string) ($user['created_at'] ?? '');
        syncRoleSessionMeta($role);

        if ($role === 'provider') {
            $_SESSION['user_firstname'] = (string) ($profile['contact_person_firstname'] ?? '');
            $_SESSION['user_lastname'] = (string) ($profile['contact_person_lastname'] ?? '');
            $_SESSION['user_middleinitial'] = '';
            $_SESSION['user_suffix'] = '';
        } else {
            $_SESSION['user_firstname'] = (string) ($profile['firstname'] ?? '');
            $_SESSION['user_lastname'] = (string) ($profile['lastname'] ?? '');
            $_SESSION['user_middleinitial'] = (string) ($profile['middleinitial'] ?? '');
            $_SESSION['user_suffix'] = (string) ($profile['suffix'] ?? '');
        }

        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['admin_username'] = (string) ($user['username'] ?? 'Staff');
        $_SESSION['admin_email'] = (string) ($user['email'] ?? '');
        $_SESSION['admin_role'] = $role;
        $_SESSION['admin_name'] = $displayName;
        syncStaffPermissionSessionMeta($role, $profile);
    }

    public function updateProfile(int $userId, array $data): array
    {
        $user = $this->getUserRow($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'Account not found'];
        }

        $role = normalizeUserRole($user['role'] ?? '');
        if (!in_array($role, ['provider', 'admin', 'super_admin'], true)) {
            return ['success' => false, 'message' => 'This profile editor is only available for staff accounts'];
        }

        $storageStatus = $this->staffAccountProfileModel->getStorageStatus($role);
        if (!$storageStatus['ready']) {
            return ['success' => false, 'message' => $this->staffAccountProfileModel->getStorageMessage($role)];
        }

        $existing = $this->staffAccountProfileModel->getByUserId($userId, $role, $user);
        [$errors, $payload] = $role === 'provider'
            ? $this->validateProviderData($data, $user, $existing)
            : $this->validateAdminData($data, $user, $existing);

        if (!empty($errors)) {
            return ['success' => false, 'message' => implode("\n", $errors)];
        }

        if (!$this->hasProfileChanges($payload, $existing)) {
            return [
                'success' => true,
                'no_changes' => true,
                'message' => 'No changes detected in your profile'
            ];
        }

        try {
            $this->pdo->beginTransaction();
            $this->staffAccountProfileModel->saveForUser($userId, $role, $payload, [
                'role' => $role,
                'email' => (string) ($user['email'] ?? ''),
                'access_level' => (int) ($user['access_level'] ?? 0),
                'created_by' => (int) ($user['id'] ?? 0)
            ]);
            $freshProfile = $this->staffAccountProfileModel->getByUserId($userId, $role, $user) ?? $payload;
            $this->pdo->commit();

            $this->syncSession($user, $freshProfile);

            try {
                $activityLog = new ActivityLog($this->pdo);
                $activityLog->log('update_profile', $role === 'provider' ? 'provider_profile' : 'admin_profile', 'Updated staff profile details.', [
                    'entity_id' => $userId,
                    'entity_name' => $this->staffAccountProfileModel->buildDisplayName($role, $freshProfile, $user),
                    'target_user_id' => $userId,
                    'target_name' => $this->staffAccountProfileModel->buildDisplayName($role, $freshProfile, $user),
                    'details' => [
                        'role' => $role,
                        'source' => (string) ($freshProfile['profile_source'] ?? ''),
                        'summary' => $role === 'provider'
                            ? (string) ($freshProfile['organization_name'] ?? '')
                            : (string) ($freshProfile['position'] ?? '')
                    ]
                ]);
            } catch (Throwable $e) {
                error_log('staff profile activity log error: ' . $e->getMessage());
            }

            return ['success' => true, 'message' => 'Profile updated successfully'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log('staff profile update error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update profile: ' . $e->getMessage()];
        }
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        return $this->userModel->changePassword($userId, $currentPassword, $newPassword);
    }
}

$controller = new StaffSelfProfileController($pdo);
$action = trim((string) ($_POST['action'] ?? ''));

switch ($action) {
    case 'update_profile':
        echo json_encode($controller->updateProfile((int) $_SESSION['user_id'], $_POST));
        exit();

    case 'change_password':
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPassword === '') {
            echo json_encode(['success' => false, 'message' => 'Current password is required']);
            exit();
        }

        if ($newPassword === '') {
            echo json_encode(['success' => false, 'message' => 'New password is required']);
            exit();
        }

        if ($newPassword !== $confirmPassword) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
            exit();
        }

        $passwordValidation = validateStrongPassword($newPassword, [
            'username' => (string) ($_SESSION['admin_username'] ?? $_SESSION['user_username'] ?? ''),
            'email' => (string) ($_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? ''),
            'name' => (string) ($_SESSION['user_display_name'] ?? ''),
        ]);
        if (!$passwordValidation['valid']) {
            echo json_encode(['success' => false, 'message' => $passwordValidation['errors'][0] ?? passwordPolicyHint()]);
            exit();
        }

        echo json_encode($controller->changePassword((int) $_SESSION['user_id'], $currentPassword, $newPassword));
        exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
exit();
?>
