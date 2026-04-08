<?php
require_once __DIR__ . '/password_policy.php';

if (!function_exists('appBasePath')) {
    function appBasePath(): string {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return '';
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($scriptDir === '.' || $scriptDir === '/') {
            $scriptDir = '';
        }

        $knownSuffixes = [
            '/app/AdminControllers',
            '/app/Controllers',
            '/app/Config',
            '/app/Models',
            '/AdminView/layouts',
            '/View/layout',
            '/View/partials',
            '/AdminView',
            '/View',
            '/AdminController',
            '/Controller',
            '/Config',
            '/Model',
            '/public',
            '/AdminPublic',
        ];

        foreach ($knownSuffixes as $suffix) {
            if ($scriptDir !== '' && str_ends_with($scriptDir, $suffix)) {
                $scriptDir = substr($scriptDir, 0, -strlen($suffix));
                break;
            }
        }

        return rtrim($scriptDir, '/');
    }
}

if (!function_exists('appUrl')) {
    function appUrl(string $projectRelativePath = ''): string {
        $normalized = trim(str_replace('\\', '/', $projectRelativePath));
        $basePath = appBasePath();

        if ($normalized === '' || $normalized === '/') {
            return $basePath !== '' ? $basePath : '/';
        }

        $normalized = ltrim($normalized, '/');
        $url = ($basePath !== '' ? $basePath : '') . '/' . $normalized;
        $url = preg_replace('#/+#', '/', $url);

        return $url !== '' ? $url : '/';
    }
}

if (!function_exists('normalizeAppUrl')) {
    function normalizeAppUrl(?string $path, string $defaultPath = 'View/index.php'): string {
        $value = trim((string) ($path ?? ''));
        if ($value === '') {
            $value = $defaultPath;
        }

        $value = str_replace('\\', '/', $value);

        if (preg_match('~^(?:https?:)?//~i', $value)) {
            return $value;
        }

        if (str_starts_with($value, '/')) {
            return preg_replace('#/+#', '/', $value);
        }

        $fragment = '';
        $query = '';

        $fragmentPosition = strpos($value, '#');
        if ($fragmentPosition !== false) {
            $fragment = substr($value, $fragmentPosition);
            $value = substr($value, 0, $fragmentPosition);
        }

        $queryPosition = strpos($value, '?');
        if ($queryPosition !== false) {
            $query = substr($value, $queryPosition);
            $value = substr($value, 0, $queryPosition);
        }

        while (str_starts_with($value, '../')) {
            $value = substr($value, 3);
        }

        while (str_starts_with($value, './')) {
            $value = substr($value, 2);
        }

        return appUrl($value) . $query . $fragment;
    }
}

