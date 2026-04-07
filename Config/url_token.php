<?php
require_once __DIR__ . '/access_control.php';

if (!function_exists('getUrlTokenSecret')) {
    function getUrlTokenSecret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        $envSecret = trim((string) getenv('SCHOLARSHIP_URL_TOKEN_SECRET'));
        if ($envSecret !== '') {
            $secret = $envSecret;
            return $secret;
        }

        $secret = hash('sha256', implode('|', [
            dirname(__DIR__),
            (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'),
            (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            (string) ($GLOBALS['dbname'] ?? ''),
            'scholarshipfinder-url-token'
        ]));

        return $secret;
    }
}

if (!function_exists('buildEntityUrlToken')) {
    function buildEntityUrlToken(string $entityType, int $entityId, string $intent = 'view'): string
    {
        $actorUserId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : 0;
        $actorRole = getCurrentSessionRole();

        $payload = implode('|', [
            strtolower(trim($entityType)),
            (string) $entityId,
            strtolower(trim($intent)),
            (string) $actorUserId,
            $actorRole
        ]);

        return hash_hmac('sha256', $payload, getUrlTokenSecret());
    }
}

if (!function_exists('isValidEntityUrlToken')) {
    function isValidEntityUrlToken(string $entityType, int $entityId, ?string $providedToken, string $intent = 'view'): bool
    {
        if ($entityId <= 0 || !isset($_SESSION['user_id'])) {
            return false;
        }

        $token = trim((string) $providedToken);
        if ($token === '') {
            return false;
        }

        return hash_equals(buildEntityUrlToken($entityType, $entityId, $intent), $token);
    }
}

if (!function_exists('buildEntityUrl')) {
    function buildEntityUrl(string $path, string $entityType, int $entityId, string $intent = 'view', array $query = []): string
    {
        if ($entityId <= 0) {
            return $path;
        }

        $query['token'] = buildEntityUrlToken($entityType, $entityId, $intent);
        $separator = strpos($path, '?') !== false ? '&' : '?';

        return $path . $separator . http_build_query($query);
    }
}

if (!function_exists('requireValidEntityUrlToken')) {
    function requireValidEntityUrlToken(
        string $entityType,
        int $entityId,
        ?string $providedToken,
        string $intent,
        string $redirectTo,
        string $errorMessage = 'Invalid or expired access link.'
    ): void {
        if (isValidEntityUrlToken($entityType, $entityId, $providedToken, $intent)) {
            return;
        }

        $_SESSION['error'] = $errorMessage;
        header('Location: ' . $redirectTo);
        exit();
    }
}
