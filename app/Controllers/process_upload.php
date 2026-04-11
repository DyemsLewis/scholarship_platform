<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Models/UserDocument.php';
require_once __DIR__ . '/../Models/ActivityLog.php';
require_once __DIR__ . '/../Models/OcrService.php';

const MAX_GRADE_UPLOAD_SIZE = 5242880; // 5MB

function redirectWithUploadError(string $message): void
{
    $_SESSION['upload_error'] = $message;
    $_SESSION['upload_notice_title'] = 'Grade Document Scan Result';
    header('Location: ' . normalizeAppUrl('../View/upload.php'));
    exit();
}

function getUploadMimeType(string $tmpPath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = (string) finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    return $mimeType;
}

function normalizeExtension(string $filename): string
{
    return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
}

function buildSafeFilename(string $prefix, string $extension): string
{
    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $random = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
    }

    return $prefix . '_' . date('Ymd_His') . '_' . $random . '.' . $extension;
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}

function loadDocumentTypes(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT code, name FROM document_types ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function resolvePreferredDocumentTypeCode(array $types, array $priorityCodes, array $nameKeywords): ?string
{
    foreach ($priorityCodes as $code) {
        foreach ($types as $type) {
            if (isset($type['code']) && strtolower((string) $type['code']) === $code) {
                return (string) $type['code'];
            }
        }
    }

    foreach ($types as $type) {
        $name = strtolower((string) ($type['name'] ?? ''));
        foreach ($nameKeywords as $keyword) {
            if ($keyword !== '' && strpos($name, $keyword) !== false) {
                return (string) $type['code'];
            }
        }
    }

    return null;
}

function ensureDocumentTypeExists(PDO $pdo, string $code, string $name, string $description): ?string
{
    $insert = $pdo->prepare('INSERT INTO document_types (code, name, description) VALUES (?, ?, ?)');
    if ($insert->execute([$code, $name, $description])) {
        return $code;
    }

    $types = loadDocumentTypes($pdo);
    foreach ($types as $type) {
        if (strtolower((string) ($type['code'] ?? '')) === strtolower($code)) {
            return (string) $type['code'];
        }
    }

    return null;
}

function resolveAcademicDocumentType(PDO $pdo, ?string $applicantType): ?string
{
    $normalizedApplicantType = strtolower(trim((string) $applicantType));
    $types = loadDocumentTypes($pdo);
    $isIncomingFreshman = $normalizedApplicantType === 'incoming_freshman';

    if ($isIncomingFreshman) {
        $preferredCode = resolvePreferredDocumentTypeCode(
            $types,
            ['form_138', 'form138', 'report_card', 'reportcard'],
            ['form 138', 'report card', 'reportcard']
        );

        if ($preferredCode !== null) {
            return $preferredCode;
        }

        return ensureDocumentTypeExists($pdo, 'form_138', 'Form 138', 'Senior high school report card / Form 138');
    }

    if (empty($types)) {
        return ensureDocumentTypeExists($pdo, 'grades', 'Academic Record', 'TOR / grade card');
    }

    $preferredCode = resolvePreferredDocumentTypeCode(
        $types,
        ['tor', 'grades', 'transcript_of_records', 'transcript', 'grade_slip'],
        ['transcript', 'tor', 'grade', 'academic record']
    );

    if ($preferredCode !== null) {
        return $preferredCode;
    }

    return ensureDocumentTypeExists($pdo, 'grades', 'Academic Record', 'TOR / grade card');
}

function getAcademicDocumentLabel(string $documentType): string
{
    return strtolower(trim($documentType)) === 'form_138' ? 'Form 138' : 'academic record';
}

function getAcademicEntityName(string $documentType): string
{
    return strtolower(trim($documentType)) === 'form_138' ? 'Form 138' : 'Grades / Academic Record';
}

function resolveStudentApplicantType(PDO $pdo, int $userId): string
{
    $sessionApplicantType = strtolower(trim((string) ($_SESSION['user_applicant_type'] ?? '')));
    if ($sessionApplicantType !== '') {
        return $sessionApplicantType;
    }

    $stmt = $pdo->prepare('SELECT applicant_type FROM student_data WHERE student_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $applicantType = strtolower(trim((string) $stmt->fetchColumn()));

    if ($applicantType !== '') {
        $_SESSION['user_applicant_type'] = $applicantType;
    }

    return $applicantType;
}

function studentDataHasGwaColumn(PDO $pdo): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'student_data'
          AND COLUMN_NAME = 'gwa'
    ");
    $stmt->execute();
    return ((int) $stmt->fetchColumn()) > 0;
}

function studentDataHasShsAverageColumn(PDO $pdo): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'student_data'
          AND COLUMN_NAME = 'shs_average'
    ");
    $stmt->execute();
    return ((int) $stmt->fetchColumn()) > 0;
}

