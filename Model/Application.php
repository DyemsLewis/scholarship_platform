<?php
// Model/Application.php
require_once 'Model.php';

class Application extends Model {
    protected $table = 'applications';
    protected $primaryKey = 'id';
    private static array $columnCache = [];

    private function hasColumn(string $columnName): bool
    {
        if (isset(self::$columnCache[$columnName])) {
            return self::$columnCache[$columnName];
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
                ':table_name' => $this->table,
                ':column_name' => $columnName,
            ]);
            self::$columnCache[$columnName] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            error_log('Application hasColumn error: ' . $e->getMessage());
            self::$columnCache[$columnName] = false;
        }

        return self::$columnCache[$columnName];
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
        $selectParts = [
            'a.id',
            'a.user_id',
            'a.scholarship_id',
            'a.status',
            'a.probability_score',
            'a.applied_at',
            's.name as scholarship_name',
            'sd.provider',
            'sd.deadline'
        ];

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

        $limit = max(1, min(10, (int) $limit));

        $stmt = $this->pdo->prepare("
            SELECT " . implode(",\n                   ", $selectParts) . "
            FROM applications a
            JOIN scholarships s ON a.scholarship_id = s.id
            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
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
