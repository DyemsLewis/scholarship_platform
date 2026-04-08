<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Models/UserDocument.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !isRoleIn(['provider', 'admin', 'super_admin'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit();
}

try {
    $documentModel = new UserDocument($pdo);
    $documentTypes = $documentModel->getDocumentTypes();
    $documentTypes = array_map(static function (array $documentType): array {
        return [
            'code' => (string) ($documentType['code'] ?? ''),
            'name' => (string) ($documentType['name'] ?? ''),
            'description' => (string) ($documentType['description'] ?? ''),
        ];
    }, $documentTypes);

    echo json_encode([
        'success' => true,
        'documentTypes' => $documentTypes
    ]);
} catch (Exception $e) {
    error_log('get_document_types error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load document types'
    ]);
}
?>
