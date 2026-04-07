<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/notification_helpers.php';

function redirectWithNotice(string $type, string $title, string $message): void
{
    if ($type === 'success') {
        $_SESSION['upload_success'] = true;
        $_SESSION['message'] = $message;
    } else {
        $_SESSION['upload_error'] = $message;
    }

    $_SESSION['upload_notice_title'] = $title;
    header('Location: ../View/upload.php');
    exit();
}

function ensureGwaIssueTable(PDO $pdo): bool
{
    $sql = "
        CREATE TABLE IF NOT EXISTS gwa_issue_reports (
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
        $pdo->exec($sql);
        return true;
    } catch (Throwable $e) {
        error_log('Failed to create gwa_issue_reports table: ' . $e->getMessage());
        return false;
    }
}

function resolveLatestAcademicDocumentId(PDO $pdo, int $userId): ?int
{
    if ($userId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id
            FROM user_documents
            WHERE user_id = ?
              AND document_type IN ('grades', 'form_138')
            ORDER BY uploaded_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $documentId = $stmt->fetchColumn();

        return is_numeric($documentId) ? (int) $documentId : null;
    } catch (Throwable $e) {
        error_log('resolveLatestAcademicDocumentId error: ' . $e->getMessage());
        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithNotice('error', 'GWA Report Error', 'Invalid request method.');
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['upload_error'] = 'Please login first.';
    $_SESSION['upload_notice_title'] = 'GWA Report Error';
    header('Location: ../View/login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$allowedReasons = [
    'wrong_detected_gwa',
    'gwa_not_detected',
    'wrong_conversion',
    'blurry_scan',
    'other'
];

$reasonCode = trim((string) ($_POST['reason_code'] ?? ''));
if (!in_array($reasonCode, $allowedReasons, true)) {
    redirectWithNotice('error', 'GWA Report Error', 'Please choose a valid reason for the report.');
}

$reportedGwa = null;
$reportedGwaInput = trim((string) ($_POST['reported_gwa'] ?? ''));
if ($reportedGwaInput !== '') {
    $normalizedReportedGwa = str_replace(',', '.', $reportedGwaInput);
    if (!is_numeric($normalizedReportedGwa)) {
        redirectWithNotice('error', 'GWA Report Error', 'Correct GWA must be a valid number between 1.00 and 5.00.');
    }

    $reportedGwaValue = (float) $normalizedReportedGwa;
    if ($reportedGwaValue < 1.0 || $reportedGwaValue > 5.0) {
        redirectWithNotice('error', 'GWA Report Error', 'Correct GWA must be between 1.00 and 5.00.');
    }

    $reportedGwa = round($reportedGwaValue, 2);
}

$details = trim((string) ($_POST['details'] ?? ''));
if (strlen($details) > 1200) {
    $details = substr($details, 0, 1200);
}

$extractedGwa = null;
$extractedGwaInput = trim((string) ($_POST['extracted_gwa'] ?? ''));
if ($extractedGwaInput !== '') {
    $normalizedExtractedGwa = str_replace(',', '.', $extractedGwaInput);
    if (is_numeric($normalizedExtractedGwa)) {
        $candidate = (float) $normalizedExtractedGwa;
        if ($candidate >= 1.0 && $candidate <= 5.0) {
            $extractedGwa = round($candidate, 2);
        }
    }
}

if ($extractedGwa === null && isset($_SESSION['last_ocr_final_gwa']) && is_numeric($_SESSION['last_ocr_final_gwa'])) {
    $sessionExtracted = (float) $_SESSION['last_ocr_final_gwa'];
    if ($sessionExtracted >= 1.0 && $sessionExtracted <= 5.0) {
        $extractedGwa = round($sessionExtracted, 2);
    }
}

$rawOcrValue = null;
if (isset($_SESSION['last_ocr_raw_gwa']) && is_numeric($_SESSION['last_ocr_raw_gwa'])) {
    $rawCandidate = (float) $_SESSION['last_ocr_raw_gwa'];
    if ($rawCandidate >= 0 && $rawCandidate <= 100) {
        $rawOcrValue = round($rawCandidate, 2);
    }
}

$documentId = 0;
if (isset($_POST['document_id']) && is_numeric($_POST['document_id'])) {
    $documentId = (int) $_POST['document_id'];
} elseif (isset($_SESSION['last_uploaded_tor_document_id']) && is_numeric($_SESSION['last_uploaded_tor_document_id'])) {
    $documentId = (int) $_SESSION['last_uploaded_tor_document_id'];
} else {
    $documentId = resolveLatestAcademicDocumentId($pdo, $userId) ?? 0;
}
$documentId = $documentId > 0 ? $documentId : null;

if (!ensureGwaIssueTable($pdo)) {
    redirectWithNotice('error', 'GWA Report Error', 'Unable to store report right now. Please try again later.');
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO gwa_issue_reports
            (user_id, document_id, extracted_gwa, reported_gwa, raw_ocr_value, reason_code, details, status, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");

    $saved = $stmt->execute([
        $userId,
        $documentId,
        $extractedGwa,
        $reportedGwa,
        $rawOcrValue,
        $reasonCode,
        $details !== '' ? $details : null
    ]);

    if (!$saved) {
        redirectWithNotice('error', 'GWA Report Error', 'Report could not be saved. Please try again.');
    }

    try {
        createNotificationsForUsers(
            $pdo,
            getNotificationRecipientIdsByRoles($pdo, ['admin', 'super_admin']),
            'gwa_report_pending_review',
            'New GWA report submitted',
            'A student submitted a GWA correction report that is waiting for manual review.',
            [
                'entity_type' => 'gwa_report',
                'entity_id' => (int) $pdo->lastInsertId(),
                'link_url' => 'gwa_reports.php'
            ]
        );
    } catch (Throwable $notificationError) {
        error_log('report_gwa_issue notification error: ' . $notificationError->getMessage());
    }
} catch (Throwable $e) {
    error_log('GWA report submission error: ' . $e->getMessage());
    redirectWithNotice('error', 'GWA Report Error', 'Report submission failed. Please try again.');
}

$successMessage = 'Your GWA report was submitted for manual verification.';
if ($reportedGwa !== null) {
    $successMessage .= ' Proposed correct GWA: ' . number_format($reportedGwa, 2) . '.';
}

redirectWithNotice('success', 'GWA Report Submitted', $successMessage);
?>
