<?php

if (!function_exists('getAdminSuffixOptions')) {
    function getAdminSuffixOptions(): array
    {
        return [
            '' => 'No suffix',
            'Jr.' => 'Jr.',
            'Sr.' => 'Sr.',
            'II' => 'II',
            'III' => 'III',
            'IV' => 'IV',
            'V' => 'V'
        ];
    }
}

if (!function_exists('getAdminPositionOptions')) {
    function getAdminPositionOptions(): array
    {
        return [
            'Scholarship Operations Administrator' => 'Scholarship Operations Administrator',
            'User Management Administrator' => 'User Management Administrator',
            'Document Review Administrator' => 'Document Review Administrator',
            'Reports and Analytics Administrator' => 'Reports and Analytics Administrator',
            'Content Administrator' => 'Content Administrator',
            'System Administrator' => 'System Administrator',
            'Compliance Officer' => 'Compliance Officer',
            'Program Coordinator' => 'Program Coordinator',
            'Super Administrator' => 'Super Administrator'
        ];
    }
}

if (!function_exists('getDefaultAdminPositionLabel')) {
    function getDefaultAdminPositionLabel(string $role = 'admin'): string
    {
        return strtolower(trim($role)) === 'super_admin'
            ? 'Super Administrator'
            : 'Scholarship Operations Administrator';
    }
}

if (!function_exists('getAdminPositionProfiles')) {
    function getAdminPositionProfiles(): array
    {
        return [
            'Scholarship Operations Administrator' => [
                'access_level' => 70,
                'can_manage_users' => false,
                'can_manage_scholarships' => true,
                'can_review_documents' => true,
                'can_view_reports' => false,
            ],
            'User Management Administrator' => [
                'access_level' => 70,
                'can_manage_users' => true,
                'can_manage_scholarships' => false,
                'can_review_documents' => false,
                'can_view_reports' => false,
            ],
            'Document Review Administrator' => [
                'access_level' => 60,
                'can_manage_users' => false,
                'can_manage_scholarships' => false,
                'can_review_documents' => true,
                'can_view_reports' => false,
            ],
            'Reports and Analytics Administrator' => [
                'access_level' => 60,
                'can_manage_users' => false,
                'can_manage_scholarships' => false,
                'can_review_documents' => false,
                'can_view_reports' => true,
            ],
            'Content Administrator' => [
                'access_level' => 60,
                'can_manage_users' => false,
                'can_manage_scholarships' => true,
                'can_review_documents' => false,
                'can_view_reports' => false,
            ],
            'System Administrator' => [
                'access_level' => 80,
                'can_manage_users' => true,
                'can_manage_scholarships' => true,
                'can_review_documents' => true,
                'can_view_reports' => true,
            ],
            'Compliance Officer' => [
                'access_level' => 70,
                'can_manage_users' => false,
                'can_manage_scholarships' => false,
                'can_review_documents' => true,
                'can_view_reports' => true,
            ],
            'Program Coordinator' => [
                'access_level' => 70,
                'can_manage_users' => false,
                'can_manage_scholarships' => true,
                'can_review_documents' => true,
                'can_view_reports' => false,
            ],
            'Super Administrator' => [
                'access_level' => 90,
                'can_manage_users' => true,
                'can_manage_scholarships' => true,
                'can_review_documents' => true,
                'can_view_reports' => true,
            ],
        ];
    }
}

if (!function_exists('getAdminDepartmentOptions')) {
    function getAdminDepartmentOptions(): array
    {
        return [
            'Scholarship Operations' => 'Scholarship Operations',
            'User Administration and Access Control' => 'User Administration and Access Control',
            'Document Verification and Compliance' => 'Document Verification and Compliance',
            'Reports and Analytics' => 'Reports and Analytics',
            'Content and Communications' => 'Content and Communications',
            'System Administration' => 'System Administration',
            'Executive Oversight' => 'Executive Oversight'
        ];
    }
}

if (!function_exists('getDefaultAdminDepartmentLabel')) {
    function getDefaultAdminDepartmentLabel(string $role = 'admin'): string
    {
        return strtolower(trim($role)) === 'super_admin'
            ? 'Executive Oversight'
            : 'Scholarship Operations';
    }
}

if (!function_exists('resolveAdminPositionProfile')) {
    function resolveAdminPositionProfile(string $role, ?string $position = null, ?int $fallbackLevel = null): array
    {
        $normalizedRole = strtolower(trim($role));
        $profiles = getAdminPositionProfiles();
        $defaultPosition = getDefaultAdminPositionLabel($normalizedRole);
        $resolvedPosition = trim((string) $position);

        if ($normalizedRole === 'super_admin') {
            $resolvedPosition = 'Super Administrator';
        } elseif ($resolvedPosition === '' || !isset($profiles[$resolvedPosition])) {
            $resolvedPosition = $defaultPosition;
        }

        $baseProfile = $profiles[$resolvedPosition] ?? $profiles[$defaultPosition] ?? [
            'access_level' => $fallbackLevel ?? 70,
            'can_manage_users' => false,
            'can_manage_scholarships' => true,
            'can_review_documents' => true,
            'can_view_reports' => false,
        ];

        $resolved = [
            'position' => $resolvedPosition,
            'access_level' => (int) ($baseProfile['access_level'] ?? ($fallbackLevel ?? 70)),
            'can_manage_users' => !empty($baseProfile['can_manage_users']),
            'can_manage_scholarships' => !empty($baseProfile['can_manage_scholarships']),
            'can_review_documents' => !empty($baseProfile['can_review_documents']),
            'can_view_reports' => !empty($baseProfile['can_view_reports']),
        ];

        if ($normalizedRole === 'super_admin') {
            $resolved['access_level'] = 90;
            $resolved['can_manage_users'] = true;
            $resolved['can_manage_scholarships'] = true;
            $resolved['can_review_documents'] = true;
            $resolved['can_view_reports'] = true;
        } elseif ($normalizedRole === 'admin') {
            // Account management remains reserved to super administrators.
            $resolved['can_manage_users'] = false;
        }

        return $resolved;
    }
}

if (!function_exists('getAdminAccessLevelOptions')) {
    function getAdminAccessLevelOptions(string $role = 'admin'): array
    {
        $normalizedRole = strtolower(trim($role));
        if ($normalizedRole === 'super_admin') {
            return [
                90 => '90 - Full system control'
            ];
        }

        return [
            60 => '60 - Reviewer access',
            70 => '70 - Operations access',
            80 => '80 - Senior administrator access'
        ];
    }
}

if (!function_exists('resolveAdminAccessLevel')) {
    function resolveAdminAccessLevel(string $role, $submittedLevel = null, ?int $fallback = null): int
    {
        $normalizedRole = strtolower(trim($role));
        $options = array_map('intval', array_keys(getAdminAccessLevelOptions($normalizedRole)));
        $defaultLevel = $normalizedRole === 'super_admin'
            ? 90
            : ($fallback !== null ? $fallback : 70);

        $value = is_numeric($submittedLevel) ? (int) $submittedLevel : $defaultLevel;

        if ($normalizedRole === 'super_admin') {
            return 90;
        }

        return in_array($value, $options, true) ? $value : $defaultLevel;
    }
}
