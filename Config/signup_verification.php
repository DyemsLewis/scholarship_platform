<?php

if (!defined('SIGNUP_VERIFICATION_CODE_TTL')) {
    define('SIGNUP_VERIFICATION_CODE_TTL', 600);
}

if (!defined('SIGNUP_VERIFICATION_VERIFIED_TTL')) {
    define('SIGNUP_VERIFICATION_VERIFIED_TTL', 1800);
}

if (!defined('SIGNUP_VERIFICATION_RESEND_COOLDOWN')) {
    define('SIGNUP_VERIFICATION_RESEND_COOLDOWN', 60);
}

if (!defined('SIGNUP_VERIFICATION_MAX_ATTEMPTS')) {
    define('SIGNUP_VERIFICATION_MAX_ATTEMPTS', 5);
}

if (!defined('SIGNUP_VERIFICATION_SESSION_KEY')) {
    define('SIGNUP_VERIFICATION_SESSION_KEY', 'signup_verification_verified');
}

if (!function_exists('normalizeSignupVerificationEmail')) {
    function normalizeSignupVerificationEmail($email) {
        return strtolower(trim((string) $email));
    }
}

if (!function_exists('signupVerificationTimestamp')) {
    function signupVerificationTimestamp(int $offsetSeconds = 0): string {
        return date('Y-m-d H:i:s', time() + $offsetSeconds);
    }
}

if (!function_exists('getSignupVerificationSessionState')) {
    function getSignupVerificationSessionState(): array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }

        $state = $_SESSION[SIGNUP_VERIFICATION_SESSION_KEY] ?? [];
        return is_array($state) ? $state : [];
    }
}

if (!function_exists('rememberSignupVerifiedEmail')) {
    function rememberSignupVerifiedEmail($email): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $normalizedEmail = normalizeSignupVerificationEmail($email);
        if ($normalizedEmail === '') {
            return;
        }

        $_SESSION['signup_verification_last_email'] = $normalizedEmail;
        $_SESSION[SIGNUP_VERIFICATION_SESSION_KEY] = [
            'email' => $normalizedEmail,
            'verified_at' => time(),
            'expires_at' => time() + SIGNUP_VERIFICATION_VERIFIED_TTL,
        ];
    }
}

if (!function_exists('clearSignupVerificationSession')) {
    function clearSignupVerificationSession($email = null): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if ($email === null || $email === '') {
            unset($_SESSION['signup_verification_last_email'], $_SESSION[SIGNUP_VERIFICATION_SESSION_KEY]);
            return;
        }

        $normalizedEmail = normalizeSignupVerificationEmail($email);
        $state = getSignupVerificationSessionState();
        if (!empty($state['email']) && hash_equals((string) $state['email'], $normalizedEmail)) {
            unset($_SESSION[SIGNUP_VERIFICATION_SESSION_KEY]);
        }

        $lastEmail = normalizeSignupVerificationEmail($_SESSION['signup_verification_last_email'] ?? '');
        if ($lastEmail !== '' && hash_equals($lastEmail, $normalizedEmail)) {
            unset($_SESSION['signup_verification_last_email']);
        }
    }
}

if (!function_exists('isSignupEmailVerifiedInSession')) {
    function isSignupEmailVerifiedInSession($email): bool {
        $normalizedEmail = normalizeSignupVerificationEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        $state = getSignupVerificationSessionState();
        if (empty($state['email']) || empty($state['expires_at'])) {
            return false;
        }

        if (!hash_equals((string) $state['email'], $normalizedEmail)) {
            return false;
        }

        if ((int) $state['expires_at'] < time()) {
            clearSignupVerificationSession($normalizedEmail);
            return false;
        }

        return true;
    }
}

if (!function_exists('signupVerificationTableExists')) {
    function signupVerificationTableExists(PDO $pdo): bool {
        static $tableExists = null;
        if ($tableExists !== null) {
            return $tableExists;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'signup_verifications'
            ");
            $stmt->execute();
        } catch (PDOException $e) {
            error_log('Signup verification table check failed: ' . $e->getMessage());
            $tableExists = false;
            return false;
        }

        $tableExists = ((int) $stmt->fetchColumn()) > 0;
        return $tableExists;
    }
}