function saveUserGwa(PDO $pdo, int $userId, float $gwa): bool
{
    if (!studentDataHasGwaColumn($pdo)) {
        return false;
    }

    $check = $pdo->prepare('SELECT student_id FROM student_data WHERE student_id = ? LIMIT 1');
    $check->execute([$userId]);
    $exists = $check->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $update = $pdo->prepare('UPDATE student_data SET gwa = ? WHERE student_id = ?');
        return $update->execute([$gwa, $userId]);
    }

    $insert = $pdo->prepare('INSERT INTO student_data (student_id, gwa) VALUES (?, ?)');
    return $insert->execute([$userId, $gwa]);
}

function saveUserShsAverage(PDO $pdo, int $userId, float $shsAverage): bool
{
    if (!studentDataHasShsAverageColumn($pdo)) {
        return false;
    }

    if ($shsAverage <= 0 || $shsAverage > 100) {
        return false;
    }

    $formattedAverage = number_format($shsAverage, 2, '.', '');
    $check = $pdo->prepare('SELECT student_id FROM student_data WHERE student_id = ? LIMIT 1');
    $check->execute([$userId]);
    $exists = $check->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $update = $pdo->prepare('UPDATE student_data SET shs_average = ? WHERE student_id = ?');
        return $update->execute([$formattedAverage, $userId]);
    }

    $insert = $pdo->prepare('INSERT INTO student_data (student_id, shs_average) VALUES (?, ?)');
    return $insert->execute([$userId, $formattedAverage]);
}

function resolveSavedDocumentId(UserDocument $docModel, int $userId, string $documentType, $savedDocument): int
{
    if (is_numeric($savedDocument) && (int) $savedDocument > 0) {
        return (int) $savedDocument;
    }

    $latestTorDocument = $docModel->getUserDocumentByType($userId, $documentType);
    if (is_array($latestTorDocument) && isset($latestTorDocument['id'])) {
        return (int) $latestTorDocument['id'];
    }

    return 0;
}

function storeOcrSnapshotInSession(array $ocrResult, int $savedDocumentId): void
{
    $_SESSION['last_uploaded_tor_document_id'] = $savedDocumentId > 0 ? $savedDocumentId : null;
    $_SESSION['last_ocr_provider'] = (string) ($ocrResult['provider'] ?? '');
    $_SESSION['last_ocr_raw_gwa'] = isset($ocrResult['raw_gwa']) && is_numeric($ocrResult['raw_gwa'])
        ? (float) $ocrResult['raw_gwa']
        : null;
    $_SESSION['last_ocr_final_gwa'] = isset($ocrResult['final_gwa']) && is_numeric($ocrResult['final_gwa'])
        ? (float) $ocrResult['final_gwa']
        : null;

    $analysis = is_array($ocrResult['analysis'] ?? null) ? $ocrResult['analysis'] : [];
    $_SESSION['last_ocr_method'] = (string) ($analysis['method'] ?? 'none');
    $_SESSION['last_ocr_basis'] = (string) ($analysis['basis'] ?? ($ocrResult['message'] ?? ''));
    $_SESSION['last_ocr_candidates'] = is_array($analysis['candidates'] ?? null)
        ? array_values($analysis['candidates'])
        : [];
    $_SESSION['last_ocr_semester_values'] = is_array($analysis['semester_values'] ?? null)
        ? array_values($analysis['semester_values'])
        : [];

    $ocrTextPreview = trim((string) ($ocrResult['text'] ?? ''));
    if (strlen($ocrTextPreview) > 6000) {
        $ocrTextPreview = substr($ocrTextPreview, 0, 6000) . "\n...[OCR preview truncated]";
    }
    $_SESSION['last_ocr_text_preview'] = $ocrTextPreview;
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['upload_error'] = 'Please login first.';
    header('Location: ' . normalizeAppUrl('../View/login.php'));
    exit();
}

