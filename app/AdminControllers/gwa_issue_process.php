<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Models/GwaIssueReport.php';
require_once __DIR__ . '/../Models/ActivityLog.php';
require_once __DIR__ . '/../Models/Notification.php';

requireRoles(['admin', 'super_admin'], '../AdminView/reviews.php', 'Only administrators can review GWA reports.');
if (!canAccessGwaIssueReports()) {
    $_SESSION['error'] = 'You do not have permission to review GWA reports.';
    header('Location: ' . normalizeAppUrl('../AdminView/reviews.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ' . normalizeAppUrl('../AdminView/gwa_reports.php'));
    exit();
}

$csrfValidation = csrfValidateRequest('gwa_report_review');
if (!$csrfValidation['valid']) {
    $_SESSION['error'] = $csrfValidation['message'];
    header('Location: ' . normalizeAppUrl('../AdminView/gwa_reports.php'));
    exit();
}

function buildGwaReportReturnUrl(): string
{
    $query = [];
    $status = trim((string) ($_POST['return_status'] ?? ''));
    $search = trim((string) ($_POST['return_search'] ?? ''));

    if ($status !== '' && $status !== 'all') {
        $query['status'] = $status;
    }

    if ($search !== '') {
        $query['search'] = $search;
    }

    $url = '../AdminView/gwa_reports.php';
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $reportId = isset($_POST['report_id']) && is_numeric($_POST['report_id'])
        ? (int) $_POST['report_id']
        : 0;

    if ($reportId > 0) {
        $url .= '#gwa-report-' . $reportId;
    }

    return $url;
}

function redirectBackWithMessage(string $type, string $message): void
{
    $_SESSION[$type] = $message;
    header('Location: ' . normalizeAppUrl(buildGwaReportReturnUrl()));
    exit();
}

function gwaReportCanMarkReviewed(string $status): bool
{
    return $status === 'pending';
}

function gwaReportCanClose(string $status): bool
{
    return in_array($status, ['pending', 'reviewed'], true);
}

function gwaReportCanApplyReportedGwa(array $report, string $status): bool
{
    return gwaReportCanClose($status) && isset($report['reported_gwa']) && is_numeric($report['reported_gwa']);
}

$reportId = isset($_POST['report_id']) && is_numeric($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
$action = trim((string) ($_POST['action'] ?? ''));
$adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));

if ($reportId <= 0) {
    redirectBackWithMessage('error', 'GWA report not found.');
}

$reportModel = new GwaIssueReport($pdo);
if (!$reportModel->ensureTableExists()) {
    redirectBackWithMessage('error', 'The GWA report queue is not available right now.');
}

$report = $reportModel->getById($reportId);
if (!$report) {
    redirectBackWithMessage('error', 'GWA report not found.');
}

$currentStatus = strtolower(trim((string) ($report['status'] ?? 'pending')));

$studentName = trim(((string) ($report['firstname'] ?? '')) . ' ' . ((string) ($report['lastname'] ?? '')));
if ($studentName === '') {
    $studentName = (string) ($report['username'] ?? 'Student');
}

$activityLog = new ActivityLog($pdo);
$activityContext = [
    'entity_id' => $reportId,
    'entity_name' => 'GWA report #' . $reportId,
    'target_user_id' => (int) ($report['user_id'] ?? 0),
    'target_name' => $studentName,
    'details' => [
        'reason_code' => (string) ($report['reason_code'] ?? ''),
        'extracted_gwa' => $report['extracted_gwa'] ?? null,
        'reported_gwa' => $report['reported_gwa'] ?? null,
        'admin_notes' => $adminNotes !== '' ? $adminNotes : null,
    ],
];

function notifyGwaReportUser(PDO $pdo, array $report, string $type, string $title, string $message): void
{
    $userId = isset($report['user_id']) && is_numeric($report['user_id'])
        ? (int) $report['user_id']
        : 0;

    if ($userId <= 0) {
        return;
    }

    $reportId = isset($report['id']) && is_numeric($report['id']) ? (int) $report['id'] : null;

    try {
        $notificationModel = new Notification($pdo);
        $notificationModel->createForUser(
            $userId,
            $type,
            $title,
            $message,
            [
                'entity_type' => 'gwa_report',
                'entity_id' => $reportId,
                'link_url' => 'profile.php'
            ]
        );
    } catch (Throwable $notificationError) {
        error_log('gwa_issue_process notification error: ' . $notificationError->getMessage());
    }
}

