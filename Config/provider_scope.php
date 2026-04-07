<?php
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/../Model/StaffAccountProfile.php';

if (!function_exists('normalizeProviderScopeValue')) {
    function normalizeProviderScopeValue(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}

if (!function_exists('getCurrentProviderScope')) {
    function getCurrentProviderScope(PDO $pdo): array
    {
        static $cache = [];

        $role = getCurrentSessionRole();
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $cacheKey = $role . ':' . $userId;

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $scope = [
            'is_provider' => $role === 'provider',
            'organization_name' => null,
            'normalized_organization_name' => null,
        ];

        if ($scope['is_provider'] && $userId > 0) {
            try {
                $profileModel = new StaffAccountProfile($pdo);
                $profile = $profileModel->getByUserId($userId, 'provider', [
                    'id' => $userId,
                    'role' => 'provider',
                    'username' => $_SESSION['user_username'] ?? $_SESSION['admin_username'] ?? '',
                ]);

                $organizationName = trim((string) ($profile['organization_name'] ?? ''));
                if ($organizationName !== '') {
                    $scope['organization_name'] = $organizationName;
                    $scope['normalized_organization_name'] = normalizeProviderScopeValue($organizationName);
                }
            } catch (Throwable $e) {
                $scope['organization_name'] = null;
                $scope['normalized_organization_name'] = null;
            }
        }

        $cache[$cacheKey] = $scope;
        return $scope;
    }
}

if (!function_exists('getCurrentProviderOrganization')) {
    function getCurrentProviderOrganization(PDO $pdo): ?string
    {
        $scope = getCurrentProviderScope($pdo);
        return $scope['organization_name'] ?? null;
    }
}

if (!function_exists('getProviderScholarshipScopeClause')) {
    function getProviderScholarshipScopeClause(PDO $pdo, string $providerColumn = 'sd.provider'): array
    {
        $scope = getCurrentProviderScope($pdo);
        if (empty($scope['is_provider'])) {
            return ['sql' => '', 'params' => []];
        }

        $normalizedOrganization = trim((string) ($scope['normalized_organization_name'] ?? ''));
        if ($normalizedOrganization === '') {
            return ['sql' => ' AND 1 = 0', 'params' => []];
        }

        return [
            'sql' => " AND LOWER(TRIM(COALESCE({$providerColumn}, ''))) = ?",
            'params' => [$normalizedOrganization],
        ];
    }
}

if (!function_exists('getProviderDocumentScopeClause')) {
    function getProviderDocumentScopeClause(PDO $pdo, string $userIdColumn = 'ud.user_id'): array
    {
        $scope = getCurrentProviderScope($pdo);
        if (empty($scope['is_provider'])) {
            return ['sql' => '', 'params' => []];
        }

        $normalizedOrganization = trim((string) ($scope['normalized_organization_name'] ?? ''));
        if ($normalizedOrganization === '') {
            return ['sql' => ' AND 1 = 0', 'params' => []];
        }

        return [
            'sql' => "
                AND EXISTS (
                    SELECT 1
                    FROM applications scope_app
                    JOIN scholarships scope_sch ON scope_app.scholarship_id = scope_sch.id
                    LEFT JOIN scholarship_data scope_sd ON scope_sch.id = scope_sd.scholarship_id
                    WHERE scope_app.user_id = {$userIdColumn}
                      AND LOWER(TRIM(COALESCE(scope_sd.provider, ''))) = ?
                )
            ",
            'params' => [$normalizedOrganization],
        ];
    }
}

if (!function_exists('providerCanAccessScholarship')) {
    function providerCanAccessScholarship(PDO $pdo, int $scholarshipId): bool
    {
        $scope = getCurrentProviderScope($pdo);
        if (empty($scope['is_provider'])) {
            return true;
        }

        $normalizedOrganization = trim((string) ($scope['normalized_organization_name'] ?? ''));
        if ($scholarshipId <= 0 || $normalizedOrganization === '') {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM scholarships s
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            WHERE s.id = ?
              AND LOWER(TRIM(COALESCE(sd.provider, ''))) = ?
            LIMIT 1
        ");
        $stmt->execute([$scholarshipId, $normalizedOrganization]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('providerCanAccessApplication')) {
    function providerCanAccessApplication(PDO $pdo, int $applicationId): bool
    {
        $scope = getCurrentProviderScope($pdo);
        if (empty($scope['is_provider'])) {
            return true;
        }

        $normalizedOrganization = trim((string) ($scope['normalized_organization_name'] ?? ''));
        if ($applicationId <= 0 || $normalizedOrganization === '') {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM applications a
            JOIN scholarships s ON a.scholarship_id = s.id
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            WHERE a.id = ?
              AND LOWER(TRIM(COALESCE(sd.provider, ''))) = ?
            LIMIT 1
        ");
        $stmt->execute([$applicationId, $normalizedOrganization]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('providerCanAccessDocument')) {
    function providerCanAccessDocument(PDO $pdo, int $documentId, int $userId): bool
    {
        $scope = getCurrentProviderScope($pdo);
        if (empty($scope['is_provider'])) {
            return true;
        }

        $normalizedOrganization = trim((string) ($scope['normalized_organization_name'] ?? ''));
        if ($documentId <= 0 || $userId <= 0 || $normalizedOrganization === '') {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM user_documents ud
            WHERE ud.id = ?
              AND ud.user_id = ?
              AND EXISTS (
                    SELECT 1
                    FROM applications a
                    JOIN scholarships s ON a.scholarship_id = s.id
                    LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
                    WHERE a.user_id = ud.user_id
                      AND LOWER(TRIM(COALESCE(sd.provider, ''))) = ?
              )
            LIMIT 1
        ");
        $stmt->execute([$documentId, $userId, $normalizedOrganization]);

        return (bool) $stmt->fetchColumn();
    }
}
