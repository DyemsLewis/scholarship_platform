<?php

if (!defined('PASSWORD_RESET_CODE_TTL')) {
    define('PASSWORD_RESET_CODE_TTL', 600);
}

if (!defined('PASSWORD_RESET_RESEND_COOLDOWN')) {
    define('PASSWORD_RESET_RESEND_COOLDOWN', 60);
}

if (!defined('PASSWORD_RESET_MAX_ATTEMPTS')) {
    define('PASSWORD_RESET_MAX_ATTEMPTS', 5);
}

if (!function_exists('normalizePasswordResetEmail')) {
    function normalizePasswordResetEmail($email) {
        return strtolower(trim((string) $email));
    }
}

if (!function_exists('passwordResetTableHasColumn')) {
    function passwordResetTableHasColumn(PDO $pdo, string $columnName): bool {
        static $cache = [];
        $cacheKey = 'users.' . $columnName;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([':column_name' => $columnName]);

        $cache[$cacheKey] = ((int) $stmt->fetchColumn()) > 0;
        return $cache[$cacheKey];
    }
}

if (!function_exists('passwordResetColumnsReady')) {
    function passwordResetColumnsReady(PDO $pdo): bool {
        $requiredColumns = [
            'password_reset_code_hash',
            'password_reset_attempts',
            'password_reset_sent_at',
            'password_reset_expires_at',
        ];

        foreach ($requiredColumns as $columnName) {
            if (!passwordResetTableHasColumn($pdo, $columnName)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('getPasswordResetState')) {
    function getPasswordResetState(PDO $pdo, $email = null, $userId = null) {
        if (!passwordResetColumnsReady($pdo)) {
            return [];
        }

        $whereClause = '';
        $bindings = [];

        if (is_numeric($userId) && (int) $userId > 0) {
            $whereClause = 'u.id = :user_id';
            $bindings[':user_id'] = (int) $userId;
        } else {
            $normalizedEmail = normalizePasswordResetEmail($email);
            if ($normalizedEmail === '') {
                return [];
            }

            $whereClause = 'LOWER(u.email) = :email';
            $bindings[':email'] = $normalizedEmail;
        }

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.email,
                u.status,
                u.password_reset_code_hash,
                u.password_reset_attempts,
                u.password_reset_sent_at,
                u.password_reset_expires_at
            FROM users u
            WHERE {$whereClause}
            LIMIT 1
        ");
        $stmt->execute($bindings);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($state) ? $state : [];
    }
}

if (!function_exists('clearPasswordReset')) {
    function clearPasswordReset(PDO $pdo, $email = null, $userId = null): bool {
        if (!passwordResetColumnsReady($pdo)) {
            return false;
        }

        $whereClause = '';
        $bindings = [];

        if (is_numeric($userId) && (int) $userId > 0) {
            $whereClause = 'id = :user_id';
            $bindings[':user_id'] = (int) $userId;
        } else {
            $normalizedEmail = normalizePasswordResetEmail($email);
            if ($normalizedEmail === '') {
                return false;
            }

            $whereClause = 'LOWER(email) = :email';
            $bindings[':email'] = $normalizedEmail;
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                password_reset_code_hash = NULL,
                password_reset_attempts = 0,
                password_reset_sent_at = NULL,
                password_reset_expires_at = NULL
            WHERE {$whereClause}
            LIMIT 1
        ");

        return $stmt->execute($bindings);
    }
}

if (!function_exists('getPasswordResetCooldown')) {
    function getPasswordResetCooldown(PDO $pdo, $email = null): int {
        $state = getPasswordResetState($pdo, $email);
        if (empty($state['password_reset_sent_at'])) {
            return 0;
        }

        $sentAtTimestamp = strtotime((string) $state['password_reset_sent_at']);
        if ($sentAtTimestamp === false) {
            return 0;
        }

        $remaining = ($sentAtTimestamp + PASSWORD_RESET_RESEND_COOLDOWN) - time();
        return max(0, $remaining);
    }
}

if (!function_exists('storePasswordResetCode')) {
    function storePasswordResetCode(PDO $pdo, $email, $userId, $code): bool {
        if (!passwordResetColumnsReady($pdo)) {
            return false;
        }

        $normalizedEmail = normalizePasswordResetEmail($email);
        $normalizedCode = trim((string) $code);
        if ($normalizedEmail === '' || $normalizedCode === '' || !is_numeric($userId) || (int) $userId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                password_reset_code_hash = :code_hash,
                password_reset_attempts = 0,
                password_reset_sent_at = NOW(),
                password_reset_expires_at = DATE_ADD(NOW(), INTERVAL :ttl SECOND)
            WHERE id = :user_id
              AND LOWER(email) = :email
              AND status = 'active'
            LIMIT 1
        ");

        $executed = $stmt->execute([
            ':code_hash' => hash('sha256', $normalizedCode),
            ':ttl' => (int) PASSWORD_RESET_CODE_TTL,
            ':user_id' => (int) $userId,
            ':email' => $normalizedEmail,
        ]);

        return $executed && $stmt->rowCount() > 0;
    }
}

if (!function_exists('hasActivePasswordResetCode')) {
    function hasActivePasswordResetCode(PDO $pdo, $email = null): bool {
        $state = getPasswordResetState($pdo, $email);
        if (empty($state['email']) || empty($state['password_reset_code_hash']) || empty($state['password_reset_expires_at'])) {
            return false;
        }

        $expiresAtTimestamp = strtotime((string) $state['password_reset_expires_at']);
        if ($expiresAtTimestamp === false || time() > $expiresAtTimestamp) {
            clearPasswordReset($pdo, $state['email'], (int) ($state['id'] ?? 0));
            return false;
        }

        if (($state['status'] ?? 'inactive') !== 'active') {
            clearPasswordReset($pdo, $state['email'], (int) ($state['id'] ?? 0));
            return false;
        }

        return true;
    }
}

if (!function_exists('verifyPasswordResetCode')) {
    function verifyPasswordResetCode(PDO $pdo, $email, $code, &$message = null): int {
        if (!passwordResetColumnsReady($pdo)) {
            $message = 'Password reset database fields are missing. Please run the migration first.';
            return 0;
        }

        $state = getPasswordResetState($pdo, $email);
        $normalizedEmail = normalizePasswordResetEmail($email);
        $normalizedCode = trim((string) $code);

        if (empty($state['email']) || empty($state['password_reset_code_hash']) || empty($state['password_reset_expires_at']) || empty($state['id'])) {
            $message = 'Please request a reset code first.';
            return 0;
        }

        if (!hash_equals((string) $state['email'], $normalizedEmail)) {
            $message = 'The reset code does not match this email address.';
            return 0;
        }

        $expiresAtTimestamp = strtotime((string) $state['password_reset_expires_at']);
        if ($expiresAtTimestamp === false || time() > $expiresAtTimestamp) {
            clearPasswordReset($pdo, $normalizedEmail, (int) $state['id']);
            $message = 'Your reset code has expired. Please request a new one.';
            return 0;
        }

        if (($state['status'] ?? 'inactive') !== 'active') {
            clearPasswordReset($pdo, $normalizedEmail, (int) $state['id']);
            $message = 'This account is no longer active for password reset.';
            return 0;
        }

        $attempts = (int) ($state['password_reset_attempts'] ?? 0);
        if ($attempts >= PASSWORD_RESET_MAX_ATTEMPTS) {
            clearPasswordReset($pdo, $normalizedEmail, (int) $state['id']);
            $message = 'Too many incorrect attempts. Please request a new reset code.';
            return 0;
        }

        if (!hash_equals((string) $state['password_reset_code_hash'], hash('sha256', $normalizedCode))) {
            $attemptStmt = $pdo->prepare("
                UPDATE users
                SET password_reset_attempts = COALESCE(password_reset_attempts, 0) + 1
                WHERE id = :user_id
                LIMIT 1
            ");
            $attemptStmt->execute([':user_id' => (int) $state['id']]);
            $message = 'Invalid reset code.';
            return 0;
        }

        return (int) $state['id'];
    }
}
?>
