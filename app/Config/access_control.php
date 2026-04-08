<?php
require_once __DIR__ . '/helpers.php';

if (!function_exists('normalizeUserRole')) {
    function normalizeUserRole($role): string
    {
        $value = strtolower(trim((string) $role));
        if ($value === '') {
            return 'guest';
        }

        $allowed = ['guest', 'student', 'provider', 'admin', 'super_admin'];
        return in_array($value, $allowed, true) ? $value : 'guest';
    }
}

if (!function_exists('getRoleAccessLevel')) {
    function getRoleAccessLevel($role): int
    {
        $normalized = normalizeUserRole($role);
        $map = [
            'guest' => 0,
            'student' => 10,
            'provider' => 40,
            'admin' => 70,
            'super_admin' => 90,
        ];

        return $map[$normalized] ?? 0;
    }
}

if (!function_exists('getCurrentSessionRole')) {
    function getCurrentSessionRole(): string
    {
        if (isset($_SESSION['user_role'])) {
            return normalizeUserRole($_SESSION['user_role']);
        }
        if (isset($_SESSION['admin_role'])) {
            return normalizeUserRole($_SESSION['admin_role']);
        }
        return 'guest';
    }
}

if (!function_exists('isRoleIn')) {
    function isRoleIn(array $roles): bool
    {
        $currentRole = getCurrentSessionRole();
        foreach ($roles as $role) {
            if ($currentRole === normalizeUserRole($role)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('hasMinimumRoleLevel')) {
    function hasMinimumRoleLevel(int $minimumLevel): bool
    {
        return getRoleAccessLevel(getCurrentSessionRole()) >= $minimumLevel;
    }
}

if (!function_exists('isAdminRole')) {
    function isAdminRole(): bool
    {
        return isRoleIn(['admin', 'super_admin']);
    }
}

if (!function_exists('isProviderOrAdminRole')) {
    function isProviderOrAdminRole(): bool
    {
        return isRoleIn(['provider', 'admin', 'super_admin']);
    }
}

if (!function_exists('requireLoginSession')) {
    function requireLoginSession(string $redirectTo = '../View/login.php'): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . normalizeAppUrl($redirectTo, 'View/login.php'));
            exit();
        }
    }
}

if (!function_exists('requireRoles')) {
    function requireRoles(array $roles, string $redirectTo = '../View/index.php', ?string $errorMessage = null): void
    {
        requireLoginSession('../View/login.php');
        if (!isRoleIn($roles)) {
            if ($errorMessage !== null && $errorMessage !== '') {
                $_SESSION['error'] = $errorMessage;
            }
            header('Location: ' . normalizeAppUrl($redirectTo, 'View/index.php'));
            exit();
        }
    }
}

if (!function_exists('syncRoleSessionMeta')) {
    function syncRoleSessionMeta($role): void
    {
        $normalizedRole = normalizeUserRole($role);
        $_SESSION['user_role'] = $normalizedRole;
        $_SESSION['user_access_level'] = getRoleAccessLevel($normalizedRole);
    }
}

if (!function_exists('getDefaultStaffPermissions')) {
    function getDefaultStaffPermissions($role): array
    {
        $normalizedRole = normalizeUserRole($role);

        $defaults = [
            'can_manage_users' => false,
            'can_manage_scholarships' => false,
            'can_review_documents' => false,
            'can_view_reports' => false,
        ];

        if ($normalizedRole === 'provider') {
            $defaults['can_manage_scholarships'] = true;
            $defaults['can_review_documents'] = true;
        } elseif ($normalizedRole === 'admin') {
            $defaults['can_manage_users'] = true;
            $defaults['can_manage_scholarships'] = true;
            $defaults['can_review_documents'] = true;
            $defaults['can_view_reports'] = true;
        } elseif ($normalizedRole === 'super_admin') {
            foreach ($defaults as $key => $value) {
                $defaults[$key] = true;
            }
        }

        return $defaults;
    }
}

if (!function_exists('syncStaffPermissionSessionMeta')) {
    function syncStaffPermissionSessionMeta($role, array $profile = []): void
    {
        $normalizedRole = normalizeUserRole($role);
        $permissions = getDefaultStaffPermissions($normalizedRole);

        if (in_array($normalizedRole, ['admin', 'super_admin'], true)) {
            require_once __DIR__ . '/admin_account_options.php';
            if (function_exists('resolveAdminPositionProfile')) {
                $positionProfile = resolveAdminPositionProfile(
                    $normalizedRole,
                    (string) ($profile['position'] ?? $profile['position_title'] ?? ''),
                    isset($profile['access_level']) ? (int) $profile['access_level'] : getRoleAccessLevel($normalizedRole)
                );
                foreach (array_keys($permissions) as $permissionKey) {
                    if (array_key_exists($permissionKey, $positionProfile)) {
                        $permissions[$permissionKey] = (bool) $positionProfile[$permissionKey];
                    }
                }
            }
        }

        foreach (array_keys($permissions) as $permissionKey) {
            if (
                array_key_exists($permissionKey, $profile)
                && !in_array($normalizedRole, ['admin', 'super_admin'], true)
            ) {
                $permissions[$permissionKey] = (bool) $profile[$permissionKey];
            }
        }

        if ($normalizedRole === 'super_admin') {
            foreach ($permissions as $permissionKey => $allowed) {
                $permissions[$permissionKey] = true;
            }
        } elseif ($normalizedRole === 'admin') {
            $permissions['can_manage_users'] = false;
        }

        $_SESSION['staff_permissions'] = $permissions;
    }
}

