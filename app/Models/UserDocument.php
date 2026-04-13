<?php
// Model/UserDocument.php
require_once 'Model.php';
require_once 'StudentData.php';

class UserDocument extends Model {
    protected $table = 'user_documents';
    protected $primaryKey = 'id';
    private static ?array $documentTypeColumnCache = null;
    private static ?array $studentDataColumnCache = null;
    private static bool $documentTypesEnsured = false;

    private function getDefaultDocumentTypes(): array
    {
        return [
            ['code' => 'id', 'name' => 'Valid ID', 'description' => 'Government-issued ID (passport, driver\'s license, school ID)', 'icon' => 'id-card'],
            ['code' => 'birth_certificate', 'name' => 'Birth Certificate', 'description' => 'PSA or NSO issued birth certificate', 'icon' => 'baby'],
            ['code' => 'grades', 'name' => 'Transcript of Records', 'description' => 'Official transcript or grade slip', 'icon' => 'scroll'],
            ['code' => 'form_138', 'name' => 'Form 137/138', 'description' => 'Senior high school report card / Form 137 or 138', 'icon' => 'file-lines'],
            ['code' => 'good_moral', 'name' => 'Good Moral Character', 'description' => 'Certificate from school guidance office', 'icon' => 'hand-holding-heart'],
            ['code' => 'enrollment', 'name' => 'Proof of Enrollment', 'description' => 'Certificate of enrollment or registration form', 'icon' => 'user-graduate'],
            ['code' => 'income_tax', 'name' => 'Income Tax Return', 'description' => 'ITR of parents or guardian (if applicable)', 'icon' => 'file-invoice'],
            ['code' => 'citizenship_proof', 'name' => 'Citizenship / Residency Proof', 'description' => 'Birth certificate, passport, or residency document', 'icon' => 'flag'],
            ['code' => 'income_proof', 'name' => 'Household Income Proof', 'description' => 'Certificate of indigency, payslip, ITR, or income certification', 'icon' => 'wallet'],
            ['code' => 'special_category_proof', 'name' => 'Special Category Proof', 'description' => 'PWD ID, 4Ps proof, solo parent proof, IP certification, or similar support document', 'icon' => 'users'],
            ['code' => 'certificate_of_indigency', 'name' => 'Certificate of Indigency', 'description' => 'Barangay or LGU certificate of indigency', 'icon' => 'file-contract'],
            ['code' => 'voters_id', 'name' => 'Voter\'s ID/Certificate', 'description' => 'Voter\'s ID or voter certification', 'icon' => 'id-badge'],
            ['code' => 'barangay_clearance', 'name' => 'Barangay Clearance', 'description' => 'Current barangay clearance', 'icon' => 'file-circle-check'],
            ['code' => 'medical_certificate', 'name' => 'Medical Certificate', 'description' => 'Medical certificate if required by the scholarship', 'icon' => 'file-waveform'],
            ['code' => 'essay', 'name' => 'Personal Essay', 'description' => 'Scholarship essay or personal statement', 'icon' => 'pen-nib'],
            ['code' => 'recommendation', 'name' => 'Recommendation Letter', 'description' => 'Recommendation letter from teacher, adviser, or mentor', 'icon' => 'envelope-open-text'],
        ];
    }

    private function getDocumentTypeTableColumns(): array
    {
        if (self::$documentTypeColumnCache !== null) {
            return self::$documentTypeColumnCache;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM document_types");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            self::$documentTypeColumnCache = array_map(static fn(array $column): string => (string) ($column['Field'] ?? ''), $columns);
        } catch (Throwable $e) {
            error_log('UserDocument getDocumentTypeTableColumns error: ' . $e->getMessage());
            self::$documentTypeColumnCache = ['code', 'name', 'description'];
        }

        return self::$documentTypeColumnCache;
    }

    private function getStudentDataTableColumns(): array
    {
        if (self::$studentDataColumnCache !== null) {
            return self::$studentDataColumnCache;
        }

        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM student_data");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            self::$studentDataColumnCache = array_map(static fn(array $column): string => (string) ($column['Field'] ?? ''), $columns);
        } catch (Throwable $e) {
            error_log('UserDocument getStudentDataTableColumns error: ' . $e->getMessage());
            self::$studentDataColumnCache = ['student_id'];
        }

