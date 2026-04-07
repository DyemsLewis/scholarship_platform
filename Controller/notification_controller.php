<?php
require_once '../Config/init.php';
require_once '../Config/csrf.php';
require_once '../Model/Notification.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in first.';
    header('Location: ../View/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../View/profile.php');
    exit();
}

$csrfValidation = csrfValidateRequest('notification_center');
if (!$csrfValidation['valid']) {
    $_SESSION['error'] = $csrfValidation['message'];
    header('Location: ../View/profile.php');
    exit();
}

$action = trim((string) ($_POST['action'] ?? ''));
$redirect = trim((string) ($_POST['redirect'] ?? '../View/profile.php'));

if ($redirect === '' || str_contains($redirect, '://')) {
    $redirect = '../View/profile.php';
}

$notificationModel = new Notification($pdo);

if ($action === 'mark_all_read') {
    if ($notificationModel->markAllAsRead((int) $_SESSION['user_id'])) {
        $_SESSION['success'] = 'Notifications marked as read.';
    } else {
        $_SESSION['error'] = 'Notifications could not be updated right now.';
    }
}

header('Location: ' . $redirect);
exit();
