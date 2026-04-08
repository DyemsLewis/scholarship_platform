<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/security.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $loginRateLimitBucket = securityBuildRateLimitBucket('admin_login', $username);
    $loginRateLimitStatus = securityGetRateLimitStatus($loginRateLimitBucket, 8, 900);

    if ($loginRateLimitStatus['blocked']) {
        $_SESSION['error'] = 'Too many login attempts. Please wait ' . (int) $loginRateLimitStatus['retry_after'] . ' seconds before trying again.';
        header('Location: ' . normalizeAppUrl('../AdminView/login.php'));
        exit();
    }
    
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = 'Please fill in all fields';
        header('Location: ' . normalizeAppUrl('../AdminView/login.php'));
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin && password_verify($password, $admin['password'])) {
        securityClearRateLimit($loginRateLimitBucket);
        session_regenerate_id(true);

        // Set admin-specific session variables
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_role'] = normalizeUserRole($admin['role'] ?? 'admin');
        
        // Also set standard user variables for consistency
        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['user_name'] = $admin['username'];
        $_SESSION['user_username'] = $admin['username'];
        $_SESSION['user_display_name'] = $admin['username'];
        $_SESSION['user_email'] = $admin['email'];
        syncRoleSessionMeta($admin['role'] ?? 'admin');
        syncStaffPermissionSessionMeta($admin['role'] ?? 'admin');
        
        header('Location: ' . normalizeAppUrl('../AdminView/admin_dashboard.php'));
        exit();
    } else {
        securityRegisterRateLimitAttempt($loginRateLimitBucket, 900);
        $_SESSION['error'] = 'Invalid username or password';
        header('Location: ' . normalizeAppUrl('../AdminView/login.php'));
        exit();
    }
} else {
    header('Location: ' . normalizeAppUrl('../AdminView/login.php'));
    exit();
}
?>
