<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Config/notification_helpers.php';
require_once __DIR__ . '/../Model/UserIssueReport.php';

requireRoles(['student'], '../View/login.php', 'Please log in to report a problem.');

function normalizeUserIssueRedirectUrl(?string $value, bool $appendFragment = false): string
{
    $fallback = '../View/profile.php' . ($appendFragment ? '#report-issue' : '');
    $raw = trim((string) ($value ?? ''));

    if ($raw === '') {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $raw)) {
        $parts = parse_url($raw);
        if (!$parts) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $raw = $path . $query;
    }

    if ($raw === '') {
        return $fallback;
    }

    if (preg_match('#^[A-Za-z0-9_.-]+\.php$#', $raw)) {
        $raw = '../View/' . $raw;
    }

    $path = (string) parse_url($raw, PHP_URL_PATH);
    $query = (string) parse_url($raw, PHP_URL_QUERY);

    $allowed = false;
    if (strpos($raw, '../View/') === 0) {
        $allowed = true;
    } elseif (strpos($path, '/Thesis/View/') === 0) {
        $allowed = true;
    }

    if (!$allowed) {
        return $fallback;
    }

    $normalized = $path !== '' ? $path : $raw;
    if ($query !== '') {
        $normalized .= '?' . $query;
    }

    if ($appendFragment && strpos($normalized, '#report-issue') === false) {
        $normalized .= '#report-issue';
    }

    return $normalized;
}

function redirectToIssueTarget(string $type, string $title, string $message, bool $appendFragment = false): void
{
    $_SESSION['user_issue_flash'] = [
        'type' => $type,
        'title' => $title,
        'message' => $message,
    ];
    header('Location: ' . normalizeUserIssueRedirectUrl($_POST['redirect_url'] ?? null, $appendFragment));
    exit();
}

function storeUserIssueOldInput(array $input): void
{
    $allowedKeys = ['category', 'subject', 'page_context', 'details'];
    $old = [];

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $input) || is_array($input[$key])) {
            continue;
        }

        $old[$key] = trim((string) $input[$key]);
    }

    $_SESSION['user_issue_old'] = $old;
}

function redirectToProfileWithIssueErrors(array $errors, array $oldInput): void
{
    $_SESSION['user_issue_errors'] = array_values($errors);
    storeUserIssueOldInput($oldInput);
    header('Location: ' . normalizeUserIssueRedirectUrl($_POST['redirect_url'] ?? null, true));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectToIssueTarget('error', 'Problem Report', 'Invalid request method for problem reporting.', true);
}

$csrfValidation = csrfValidateRequest('user_issue_report');
if (!$csrfValidation['valid']) {
    redirectToIssueTarget('error', 'Problem Report', $csrfValidation['message'], true);
}

$allowedCategories = [
    'account',
    'documents',
    'applications',
    'scholarships',
    'mapping',
    'ocr',
    'notifications',
    'other',
];

$category = strtolower(trim((string) ($_POST['category'] ?? 'other')));
$subject = trim((string) ($_POST['subject'] ?? ''));
$pageContext = trim((string) ($_POST['page_context'] ?? ''));
$details = trim((string) ($_POST['details'] ?? ''));
$reportedUrl = trim((string) ($_POST['reported_url'] ?? ''));

$errors = [];

if (!in_array($category, $allowedCategories, true)) {
    $errors[] = 'Please choose a valid report category.';
}

if ($subject === '') {
    $errors[] = 'Please enter a short subject for the problem report.';
} elseif ((function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject)) > 180) {
    $errors[] = 'Subject must be 180 characters or fewer.';
}

if ($pageContext !== '' && (function_exists('mb_strlen') ? mb_strlen($pageContext) : strlen($pageContext)) > 180) {
    $errors[] = 'Page or feature must be 180 characters or fewer.';
}

if ($details === '') {
    $errors[] = 'Please describe the problem you encountered.';
} elseif ((function_exists('mb_strlen') ? mb_strlen($details) : strlen($details)) > 3000) {
    $errors[] = 'Problem details must be 3000 characters or fewer.';
}

if (!empty($errors)) {
    redirectToProfileWithIssueErrors($errors, $_POST);
}

$reportModel = new UserIssueReport($pdo);
if (!$reportModel->ensureTableExists()) {
    redirectToIssueTarget('error', 'Problem Report', 'Problem reports are not available right now. Please try again later.', true);
}

$created = $reportModel->createReport(
    (int) $_SESSION['user_id'],
    $category,
    $subject,
    $details,
    $pageContext !== '' ? $pageContext : null,
    $reportedUrl !== '' ? $reportedUrl : null
);

if (!$created) {
    redirectToIssueTarget('error', 'Problem Report', 'Your problem report could not be submitted right now.', true);
}

try {
    $recipientIds = array_merge(
        getNotificationRecipientIdsByRoles($pdo, ['admin'], true),
        getNotificationRecipientIdsByRoles($pdo, ['super_admin'], true)
    );

    $categoryLabel = ucwords(str_replace('_', ' ', $category));
    createNotificationsForUsers(
        $pdo,
        $recipientIds,
        'user_issue_report_submitted',
        'New user problem report',
        'A student submitted a new problem report: ' . $subject . ' (' . $categoryLabel . ').',
        [
            'entity_type' => 'user_issue_report',
            'link_url' => 'user_issue_reports.php'
        ]
    );
} catch (Throwable $notificationError) {
    error_log('report_user_issue notification error: ' . $notificationError->getMessage());
}

unset($_SESSION['user_issue_old'], $_SESSION['user_issue_errors']);
redirectToIssueTarget('success', 'Problem Report Submitted', 'Your problem report was submitted. We will review it as soon as possible.');
