<?php
require_once __DIR__ . '/../Config/init.php';
require_once __DIR__ . '/../Config/password_reset.php';
require_once __DIR__ . '/../Config/password_policy.php';

function redirectForgotPasswordWithMessage(string $type, string $message, array $oldInput = []): void
{
    $_SESSION['forgot_password_flash_type'] = $type;
    $_SESSION['forgot_password_flash_message'] = $message;
    $_SESSION['forgot_password_old'] = [
        'email' => trim((string) ($oldInput['email'] ?? '')),
        'code' => trim((string) ($oldInput['code'] ?? '')),
    ];

    header('Location: ../View/forgot_password.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../View/forgot_password.php');
    exit();
}

$email = trim((string) ($_POST['email'] ?? ''));
$code = trim((string) ($_POST['code'] ?? ''));
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($email === '' || $code === '' || $newPassword === '' || $confirmPassword === '') {
    redirectForgotPasswordWithMessage('error', 'Please complete all fields before resetting your password.', $_POST);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirectForgotPasswordWithMessage('error', 'Please enter a valid email address.', $_POST);
}

if (!passwordResetColumnsReady($pdo)) {
    redirectForgotPasswordWithMessage('error', 'Password reset database fields are missing. Please run the migration first.', $_POST);
}

if (!hash_equals($newPassword, $confirmPassword)) {
    redirectForgotPasswordWithMessage('error', 'New password and confirmation do not match.', $_POST);
}

$verificationMessage = '';
$userId = verifyPasswordResetCode($pdo, $email, $code, $verificationMessage);
if ($userId <= 0) {
    redirectForgotPasswordWithMessage('error', $verificationMessage !== '' ? $verificationMessage : 'Unable to verify the reset code.', $_POST);
}

$stmt = $pdo->prepare("
    SELECT id, username, email, status
    FROM users
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || (($user['status'] ?? 'inactive') !== 'active')) {
    clearPasswordReset($pdo, $email, $userId);
    redirectForgotPasswordWithMessage('error', 'This account is no longer available for password reset.');
}

$passwordValidation = validateStrongPassword($newPassword, [
    'username' => (string) ($user['username'] ?? ''),
    'email' => (string) ($user['email'] ?? $email),
]);
if (!$passwordValidation['valid']) {
    redirectForgotPasswordWithMessage('error', $passwordValidation['errors'][0] ?? passwordPolicyHint(), $_POST);
}

if (password_verify($newPassword, (string) ($user['password'] ?? ''))) {
    redirectForgotPasswordWithMessage('error', 'Please choose a new password that is different from your current password.', $_POST);
}

$updateStmt = $pdo->prepare("
    UPDATE users
    SET password = :password
    WHERE id = :id
");
$updated = $updateStmt->execute([
    ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
    ':id' => $userId,
]);

if (!$updated) {
    redirectForgotPasswordWithMessage('error', 'Failed to update your password. Please try again.', $_POST);
}

clearPasswordReset($pdo, $email, $userId);
unset($_SESSION['forgot_password_old']);
unset($_SESSION['forgot_password_last_email']);

try {
    $activityLog = new ActivityLog($pdo);
    $targetName = trim((string) (($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')));
    if ($targetName === '') {
        $targetName = (string) ($user['username'] ?? $email);
    }

    $activityLog->log('reset_password', 'authentication', 'Password reset completed.', [
        'target_user_id' => (int) $userId,
        'target_name' => $targetName,
        'entity_id' => (int) $userId,
        'entity_name' => $targetName,
        'details' => [
            'email' => (string) ($user['email'] ?? $email),
        ],
    ]);
} catch (Throwable $e) {
    error_log('password reset activity log error: ' . $e->getMessage());
}

$_SESSION['success'] = 'Password reset successful. You can now log in with your new password.';
header('Location: ../View/login.php');
exit();
?>
