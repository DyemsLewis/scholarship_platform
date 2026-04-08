<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/security.php';

header('Content-Type: application/json');

require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/signup_verification.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit();
}

$email = trim($_POST['email'] ?? '');
$code = trim($_POST['code'] ?? '');
$verifyRateLimitBucket = securityBuildRateLimitBucket('signup_verification_check', $email);
$verifyRateLimitStatus = securityGetRateLimitStatus($verifyRateLimitBucket, 10, 900);

if ($verifyRateLimitStatus['blocked']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many verification attempts. Please wait before trying again.'
    ]);
    exit();
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    securityRegisterRateLimitAttempt($verifyRateLimitBucket, 900);
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address first.'
    ]);
    exit();
}

if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
    securityRegisterRateLimitAttempt($verifyRateLimitBucket, 900);
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Enter the 6-digit verification code sent to your email.'
    ]);
    exit();
}

$message = '';

if (!verifySignupCode($pdo, $email, $code, $message)) {
    securityRegisterRateLimitAttempt($verifyRateLimitBucket, 900);
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $message ?: 'Verification failed.'
    ]);
    exit();
}

securityClearRateLimit($verifyRateLimitBucket);
rememberSignupVerifiedEmail($email);

echo json_encode([
    'success' => true,
    'message' => 'Email verified successfully. You can now create your account.'
]);
exit();
?>