if (!function_exists('signupVerificationColumnExists')) {
    function signupVerificationColumnExists(PDO $pdo, string $columnName): bool {
        static $cache = [];
        $cacheKey = 'signup_verifications.' . $columnName;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'signup_verifications'
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute([':column_name' => $columnName]);
        } catch (PDOException $e) {
            error_log('Signup verification column check failed for ' . $columnName . ': ' . $e->getMessage());
            $cache[$cacheKey] = false;
            return false;
        }

        $cache[$cacheKey] = ((int) $stmt->fetchColumn()) > 0;
        return $cache[$cacheKey];
    }
}

if (!function_exists('signupVerificationTableReady')) {
    function signupVerificationTableReady(PDO $pdo): bool {
        if (!signupVerificationTableExists($pdo)) {
            return false;
        }

        $requiredColumns = [
            'email',
            'code_hash',
            'verified',
            'attempts',
            'sent_at',
            'expires_at',
            'verified_at',
        ];

        foreach ($requiredColumns as $columnName) {
            if (!signupVerificationColumnExists($pdo, $columnName)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('getSignupVerificationState')) {
    function getSignupVerificationState(PDO $pdo, $email = null): array {
        if (!signupVerificationTableReady($pdo)) {
            return [];
        }

        $normalizedEmail = normalizeSignupVerificationEmail($email);
        if ($normalizedEmail === '') {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    email,
                    code_hash,
                    verified,
                    attempts,
                    sent_at,
                    expires_at,
                    verified_at
                FROM signup_verifications
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute([':email' => $normalizedEmail]);
            $state = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Failed to fetch signup verification state for ' . $normalizedEmail . ': ' . $e->getMessage());
            return [];
        }

        return is_array($state) ? $state : [];
    }
}

if (!function_exists('clearSignupVerification')) {
    function clearSignupVerification(PDO $pdo, $email = null): bool {
        if (!signupVerificationTableReady($pdo)) {
            return false;
        }

        $normalizedEmail = normalizeSignupVerificationEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                DELETE FROM signup_verifications
                WHERE email = :email
                LIMIT 1
            ");
            $deleted = $stmt->execute([':email' => $normalizedEmail]);
            clearSignupVerificationSession($normalizedEmail);
            return $deleted;
        } catch (PDOException $e) {
            error_log('Failed to clear signup verification for ' . $normalizedEmail . ': ' . $e->getMessage());
            clearSignupVerificationSession($normalizedEmail);
            return false;
        }
    }
}

if (!function_exists('getSignupVerificationCooldown')) {
    function getSignupVerificationCooldown(PDO $pdo, $email = null): int {
        $state = getSignupVerificationState($pdo, $email);
        if (empty($state['sent_at'])) {
            return 0;
        }

        $sentAtTimestamp = strtotime((string) $state['sent_at']);
        if ($sentAtTimestamp === false) {
            return 0;
        }

        $remaining = ($sentAtTimestamp + SIGNUP_VERIFICATION_RESEND_COOLDOWN) - time();
        return max(0, $remaining);
    }
}

if (!function_exists('storeSignupVerificationCode')) {
    function storeSignupVerificationCode(PDO $pdo, $email, $code): bool {
        if (!signupVerificationTableReady($pdo)) {
            return false;
        }

        $normalizedEmail = normalizeSignupVerificationEmail($email);
        $normalizedCode = trim((string) $code);
        if ($normalizedEmail === '' || $normalizedCode === '') {
            return false;
        }

        try {
            $sentAt = signupVerificationTimestamp();
            $expiresAt = signupVerificationTimestamp((int) SIGNUP_VERIFICATION_CODE_TTL);

            $stmt = $pdo->prepare("
                INSERT INTO signup_verifications (
                    email,
                    code_hash,
                    verified,
                    attempts,
                    sent_at,
                    expires_at,
                    verified_at
                )
                VALUES (
                    :email,
                    :code_hash,
                    0,
                    0,
                    :sent_at,
                    :expires_at,
                    NULL
                )
                ON DUPLICATE KEY UPDATE
                    code_hash = VALUES(code_hash),
                    verified = 0,
                    attempts = 0,
                    sent_at = VALUES(sent_at),
                    expires_at = VALUES(expires_at),
                    verified_at = NULL
            ");

            clearSignupVerificationSession($normalizedEmail);

            return $stmt->execute([
                ':email' => $normalizedEmail,
                ':code_hash' => hash('sha256', $normalizedCode),
                ':sent_at' => $sentAt,
                ':expires_at' => $expiresAt,
            ]);
        } catch (PDOException $e) {
            error_log('Failed to store signup verification code for ' . $normalizedEmail . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('markSignupEmailVerified')) {
    function markSignupEmailVerified(PDO $pdo, $email): bool {
        if (!signupVerificationTableReady($pdo)) {
            return false;
        }

        $normalizedEmail = normalizeSignupVerificationEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        try {
            $verifiedAt = signupVerificationTimestamp();
            $expiresAt = signupVerificationTimestamp((int) SIGNUP_VERIFICATION_VERIFIED_TTL);

            $stmt = $pdo->prepare("
                INSERT INTO signup_verifications (
                    email,
                    code_hash,
                    verified,
                    attempts,
                    sent_at,
                    expires_at,
                    verified_at
                )
                VALUES (
                    :email,
                    NULL,
                    1,
                    0,
                    NULL,
                    :expires_at,
                    :verified_at
                )
                ON DUPLICATE KEY UPDATE
                    code_hash = NULL,
                    verified = 1,
                    attempts = 0,
                    sent_at = NULL,
                    expires_at = VALUES(expires_at),
                    verified_at = VALUES(verified_at)
            ");

            $verified = $stmt->execute([
                ':email' => $normalizedEmail,
                ':expires_at' => $expiresAt,
                ':verified_at' => $verifiedAt,
            ]);

            if ($verified) {
                rememberSignupVerifiedEmail($normalizedEmail);
            }

            return $verified;
        } catch (PDOException $e) {
            error_log('Failed to mark signup email verified for ' . $normalizedEmail . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('isSignupEmailVerified')) {
    function isSignupEmailVerified(PDO $pdo, $email): bool {
        $normalizedEmail = normalizeSignupVerificationEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        if (isSignupEmailVerifiedInSession($normalizedEmail)) {
            return true;
        }

        $state = getSignupVerificationState($pdo, $normalizedEmail);

        if (empty($state['verified']) || empty($state['email'])) {
            return false;
        }

        if (!hash_equals($state['email'], $normalizedEmail)) {
            return false;
        }

        $expiresAtTimestamp = strtotime((string) ($state['expires_at'] ?? ''));
        if ($expiresAtTimestamp === false || time() > $expiresAtTimestamp) {
            clearSignupVerification($pdo, $normalizedEmail);
            return false;
        }

        rememberSignupVerifiedEmail($normalizedEmail);
        return true;
    }
}

if (!function_exists('verifySignupCode')) {
    function verifySignupCode(PDO $pdo, $email, $code, &$message = null): bool {
        if (!signupVerificationTableReady($pdo)) {
            $message = 'Signup verification database table is missing. Please run the migration first.';
            return false;
        }

        $state = getSignupVerificationState($pdo, $email);
        $normalizedEmail = normalizeSignupVerificationEmail($email);
        $normalizedCode = trim((string) $code);

        if (empty($state['email']) || empty($state['code_hash']) || empty($state['expires_at'])) {
            $message = 'Please request a verification code first.';
            return false;
        }

        if (!hash_equals($state['email'], $normalizedEmail)) {
            $message = 'The code does not match the email you are trying to verify.';
            return false;
        }

        $expiresAtTimestamp = strtotime((string) $state['expires_at']);
        if ($expiresAtTimestamp === false || time() > $expiresAtTimestamp) {
            clearSignupVerification($pdo, $normalizedEmail);
            $message = 'Your verification code has expired. Please request a new one.';
            return false;
        }

        $attempts = (int) ($state['attempts'] ?? 0);
        if ($attempts >= SIGNUP_VERIFICATION_MAX_ATTEMPTS) {
            clearSignupVerification($pdo, $normalizedEmail);
            $message = 'Too many incorrect attempts. Please request a new verification code.';
            return false;
        }

        if (!hash_equals($state['code_hash'], hash('sha256', $normalizedCode))) {
            $attemptStmt = $pdo->prepare("
                UPDATE signup_verifications
                SET attempts = COALESCE(attempts, 0) + 1
                WHERE email = :email
                LIMIT 1
            ");
            $attemptStmt->execute([':email' => $normalizedEmail]);
            $message = 'Invalid verification code.';
            return false;
        }

        if (!markSignupEmailVerified($pdo, $normalizedEmail)) {
            $message = 'Unable to mark email as verified.';
            return false;
        }

        rememberSignupVerifiedEmail($normalizedEmail);
        return true;
    }
}
?>
