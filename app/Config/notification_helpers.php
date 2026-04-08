<?php
require_once __DIR__ . '/../Models/Notification.php';

if (!function_exists('normalizeNotificationOrganization')) {
    function normalizeNotificationOrganization(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}

if (!function_exists('getNotificationRecipientIdsByRoles')) {
    function getNotificationRecipientIdsByRoles(PDO $pdo, array $roles, bool $onlyActive = true): array
    {
        $roles = array_values(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $roles
        ), static fn(string $value): bool => $value !== ''));

        if (empty($roles)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($roles), '?'));
        $sql = "SELECT id FROM users WHERE role IN ({$placeholders})";
        $params = $roles;

        if ($onlyActive) {
            $sql .= " AND status = 'active'";
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            error_log('getNotificationRecipientIdsByRoles error: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getProviderNotificationRecipientIds')) {
    function getProviderNotificationRecipientIds(PDO $pdo, string $organizationName, bool $onlyActive = true): array
    {
        $normalizedOrganization = normalizeNotificationOrganization($organizationName);
        if ($normalizedOrganization === '') {
            return [];
        }

        $sql = "
            SELECT u.id
            FROM users u
            JOIN provider_data pd ON pd.user_id = u.id
            WHERE u.role = 'provider'
              AND LOWER(TRIM(COALESCE(pd.organization_name, ''))) = ?
        ";
        $params = [$normalizedOrganization];

        if ($onlyActive) {
            $sql .= " AND u.status = 'active'";
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            error_log('getProviderNotificationRecipientIds error: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('createNotificationsForUsers')) {
    function createNotificationsForUsers(PDO $pdo, array $userIds, string $type, string $title, string $message, array $options = []): void
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if (empty($cleanIds)) {
            return;
        }

        $notificationModel = new Notification($pdo);
        foreach ($cleanIds as $userId) {
            $notificationModel->createForUser($userId, $type, $title, $message, $options);
        }
    }
}
