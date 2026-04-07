<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../Model/OcrService.php';

const OCR_API_MAX_UPLOAD_SIZE = 5242880;

function ocrApiJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function ocrApiRequestHeader(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$serverKey])) {
        return trim((string) $_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function ocrApiMimeType(string $path): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = (string) finfo_file($finfo, $path);
    finfo_close($finfo);
    return $mimeType;
}

function ocrApiEnsureDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}

function ocrApiSafeTempFile(string $extension): string
{
    $tempDir = __DIR__ . '/../public/temp/ocr_api/';
    ocrApiEnsureDirectory($tempDir);

    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $random = substr(md5(uniqid((string) mt_rand(), true)), 0, 16);
    }

    return $tempDir . 'ocr_api_' . date('Ymd_His') . '_' . $random . '.' . $extension;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ocrApiJsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
}

$config = OcrService::loadConfig();
$expectedKey = trim((string) ($config['endpoint_api_key'] ?? ''));
$requestKey = trim((string) ($_POST['api_key'] ?? ''));
if ($requestKey === '') {
    $requestKey = ocrApiRequestHeader('X-OCR-API-Key');
}

if ($expectedKey !== '') {
    if ($requestKey === '' || !hash_equals($expectedKey, $requestKey)) {
        ocrApiJsonResponse(401, [
            'success' => false,
            'message' => 'Unauthorized OCR request.'
        ]);
    }
} else {
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
    if (!$isLocalRequest) {
        ocrApiJsonResponse(503, [
            'success' => false,
            'message' => 'Set endpoint_api_key in Config/ocr_config.php before exposing the OCR API publicly.'
        ]);
    }
}

if (!isset($_FILES['document']) || !is_array($_FILES['document'])) {
    ocrApiJsonResponse(422, [
        'success' => false,
        'message' => 'Missing document file.'
    ]);
}

$file = $_FILES['document'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'File is too large for server settings.',
        UPLOAD_ERR_FORM_SIZE => 'File is too large.',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Unable to write uploaded file.',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by a PHP extension.'
    ];

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    ocrApiJsonResponse(422, [
        'success' => false,
        'message' => $uploadErrors[$errorCode] ?? ('Upload failed with code ' . $errorCode . '.')
    ]);
}

if ((int) ($file['size'] ?? 0) > OCR_API_MAX_UPLOAD_SIZE) {
    ocrApiJsonResponse(422, [
        'success' => false,
        'message' => 'Document is too large. Maximum size is 5MB.'
    ]);
}

$mimeType = ocrApiMimeType((string) $file['tmp_name']);
$extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
$allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];
$allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
if (!in_array($mimeType, $allowedMime, true) || !in_array($extension, $allowedExt, true)) {
    ocrApiJsonResponse(422, [
        'success' => false,
        'message' => 'Only PDF, JPG, and PNG files are allowed.'
    ]);
}

$workingFile = ocrApiSafeTempFile($extension);
if (!move_uploaded_file((string) $file['tmp_name'], $workingFile)) {
    ocrApiJsonResponse(500, [
        'success' => false,
        'message' => 'Failed to prepare the document for OCR.'
    ]);
}

$serviceConfig = array_merge($config, ['mode' => 'local']);
$ocrService = new OcrService($serviceConfig);

try {
    $result = $ocrService->processDocument($workingFile, $mimeType, (string) ($file['name'] ?? basename($workingFile)));
} catch (Throwable $e) {
    @unlink($workingFile);
    ocrApiJsonResponse(500, [
        'success' => false,
        'message' => 'OCR processing failed unexpectedly.'
    ]);
}

@unlink($workingFile);

if (!($result['success'] ?? false)) {
    ocrApiJsonResponse(503, [
        'success' => false,
        'message' => (string) ($result['message'] ?? $result['scanner_message'] ?? 'OCR processing failed.')
    ]);
}

$text = trim((string) ($result['text'] ?? ''));
$textPreview = $text;
if (strlen($textPreview) > 6000) {
    $textPreview = substr($textPreview, 0, 6000) . "\n...[OCR preview truncated]";
}

ocrApiJsonResponse(200, [
    'success' => true,
    'provider' => (string) ($result['provider'] ?? 'local_tesseract'),
    'text' => $text,
    'text_preview' => $textPreview,
    'analysis' => is_array($result['analysis'] ?? null) ? $result['analysis'] : [],
    'raw_gwa' => $result['raw_gwa'] ?? null,
    'final_gwa' => $result['final_gwa'] ?? null,
    'scanner_message' => (string) ($result['scanner_message'] ?? ''),
    'processed_at' => date('c')
]);
