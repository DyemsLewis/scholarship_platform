<?php
require_once __DIR__ . '/session_bootstrap.php';

if (!function_exists('csrfStorageKey')) {
    function csrfStorageKey(string $scope = 'default'): string
    {
        $normalizedScope = strtolower(trim($scope));
        if ($normalizedScope === '') {
            $normalizedScope = 'default';
        }

        return '_csrf_' . preg_replace('/[^a-z0-9_]+/', '_', $normalizedScope);
    }
}

if (!function_exists('csrfGetToken')) {
    function csrfGetToken(string $scope = 'default'): string
    {
        $storageKey = csrfStorageKey($scope);
        $current = $_SESSION[$storageKey] ?? null;
        if (is_string($current) && preg_match('/^[a-f0-9]{64}$/', $current)) {
            return $current;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[$storageKey] = $token;
        return $token;
    }
}

if (!function_exists('csrfInputField')) {
    function csrfInputField(string $scope = 'default', string $fieldName = 'csrf_token'): string
    {
        return '<input type="hidden" name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8')
            . '" value="' . htmlspecialchars(csrfGetToken($scope), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrfRequestToken')) {
    function csrfRequestToken(): string
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return trim((string) $token);
    }
}

if (!function_exists('csrfIsSameOriginRequest')) {
    function csrfIsSameOriginRequest(): bool
    {
        if (appSecurityIsCli()) {
            return true;
        }

        $currentHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if ($currentHost === '') {
            return true;
        }

        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $source = $origin !== '' ? $origin : $referer;

        if ($source === '') {
            return true;
        }

        $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        if ($sourceHost === '') {
            return false;
        }

        $currentHostName = strtolower((string) parse_url((appSecurityIsHttps() ? 'https://' : 'http://') . $currentHost, PHP_URL_HOST));
        if ($currentHostName === '') {
            $currentHostName = strtolower(preg_replace('/:\d+$/', '', $currentHost));
        }

        return $sourceHost === $currentHostName;
    }
}

if (!function_exists('csrfValidateRequest')) {
    function csrfValidateRequest(string $scope = 'default', ?string $providedToken = null): array
    {
        if (!csrfIsSameOriginRequest()) {
            return [
                'valid' => false,
                'message' => 'Cross-site request blocked.',
            ];
        }

        $requestToken = $providedToken !== null ? trim((string) $providedToken) : csrfRequestToken();
        $expectedToken = $_SESSION[csrfStorageKey($scope)] ?? '';

        if (
            $requestToken === ''
            || !is_string($expectedToken)
            || $expectedToken === ''
            || !hash_equals($expectedToken, $requestToken)
        ) {
            return [
                'valid' => false,
                'message' => 'Invalid or expired security token.',
            ];
        }

        return [
            'valid' => true,
            'message' => '',
        ];
    }
}
