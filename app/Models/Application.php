<?php
// Model/Application.php
require_once 'Model.php';

class Application extends Model {
    protected $table = 'applications';
    protected $primaryKey = 'id';
    private static array $columnCache = [];
    private static array $tableCache = [];

    public function __construct($pdo)
    {
        parent::__construct($pdo);
        $this->ensureAssessmentColumns();
    }

    private function hasColumn(string $columnName): bool
    {
        return $this->hasTableColumn($this->table, $columnName);
    }

    private function hasTableColumn(string $tableName, string $columnName): bool
    {
        $cacheKey = $tableName . '.' . $columnName;
        if (isset(self::$columnCache[$cacheKey])) {
            return self::$columnCache[$cacheKey];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute([
                ':table_name' => $tableName,
                ':column_name' => $columnName,
            ]);
            self::$columnCache[$cacheKey] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            error_log('Application hasTableColumn error: ' . $e->getMessage());
            self::$columnCache[$cacheKey] = false;
        }

        return self::$columnCache[$cacheKey];
    }

    private function hasTable(string $tableName): bool
    {
        if (array_key_exists($tableName, self::$tableCache)) {
            return self::$tableCache[$tableName];
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
            ");
            $stmt->execute([
                ':table_name' => $tableName,
            ]);
            self::$tableCache[$tableName] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            error_log('Application hasTable error: ' . $e->getMessage());
            self::$tableCache[$tableName] = false;
        }

        return self::$tableCache[$tableName];
    }

    public function ensureAssessmentColumns(): void
    {
        $columnDefinitions = [
            'assessment_status' => "ALTER TABLE {$this->table} ADD COLUMN assessment_status VARCHAR(30) NULL DEFAULT NULL AFTER student_responded_at",
            'assessment_schedule_at' => "ALTER TABLE {$this->table} ADD COLUMN assessment_schedule_at DATETIME NULL DEFAULT NULL AFTER assessment_status",
            'assessment_link_override' => "ALTER TABLE {$this->table} ADD COLUMN assessment_link_override VARCHAR(255) NULL DEFAULT NULL AFTER assessment_schedule_at",
            'assessment_site_id' => "ALTER TABLE {$this->table} ADD COLUMN assessment_site_id INT UNSIGNED NULL DEFAULT NULL AFTER assessment_link_override",
            'assessment_notes' => "ALTER TABLE {$this->table} ADD COLUMN assessment_notes TEXT NULL DEFAULT NULL AFTER assessment_site_id",
        ];

        foreach ($columnDefinitions as $columnName => $sql) {
            if ($this->hasColumn($columnName)) {
                continue;
            }

            try {
                $this->pdo->exec($sql);
                self::$columnCache[$this->table . '.' . $columnName] = true;
            } catch (Throwable $e) {
                error_log('Application ensureAssessmentColumns error (' . $columnName . '): ' . $e->getMessage());
            }
        }
    }

    public function getAllowedAssessmentStatuses(): array
    {
        return ['not_started', 'scheduled', 'ready', 'submitted', 'under_review', 'passed', 'failed'];
    }

    public function normalizeAssessmentStatus(?string $value): string
    {
        $status = strtolower(trim((string) $value));
        return in_array($status, $this->getAllowedAssessmentStatuses(), true) ? $status : 'not_started';
    }

    public function updateAssessmentFields(int $applicationId, array $data): bool
    {
        if ($applicationId <= 0) {
            return false;
        }

        $this->ensureAssessmentColumns();

        $allowedColumns = [
            'assessment_status',
            'assessment_schedule_at',
            'assessment_link_override',
            'assessment_site_id',
            'assessment_notes',
        ];

        $payload = [];
        foreach ($allowedColumns as $columnName) {
            if (!array_key_exists($columnName, $data) || !$this->hasColumn($columnName)) {
                continue;
            }

            $payload[$columnName] = $data[$columnName];
        }

        if (empty($payload)) {
            return false;
        }

        return $this->update($applicationId, $payload);
    }
    
