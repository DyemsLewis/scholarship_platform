<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Models/ActivityLog.php';

$logoutMessage = 'You have been successfully logged out.';
$actorUserId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$actorName = $_SESSION['user_display_name'] ?? $_SESSION['admin_username'] ?? $_SESSION['user_username'] ?? 'User';
$actorRole = getCurrentSessionRole();

try {
    $activityLog = new ActivityLog($pdo);
    $activityLog->log('logout', 'authentication', 'User logged out.', [
        'actor_user_id' => $actorUserId,
        'actor_name' => $actorName,
        'actor_role' => $actorRole,
        'entity_id' => $actorUserId,
        'entity_name' => $actorName,
        'target_user_id' => $actorUserId,
        'target_name' => $actorName,
        'details' => [
            'role' => $actorRole
        ]
    ]);
} catch (Throwable $e) {
    error_log('logout activity log error: ' . $e->getMessage());
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool) ($params['secure'] ?? false),
        (bool) ($params['httponly'] ?? true)
    );
}

session_destroy();
appSecurityStartSession();
session_regenerate_id(true);
$_SESSION['success'] = $logoutMessage;
header('Location: login.php');
exit();
?>