        return self::$studentDataColumnCache;
    }

    private function canStoreStudentGwa(): bool
    {
        return in_array('gwa', $this->getStudentDataTableColumns(), true);
    }

    private function canStoreStudentShsAverage(): bool
    {
        return in_array('shs_average', $this->getStudentDataTableColumns(), true);
    }

    private function isGradeReviewDocument(?string $documentType): bool
    {
        return in_array((string) $documentType, ['grades', 'form_138'], true);
    }

    private function resolveStoredFileAbsolutePath(?string $storedPath): ?string
    {
        $normalized = trim(str_replace('\\', '/', (string) $storedPath));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || strpos($normalized, '//') === 0) {
            return $normalized;
        }

        return dirname(__DIR__) . '/' . ltrim($normalized, '/');
    }

    public function saveStudentGwa(int $userId, $gwa): bool
    {
        if (!$this->canStoreStudentGwa()) {
            return false;
        }

        $normalized = trim((string) $gwa);
        if ($normalized === '' || !is_numeric($normalized)) {
            return false;
        }

        $formatted = number_format((float) $normalized, 2, '.', '');
        $numericValue = (float) $formatted;
        if ($numericValue < 1.0 || $numericValue > 5.0) {
            return false;
        }

        $existsStmt = $this->pdo->prepare("SELECT COUNT(*) FROM student_data WHERE student_id = ?");
        $existsStmt->execute([$userId]);
        $recordExists = ((int) $existsStmt->fetchColumn()) > 0;

        if ($recordExists) {
            $updateStmt = $this->pdo->prepare("UPDATE student_data SET gwa = ? WHERE student_id = ?");
            return $updateStmt->execute([$formatted, $userId]);
        }

        $insertStmt = $this->pdo->prepare("INSERT INTO student_data (student_id, gwa) VALUES (?, ?)");
        return $insertStmt->execute([$userId, $formatted]);
    }

    public function saveStudentShsAverage(int $userId, $shsAverage): bool
    {
        if (!$this->canStoreStudentShsAverage()) {
            return false;
        }

        $normalized = trim((string) $shsAverage);
        if ($normalized === '' || !is_numeric($normalized)) {
            return false;
        }

        $formatted = number_format((float) $normalized, 2, '.', '');
        $numericValue = (float) $formatted;
        $isAcademicScoreScale = $numericValue >= 1.0 && $numericValue <= 5.0;
        $isPercentageScale = $numericValue >= 60.0 && $numericValue <= 100.0;

        if (!$isAcademicScoreScale && !$isPercentageScale) {
            return false;
        }

        $existsStmt = $this->pdo->prepare("SELECT COUNT(*) FROM student_data WHERE student_id = ?");
        $existsStmt->execute([$userId]);
        $recordExists = ((int) $existsStmt->fetchColumn()) > 0;

        if ($recordExists) {
            $updateStmt = $this->pdo->prepare("UPDATE student_data SET shs_average = ? WHERE student_id = ?");
            return $updateStmt->execute([$formatted, $userId]);
        }

        $insertStmt = $this->pdo->prepare("INSERT INTO student_data (student_id, shs_average) VALUES (?, ?)");
        return $insertStmt->execute([$formatted, $userId]);
    }

    public function clearStudentGwa(int $userId): bool
    {
        if (!$this->canStoreStudentGwa()) {
            return false;
        }

        $stmt = $this->pdo->prepare("UPDATE student_data SET gwa = NULL WHERE student_id = ?");
        return $stmt->execute([$userId]);
    }

    public function clearStudentShsAverage(int $userId): bool
    {
        if (!$this->canStoreStudentShsAverage()) {
            return false;
        }

        $stmt = $this->pdo->prepare("UPDATE student_data SET shs_average = NULL WHERE student_id = ?");
        return $stmt->execute([$userId]);
    }

    private function syncReviewedAcademicScore(int $userId, $reviewedGwa, ?string $documentType): bool
    {
        if (!$this->saveStudentGwa($userId, $reviewedGwa)) {
            return false;
        }

        if ((string) $documentType === 'form_138' && $this->canStoreStudentShsAverage()) {
            if (!$this->saveStudentShsAverage($userId, $reviewedGwa)) {
                return false;
            }
        }

        return true;
    }

    public function updateReviewedAcademicScore(int $userId, $reviewedGwa, ?string $documentType = null): bool
    {
        try {
            $this->pdo->beginTransaction();

            if (!$this->syncReviewedAcademicScore($userId, $reviewedGwa, $documentType)) {
                $this->pdo->rollBack();
                return false;
            }

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Update reviewed academic score error: " . $e->getMessage());
            return false;
        }
    }

    public function ensureDocumentTypes(): bool
    {
        if (self::$documentTypesEnsured) {
            return true;
        }

        try {
            $stmt = $this->pdo->query("SELECT code FROM document_types");
            $existingCodes = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $existingLookup = array_fill_keys(array_map('strval', $existingCodes), true);
            $columns = $this->getDocumentTypeTableColumns();

            foreach ($this->getDefaultDocumentTypes() as $documentType) {
                $code = (string) ($documentType['code'] ?? '');
                if ($code === '' || isset($existingLookup[$code])) {
                    continue;
                }

                $insertColumns = ['code', 'name', 'description'];
                $placeholders = ['?', '?', '?'];
                $values = [
                    $code,
                    (string) ($documentType['name'] ?? $code),
                    (string) ($documentType['description'] ?? ''),
                ];

                if (in_array('max_size', $columns, true)) {
                    $insertColumns[] = 'max_size';
                    $placeholders[] = '?';
                    $values[] = 5 * 1024 * 1024;
                }

                if (in_array('allowed_types', $columns, true)) {
                    $insertColumns[] = 'allowed_types';
                    $placeholders[] = '?';
                    $values[] = 'pdf,jpg,jpeg,png';
                }

                $sql = sprintf(
                    'INSERT INTO document_types (%s) VALUES (%s)',
                    implode(', ', $insertColumns),
                    implode(', ', $placeholders)
                );

                $insert = $this->pdo->prepare($sql);
                $insert->execute($values);
                $existingLookup[$code] = true;
            }

            self::$documentTypesEnsured = true;
            return true;
        } catch (Throwable $e) {
            error_log('UserDocument ensureDocumentTypes error: ' . $e->getMessage());
            return false;
        }
    }

    private function normalizeProviderScopeValue($value): string
    {
        return strtolower(trim((string) $value));
    }

    private function appendProviderScopeToDocumentSql(string &$sql, array &$params, array $scope = [], string $userIdColumn = 'ud.user_id'): void
    {
        if (empty($scope['is_provider'])) {
            return;
        }

        $normalizedOrganization = $this->normalizeProviderScopeValue($scope['organization_name'] ?? '');
        if ($normalizedOrganization === '') {
            $sql .= ' AND 1=0';
            return;
        }

        $sql .= "
            AND EXISTS (
                SELECT 1
                FROM applications scope_app
                JOIN scholarships scope_sch ON scope_app.scholarship_id = scope_sch.id
                LEFT JOIN scholarship_data scope_sd ON scope_sch.id = scope_sd.scholarship_id
                WHERE scope_app.user_id = {$userIdColumn}
                  AND LOWER(TRIM(COALESCE(scope_sd.provider, ''))) = ?
            )
        ";
        $params[] = $normalizedOrganization;
    }
    
    /**
     * Get all documents for a user with details
     */
    public function getUserDocuments($userId, $groupByType = false) {
        $stmt = $this->pdo->prepare("
            SELECT ud.*, dt.name as document_type_name, dt.description as type_description
            FROM {$this->table} ud
            LEFT JOIN document_types dt ON ud.document_type = dt.code
            WHERE ud.user_id = ?
            ORDER BY 
                CASE ud.status 
                    WHEN 'pending' THEN 1
                    WHEN 'verified' THEN 2
                    WHEN 'rejected' THEN 3
                END,
                ud.uploaded_at DESC
        ");
        $stmt->execute([$userId]);
        $documents = $stmt->fetchAll();
        
        if ($groupByType) {
            $grouped = [];
            foreach ($documents as $doc) {
                $grouped[$doc['document_type']] = $doc;
            }
            return $grouped;
        }
        
        return $documents;
    }
    
    /**
     * Get document by type for a user
     */
    public function getUserDocumentByType($userId, $documentType) {
        $stmt = $this->pdo->prepare("
            SELECT ud.*, dt.name as document_type_name 
            FROM {$this->table} ud
            LEFT JOIN document_types dt ON ud.document_type = dt.code
            WHERE ud.user_id = ? AND ud.document_type = ?
            ORDER BY ud.uploaded_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $documentType]);
        return $stmt->fetch();
    }
    
    /**
     * Upload or update document
     */
    public function uploadDocument($userId, $documentType, $fileData) {
        // Begin transaction
        $this->pdo->beginTransaction();
        
        try {
            $this->ensureDocumentTypes();

            // Check if document type exists in document_types
            $stmt = $this->pdo->prepare("SELECT code FROM document_types WHERE code = ?");
            $stmt->execute([$documentType]);
            $typeExists = $stmt->fetch();
            
            if (!$typeExists) {
                throw new Exception('Invalid document type');
            }
            
            // Get existing document
            $existing = $this->getUserDocumentByType($userId, $documentType);
            
            $documentData = [
                'user_id' => $userId,
                'document_type' => $documentType,
                'file_name' => $fileData['file_name'],
                'file_path' => $fileData['file_path'],
                'file_size' => $fileData['file_size'],
                'mime_type' => $fileData['mime_type'],
                'status' => 'pending'
            ];
            
            $result = false;
            
            if ($existing) {
                // Delete old file if exists
                $existingAbsolutePath = $this->resolveStoredFileAbsolutePath($existing['file_path'] ?? null);
                if ($existingAbsolutePath !== null && file_exists($existingAbsolutePath)) {
                    unlink($existingAbsolutePath);
                }
                $result = $this->update($existing['id'], $documentData);
            } else {
                $result = $this->create($documentData);
            }
            
            // Log the upload
            $this->logUpload($userId, $documentType, 'upload', $fileData['file_name']);
            
            $this->pdo->commit();
            return $result;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Upload error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log upload activity
     */
    private function logUpload($userId, $documentType, $action, $fileName) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO upload_history (user_id, document_type, action, file_name, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$userId, $documentType, $action, $fileName, $ip]);
    }
    
    /**
     * Update document status
     */
    public function updateStatus($documentId, $status, $adminNotes = null) {
        $allowedStatuses = ['pending', 'verified', 'rejected'];
        
        if (!in_array($status, $allowedStatuses)) {
            return false;
        }
        
        $data = ['status' => $status];
        
        if ($status === 'verified') {
            $data['verified_at'] = date('Y-m-d H:i:s');
        }
        
        if ($adminNotes !== null) {
            $data['admin_notes'] = $adminNotes;
        }
        
        return $this->update($documentId, $data);
    }

    public function saveAdminNote($documentId, $userId, ?string $adminNote): bool
    {
        $checkStmt = $this->pdo->prepare("
            SELECT id
            FROM {$this->table}
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        $checkStmt->execute([$documentId, $userId]);

        if (!$checkStmt->fetch()) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            UPDATE {$this->table}
            SET admin_notes = ?
            WHERE id = ? AND user_id = ?
        ");

        return $stmt->execute([$adminNote, $documentId, $userId]);
    }
    
    /**
     * Delete document
     */
    public function deleteDocument($documentId, $userId = null) {
        if ($userId) {
            // Verify ownership
            $stmt = $this->pdo->prepare("
                SELECT file_path, document_type, file_name FROM {$this->table} 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$documentId, $userId]);
            $doc = $stmt->fetch();
            
            if ($doc) {
                // Delete file
                $storedAbsolutePath = $this->resolveStoredFileAbsolutePath($doc['file_path'] ?? null);
                if ($storedAbsolutePath !== null && file_exists($storedAbsolutePath)) {
                    unlink($storedAbsolutePath);
                }
                
                // Log deletion
                $this->logUpload($userId, $doc['document_type'], 'delete', $doc['file_name']);
                
                return $this->delete($documentId);
            }
            return false;
        }
        
        return $this->delete($documentId);
    }
    
    /**
     * Get document statistics for a user
     */
    public function getUserStats($userId) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM {$this->table}
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
    
    /**
     * Get all document types
     */
    public function getDocumentTypes() {
        $this->ensureDocumentTypes();

        $stmt = $this->pdo->query("SELECT * FROM document_types ORDER BY name");
        $types = $stmt->fetchAll() ?: [];
        $defaultLookup = [];
        foreach ($this->getDefaultDocumentTypes() as $documentType) {
            $defaultLookup[(string) $documentType['code']] = $documentType;
        }

        foreach ($types as &$type) {
            $code = (string) ($type['code'] ?? '');
            if ($code !== '' && !isset($type['icon']) && isset($defaultLookup[$code]['icon'])) {
                $type['icon'] = $defaultLookup[$code]['icon'];
            }
        }
        unset($type);

        return $types;
    }
    
    /**
     * Check which required documents a user has uploaded for a scholarship
     */
    public function checkScholarshipRequirements($userId, $scholarshipId) {
        // Get required documents for scholarship
        $stmt = $this->pdo->prepare("
            SELECT dr.*, dt.name as document_name
            FROM document_requirements dr
            JOIN document_types dt ON dr.document_type = dt.code
            WHERE dr.scholarship_id = ? AND dr.is_required = 1
        ");
        $stmt->execute([$scholarshipId]);
        $requirements = $stmt->fetchAll();
        
        // Get user's uploaded documents
        $userDocs = $this->getUserDocuments($userId, true);
        
        $result = [
            'total_required' => count($requirements),
            'uploaded' => 0,
            'verified' => 0,
            'pending' => 0,
            'missing' => [],
            'requirements' => []
        ];
        
        foreach ($requirements as $req) {
            $docType = $req['document_type'];
            $status = 'missing';
            $uploadedDoc = null;
            
            if (isset($userDocs[$docType])) {
                $uploadedDoc = $userDocs[$docType];
                $status = $uploadedDoc['status'];
                
                if ($status === 'verified') {
                    $result['verified']++;
                } elseif ($status === 'pending') {
                    $result['pending']++;
                }
                
                $result['uploaded']++;
            } else {
                $result['missing'][] = $req['document_name'];
            }
            
            $result['requirements'][] = [
                'type' => $docType,
                'name' => $req['document_name'],
                'status' => $status,
                'document' => $uploadedDoc
            ];
        }
        
        return $result;
    }
    public function getPendingDocumentsForAdmin($limit = null, array $scope = []) {
        $sql = "
            SELECT 
                ud.*,
                u.username,
                u.email,
                u.role,
                CONCAT(sd.firstname, ' ', sd.lastname) as full_name,
                sd.school,
                sd.course,
                sd.gwa as current_gwa,
                sd.citizenship,
                sd.household_income_bracket,
                sd.special_category,
                dt.name as document_display_name,
                dt.description as document_description
            FROM user_documents ud
            JOIN users u ON ud.user_id = u.id
            LEFT JOIN student_data sd ON u.id = sd.student_id
            LEFT JOIN document_types dt ON ud.document_type = dt.code
            WHERE ud.status = 'pending'
        ";
        $params = [];
        $this->appendProviderScopeToDocumentSql($sql, $params, $scope);

        $sql .= " ORDER BY ud.uploaded_at ASC";
        
        if ($limit) {
            $sql .= " LIMIT " . intval($limit);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all documents for admin with filters
     */
    public function getDocumentsForAdmin($filter = 'all', $search = '', array $scope = []) {
        $sql = "
            SELECT 
                ud.*,
                u.username,
                u.email,
                u.role,
                CONCAT(sd.firstname, ' ', sd.lastname) as full_name,
                sd.school,
                sd.course,
                sd.gwa as current_gwa,
                sd.citizenship,
                sd.household_income_bracket,
                sd.special_category,
                dt.name as document_display_name,
                dt.description as document_description
            FROM user_documents ud
            JOIN users u ON ud.user_id = u.id
            LEFT JOIN student_data sd ON u.id = sd.student_id
            LEFT JOIN document_types dt ON ud.document_type = dt.code
            WHERE 1=1
        ";
        $params = [];

        $this->appendProviderScopeToDocumentSql($sql, $params, $scope);
        
        if ($filter !== 'all') {
            $sql .= " AND ud.status = ?";
            $params[] = $filter;
        }
        
        if (!empty($search)) {
            $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR CONCAT(sd.firstname, ' ', sd.lastname) LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY ud.uploaded_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get document statistics for admin dashboard
     */
    public function getAdminStats(array $scope = []) {
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN ud.status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN ud.status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN ud.status = 'rejected' THEN 1 ELSE 0 END) as rejected
            FROM user_documents ud
            WHERE 1=1
        ";
        $params = [];
        $this->appendProviderScopeToDocumentSql($sql, $params, $scope);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    /**
     * Verify document (admin action)
     */
    public function verifyDocument($documentId, $userId, $reviewedGwa = null, array $profileUpdate = [], ?string $adminNote = null, ?string $documentType = null) {
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                UPDATE user_documents 
                SET status = 'verified', 
                    verified_at = NOW(),
                    admin_notes = ?,
                    rejection_reason = NULL
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$adminNote, $documentId, $userId]);
            
            if ($stmt->rowCount() > 0) {
                $stmt = $this->pdo->prepare("
                    SELECT document_type, file_name FROM user_documents WHERE id = ?
                ");
                $stmt->execute([$documentId]);
                $doc = $stmt->fetch();

                $resolvedDocumentType = $documentType ?? ($doc['document_type'] ?? null);
                if ($reviewedGwa !== null && !$this->syncReviewedAcademicScore((int) $userId, $reviewedGwa, $resolvedDocumentType)) {
                    $this->pdo->rollBack();
                    return false;
                }

                if (!empty($profileUpdate)) {
                    $studentDataModel = new StudentData($this->pdo);
                    if (!$studentDataModel->saveStudentData((int) $userId, $profileUpdate)) {
                        $this->pdo->rollBack();
                        return false;
                    }
                }
                
                // Log verification
                $stmt = $this->pdo->prepare("
                    INSERT INTO upload_history (user_id, document_type, action, file_name, ip_address) 
                    VALUES (?, ?, 'verified', ?, ?)
                ");
                $stmt->execute([$userId, $doc['document_type'], $doc['file_name'], $_SERVER['REMOTE_ADDR']]);
                
                $this->pdo->commit();
                return true;
            }
            
            $this->pdo->rollBack();
            return false;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Verify error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reject document (admin action)
     */
    public function rejectDocument($documentId, $userId, $reason) {
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                UPDATE user_documents 
                SET status = 'rejected',
                    rejection_reason = ?,
                    admin_notes = ?,
                    verified_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$reason, "Rejected: " . $reason, $documentId, $userId]);
            
            if ($stmt->rowCount() > 0) {
                // Get document details for logging
                $stmt = $this->pdo->prepare("
                    SELECT document_type, file_name FROM user_documents WHERE id = ?
                ");
                $stmt->execute([$documentId]);
                $doc = $stmt->fetch();

                if ($this->isGradeReviewDocument($doc['document_type'] ?? null)) {
                    if (!$this->clearStudentGwa((int) $userId)) {
                        $this->pdo->rollBack();
                        return false;
                    }

                    if (($doc['document_type'] ?? null) === 'form_138' && $this->canStoreStudentShsAverage()) {
                        if (!$this->clearStudentShsAverage((int) $userId)) {
                            $this->pdo->rollBack();
                            return false;
                        }
                    }
                }
                
                // Log rejection
                $stmt = $this->pdo->prepare("
                    INSERT INTO upload_history (user_id, document_type, action, file_name, ip_address) 
                    VALUES (?, ?, 'rejected', ?, ?)
                ");
                $stmt->execute([$userId, $doc['document_type'], $doc['file_name'], $_SERVER['REMOTE_ADDR']]);
                
                $this->pdo->commit();
                return true;
            }
            
            $this->pdo->rollBack();
            return false;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Reject error: " . $e->getMessage());
            return false;
        }
    }
}
?>
