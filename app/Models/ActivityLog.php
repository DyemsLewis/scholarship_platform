<?php

class ActivityLog
{
    private PDO $pdo;
    private string $table = 'activity_logs';
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
                actor_user_id INT UNSIGNED NULL,
                actor_role VARCHAR(30) NOT NULL DEFAULT 'guest',
                actor_name VARCHAR(150) NULL,
                target_user_id INT UNSIGNED NULL,
                target_name VARCHAR(150) NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT UNSIGNED NULL,
                entity_name VARCHAR(255) NULL,
                description TEXT NOT NULL,
                details LONGTEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activity_created_at (created_at),
                INDEX idx_activity_actor_user (actor_user_id),
                INDEX idx_activity_target_user (target_user_id),
                INDEX idx_activity_action (action),
                INDEX idx_activity_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        try {
            $this->pdo->exec($sql);
            self::$tableEnsured = true;
            return true;
        } catch (Throwable $e) {
            error_log('ActivityLog ensureTableExists error: ' . $e->getMessage());
            return false;
        }
    }

    public function log(string $action, string $entityType, string $description, array $options = []): bool
    {
        if (!$this->ensureTableExists()) {
            return false;
        }

        $actor = $this->resolveActor($options);
        $targetUserId = isset($options['target_user_id']) && is_numeric($options['target_user_id'])
            ? (int) $options['target_user_id']
            : null;
        $targetName = $this->normalizeNullableString($options['target_name'] ?? null);
        $entityId = isset($options['entity_id']) && is_numeric($options['entity_id'])
            ? (int) $options['entity_id']
            : null;
        $entityName = $this->normalizeNullableString($options['entity_name'] ?? null);
        $details = $this->normalizeDetails($options['details'] ?? null);
        $ipAddress = $this->normalizeNullableString($options['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null));

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->table}
                    (
                        actor_user_id,
                        actor_role,
                        actor_name,
                        target_user_id,
                        target_name,
                        action,
                        entity_type,
                        entity_id,
                        entity_name,
                        description,
                        details,
                        ip_address
                    )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            return $stmt->execute([
                $actor['user_id'],
                $actor['role'],
                $actor['name'],
                $targetUserId,
                $targetName,
                trim($action),
                trim($entityType),
                $entityId,
                $entityName,
                trim($description),
                $details,
                $ipAddress
            ]);
        } catch (Throwable $e) {
            error_log('ActivityLog log error: ' . $e->getMessage());
            return false;
        }
    }

    public function getStats(?string $viewerRole = null): array
    {
        $default = [
            'total' => 0,
            'today' => 0,
            'scholarship' => 0,
            'application' => 0,
            'document' => 0,
            'user' => 0,
            'authentication' => 0,
        ];

        if (!$this->ensureTableExists()) {
            return $default;
        }

        [$visibilitySql, $params] = $this->buildVisibilityClause($viewerRole);

        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
                    SUM(CASE WHEN entity_type = 'scholarship' THEN 1 ELSE 0 END) AS scholarship,
                    SUM(CASE WHEN entity_type = 'application' THEN 1 ELSE 0 END) AS application,
                    SUM(CASE WHEN entity_type = 'document' THEN 1 ELSE 0 END) AS document,
                    SUM(CASE WHEN entity_type = 'user' THEN 1 ELSE 0 END) AS user,
                    SUM(CASE WHEN entity_type = 'authentication' THEN 1 ELSE 0 END) AS authentication
                FROM {$this->table}
                WHERE 1 = 1 {$visibilitySql}
            ");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            foreach ($default as $key => $value) {
                $default[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
            }
        } catch (Throwable $e) {
            error_log('ActivityLog getStats error: ' . $e->getMessage());
        }

        return $default;
    }

    public function countLogs(array $filters = [], ?string $viewerRole = null): int
    {
        if (!$this->ensureTableExists()) {
            return 0;
        }

        [$filterSql, $params] = $this->buildLogFilterClause($filters, $viewerRole);

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM {$this->table}
                WHERE 1 = 1 {$filterSql}
            ");
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('ActivityLog countLogs error: ' . $e->getMessage());
            return 0;
        }
    }

    public function getLogs(array $filters = [], int $limit = 100, ?string $viewerRole = null, int $offset = 0): array
    {
        if (!$this->ensureTableExists()) {
            return [];
        }

        $limit = max(10, min(500, (int) $limit));
        $offset = max(0, (int) $offset);
        [$filterSql, $params] = $this->buildLogFilterClause($filters, $viewerRole);

        $sql = "
            SELECT *
            FROM {$this->table}
            WHERE 1 = 1 {$filterSql}
        ";

        $sql .= " ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('ActivityLog getLogs error: ' . $e->getMessage());
            return [];
        }
    }

    private function buildLogFilterClause(array $filters, ?string $viewerRole = null): array
    {
        [$visibilitySql, $params] = $this->buildVisibilityClause($viewerRole);
        $sql = $visibilitySql;

        $entityType = trim((string) ($filters['entity_type'] ?? ''));
        if ($entityType !== '' && $entityType !== 'all') {
            $sql .= ' AND entity_type = ?';
            $params[] = $entityType;
        }

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '' && $action !== 'all') {
            $sql .= ' AND action = ?';
            $params[] = $action;
        }

        $actorRole = trim((string) ($filters['actor_role'] ?? ''));
        if ($actorRole !== '' && $actorRole !== 'all') {
            $sql .= ' AND actor_role = ?';
            $params[] = $this->normalizeRole($actorRole);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $sql .= ' AND (actor_name LIKE ? OR target_name LIKE ? OR entity_name LIKE ? OR description LIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        return [$sql, $params];
    }

    public function getEntityTypes(?string $viewerRole = null): array
    {
        return $this->getDistinctColumnValues('entity_type', $viewerRole);
    }

    public function getActions(?string $viewerRole = null): array
    {
        return $this->getDistinctColumnValues('action', $viewerRole);
    }

    private function getDistinctColumnValues(string $column, ?string $viewerRole = null): array
    {
        if (!$this->ensureTableExists()) {
            return [];
        }

        if (!in_array($column, ['entity_type', 'action'], true)) {
            return [];
        }

        [$visibilitySql, $params] = $this->buildVisibilityClause($viewerRole);

        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT {$column}
                FROM {$this->table}
                WHERE {$column} IS NOT NULL AND {$column} <> '' {$visibilitySql}
                ORDER BY {$column} ASC
            ");
            $stmt->execute($params);
            return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (Throwable $e) {
            error_log('ActivityLog getDistinctColumnValues error: ' . $e->getMessage());
            return [];
        }
    }

    private function buildVisibilityClause(?string $viewerRole): array
    {
        $role = $this->normalizeRole($viewerRole ?? (function_exists('getCurrentSessionRole') ? getCurrentSessionRole() : 'guest'));
        if ($role === 'provider') {
            return [" AND entity_type IN ('scholarship', 'application', 'document')", []];
        }

        return ['', []];
    }

    private function resolveActor(array $options): array
    {
        $sessionName = $_SESSION['user_display_name']
            ?? $_SESSION['admin_username']
            ?? $_SESSION['user_username']
            ?? null;

        $actorUserId = isset($options['actor_user_id']) && is_numeric($options['actor_user_id'])
            ? (int) $options['actor_user_id']
            : (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);

        $actorRole = $this->normalizeRole(
            $options['actor_role']
            ?? (function_exists('getCurrentSessionRole') ? getCurrentSessionRole() : ($_SESSION['user_role'] ?? 'guest'))
        );

        $actorName = $this->normalizeNullableString($options['actor_name'] ?? $sessionName);

        return [
            'user_id' => $actorUserId,
            'role' => $actorRole,
            'name' => $actorName,
        ];
    }

    private function normalizeRole($role): string
    {
        if (function_exists('normalizeUserRole')) {
            return normalizeUserRole($role);
        }

        $value = strtolower(trim((string) $role));
        return $value === '' ? 'guest' : $value;
    }

    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeDetails($details): ?string
    {
        if ($details === null || $details === '') {
            return null;
        }

        if (is_array($details) || is_object($details)) {
            $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? null : $encoded;
        }

        $trimmed = trim((string) $details);
        return $trimmed === '' ? null : $trimmed;
    }
}