if (!isset($_FILES['grade_file']) || !is_array($_FILES['grade_file'])) {
    redirectWithUploadError('No academic record file was received.');
}

$file = $_FILES['grade_file'];
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
    redirectWithUploadError($uploadErrors[$errorCode] ?? ('Upload failed with code ' . $errorCode . '.'));
}

if ((int) ($file['size'] ?? 0) > MAX_GRADE_UPLOAD_SIZE) {
    redirectWithUploadError('Academic record file is too large. Maximum size is 5MB.');
}

$mimeType = getUploadMimeType((string) $file['tmp_name']);
$extension = normalizeExtension((string) $file['name']);

$allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];
$allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
if (!in_array($mimeType, $allowedMime, true) || !in_array($extension, $allowedExt, true)) {
    redirectWithUploadError('Only PDF, JPG, and PNG academic record files are allowed.');
}

$userId = (int) $_SESSION['user_id'];
$applicantType = resolveStudentApplicantType($pdo, $userId);
$documentType = resolveAcademicDocumentType($pdo, $applicantType);
if ($documentType === null || $documentType === '') {
    redirectWithUploadError('No academic document type is configured in document_types.');
}
$documentLabel = getAcademicDocumentLabel($documentType);
$documentEntityName = getAcademicEntityName($documentType);

$docAbsoluteDir = __DIR__ . '/../public/uploads/documents/' . $userId . '/' . $documentType . '/';
ensureDirectory($docAbsoluteDir);

$storedFilename = buildSafeFilename($documentType === 'form_138' ? 'form138' : 'grades', $extension);
$storedAbsolutePath = $docAbsoluteDir . $storedFilename;
if (!move_uploaded_file((string) $file['tmp_name'], $storedAbsolutePath)) {
    redirectWithUploadError('Failed to save uploaded academic record file.');
}

$relativeFilePath = 'public/uploads/documents/' . $userId . '/' . $documentType . '/' . $storedFilename;
$docModel = new UserDocument($pdo);
$savedDocument = $docModel->uploadDocument($userId, $documentType, [
    'file_name' => (string) $file['name'],
    'file_path' => $relativeFilePath,
    'file_size' => (int) $file['size'],
    'mime_type' => $mimeType
]);

if (!$savedDocument) {
    if (file_exists($storedAbsolutePath)) {
        @unlink($storedAbsolutePath);
    }
    redirectWithUploadError(ucfirst($documentLabel) . ' uploaded but failed to save into documents.');
}

$savedDocumentId = resolveSavedDocumentId($docModel, $userId, $documentType, $savedDocument);

$ocrService = new OcrService();
$ocrResult = $ocrService->processDocument($storedAbsolutePath, $mimeType, (string) $file['name']);
storeOcrSnapshotInSession($ocrResult, $savedDocumentId);

$activityLog = new ActivityLog($pdo);
$ocrProvider = (string) ($ocrResult['provider'] ?? '');
$scannerStatusMessage = (string) ($ocrResult['scanner_message'] ?? '');

