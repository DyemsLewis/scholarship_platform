<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Models/UserIssueReport.php';

requireRoles(['admin', 'super_admin'], '../AdminView/reviews.php', 'Only administrators can review user problem reports.');
if (!canAccessUserIssueReports()) {
    $_SESSION['error'] = 'You do not have permission to review user problem reports.';
    header('Location: reviews.php');
    exit();
}

function userIssueReportCategoryLabel(string $category): string
{
    $map = [
        'account' => 'Account',
        'documents' => 'Documents',
        'applications' => 'Applications',
        'scholarships' => 'Scholarships',
        'mapping' => 'Mapping',
        'ocr' => 'OCR / GWA',
        'notifications' => 'Notifications',
        'other' => 'Other',
    ];

    return $map[$category] ?? ucwords(str_replace('_', ' ', $category));
}

function userIssueReportStatusLabel(string $status): string
{
    return ucfirst(str_replace('_', ' ', $status));
}

function userIssueReportValue($value, string $fallback = 'Not available'): string
{
    $trimmed = trim((string) ($value ?? ''));
    return $trimmed !== '' ? $trimmed : $fallback;
}

function userIssueReportCanMarkReviewedUi(string $status): bool
{
    return $status === 'pending';
}

function userIssueReportCanCloseUi(string $status): bool
{
    return in_array($status, ['pending', 'reviewed'], true);
}

function userIssueReportSubmittedAt(?string $value): string
{
    if (!$value) {
        return 'Recently';
    }

    try {
        return (new DateTime($value))->format('M d, Y h:i A');
    } catch (Throwable $e) {
        return 'Recently';
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'pending', 'reviewed', 'resolved', 'rejected'], true)) {
    $statusFilter = 'all';
}

$reportModel = new UserIssueReport($pdo);
$reportModel->ensureTableExists();
$stats = $reportModel->getStats();
$reports = $reportModel->getReports($search, $statusFilter, 300);

