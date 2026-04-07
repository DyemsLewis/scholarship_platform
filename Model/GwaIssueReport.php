<?php

class GwaIssueReport
{
    private PDO $pdo;
    private string $table = 'gwa_issue_reports';
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
                document_id INT UNSIGNED NULL,
                extracted_gwa DECIMAL(4,2) NULL,
                reported_gwa DECIMAL(4,2) NULL,
                raw_ocr_value DECIMAL(6,2) NULL,
                reason_code VARCHAR(50) NOT NULL,
                details TEXT NULL,
                status ENUM('pending','reviewed','resolved','rejected') NOT NULL DEFAULT 'pending',
                admin_notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                INDEX idx_gwa_issue_user (user_id, created_at),
                INDEX idx_gwa_issue_status (status, created_at),
                INDEX idx_gwa_issue_document (document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        try {
            $this->pdo->exec($sql);
            self::$tableEnsured = true;
            return true;
        } catch (Throwable $e) {
            error_log('GwaIssueReport ensureTableExists error: ' . $e->getMessage());
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
            'with_reported_gwa' => 0,
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
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN reported_gwa IS NOT NULL THEN 1 ELSE 0 END) AS with_reported_gwa
                FROM {$this->table}
            ");

            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($default as $key => $value) {
                $default[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
            }
        } catch (Throwable $e) {
            error_log('GwaIssueReport getStats error: ' . $e->getMessage());
        }

        return $default;
    }

    public function getReports(string $search = '', string $status = 'all', int $limit = 200): array
    {
        if (!$this->ensureTableExists()) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $sql = $this->buildBaseQuery() . ' WHERE 1 = 1';
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
                    OR COALESCE(ud.file_name, '') LIKE ?
                    OR COALESCE(dt.name, '') LIKE ?
                    OR COALESCE(r.reason_code, '') LIKE ?
                    OR COALESCE(r.details, '') LIKE ?
                )
            ";
            $term = '%' . $normalizedSearch . '%';
            for ($i = 0; $i < 7; $i++) {
                $params[] = $term;
            }
        }

        $sql .= " ORDER BY
            CASE
                WHEN r.status = 'pending' THEN 0
                WHEN r.status = 'reviewed' THEN 1
                WHEN r.status = 'resolved' THEN 2
                ELSE 3
            END,
            r.created_at DESC,
            r.id DESC
            LIMIT {$limit}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('GwaIssueReport getReports error: ' . $e->getMessage());
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
            error_log('GwaIssueReport getById error: ' . $e->getMessage());
            return null;
        }
    }

    public function getLatestForUser(int $userId, array $documentTypes = []): ?array
    {
        if ($userId <= 0 || !$this->ensureTableExists()) {
            return null;
        }

        $sql = $this->buildBaseQuery() . ' WHERE r.user_id = ?';
        $params = [$userId];

        $normalizedTypes = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string) $value)),
            $documentTypes
        ), static fn(string $value): bool => $value !== ''));

        if (!empty($normalizedTypes)) {
            $placeholders = implode(', ', array_fill(0, count($normalizedTypes), '?'));
            $sql .= " AND LOWER(COALESCE(ud.document_type, '')) IN ({$placeholders})";
            array_push($params, ...$normalizedTypes);
        }

        $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 1';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('GwaIssueReport getLatestForUser error: ' . $e->getMessage());
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
            error_log('GwaIssueReport updateStatus error: ' . $e->getMessage());
            return false;
        }
    }

    public function applyReportedGwaAndResolve(int $reportId, ?string $adminNotes = null): array
    {
        $report = $this->getById($reportId);
        if (!$report) {
            return ['success' => false, 'message' => 'Report not found.'];
        }

        if (!isset($report['reported_gwa']) || !is_numeric($report['reported_gwa'])) {
            return ['success' => false, 'message' => 'This report does not have a proposed correct GWA to apply.'];
        }

        $reportedGwa = (float) $report['reported_gwa'];
        if ($reportedGwa < 1.0 || $reportedGwa > 5.0) {
            return ['success' => false, 'message' => 'The proposed GWA is outside the allowed range.'];
        }

        if (!$this->saveStudentGwa((int) $report['user_id'], $reportedGwa)) {
            return ['success' => false, 'message' => 'The corrected GWA could not be saved to the student record.'];
        }

        if (!$this->updateStatus($reportId, 'resolved', $adminNotes)) {
            return ['success' => false, 'message' => 'The report status could not be updated after saving the GWA.'];
        }

        return [
            'success' => true,
            'message' => 'The corrected GWA was applied and the report was marked as resolved.',
            'report' => $report,
            'applied_gwa' => $reportedGwa,
        ];
    }

    private function buildBaseQuery(): string
    {
        $currentGwaSelect = $this->tableHasColumn('student_data', 'gwa')
            ? 'sd.gwa AS current_gwa'
            : 'NULL AS current_gwa';

        return "
            SELECT
                r.*,
                u.username,
                u.email,
                u.status AS user_status,
                sd.firstname,
                sd.lastname,
                sd.school,
                sd.course,
                {$currentGwaSelect},
                ud.document_type,
                ud.file_name,
                ud.file_path,
                ud.status AS document_status,
                dt.name AS document_name
            FROM {$this->table} r
            JOIN users u ON u.id = r.user_id
            LEFT JOIN student_data sd ON sd.student_id = r.user_id
            LEFT JOIN user_documents ud ON ud.id = r.document_id
            LEFT JOIN document_types dt ON dt.code = ud.document_type
        ";
    }

    private function saveStudentGwa(int $userId, float $gwa): bool
    {
        if ($userId <= 0 || !$this->studentDataHasGwaColumn()) {
            return false;
        }

        try {
            $check = $this->pdo->prepare('SELECT student_id FROM student_data WHERE student_id = ? LIMIT 1');
            $check->execute([$userId]);
            $exists = $check->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                $update = $this->pdo->prepare('UPDATE student_data SET gwa = ? WHERE student_id = ?');
                return $update->execute([$gwa, $userId]);
            }

            $insert = $this->pdo->prepare('INSERT INTO student_data (student_id, gwa) VALUES (?, ?)');
            return $insert->execute([$userId, $gwa]);
        } catch (Throwable $e) {
            error_log('GwaIssueReport saveStudentGwa error: ' . $e->getMessage());
            return false;
        }
    }

    private function studentDataHasGwaColumn(): bool
    {
        return $this->tableHasColumn('student_data', 'gwa');
    }

    private function tableHasColumn(string $tableName, string $columnName): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$tableName, $columnName]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            error_log('GwaIssueReport tableHasColumn error: ' . $e->getMessage());
            return false;
        }
    }

    private function normalizeNullableString(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
