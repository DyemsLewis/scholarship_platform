<?php
// process_document_upload.php
require_once __DIR__ . '/../Config/session_bootstrap.php';
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../Config/db_config.php';
require_once '../Model/UserDocument.php';

$docModel = new UserDocument($pdo);

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file'])) {
    $userId = $_SESSION['user_id'];
    $documentType = $_POST['document_type'];
    
    $file = $_FILES['document_file'];
    
    // Validate file
    $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        $response['message'] = 'Invalid file type. Only JPG, PNG, and PDF are allowed.';
    } elseif ($file['size'] > $maxSize) {
        $response['message'] = 'File too large. Maximum size is 5MB.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $response['message'] = 'Upload failed. Error code: ' . $file['error'];
    } else {
        // Create upload directory if it doesn't exist
        $uploadDir = '../public/uploads/documents/' . $userId . '/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $documentType . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Save to database
            $fileData = [
                'file_name' => $filename,
                'file_path' => $filepath,
                'file_size' => $file['size'],
                'mime_type' => $file['type']
            ];
            
            $result = $docModel->uploadDocument($userId, $documentType, $fileData);
            
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'Document uploaded successfully.';
            } else {
                $response['message'] = 'Failed to save document information.';
                // Delete uploaded file if database save fails
                unlink($filepath);
            }
        } else {
            $response['message'] = 'Failed to move uploaded file.';
        }
    }
}

// Return JSON response for AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Redirect for form submission
if ($response['success']) {
    $_SESSION['success_message'] = $response['message'];
} else {
    $_SESSION['error_message'] = $response['message'];
}

header('Location: upload.php#documents');
exit();
?>