    /**
     * Get applications by user
     */
    public function getByUser($userId) {
        $stmt = $this->pdo->prepare("
            SELECT a.*, s.name as scholarship_name, sd.provider
            FROM applications a
            JOIN scholarships s ON a.scholarship_id = s.id
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            WHERE a.user_id = ?
            ORDER BY a.applied_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getTimelineByUser($userId, $limit = 5) {
        $this->ensureAssessmentColumns();

        $selectParts = [
            'a.id',
            'a.user_id',
            'a.scholarship_id',
            'a.status',
            'a.probability_score',
            'a.applied_at',
            's.name as scholarship_name',
            'sd.provider',
            'sd.deadline',
            'sd.assessment_requirement',
            'sd.assessment_link',
            'sd.assessment_details'
        ];

        $selectParts[] = $this->hasTableColumn('scholarship_data', 'assessment_schedule_at')
            ? 'sd.assessment_schedule_at AS shared_assessment_schedule_at'
            : 'NULL AS shared_assessment_schedule_at';

        $selectParts[] = $this->hasColumn('updated_at')
            ? 'a.updated_at'
            : 'a.applied_at AS updated_at';

        $selectParts[] = $this->hasColumn('rejection_reason')
            ? 'a.rejection_reason'
            : 'NULL AS rejection_reason';

        $selectParts[] = $this->hasColumn('student_response_status')
            ? 'a.student_response_status'
            : 'NULL AS student_response_status';

        $selectParts[] = $this->hasColumn('student_responded_at')
            ? 'a.student_responded_at'
            : 'NULL AS student_responded_at';

        $selectParts[] = $this->hasColumn('assessment_status')
            ? 'a.assessment_status'
            : 'NULL AS assessment_status';

        $selectParts[] = $this->hasColumn('assessment_schedule_at')
            ? 'a.assessment_schedule_at'
            : 'NULL AS assessment_schedule_at';

        $selectParts[] = $this->hasColumn('assessment_link_override')
            ? 'a.assessment_link_override'
            : 'NULL AS assessment_link_override';

        $selectParts[] = $this->hasColumn('assessment_site_id')
            ? 'a.assessment_site_id'
            : 'NULL AS assessment_site_id';

        $selectParts[] = $this->hasColumn('assessment_notes')
            ? 'a.assessment_notes'
            : 'NULL AS assessment_notes';

        if ($this->hasTable('scholarship_remote_exam_locations')) {
            $selectParts[] = 'srel.site_name AS assessment_site_name';
            $selectParts[] = 'srel.address AS assessment_site_address';
            $selectParts[] = 'srel.city AS assessment_site_city';
            $selectParts[] = 'srel.province AS assessment_site_province';
        } else {
            $selectParts[] = 'NULL AS assessment_site_name';
            $selectParts[] = 'NULL AS assessment_site_address';
            $selectParts[] = 'NULL AS assessment_site_city';
            $selectParts[] = 'NULL AS assessment_site_province';
        }

        $limit = max(1, min(10, (int) $limit));

        $siteJoinSql = $this->hasTable('scholarship_remote_exam_locations')
            ? 'LEFT JOIN scholarship_remote_exam_locations srel ON srel.id = a.assessment_site_id'
            : '';

        $stmt = $this->pdo->prepare("
            SELECT " . implode(",\n                   ", $selectParts) . "
            FROM applications a
            JOIN scholarships s ON a.scholarship_id = s.id
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
            {$siteJoinSql}
            WHERE a.user_id = ?
            ORDER BY a.applied_at DESC, a.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function acceptScholarship(int $applicationId, int $userId): bool
    {
        if (
            $applicationId <= 0
            || $userId <= 0
            || !$this->hasColumn('student_response_status')
            || !$this->hasColumn('student_responded_at')
        ) {
            return false;
        }

        $updatedAtSql = $this->hasColumn('updated_at')
            ? ', updated_at = CURRENT_TIMESTAMP'
            : '';

        $stmt = $this->pdo->prepare("
            UPDATE {$this->table}
            SET student_response_status = 'accepted',
                student_responded_at = NOW()
                {$updatedAtSql}
            WHERE id = ?
              AND user_id = ?
              AND status = 'approved'
              AND (student_response_status IS NULL OR TRIM(student_response_status) = '')
        ");

        $stmt->execute([$applicationId, $userId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get applications by scholarship
     */
    public function getByScholarship($scholarshipId) {
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.username as user_name, u.email
            FROM applications a
            JOIN users u ON a.user_id = u.id
            WHERE a.scholarship_id = ?
            ORDER BY a.applied_at DESC
        ");
        $stmt->execute([$scholarshipId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Check if user already applied to scholarship
     */
    public function hasUserApplied($userId, $scholarshipId) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count 
            FROM applications 
            WHERE user_id = ? AND scholarship_id = ?
        ");
        $stmt->execute([$userId, $scholarshipId]);
        return $stmt->fetch()['count'] > 0;
    }

    public function getAcceptedScholarshipSummary(int $userId, ?int $excludeScholarshipId = null): ?array
    {
        if (
            $userId <= 0
            || !$this->hasColumn('student_response_status')
            || !$this->hasColumn('student_responded_at')
        ) {
            return null;
        }

        $params = [$userId];
        $excludeSql = '';
        if ($excludeScholarshipId !== null && $excludeScholarshipId > 0) {
            $excludeSql = ' AND a.scholarship_id <> ?';
            $params[] = $excludeScholarshipId;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                a.id,
                a.scholarship_id,
                a.student_responded_at,
                s.name AS scholarship_name,
                sd.provider
            FROM {$this->table} a
            JOIN scholarships s ON s.id = a.scholarship_id
            LEFT JOIN scholarship_data sd ON sd.scholarship_id = s.id
            WHERE a.user_id = ?
              AND a.status = 'approved'
              AND TRIM(COALESCE(a.student_response_status, '')) = 'accepted'
              {$excludeSql}
            ORDER BY COALESCE(a.student_responded_at, a.applied_at) DESC, a.id DESC
            LIMIT 1
        ");
        $stmt->execute($params);

        $acceptedScholarship = $stmt->fetch(PDO::FETCH_ASSOC);
        return $acceptedScholarship ?: null;
    }

    public function hasAcceptedScholarship(int $userId, ?int $excludeScholarshipId = null): bool
    {
        return $this->getAcceptedScholarshipSummary($userId, $excludeScholarshipId) !== null;
    }
    
    /**
     * Create new application
     */
    public function createApplication($userId, $scholarshipId, $probabilityScore = null) {
        if ($this->hasUserApplied($userId, $scholarshipId)) {
            return ['success' => false, 'message' => 'You have already applied to this scholarship'];
        }
        
        $data = [
            'user_id' => $userId,
            'scholarship_id' => $scholarshipId,
            'status' => 'pending',
            'probability_score' => $probabilityScore,
            'applied_at' => date('Y-m-d H:i:s')
        ];
        
        $applicationId = $this->create($data);
        
        if ($applicationId) {
            return ['success' => true, 'application_id' => $applicationId];
        }
        
        return ['success' => false, 'message' => 'Application failed'];
    }
    
    /**
     * Update application status
     */
    public function updateStatus($applicationId, $status) {
        $allowedStatuses = ['pending', 'approved', 'rejected'];
        
        if (!in_array($status, $allowedStatuses)) {
            return false;
        }
        
        return $this->update($applicationId, ['status' => $status]);
    }
    
    /**
     * Get application statistics
     */
    public function getStats($userId = null) {
        if ($userId) {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM applications
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
        } else {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM applications
            ");
        }
        
        return $stmt->fetch();
    }
}
?>
