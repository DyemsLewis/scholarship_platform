<?php

if (!function_exists('appSecurityIsCli')) {
    function appSecurityIsCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}

if (!function_exists('appSecurityIsHttps')) {
    function appSecurityIsHttps(): bool
    {
        if (appSecurityIsCli()) {
            return false;
        }

        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $forwardedSsl = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
        $port = (string) ($_SERVER['SERVER_PORT'] ?? '');

        return $https === 'on'
            || $https === '1'
            || $forwardedProto === 'https'
            || $forwardedSsl === 'on'
            || $port === '443';
    }
}

if (!function_exists('appSecurityDebugMode')) {
    function appSecurityDebugMode(): bool
    {
        $envValue = getenv('APP_DEBUG');
        if ($envValue !== false && $envValue !== '') {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }

        if (appSecurityIsCli()) {
            return true;
        }

        $serverName = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
        $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return in_array($serverName, ['localhost', '127.0.0.1', '::1'], true)
            || in_array($remoteAddr, ['127.0.0.1', '::1'], true);
    }
}

if (!function_exists('appSecurityApplyRuntimeSettings')) {
    function appSecurityApplyRuntimeSettings(): void
    {
        error_reporting(E_ALL);
        ini_set('log_errors', '1');
        ini_set('display_errors', appSecurityDebugMode() ? '1' : '0');
        ini_set('display_startup_errors', appSecurityDebugMode() ? '1' : '0');
        ini_set('expose_php', '0');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
        }
    }
}

if (!function_exists('appSecurityApplyHeaders')) {
    function appSecurityApplyHeaders(): void
    {
        if (appSecurityIsCli() || headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
        
        // CORS Configuration for Flutter Web and mobile fallback endpoints
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, x-csrf-token, cache-control');
        header('Access-Control-Allow-Credentials: true');
        
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}

if (!function_exists('appSecurityStartSession')) {
    function appSecurityStartSession(): void
    {
        appSecurityApplyRuntimeSettings();
        appSecurityApplyHeaders();

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!appSecurityIsCli() && !headers_sent()) {
            session_name('SCHOLARSHIPSESSID');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => appSecurityIsHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_start();
    }
}

appSecurityStartSession();
