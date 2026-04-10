<?php
require_once __DIR__ . '/../Config/init.php';
require_once __DIR__ . '/../Models/Notification.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Please log in first.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

if (!function_exists('formatNotificationFeedTimestamp')) {
    function formatNotificationFeedTimestamp(?string $value): string
    {
        if (!$value) {
            return 'Recently';
        }

        try {
            $date = new DateTime($value);
            $now = new DateTime();
            $diff = $now->getTimestamp() - $date->getTimestamp();

            if ($diff < 60) {
                return 'Just now';
            }
            if ($diff < 3600) {
                return floor($diff / 60) . ' min ago';
            }
            if ($diff < 86400) {
                $hours = (int) floor($diff / 3600);
                return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
            }
            if ($diff < 604800) {
                $days = (int) floor($diff / 86400);
                return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
            }

            return $date->format('M d, Y');
        } catch (Throwable $e) {
            return 'Recently';
        }
    }
}

try {
    $notificationModel = new Notification($pdo);
    $notifications = $notificationModel->getRecentForUser((int) $_SESSION['user_id'], 5);
    $unreadCount = $notificationModel->countUnreadForUser((int) $_SESSION['user_id']);

    $responseNotifications = array_map(static function (array $notification): array {
        return [
            'id' => (int) ($notification['id'] ?? 0),
            'type' => (string) ($notification['type'] ?? 'general'),
            'title' => (string) ($notification['title'] ?? 'Notification'),
            'message' => (string) ($notification['message'] ?? ''),
            'link_url' => trim((string) ($notification['link_url'] ?? '')),
            'is_read' => !empty($notification['is_read']) ? 1 : 0,
            'created_at' => (string) ($notification['created_at'] ?? ''),
            'created_at_label' => formatNotificationFeedTimestamp($notification['created_at'] ?? null),
        ];
    }, $notifications);

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $responseNotifications,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Notifications could not be loaded right now.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
exit();
