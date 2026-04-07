<?php

class UserIssueReport
{
    private PDO $pdo;
    private string $table = 'user_issue_reports';
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
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                category VARCHAR(50) NOT NULL DEFAULT 'other',
                subject VARCHAR(180) NOT NULL,
                details TEXT NOT NULL,
                page_context VARCHAR(180) NULL,
                reported_url VARCHAR(255) NULL,
                status ENUM('pending','reviewed','resolved','rejected') NOT NULL DEFAULT 'pending',
                admin_notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                INDEX idx_user_issue_user (user_id, created_at),
                INDEX idx_user_issue_status (status, created_at),
                INDEX idx_user_issue_category (category, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        try {
            $this->pdo->exec($sql);
            self::$tableEnsured = true;
            return true;
        } catch (Throwable $e) {
            error_log('UserIssueReport ensureTableExists error: ' . $e->getMessage());
            return false;
        }
    }

    public function createReport(int $userId, string $category, string $subject, string $details, ?string $pageContext = null, ?string $reportedUrl = null): bool
    {
        if ($userId <= 0 || !$this->ensureTableExists()) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->table}
                    (user_id, category, subject, details, page_context, reported_url, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ");

            return $stmt->execute([
                $userId,
                $this->normalizeString($category, 'other'),
                $this->normalizeString($subject, 'Problem report'),
                $this->normalizeString($details, 'No details provided.'),
                $this->normalizeNullableString($pageContext),
                $this->normalizeNullableString($reportedUrl),
            ]);
        } catch (Throwable $e) {
            error_log('UserIssueReport createReport error: ' . $e->getMessage());
            return false;
        }
    }

    public function getStats(): array
    {
        $default = [
            'total' => 0,
            'pending' => 0,
            'reviewed' => 0,
            'resolved' => 0,
            'rejected' => 0,
        ];

        if (!$this->ensureTableExists()) {
            return $default;
        }

        try {
            $stmt = $this->pdo->query("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) AS reviewed,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
                FROM {$this->table}
            ");

            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($default as $key => $value) {
                $default[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
            }
        } catch (Throwable $e) {
            error_log('UserIssueReport getStats error: ' . $e->getMessage());
        }

        return $default;
    }

    public function getReports(string $search = '', string $status = 'all', int $limit = 200): array
    {
        if (!$this->ensureTableExists()) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $sql = $this->buildBaseQuery() . ' WHERE 1=1';
        $params = [];

        $normalizedStatus = strtolower(trim($status));
        if (in_array($normalizedStatus, ['pending', 'reviewed', 'resolved', 'rejected'], true)) {
            $sql .= ' AND r.status = ?';
            $params[] = $normalizedStatus;
        }

        $normalizedSearch = trim($search);
        if ($normalizedSearch !== '') {
            $sql .= "
                AND (
                    u.username LIKE ?
                    OR u.email LIKE ?
                    OR CONCAT(COALESCE(sd.firstname, ''), ' ', COALESCE(sd.lastname, '')) LIKE ?
                    OR r.subject LIKE ?
                    OR r.details LIKE ?
                    OR r.page_context LIKE ?
                    OR r.category LIKE ?
                )
            ";
            $term = '%' . $normalizedSearch . '%';
            for ($i = 0; $i < 7; $i++) {
                $params[] = $term;
            }
        }

        $sql .= "
            ORDER BY
                CASE
                    WHEN r.status = 'pending' THEN 0
                    WHEN r.status = 'reviewed' THEN 1
                    WHEN r.status = 'resolved' THEN 2
                    ELSE 3
                END,
                r.created_at DESC,
                r.id DESC
            LIMIT {$limit}
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('UserIssueReport getReports error: ' . $e->getMessage());
            return [];
        }
    }

    public function getById(int $reportId): ?array
    {
        if ($reportId <= 0 || !$this->ensureTableExists()) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare($this->buildBaseQuery() . ' WHERE r.id = ? LIMIT 1');
            $stmt->execute([$reportId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('UserIssueReport getById error: ' . $e->getMessage());
            return null;
        }
    }

    public function updateStatus(int $reportId, string $status, ?string $adminNotes = null): bool
    {
        if ($reportId <= 0 || !$this->ensureTableExists()) {
            return false;
        }

        $normalizedStatus = strtolower(trim($status));
        if (!in_array($normalizedStatus, ['pending', 'reviewed', 'resolved', 'rejected'], true)) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                UPDATE {$this->table}
                SET status = ?, admin_notes = ?, reviewed_at = NOW()
                WHERE id = ?
            ");

            return $stmt->execute([
                $normalizedStatus,
                $this->normalizeNullableString($adminNotes),
                $reportId,
            ]);
        } catch (Throwable $e) {
            error_log('UserIssueReport updateStatus error: ' . $e->getMessage());
            return false;
        }
    }

    private function buildBaseQuery(): string
    {
        return "
            SELECT
                r.*,
                u.username,
                u.email,
                u.status AS user_status,
                sd.firstname,
                sd.lastname,
                sd.school,
                sd.course
            FROM {$this->table} r
            JOIN users u ON u.id = r.user_id
            LEFT JOIN student_data sd ON sd.student_id = r.user_id
        ";
    }

    private function normalizeNullableString($value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeString($value, string $fallback): string
    {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : $fallback;
    }
}