switch ($action) {
    case 'reviewed':
        if (!gwaReportCanMarkReviewed($currentStatus)) {
            redirectBackWithMessage('error', 'Only pending GWA reports can be marked as reviewed.');
        }

        if (!$reportModel->updateStatus($reportId, 'reviewed', $adminNotes)) {
            redirectBackWithMessage('error', 'The report could not be marked as reviewed.');
        }

        $activityLog->log('review', 'gwa_report', 'Marked a GWA report as reviewed.', $activityContext);
        notifyGwaReportUser(
            $pdo,
            $report,
            'gwa_report_reviewed',
            'GWA report under review',
            'Your GWA correction report is now under manual review.'
                . ($adminNotes !== '' ? ' Note: ' . $adminNotes : '')
        );
        redirectBackWithMessage('success', 'The GWA report was marked as reviewed.');
        break;

    case 'resolved':
        if (!gwaReportCanClose($currentStatus)) {
            redirectBackWithMessage('error', 'This GWA report is already closed and can no longer be resolved.');
        }

        if (!$reportModel->updateStatus($reportId, 'resolved', $adminNotes)) {
            redirectBackWithMessage('error', 'The report could not be marked as resolved.');
        }

        $activityLog->log('resolve', 'gwa_report', 'Marked a GWA report as resolved.', $activityContext);
        notifyGwaReportUser(
            $pdo,
            $report,
            'gwa_report_resolved',
            'GWA report resolved',
            'Your GWA correction report has been resolved.'
                . ($adminNotes !== '' ? ' Note: ' . $adminNotes : '')
        );
        redirectBackWithMessage('success', 'The GWA report was marked as resolved.');
        break;

    case 'rejected':
        if (!gwaReportCanClose($currentStatus)) {
            redirectBackWithMessage('error', 'This GWA report is already closed and can no longer be rejected.');
        }

        if ($adminNotes === '') {
            redirectBackWithMessage('error', 'Please add a short note before rejecting the report.');
        }

        if (!$reportModel->updateStatus($reportId, 'rejected', $adminNotes)) {
            redirectBackWithMessage('error', 'The report could not be rejected.');
        }

        $activityLog->log('reject', 'gwa_report', 'Rejected a GWA report after review.', $activityContext);
        notifyGwaReportUser(
            $pdo,
            $report,
            'gwa_report_rejected',
            'GWA report rejected',
            'Your GWA correction report was rejected.'
                . ($adminNotes !== '' ? ' Note: ' . $adminNotes : '')
        );
        redirectBackWithMessage('success', 'The GWA report was rejected.');
        break;

    case 'apply_reported_gwa':
        if (!gwaReportCanApplyReportedGwa($report, $currentStatus)) {
            redirectBackWithMessage('error', 'Only open GWA reports with a proposed GWA can apply the reported value.');
        }

        $result = $reportModel->applyReportedGwaAndResolve($reportId, $adminNotes);
        if (empty($result['success'])) {
            redirectBackWithMessage('error', (string) ($result['message'] ?? 'The corrected GWA could not be applied.'));
        }

        $activityContext['details']['applied_gwa'] = $result['applied_gwa'] ?? null;
        $activityLog->log('resolve', 'gwa_report', 'Applied the reported GWA to the student record.', $activityContext);
        notifyGwaReportUser(
            $pdo,
            $report,
            'gwa_report_resolved',
            'GWA updated from report',
            'Your reported GWA has been applied to your student record.'
                . (isset($result['applied_gwa']) && is_numeric($result['applied_gwa'])
                    ? ' Updated GWA: ' . number_format((float) $result['applied_gwa'], 2) . '.'
                    : '')
                . ($adminNotes !== '' ? ' Note: ' . $adminNotes : '')
        );
        redirectBackWithMessage('success', (string) ($result['message'] ?? 'The corrected GWA was applied.'));
        break;

    default:
        redirectBackWithMessage('error', 'Invalid GWA report action.');
}