if (!function_exists('assetUrl')) {
    function assetUrl(string $projectRelativePath, bool $versioned = true): string {
        $normalized = ltrim(str_replace('\\', '/', $projectRelativePath), '/');
        $basePath = appBasePath();
        $url = ($basePath !== '' ? $basePath : '') . '/' . $normalized;
        $url = preg_replace('#/+#', '/', $url);

        if (!$versioned) {
            return $url;
        }

        $diskPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $version = is_file($diskPath) ? (string) (@filemtime($diskPath) ?: time()) : (string) time();

        return $url . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('getUserInitials')) {
    function getUserInitials($name) {
        if (empty($name)) return 'U';
        $words = explode(' ', trim($name));
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[count($words)-1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }
}

if (!function_exists('getUserFullName')) {
    function getUserFullName($firstName, $middleInitial, $lastName) {
        $name = trim($firstName);
        if (!empty($middleInitial)) {
            $name .= ' ' . trim($middleInitial);
        }
        if (!empty($lastName)) {
            $name .= ' ' . trim($lastName);
        }
        return $name;
    }
}

if (!function_exists('formatGWAWithStatus')) {
    function formatGWAWithStatus($gwa) {
        if (!$gwa || $gwa == 0) return ['formatted' => '—', 'status' => 'Not set'];
        
        $formatted = number_format((float)$gwa, 2);
        
        if ($gwa <= 1.5) {
            $status = 'Excellent';
        } elseif ($gwa <= 1.75) {
            $status = 'Very Good';
        } elseif ($gwa <= 2.0) {
            $status = 'Good';
        } elseif ($gwa <= 2.5) {
            $status = 'Satisfactory';
        } else {
            $status = 'Needs Improvement';
        }
        
        return [
            'formatted' => $formatted,
            'status' => $status
        ];
    }
}

if (!function_exists('formatDateJoined')) {
    function formatDateJoined($dateString) {
        if (!$dateString) return 'N/A';
        return date('F Y', strtotime($dateString));
    }
}

if (!function_exists('formatBirthdate')) {
    function formatBirthdate($birthdate) {
        if (!$birthdate) return 'Not set';
        return date('F j, Y', strtotime($birthdate));
    }
}

if (!function_exists('getAgeFromBirthdate')) {
    function getAgeFromBirthdate($birthdate) {
        if (!$birthdate) return null;
        $birth = new DateTime($birthdate);
        $today = new DateTime();
        $age = $today->diff($birth);
        return $age->y;
    }
}

if (!function_exists('formatDocumentStatus')) {
    function formatDocumentStatus($status) {
        $status = strtolower($status);
        if ($status === 'verified') {
            return '<span class="badge verified"><i class="fas fa-check-circle"></i> Verified</span>';
        } elseif ($status === 'pending') {
            return '<span class="badge pending"><i class="fas fa-clock"></i> Pending</span>';
        } elseif ($status === 'rejected') {
            return '<span class="badge rejected"><i class="fas fa-times-circle"></i> Rejected</span>';
        }
        return '<span class="badge">Unknown</span>';
    }
}

if (!function_exists('formatApplicantTypeLabel')) {
    function formatApplicantTypeLabel($value) {
        $map = [
            'incoming_freshman' => 'Incoming Freshman',
            'current_college' => 'Current College Student',
            'transferee' => 'Transferee',
            'continuing_student' => 'Continuing Student',
            'all' => 'All Applicants'
        ];

        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return 'Not set';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('formatAdmissionStatusLabel')) {
    function formatAdmissionStatusLabel($value) {
        $map = [
            'not_yet_applied' => 'Not Yet Applied',
            'applied' => 'Applied',
            'admitted' => 'Admitted',
            'enrolled' => 'Enrolled',
            'any' => 'Any Admission Status'
        ];

        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return 'Not set';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('formatYearLevelLabel')) {
    function formatYearLevelLabel($value) {
        $map = [
            '1st_year' => '1st Year',
            '2nd_year' => '2nd Year',
            '3rd_year' => '3rd Year',
            '4th_year' => '4th Year',
            '5th_year_plus' => '5th Year+',
            'any' => 'Any Year Level'
        ];

        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return 'Not set';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('formatEnrollmentStatusLabel')) {
    function formatEnrollmentStatusLabel($value) {
        $map = [
            'currently_enrolled' => 'Currently Enrolled',
            'regular' => 'Regular',
            'irregular' => 'Irregular',
            'leave_of_absence' => 'Leave of Absence'
        ];

        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return 'Not set';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('formatAcademicStandingLabel')) {
    function formatAcademicStandingLabel($value) {
        $map = [
            'good_standing' => 'Good Standing',
            'deans_list' => "Dean's List",
            'probationary' => 'Probationary',
            'graduating' => 'Graduating'
        ];

        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return 'Not set';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('formatCitizenshipLabel')) {
    function formatCitizenshipLabel($value) {
        $map = [
            'all' => 'All Citizenship Types',
            'filipino' => 'Filipino',
            'dual_citizen' => 'Dual Citizen',
            'permanent_resident' => 'Permanent Resident',
            'other' => 'Other'
        ];

        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return 'Not set';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('formatHouseholdIncomeBracketLabel')) {
    function formatHouseholdIncomeBracketLabel($value) {
        $map = [
            'any' => 'Any Income Bracket',
            'below_10000' => 'Below PHP 10,000 / month',
            '10000_20000' => 'PHP 10,000 - 20,000 / month',
            '20001_40000' => 'PHP 20,001 - 40,000 / month',
            '40001_80000' => 'PHP 40,001 - 80,000 / month',
            'above_80000' => 'Above PHP 80,000 / month',
            'prefer_not_to_say' => 'Prefer not to say'
        ];

        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return 'Not set';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('formatSpecialCategoryLabel')) {
    function formatSpecialCategoryLabel($value) {
        $map = [
            'any' => 'Any Special Category',
            'none' => 'None declared',
            'pwd' => 'Person with Disability (PWD)',
            'indigenous_peoples' => 'Indigenous Peoples',
            'solo_parent_dependent' => 'Dependent of Solo Parent',
            'working_student' => 'Working Student',
            'child_of_ofw' => 'Child of OFW',
            'four_ps_beneficiary' => '4Ps Beneficiary',
            'orphan' => 'Orphan / Ward'
        ];

        $key = strtolower(trim((string) $value));
        if ($key === '') {
            return 'Not set';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('resolveStoredFileUrl')) {
    function normalizeStoredProjectFilePath(?string $storedPath, array $baseDirectories = []): ?string {
        $rawPath = trim((string) ($storedPath ?? ''));
        if ($rawPath === '' || preg_match('~^https?://~i', $rawPath)) {
            return null;
        }

        $normalizedPath = preg_replace('#/+#', '/', str_replace('\\', '/', $rawPath));
        $projectRoot = str_replace('\\', '/', dirname(__DIR__));
        $candidates = [];

        $pushCandidate = static function (string $candidate) use (&$candidates): void {
            $cleaned = ltrim(preg_replace('#/+#', '/', str_replace('\\', '/', trim($candidate))), '/');
            if ($cleaned === '' || strpos($cleaned, '..') !== false || in_array($cleaned, $candidates, true)) {
                return;
            }

            $candidates[] = $cleaned;
        };

        $isAbsoluteWindowsPath = (bool) preg_match('~^[A-Za-z]:/~', $normalizedPath);
        $isAbsoluteUnixPath = str_starts_with($normalizedPath, '/');
        $isAbsolutePath = $isAbsoluteWindowsPath || $isAbsoluteUnixPath;

        if ($isAbsolutePath) {
            if (
                stripos($normalizedPath, $projectRoot . '/') === 0
                || strcasecmp($normalizedPath, $projectRoot) === 0
            ) {
                $pushCandidate(substr($normalizedPath, strlen($projectRoot)));
            }
        } else {
            $pushCandidate($normalizedPath);
        }

        $knownMarkers = [
            'public/uploads/',
            'uploads/',
            'public/temp/',
            'temp/',
            'storage/',
        ];

        foreach ($knownMarkers as $marker) {
            $position = stripos($normalizedPath, $marker);
            if ($position === false) {
                continue;
            }

            $candidate = substr($normalizedPath, $position);
            if (str_starts_with($candidate, 'uploads/')) {
                $candidate = 'public/' . $candidate;
            } elseif (str_starts_with($candidate, 'temp/')) {
                $candidate = 'public/' . $candidate;
            }

            $pushCandidate($candidate);
        }

        $normalizedBaseDirectories = [];
        foreach ($baseDirectories as $baseDirectory) {
            $normalizedBase = trim(preg_replace('#/+#', '/', str_replace('\\', '/', (string) $baseDirectory)), '/');
            if ($normalizedBase !== '' && !in_array($normalizedBase, $normalizedBaseDirectories, true)) {
                $normalizedBaseDirectories[] = $normalizedBase;
            }
        }

        if (
            !$isAbsolutePath
            && !str_contains($normalizedPath, '/')
            && !empty($normalizedBaseDirectories)
        ) {
            foreach ($normalizedBaseDirectories as $baseDirectory) {
                $pushCandidate($baseDirectory . '/' . $normalizedPath);
            }
        }

        foreach ($candidates as $candidate) {
            $absolutePath = $projectRoot . '/' . $candidate;
            if (is_file($absolutePath)) {
                return $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('resolveStoredFileUrl')) {
    function resolveStoredFileUrl(?string $storedPath, string $prefix = '../'): ?string {
        $rawPath = trim((string) ($storedPath ?? ''));
        if ($rawPath === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $rawPath)) {
            return $rawPath;
        }

        $normalizedPath = normalizeStoredProjectFilePath($rawPath, ['public/uploads', 'public/temp', 'storage']);
        if ($normalizedPath === null) {
            return null;
        }

        $cleanPrefix = $prefix === '' ? '' : rtrim(str_replace('\\', '/', $prefix), '/') . '/';
        return preg_replace('#/+#', '/', $cleanPrefix . $normalizedPath);
    }
}

if (!function_exists('resolvePublicUploadUrl')) {
    function resolvePublicUploadUrl(?string $storedPath, string $prefix = '../', string $defaultRelativePath = 'public/uploads/scholarship-default.jpg'): string {
        $rawPath = trim((string) ($storedPath ?? ''));
        if ($rawPath !== '' && preg_match('~^https?://~i', $rawPath)) {
            return $rawPath;
        }

        $resolved = $rawPath !== ''
            ? resolveStoredFileUrl($rawPath, $prefix)
            : null;

        if ($resolved !== null) {
            return $resolved;
        }

        $fallbackPath = trim(str_replace('\\', '/', $defaultRelativePath), '/');
        $cleanPrefix = $prefix === '' ? '' : rtrim(str_replace('\\', '/', $prefix), '/') . '/';
        return preg_replace('#/+#', '/', $cleanPrefix . $fallbackPath);
    }
}

if (!function_exists('storedFilePreviewType')) {
    function storedFilePreviewType(?string $storedPath, ?string $fileName = null, ?string $mimeType = null): string {
        $candidate = trim((string) ($fileName ?? ''));
        if ($candidate === '') {
            $candidate = trim(str_replace('\\', '/', (string) ($storedPath ?? '')));
        }

        $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        $normalizedMime = strtolower(trim((string) ($mimeType ?? '')));

        if ($extension === 'pdf' || $normalizedMime === 'application/pdf') {
            return 'pdf';
        }

        if (
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)
            || str_starts_with($normalizedMime, 'image/')
        ) {
            return 'image';
        }

        return 'document';
    }
}
?>
