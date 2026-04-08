<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/security.php';

header('Content-Type: application/json');

require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/signup_verification.php';
require_once __DIR__ . '/../Config/SmtpMailer.php';

$mailConfig = require __DIR__ . '/../Config/mail_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit();
}

$email = trim($_POST['email'] ?? '');
$verificationRateLimitBucket = securityBuildRateLimitBucket('signup_verification_request', $email);
$verificationRateLimitStatus = securityGetRateLimitStatus($verificationRateLimitBucket, 6, 900);

if ($verificationRateLimitStatus['blocked']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many verification requests. Please wait before trying again.',
        'cooldown_seconds' => $verificationRateLimitStatus['retry_after']
    ]);
    exit();
}

if (empty($email)) {
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

if (!signupVerificationTableReady($pdo)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Signup verification database table is missing. Please run the migration first.'
    ]);
    exit();
}

if (isSignupEmailVerified($pdo, $email)) {
    securityRegisterRateLimitAttempt($verificationRateLimitBucket, 900);
    $_SESSION['signup_verification_last_email'] = normalizeSignupVerificationEmail($email);
    echo json_encode([
        'success' => true,
        'already_verified' => true,
        'message' => 'This email is already verified for signup.'
    ]);
    exit();
}

$userCheckStmt = $pdo->prepare("
    SELECT id
    FROM users
    WHERE LOWER(email) = :email
    LIMIT 1
");
$userCheckStmt->execute([':email' => normalizeSignupVerificationEmail($email)]);
if ($userCheckStmt->fetch(PDO::FETCH_ASSOC)) {
    securityRegisterRateLimitAttempt($verificationRateLimitBucket, 900);
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'That email is already registered.'
    ]);
    exit();
}

$cooldown = getSignupVerificationCooldown($pdo, $email);
if ($cooldown > 0) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Please wait before requesting another code.',
        'cooldown_seconds' => $cooldown
    ]);
    exit();
}

if (empty($mailConfig['configured'])) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Email verification is not configured on this server yet.'
    ]);
    exit();
}

$verificationCode = (string) random_int(100000, 999999);
$subject = 'Scholarship Finder Email Verification Code';
$message = "Hello,\n\n"
    . "Use this verification code to complete your Scholarship Finder signup:\n\n"
    . $verificationCode . "\n\n"
    . "This code will expire in 10 minutes.\n\n"
    . "If you did not request this signup, you can ignore this email.";

$mailer = new SmtpMailer($mailConfig);
$mailResult = $mailer->send($email, $subject, wordwrap($message, 70));

if (!$mailResult['success']) {
    securityRegisterRateLimitAttempt($verificationRateLimitBucket, 900);
    clearSignupVerification($pdo, $email);
    error_log('Failed to send signup verification email to ' . $email . ': ' . ($mailResult['error'] ?? 'Unknown error'));

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'The verification email could not be sent. ' . ($mailResult['error'] ?? 'Please check your mail server configuration and try again.')
    ]);
    exit();
}

securityRegisterRateLimitAttempt($verificationRateLimitBucket, 900);
$stored = storeSignupVerificationCode($pdo, $email, $verificationCode);
if (!$stored) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save verification code. Please try again.'
    ]);
    exit();
}

$_SESSION['signup_verification_last_email'] = normalizeSignupVerificationEmail($email);

echo json_encode([
    'success' => true,
    'message' => 'Verification code sent. Please check your email inbox.',
    'cooldown_seconds' => SIGNUP_VERIFICATION_RESEND_COOLDOWN
]);
exit();
?>
