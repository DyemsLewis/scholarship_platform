<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/security.php';

header('Content-Type: application/json');

require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/password_reset.php';
require_once __DIR__ . '/../Config/SmtpMailer.php';
require_once __DIR__ . '/../Models/ActivityLog.php';

$mailConfig = require __DIR__ . '/../Config/mail_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit();
}

$email = trim((string) ($_POST['email'] ?? ''));
$passwordResetRateLimitBucket = securityBuildRateLimitBucket('password_reset_request', $email);
$passwordResetRateLimitStatus = securityGetRateLimitStatus($passwordResetRateLimitBucket, 5, 900);

if ($passwordResetRateLimitStatus['blocked']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many reset requests. Please wait before trying again.',
        'cooldown_seconds' => $passwordResetRateLimitStatus['retry_after']
    ]);
    exit();
}

if ($email === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Email is required.'
    ]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ]);
    exit();
}

if (!passwordResetColumnsReady($pdo)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Password reset database fields are missing. Please run the migration first.'
    ]);
    exit();
}

$cooldown = getPasswordResetCooldown($pdo, $email);
if ($cooldown > 0) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Please wait before requesting another reset code.',
        'cooldown_seconds' => $cooldown
    ]);
    exit();
}

$stmt = $pdo->prepare("
    SELECT id, username, email, status
    FROM users
    WHERE LOWER(email) = :email
    LIMIT 1
");
$stmt->execute([':email' => normalizePasswordResetEmail($email)]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$genericSuccessMessage = 'If an active account exists for this email, a reset code has been sent.';

if (!$user || (($user['status'] ?? 'inactive') !== 'active')) {
    securityRegisterRateLimitAttempt($passwordResetRateLimitBucket, 900);
    echo json_encode([
        'success' => true,
        'message' => $genericSuccessMessage
    ]);
    exit();
}

if (empty($mailConfig['configured'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Password reset email is not configured on this server yet.'
    ]);
    exit();
}

$resetCode = (string) random_int(100000, 999999);
$subject = 'Scholarship Finder Password Reset Code';
$message = "Hello,\n\n"
    . "Use this code to reset your Scholarship Finder password:\n\n"
    . $resetCode . "\n\n"
    . "This code will expire in 10 minutes.\n\n"
    . "If you did not request a password reset, you can ignore this email.";

$mailer = new SmtpMailer($mailConfig);
$mailResult = $mailer->send($email, $subject, wordwrap($message, 70));

if (!$mailResult['success']) {
    securityRegisterRateLimitAttempt($passwordResetRateLimitBucket, 900);
    clearPasswordReset($pdo, $email, (int) ($user['id'] ?? 0));
    error_log('Failed to send password reset email to ' . $email . ': ' . ($mailResult['error'] ?? 'Unknown error'));

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'The reset email could not be sent. Please try again later.'
    ]);
    exit();
}

securityRegisterRateLimitAttempt($passwordResetRateLimitBucket, 900);
$stored = storePasswordResetCode($pdo, $email, (int) $user['id'], $resetCode);
if (!$stored) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save password reset code. Please try again.'
    ]);
    exit();
}

$_SESSION['forgot_password_last_email'] = normalizePasswordResetEmail($email);

try {
    $activityLog = new ActivityLog($pdo);
    $targetName = trim((string) (($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')));
    if ($targetName === '') {
        $targetName = (string) ($user['username'] ?? $email);
    }

    $activityLog->log('request_password_reset', 'authentication', 'Password reset code requested.', [
        'target_user_id' => (int) $user['id'],
        'target_name' => $targetName,
        'entity_id' => (int) $user['id'],
        'entity_name' => $targetName,
        'details' => [
            'email' => (string) ($user['email'] ?? $email),
        ],
    ]);
} catch (Throwable $e) {
    error_log('password reset request activity log error: ' . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'message' => $genericSuccessMessage,
    'cooldown_seconds' => PASSWORD_RESET_RESEND_COOLDOWN
]);
exit();
?>
