<?php
// Controller/document_controller.php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Models/UserDocument.php';
require_once __DIR__ . '/../Models/ActivityLog.php';

class DocumentController {
    private $documentModel;
    private $activityLog;
    private $uploadDir;
    
    public function __construct($pdo) {
        $this->documentModel = new UserDocument($pdo);
        $this->activityLog = new ActivityLog($pdo);
        $this->uploadDir = __DIR__ . '/../public/uploads/documents/';
    }
    
    public function uploadDocument($userId, $documentType, $file) {
        $result = ['success' => false, 'message' => '', 'document' => null];
        $existingDocument = $this->documentModel->getUserDocumentByType($userId, $documentType);
        $isUpdate = is_array($existingDocument) && !empty($existingDocument);
        
        // Validate document type
        $validTypes = $this->getValidDocumentTypes();
        if (!in_array($documentType, $validTypes)) {
            $result['message'] = 'Invalid document type.';
            return $result;
        }
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.'
            ];
            
            $errorMsg = $uploadErrors[$file['error']] ?? 'Unknown upload error.';
            $result['message'] = 'Upload error: ' . $errorMsg;
            return $result;
        }
        
        // Validate file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            $result['message'] = 'File size must be less than 5MB.';
            return $result;
        }
        
        // Validate file type
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($mimeType, $allowedTypes) || !in_array($extension, $allowedExtensions)) {
            $result['message'] = 'Only PDF, JPG, and PNG files are allowed.';
            return $result;
        }
        
        // Create user directory
        $userDir = $this->uploadDir . $userId . '/';
        if (!file_exists($userDir)) {
            mkdir($userDir, 0777, true);
        }
        
        // Create document type subdirectory
        $docDir = $userDir . $documentType . '/';
        if (!file_exists($docDir)) {
            mkdir($docDir, 0777, true);
        }
        
        // Generate unique filename
        $timestamp = time();
        $filename = $documentType . '_' . $timestamp . '.' . $extension;
        $filepath = $docDir . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Save to database
            $fileData = [
                'file_name' => $file['name'],
                'file_path' => str_replace(__DIR__ . '/../', '', $filepath), // Store relative path
                'file_size' => $file['size'],
                'mime_type' => $mimeType
            ];
            
            $uploaded = $this->documentModel->uploadDocument($userId, $documentType, $fileData);
            
            if ($uploaded) {
                // Get the uploaded document details
                $document = $this->documentModel->getUserDocumentByType($userId, $documentType);
                
                $result['success'] = true;
                $result['message'] = $this->getDocumentTypeName($documentType)
                    . ($isUpdate ? ' updated successfully.' : ' uploaded successfully.')
                    . ' It will be reviewed for verification.';
                $result['document'] = $document;

                $this->activityLog->log($isUpdate ? 'update' : 'upload', 'document', $isUpdate ? 'Updated a document for verification.' : 'Uploaded a document for verification.', [
                    'entity_id' => isset($document['id']) && is_numeric($document['id']) ? (int) $document['id'] : null,
                    'entity_name' => $document['document_type_name'] ?? $this->getDocumentTypeName($documentType),
                    'target_user_id' => (int) $userId,
                    'target_name' => $_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? 'Student',
                    'details' => [
                        'document_type' => $documentType,
                        'file_name' => $file['name'],
                        'replaced_existing_document' => $isUpdate,
                        'status' => 'pending'
                    ]
                ]);
            } else {
                // Delete file if database insert failed
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                $result['message'] = 'Failed to save document information.';
            }
        } else {
            $result['message'] = 'Failed to move uploaded file.';
        }
        
        return $result;
    }
    
    public function uploadMultipleDocuments($userId, $files) {
        $results = [];
        
        foreach ($files as $documentType => $file) {
            if (isset($file) && $file['error'] === UPLOAD_ERR_OK) {
                $results[$documentType] = $this->uploadDocument($userId, $documentType, $file);
            }
        }
        
        return $results;
    }
    
    public function deleteDocument($documentId, $userId) {
        $result = ['success' => false, 'message' => ''];
        $documentLabel = 'User document';
        $documentFileName = '';

        foreach ($this->documentModel->getUserDocuments($userId) as $document) {
            if ((int) ($document['id'] ?? 0) === (int) $documentId) {
                $documentLabel = (string) ($document['document_type_name'] ?? $this->getDocumentTypeName((string) ($document['document_type'] ?? '')));
                $documentFileName = (string) ($document['file_name'] ?? '');
                break;
            }
        }
        
        $deleted = $this->documentModel->deleteDocument($documentId, $userId);
        
        if ($deleted) {
            $result['success'] = true;
            $result['message'] = 'Document deleted successfully.';

            $this->activityLog->log('delete', 'document', 'Deleted a document upload.', [
                'entity_id' => (int) $documentId,
                'entity_name' => $documentLabel,
                'target_user_id' => (int) $userId,
                'target_name' => $_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? 'Student',
                'details' => [
                    'file_name' => $documentFileName
                ]
            ]);
        } else {
            $result['message'] = 'Failed to delete document.';
        }
        
        return $result;
    }
    
    public function getUserDocuments($userId) {
        return $this->documentModel->getUserDocuments($userId);
    }
    
    public function getDocumentTypes() {
        return [
            'id' => 'Valid ID',
            'birth_certificate' => 'Birth Certificate',
            'grades' => 'Transcript of Records',
            'form_138' => 'Form 137/138',
            'good_moral' => 'Good Moral Character',
            'enrollment' => 'Proof of Enrollment',
            'income_tax' => 'Income Tax Return',
            'citizenship_proof' => 'Citizenship / Residency Proof',
            'income_proof' => 'Household Income Proof',
            'special_category_proof' => 'Special Category Proof',
            'certificate_of_indigency' => 'Certificate of Indigency',
            'voters_id' => 'Voter\'s ID/Certificate',
            'barangay_clearance' => 'Barangay Clearance',
            'medical_certificate' => 'Medical Certificate',
            'essay' => 'Personal Essay',
            'recommendation' => 'Recommendation Letter'
        ];
    }
    
    private function getValidDocumentTypes() {
        return array_keys($this->getDocumentTypes());
    }
    
    private function getDocumentTypeName($type) {
        $types = $this->getDocumentTypes();
        return $types[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
    
    public function getDocumentStats($userId) {
        $stats = $this->documentModel->getUserStats($userId);
        
        // Add completion percentage
        $totalTypes = count($this->getDocumentTypes());
        $stats['completion_percentage'] = $totalTypes > 0 
            ? round(($stats['total'] / $totalTypes) * 100) 
            : 0;
        
        return $stats;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please login first']);
        exit();
    }

    if (!isRoleIn(['student'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only student accounts can manage uploaded documents.']);
        exit();
    }

    $csrfValidation = csrfValidateRequest('document_upload');
    if (!$csrfValidation['valid']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $csrfValidation['message']]);
        exit();
    }
    
    $controller = new DocumentController($pdo);
    
    // Check if it's a single file upload
    if (isset($_FILES['document_file']) && isset($_POST['document_type'])) {
        $documentType = trim((string) ($_POST['document_type'] ?? ''));
        $result = $controller->uploadDocument(
            $_SESSION['user_id'],
            $documentType,
            $_FILES['document_file']
        );
        echo json_encode($result);
    }
    // Check if it's multiple file upload
    elseif (isset($_FILES) && isset($_POST['action']) && $_POST['action'] === 'upload_multiple') {
        $files = [];
        foreach ($_FILES as $key => $file) {
            if (strpos($key, 'document_') === 0) {
                $docType = substr($key, 9); // Remove 'document_' prefix
                $files[$docType] = $file;
            }
        }
        $results = $controller->uploadMultipleDocuments($_SESSION['user_id'], $files);
        echo json_encode(['success' => true, 'results' => $results]);
    }
    // Check if it's a delete action
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['document_id'])) {
        $documentId = filter_var($_POST['document_id'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        if ($documentId === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid document selection.']);
            exit();
        }

        $result = $controller->deleteDocument($documentId, $_SESSION['user_id']);
        echo json_encode($result);
    }
    // Get document stats
    elseif (isset($_POST['action']) && $_POST['action'] === 'get_stats') {
        $stats = $controller->getDocumentStats($_SESSION['user_id']);
        echo json_encode(['success' => true, 'stats' => $stats]);
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
    }
    
    exit();
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . normalizeAppUrl('../View/login.php'));
        exit();
    }

    if (!isRoleIn(['student'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only student accounts can view uploaded documents here.']);
        exit();
    }
    
    $controller = new DocumentController($pdo);
    
    if (isset($_GET['action']) && $_GET['action'] === 'get_documents') {
        $documents = $controller->getUserDocuments($_SESSION['user_id']);
        echo json_encode(['success' => true, 'documents' => $documents]);
        exit();
    }
}
?>