$reviewsCurrentView = 'user_reports';
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();
$pendingReports = (int) ($stats['pending'] ?? 0);
$reviewedReports = (int) ($stats['reviewed'] ?? 0);
$resolvedReports = (int) ($stats['resolved'] ?? 0);
$rejectedReports = (int) ($stats['rejected'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css?v=<?php echo urlencode((string) $reviewsStyleVersion); ?>">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard provider-reviews-page">
        <div class="container">
            <div class="page-header">
                <div>
                    <h1>
                        <i class="fas fa-life-ring"></i> User Reports
                    </h1>
                    <p>Review problem reports submitted by students when they encounter issues in the system</p>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <p><?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <p><?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?></p>
            </div>
            <?php endif; ?>

            <?php include 'layouts/reviews_nav.php'; ?>

            <div class="reviews-summary-grid reviews-summary-simple-grid">
                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Pending Reports</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($pendingReports); ?></strong>
                    <span class="reviews-summary-simple-meta">New problem reports waiting for the first review</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Under Review</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($reviewedReports); ?></strong>
                    <span class="reviews-summary-simple-meta">Reports acknowledged and currently being checked</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Resolved Reports</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($resolvedReports); ?></strong>
                    <span class="reviews-summary-simple-meta">Reports that already received an action or fix</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Rejected Reports</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($rejectedReports); ?></strong>
                    <span class="reviews-summary-simple-meta">Reports closed without further action after review</span>
                </article>
            </div>

            <section class="reviews-info-panel gwa-reports-panel">
                <div class="provider-reviews-toolbar">
                    <div>
                        <h3>User support queue</h3>
                        <p><?php echo number_format(count($reports)); ?> reports shown</p>
                    </div>
                    <form method="GET" class="provider-reviews-search">
                        <div class="provider-search-field">
                            <i class="fas fa-search"></i>
                            <input
                                type="text"
                                name="search"
                                value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Search by student, subject, category, or feature">
                        </div>
                        <div class="gwa-report-select-wrap">
                            <select name="status" aria-label="Filter report status">
                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="reviewed" <?php echo $statusFilter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Search</button>
                        <?php if ($search !== '' || $statusFilter !== 'all'): ?>
                        <a href="user_issue_reports.php" class="btn btn-outline">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($reports)): ?>
                <div class="provider-reviews-empty">
                    <i class="fas fa-inbox"></i>
                    <h3>No user reports found</h3>
                    <p><?php echo ($search !== '' || $statusFilter !== 'all')
                        ? 'Try adjusting the search or filter.'
                        : 'Problem reports from students will appear here for review.'; ?></p>
                </div>
                <?php else: ?>
                <div class="issue-report-board gwa-report-board">
                    <?php foreach ($reports as $report): ?>
                    <?php
                        $studentName = trim(((string) ($report['firstname'] ?? '')) . ' ' . ((string) ($report['lastname'] ?? '')));
                        if ($studentName === '') {
                            $studentName = (string) ($report['username'] ?? 'Student');
                        }
                        $reportStatus = strtolower(trim((string) ($report['status'] ?? 'pending')));
                        $reportCategory = strtolower(trim((string) ($report['category'] ?? 'other')));
                        $canMarkReviewed = userIssueReportCanMarkReviewedUi($reportStatus);
                        $canCloseReport = userIssueReportCanCloseUi($reportStatus);
                        $isClosedReport = in_array($reportStatus, ['resolved', 'rejected'], true);
                    ?>
                    <article class="issue-report-card gwa-report-card" id="user-issue-report-<?php echo (int) $report['id']; ?>">
                        <div class="issue-report-card-top gwa-report-card-top">
                            <div>
                                <span class="provider-review-label">User Report #<?php echo (int) $report['id']; ?></span>
                                <h2><?php echo htmlspecialchars($studentName); ?></h2>
                                <p><?php echo htmlspecialchars(userIssueReportValue($report['email'] ?? '')); ?></p>
                            </div>
                            <div class="issue-report-badges gwa-report-badges">
                                <span class="issue-report-status gwa-report-status gwa-report-status-<?php echo htmlspecialchars($reportStatus); ?>">
                                    <?php echo htmlspecialchars(userIssueReportStatusLabel($reportStatus)); ?>
                                </span>
                                <span class="provider-review-chip neutral">
                                    <?php echo htmlspecialchars(userIssueReportCategoryLabel($reportCategory)); ?>
                                </span>
                            </div>
                        </div>

                        <div class="provider-review-meta-grid">
                            <div class="provider-meta-item">
                                <span>Subject</span>
                                <strong><?php echo htmlspecialchars(userIssueReportValue($report['subject'] ?? '')); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>Submitted</span>
                                <strong><?php echo htmlspecialchars(userIssueReportSubmittedAt($report['created_at'] ?? null)); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>Page or feature</span>
                                <strong><?php echo htmlspecialchars(userIssueReportValue($report['page_context'] ?? '')); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>Student context</span>
                                <strong><?php echo htmlspecialchars(userIssueReportValue(trim(implode(' / ', array_filter([
                                    trim((string) ($report['school'] ?? '')),
                                    trim((string) ($report['course'] ?? '')),
                                ]))), 'Not available')); ?></strong>
                            </div>
                        </div>

                        <div class="issue-report-note gwa-report-note">
                            <div>
                                <strong>Reported problem</strong>
                                <span><?php echo nl2br(htmlspecialchars((string) ($report['details'] ?? 'No details provided.'))); ?></span>
                            </div>
                        </div>

                        <?php if (!empty($report['reported_url'])): ?>
                        <div class="issue-report-admin-history gwa-report-admin-history">
                            <strong>Reported from</strong>
                            <a href="<?php echo htmlspecialchars((string) $report['reported_url']); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo htmlspecialchars((string) $report['reported_url']); ?>
                            </a>
                        </div>
                        <?php endif; ?>

                        <form method="POST" action="../app/AdminControllers/user_issue_report_process.php" class="issue-report-form gwa-report-form">
                            <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                            <?php echo csrfInputField('user_issue_review'); ?>
                            <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                            <input type="hidden" name="return_search" value="<?php echo htmlspecialchars($search); ?>">

                            <label class="gwa-report-form-label" for="issue-admin-notes-<?php echo (int) $report['id']; ?>">
                                Admin note
                            </label>
                            <textarea
                                id="issue-admin-notes-<?php echo (int) $report['id']; ?>"
                                name="admin_notes"
                                class="gwa-admin-notes"
                                rows="3"
                                placeholder="Add what you checked, what action was taken, or why the report was closed."
                                <?php echo $isClosedReport ? 'readonly' : ''; ?>
                            ><?php echo htmlspecialchars((string) ($report['admin_notes'] ?? '')); ?></textarea>

                            <?php if ($isClosedReport): ?>
                            <div class="issue-report-admin-history gwa-report-admin-history">
                                <strong>Closed state</strong>
                                <span>
                                    <?php echo $reportStatus === 'resolved'
                                        ? 'This report has already been resolved. Review actions are no longer available.'
                                        : 'This report has already been rejected. Review actions are no longer available.'; ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <div class="issue-report-actions provider-review-actions">
                                <?php if ($canMarkReviewed): ?>
                                <button type="submit" name="action" value="reviewed" class="btn btn-outline issue-action-button" data-action-label="Mark Reviewed">
                                    <i class="fas fa-eye"></i> Mark Reviewed
                                </button>
                                <?php endif; ?>

                                <?php if ($canCloseReport): ?>
                                <button type="submit" name="action" value="resolved" class="btn btn-primary issue-action-button" data-action-label="Resolve Report">
                                    <i class="fas fa-circle-check"></i> Resolve
                                </button>
                                <button type="submit" name="action" value="rejected" class="btn btn-outline danger-action issue-action-button" data-action-label="Reject Report">
                                    <i class="fas fa-ban"></i> Reject
                                </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>
    </section>

    <?php include 'layouts/admin_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.issue-action-button').forEach((button) => {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                const form = this.closest('form');
                const action = this.value;
                const actionLabel = this.dataset.actionLabel || 'Continue';
                const noteField = form ? form.querySelector('textarea[name="admin_notes"]') : null;

                if (action === 'rejected' && noteField && noteField.value.trim() === '') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Admin Note Required',
                        text: 'Please add a short note before rejecting this report.',
                        confirmButtonColor: '#3085d6'
                    });
                    if (noteField) {
                        noteField.focus();
                    }
                    return;
                }

                const actionCopy = {
                    reviewed: {
                        title: 'Mark report as reviewed?',
                        text: 'This will show the student that the issue is now being checked.',
                        confirmText: 'Yes, mark reviewed',
                        confirmColor: '#2563eb'
                    },
                    resolved: {
                        title: 'Resolve this report?',
                        text: 'Use this after checking the issue or finishing the needed action.',
                        confirmText: 'Yes, resolve',
                        confirmColor: '#15803d'
                    },
                    rejected: {
                        title: 'Reject this report?',
                        text: 'This will close the report without further action and send your note to the student.',
                        confirmText: 'Yes, reject',
                        confirmColor: '#b91c1c'
                    }
                };

                const config = actionCopy[action] || {
                    title: actionLabel + '?',
                    text: 'Please confirm this action.',
                    confirmText: 'Confirm',
                    confirmColor: '#2563eb'
                };

                Swal.fire({
                    title: config.title,
                    text: config.text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: config.confirmText,
                    confirmButtonColor: config.confirmColor,
                    cancelButtonColor: '#64748b',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed && form) {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit(this);
                            return;
                        }

                        const fallbackActionInput = document.createElement('input');
                        fallbackActionInput.type = 'hidden';
                        fallbackActionInput.name = this.name || 'action';
                        fallbackActionInput.value = this.value || action;
                        form.appendChild(fallbackActionInput);
                        HTMLFormElement.prototype.submit.call(form);
                    }
                });
            });
        });
    });
    </script>
</body>
</html>
