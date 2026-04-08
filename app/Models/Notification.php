<?php

class Notification
{
    private PDO $pdo;
    private string $table = 'notifications';
    private static bool $tableEnsured = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTableExists(): bool
    {
        if (self::$tableEnsured) {
            return true;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(50) NOT NULL DEFAULT 'general',
                title VARCHAR(180) NOT NULL,
                message TEXT NOT NULL,
                entity_type VARCHAR(50) NULL,
                entity_id INT UNSIGNED NULL,
                link_url VARCHAR(255) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                INDEX idx_notifications_user_created (user_id, created_at),
                INDEX idx_notifications_user_read (user_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        try {
            $this->pdo->exec($sql);
            self::$tableEnsured = true;
            return true;
        } catch (Throwable $e) {
            error_log('Notification ensureTableExists error: ' . $e->getMessage());
            return false;
        }
    }

    public function createForUser(int $userId, string $type, string $title, string $message, array $options = []): bool
    {
        if ($userId <= 0 || !$this->ensureTableExists()) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->table}
                    (user_id, type, title, message, entity_type, entity_id, link_url, is_read)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");

            $entityType = $this->normalizeNullableString($options['entity_type'] ?? null);
            $entityId = isset($options['entity_id']) && is_numeric($options['entity_id'])
                ? (int) $options['entity_id']
                : null;
            $linkUrl = $this->normalizeNullableString($options['link_url'] ?? null);

            return $stmt->execute([
                $userId,
                trim($type) !== '' ? trim($type) : 'general',
                trim($title) !== '' ? trim($title) : 'Notification',
                trim($message) !== '' ? trim($message) : 'You have a new update.',
                $entityType,
                $entityId,
                $linkUrl,
            ]);
        } catch (Throwable $e) {
            error_log('Notification createForUser error: ' . $e->getMessage());
            return false;
        }
    }

    public function getRecentForUser(int $userId, int $limit = 8): array
    {
        if ($userId <= 0 || !$this->ensureTableExists()) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        try {
            $stmt = $this->pdo->prepare("
                SELECT *
                FROM {$this->table}
                WHERE user_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Notification getRecentForUser error: ' . $e->getMessage());
            return [];
        }
    }

    public function countUnreadForUser(int $userId): int
    {
        if ($userId <= 0 || !$this->ensureTableExists()) {
            return 0;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM {$this->table}
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Notification countUnreadForUser error: ' . $e->getMessage());
            return 0;
        }
    }

    public function markAllAsRead(int $userId): bool
    {
        if ($userId <= 0 || !$this->ensureTableExists()) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                UPDATE {$this->table}
                SET is_read = 1,
                    read_at = NOW()
                WHERE user_id = ?
                  AND is_read = 0
            ");
            return $stmt->execute([$userId]);
        } catch (Throwable $e) {
            error_log('Notification markAllAsRead error: ' . $e->getMessage());
            return false;
        }
    }

    private function normalizeNullableString($value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