if (!function_exists('getCurrentStaffPermissions')) {
    function getCurrentStaffPermissions(): array
    {
        static $resolvedPermissions = null;

        if ($resolvedPermissions !== null) {
            return $resolvedPermissions;
        }

        $currentRole = getCurrentSessionRole();
        $resolvedPermissions = getDefaultStaffPermissions($currentRole);

        if (!in_array($currentRole, ['provider', 'admin', 'super_admin'], true)) {
            return $resolvedPermissions;
        }

        $sessionPermissions = $_SESSION['staff_permissions'] ?? null;
        if (is_array($sessionPermissions)) {
            foreach ($resolvedPermissions as $permissionKey => $defaultValue) {
                if (array_key_exists($permissionKey, $sessionPermissions)) {
                    $resolvedPermissions[$permissionKey] = (bool) $sessionPermissions[$permissionKey];
                }
            }

            return $resolvedPermissions;
        }

        if (
            isset($_SESSION['user_id']) &&
            isset($GLOBALS['pdo']) &&
            $GLOBALS['pdo'] instanceof PDO
        ) {
            try {
                require_once __DIR__ . '/../Models/StaffAccountProfile.php';

                $pdo = $GLOBALS['pdo'];
                $userId = (int) $_SESSION['user_id'];
                $stmt = $pdo->prepare("
                    SELECT id, email, role, access_level
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($userRow) {
                    $profileModel = new StaffAccountProfile($pdo);
                    $profile = $profileModel->getByUserId($userId, (string) ($userRow['role'] ?? $currentRole), $userRow) ?? [];
                    syncStaffPermissionSessionMeta((string) ($userRow['role'] ?? $currentRole), $profile);

                    $sessionPermissions = $_SESSION['staff_permissions'] ?? null;
                    if (is_array($sessionPermissions)) {
                        foreach ($resolvedPermissions as $permissionKey => $defaultValue) {
                            if (array_key_exists($permissionKey, $sessionPermissions)) {
                                $resolvedPermissions[$permissionKey] = (bool) $sessionPermissions[$permissionKey];
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('staff permission session sync error: ' . $e->getMessage());
            }
        }

        return $resolvedPermissions;
    }
}

if (!function_exists('staffHasPermission')) {
    function staffHasPermission(string $permission): bool
    {
        $permissions = getCurrentStaffPermissions();
        return !empty($permissions[$permission]);
    }
}

if (!function_exists('canAccessStaffAccounts')) {
    function canAccessStaffAccounts(): bool
    {
        return isRoleIn(['super_admin']) && staffHasPermission('can_manage_users');
    }
}

if (!function_exists('canAccessStaffScholarships')) {
    function canAccessStaffScholarships(): bool
    {
        return isRoleIn(['provider', 'admin', 'super_admin']) && staffHasPermission('can_manage_scholarships');
    }
}

if (!function_exists('canAccessStaffApplications')) {
    function canAccessStaffApplications(): bool
    {
        return isRoleIn(['provider', 'admin', 'super_admin'])
            && (staffHasPermission('can_manage_scholarships') || staffHasPermission('can_review_documents'));
    }
}

if (!function_exists('canAccessStaffDocuments')) {
    function canAccessStaffDocuments(): bool
    {
        return isRoleIn(['provider', 'admin', 'super_admin']) && staffHasPermission('can_review_documents');
    }
}

if (!function_exists('canAccessProviderApprovals')) {
    function canAccessProviderApprovals(): bool
    {
        return isRoleIn(['admin', 'super_admin'])
            && (staffHasPermission('can_review_documents') || staffHasPermission('can_manage_scholarships'));
    }
}

if (!function_exists('canAccessScholarshipApprovals')) {
    function canAccessScholarshipApprovals(): bool
    {
        return isRoleIn(['admin', 'super_admin']) && staffHasPermission('can_manage_scholarships');
    }
}

if (!function_exists('canAccessGwaIssueReports')) {
    function canAccessGwaIssueReports(): bool
    {
        return isRoleIn(['admin', 'super_admin'])
            && (staffHasPermission('can_review_documents') || staffHasPermission('can_view_reports'));
    }
}

if (!function_exists('canAccessUserIssueReports')) {
    function canAccessUserIssueReports(): bool
    {
        return isRoleIn(['admin', 'super_admin'])
            && (staffHasPermission('can_review_documents') || staffHasPermission('can_view_reports'));
    }
}

if (!function_exists('canAccessStaffReviews')) {
    function canAccessStaffReviews(): bool
    {
        return canAccessStaffApplications()
            || canAccessStaffDocuments()
            || canAccessProviderApprovals()
            || canAccessScholarshipApprovals()
            || canAccessGwaIssueReports()
            || canAccessUserIssueReports();
    }
}

if (!function_exists('canAccessStaffLogs')) {
    function canAccessStaffLogs(): bool
    {
        return isRoleIn(['super_admin']) && staffHasPermission('can_view_reports');
    }
}