if (!($ocrResult['success'] ?? false)) {
    $activityLog->log('upload_academic_record', 'document', 'Uploaded ' . $documentLabel . ' but OCR service was unavailable.', [
        'entity_id' => $savedDocumentId > 0 ? $savedDocumentId : null,
        'entity_name' => $documentEntityName,
        'target_user_id' => $userId,
        'target_name' => $_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? 'Student',
        'details' => [
            'file_name' => (string) $file['name'],
            'document_type' => $documentType,
            'scanner_message' => $scannerStatusMessage,
            'ocr_provider' => $ocrProvider
        ]
    ]);

    $_SESSION['upload_error'] = ucfirst($documentLabel) . ' uploaded and saved to documents, but the scanner could not complete the scan. ' . $scannerStatusMessage;
    $_SESSION['upload_notice_title'] = 'Grade Document Scan Result';
    header('Location: ' . normalizeAppUrl('../View/upload.php'));
    exit();
}

$rawGwaValue = isset($ocrResult['raw_gwa']) && is_numeric($ocrResult['raw_gwa'])
    ? (float) $ocrResult['raw_gwa']
    : null;
$finalGwa = isset($ocrResult['final_gwa']) && is_numeric($ocrResult['final_gwa'])
    ? (float) $ocrResult['final_gwa']
    : null;
$analysis = is_array($ocrResult['analysis'] ?? null) ? $ocrResult['analysis'] : [];

$activityDescription = $finalGwa !== null
    ? 'Uploaded ' . $documentLabel . ' and scanner detected a GWA value.'
    : 'Uploaded ' . $documentLabel . ' but scanner did not detect a GWA value.';
$activityLog->log('upload_academic_record', 'document', $activityDescription, [
    'entity_id' => $savedDocumentId > 0 ? $savedDocumentId : null,
    'entity_name' => $documentEntityName,
    'target_user_id' => $userId,
    'target_name' => $_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? 'Student',
    'details' => [
        'file_name' => (string) $file['name'],
        'document_type' => $documentType,
        'scanner_message' => $scannerStatusMessage,
        'raw_gwa' => $rawGwaValue,
        'final_gwa' => $finalGwa,
        'ocr_method' => (string) ($analysis['method'] ?? 'none'),
        'ocr_provider' => $ocrProvider
    ]
]);

if ($finalGwa !== null) {
    if (strtolower($documentType) === 'form_138') {
        $rawAcademicValue = $rawGwaValue ?? $finalGwa;
        if ($rawAcademicValue !== null) {
            if (saveUserShsAverage($pdo, $userId, (float) $rawAcademicValue)) {
                $_SESSION['user_shs_average'] = number_format((float) $rawAcademicValue, 2, '.', '');
            }
        }
    }

    $gwaSaved = saveUserGwa($pdo, $userId, $finalGwa);
    if ($gwaSaved) {
        $_SESSION['upload_success'] = true;
        $_SESSION['upload_notice_title'] = 'Grade Document Scan Result';
        $_SESSION['extracted_gwa'] = $finalGwa;
        $_SESSION['user_gwa'] = $finalGwa;
        $_SESSION['gwa_updated'] = date('Y-m-d H:i:s');
        $_SESSION['message'] = $scannerStatusMessage . ' ' . ucfirst($documentLabel) . ' uploaded and saved to documents.';
        header('Location: ' . normalizeAppUrl('../View/upload.php'));
        exit();
    }

    $_SESSION['upload_error'] = $scannerStatusMessage . ' ' . ucfirst($documentLabel) . ' was saved, but GWA could not be stored in student data.';
    $_SESSION['upload_notice_title'] = 'Grade Document Scan Result';
    header('Location: ' . normalizeAppUrl('../View/upload.php'));
    exit();
}

$_SESSION['upload_error'] = $scannerStatusMessage . ' ' . ucfirst($documentLabel) . ' uploaded and saved in documents. Please upload a clearer academic record.';
$_SESSION['upload_notice_title'] = 'Grade Document Scan Result';
header('Location: ' . normalizeAppUrl('../View/upload.php'));
exit();
