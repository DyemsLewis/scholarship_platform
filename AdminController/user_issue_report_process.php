<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once '../Config/db_config.php';
require_once '../Config/access_control.php';
require_once '../Config/csrf.php';
require_once '../Model/UserIssueReport.php';
require_once '../Model/ActivityLog.php';
require_once '../Model/Notification.php';

requireRoles(['admin', 'super_admin'], '../AdminView/reviews.php', 'Only administrators can review user reports.');
if (!canAccessUserIssueReports()) {
    $_SESSION['error'] = 'You do not have permission to review user reports.';
    header('Location: ../AdminView/reviews.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ../AdminView/user_issue_reports.php');
    exit();
}

$csrfValidation = csrfValidateRequest('user_issue_review');
if (!$csrfValidation['valid']) {
    $_SESSION['error'] = $csrfValidation['message'];
    header('Location: ../AdminView/user_issue_reports.php');
    exit();
}

function buildUserIssueReturnUrl(): string
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

    $url = '../AdminView/user_issue_reports.php';
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $reportId = isset($_POST['report_id']) && is_numeric($_POST['report_id'])
        ? (int) $_POST['report_id']
        : 0;

    if ($reportId > 0) {
        $url .= '#user-issue-report-' . $reportId;
    }

    return $url;
}

function redirectUserIssueWithMessage(string $type, string $message): void
{
    $_SESSION[$type] = $message;
    header('Location: ' . buildUserIssueReturnUrl());
    exit();
}

function userIssueCanMarkReviewed(string $status): bool
{
    return $status === 'pending';
}

function userIssueCanClose(string $status): bool
{
    return in_array($status, ['pending', 'reviewed'], true);
}

function notifyUserIssueReporter(PDO $pdo, array $report, string $type, string $title, string $message): void
{
    $userId = isset($report['user_id']) && is_numeric($report['user_id']) ? (int) $report['user_id'] : 0;
    if ($userId <= 0) {
        return;
    }

    try {
        $notificationModel = new Notification($pdo);
        $notificationModel->createForUser(
            $userId,
            $type,
            $title,
            $message,
            [
                'entity_type' => 'user_issue_report',
                'entity_id' => isset($report['id']) && is_numeric($report['id']) ? (int) $report['id'] : null,
                'link_url' => 'profile.php'
            ]
        );
    } catch (Throwable $notificationError) {
        error_log('user_issue_report_process notification error: ' . $notificationError->getMessage());
    }
}

$reportId = isset($_POST['report_id']) && is_numeric($_POST['report_id']) ? (int) $_POST['report_id'] : 0;
$action = trim((string) ($_POST['action'] ?? ''));
$adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));

if ($reportId <= 0) {
    redirectUserIssueWithMessage('error', 'User report not found.');
}

$reportModel = new UserIssueReport($pdo);
if (!$reportModel->ensureTableExists()) {
    redirectUserIssueWithMessage('error', 'The user report queue is not available right now.');
}

$report = $reportModel->getById($reportId);
if (!$report) {
    redirectUserIssueWithMessage('error', 'User report not found.');
}

$currentStatus = strtolower(trim((string) ($report['status'] ?? 'pending')));
$studentName = trim(((string) ($report['firstname'] ?? '')) . ' ' . ((string) ($report['lastname'] ?? '')));
if ($studentName === '') {
    $studentName = (string) ($report['username'] ?? 'Student');
}

$activityLog = new ActivityLog($pdo);
$activityContext = [
    'entity_id' => $reportId,
    'entity_name' => 'User report #' . $reportId,
    'target_user_id' => (int) ($report['user_id'] ?? 0),
    'target_name' => $studentName,
    'details' => [
        'category' => (string) ($report['category'] ?? ''),
        'subject' => (string) ($report['subject'] ?? ''),
        'page_context' => (string) ($report['page_context'] ?? ''),
        'admin_notes' => $adminNotes !== '' ? $adminNotes : null,
    ],
];

switch ($action) {
    case 'reviewed':
        if (!userIssueCanMarkReviewed($currentStatus)) {
            redirectUserIssueWithMessage('error', 'Only pending user reports can be marked as reviewed.');
        }

        if (!$reportModel->updateStatus($reportId, 'reviewed', $adminNotes)) {
            redirectUserIssueWithMessage('error', 'The report could not be marked as reviewed.');
        }

        $activityLog->log('review', 'user_issue_report', 'Marked a user problem report as reviewed.', $activityContext);
        notifyUserIssueReporter(
            $pdo,
            $report,
            'user_issue_report_reviewed',
            'Problem report under review',
            'Your reported issue is now under review.'
                . ($adminNotes !== '' ? ' Note: ' . $adminNotes : '')
        );
        redirectUserIssueWithMessage('success', 'The user report was marked as reviewed.');
        break;

    case 'resolved':
        if (!userIssueCanClose($currentStatus)) {
            redirectUserIssueWithMessage('error', 'This user report is already closed and can no longer be resolved.');
        }

        if (!$reportModel->updateStatus($reportId, 'resolved', $adminNotes)) {
            redirectUserIssueWithMessage('error', 'The report could not be marked as resolved.');
        }

        $activityLog->log('resolve', 'user_issue_report', 'Resolved a user problem report.', $activityContext);
        notifyUserIssueReporter(
            $pdo,
            $report,
            'user_issue_report_resolved',
            'Problem report resolved',
            'Your reported issue has been marked as resolved.'
                . ($adminNotes !== '' ? ' Note: ' . $adminNotes : '')
        );
        redirectUserIssueWithMessage('success', 'The user report was marked as resolved.');
        break;

    case 'rejected':
        if (!userIssueCanClose($currentStatus)) {
            redirectUserIssueWithMessage('error', 'This user report is already closed and can no longer be rejected.');
        }

        if ($adminNotes === '') {
            redirectUserIssueWithMessage('error', 'Please add a short note before rejecting the report.');
        }

        if (!$reportModel->updateStatus($reportId, 'rejected', $adminNotes)) {
            redirectUserIssueWithMessage('error', 'The report could not be rejected.');
        }

        $activityLog->log('reject', 'user_issue_report', 'Rejected a user problem report.', $activityContext);
        notifyUserIssueReporter(
            $pdo,
            $report,
            'user_issue_report_rejected',
            'Problem report rejected',
            'Your reported issue was closed without further action.'
                . ($adminNotes !== '' ? ' Note: ' . $adminNotes : '')
        );
        redirectUserIssueWithMessage('success', 'The user report was rejected.');
        break;

    default:
        redirectUserIssueWithMessage('error', 'Invalid user report action.');
}
