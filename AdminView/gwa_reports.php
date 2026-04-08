<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/helpers.php';
require_once __DIR__ . '/../app/Models/GwaIssueReport.php';

requireRoles(['admin', 'super_admin'], '../AdminView/reviews.php', 'Only administrators can review GWA reports.');
if (!canAccessGwaIssueReports()) {
    $_SESSION['error'] = 'You do not have permission to review GWA reports.';
    header('Location: reviews.php');
    exit();
}

function gwaReportValue($value, string $fallback = 'Not available'): string
{
    $trimmed = trim((string) ($value ?? ''));
    return $trimmed !== '' ? $trimmed : $fallback;
}

function gwaReportNumber($value, string $fallback = 'Not detected'): string
{
    return is_numeric($value) ? number_format((float) $value, 2) : $fallback;
}

function gwaReportReasonLabel(string $reasonCode): string
{
    $map = [
        'wrong_detected_gwa' => 'Wrong detected GWA',
        'gwa_not_detected' => 'GWA not detected',
        'wrong_conversion' => 'Wrong conversion',
        'blurry_scan' => 'Blurry scan',
        'other' => 'Other concern',
    ];

    return $map[$reasonCode] ?? ucwords(str_replace('_', ' ', $reasonCode));
}

function gwaReportFileUrl(?string $relativePath): ?string
{
    return resolveStoredFileUrl($relativePath, '../');
}

function gwaReportPreviewType(?string $relativePath, ?string $fileName = null): string
{
    return storedFilePreviewType($relativePath, $fileName);
}

function gwaReportResolveDocumentMeta(PDO $pdo, array $report): array
{
    static $cache = [];

    $directPath = trim((string) ($report['file_path'] ?? ''));
    if ($directPath !== '') {
        return [
            'file_path' => $directPath,
            'file_name' => trim((string) ($report['file_name'] ?? '')),
            'document_type' => trim((string) ($report['document_type'] ?? '')),
            'document_name' => trim((string) ($report['document_name'] ?? '')),
        ];
    }

    $userId = isset($report['user_id']) && is_numeric($report['user_id']) ? (int) $report['user_id'] : 0;
    if ($userId <= 0) {
        return [
            'file_path' => '',
            'file_name' => '',
            'document_type' => '',
            'document_name' => '',
        ];
    }

    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                ud.file_path,
                ud.file_name,
                ud.document_type,
                dt.name AS document_name
            FROM user_documents ud
            LEFT JOIN document_types dt ON dt.code = ud.document_type
            WHERE ud.user_id = ?
              AND ud.document_type IN ('grades', 'form_138')
            ORDER BY ud.uploaded_at DESC, ud.id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $fallback = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('gwaReportResolveDocumentMeta error: ' . $e->getMessage());
        $fallback = [];
    }

    $cache[$userId] = [
        'file_path' => trim((string) ($fallback['file_path'] ?? '')),
        'file_name' => trim((string) ($fallback['file_name'] ?? '')),
        'document_type' => trim((string) ($fallback['document_type'] ?? '')),
        'document_name' => trim((string) ($fallback['document_name'] ?? '')),
    ];

    return $cache[$userId];
}

function gwaReportStatusLabel(string $status): string
{
    return ucfirst(str_replace('_', ' ', $status));
}

function gwaReportAcademicLabel($school, $course): string
{
    $parts = array_values(array_filter([
        trim((string) $school),
        trim((string) $course),
    ], static fn($value): bool => $value !== ''));

    return !empty($parts) ? implode(' / ', $parts) : 'Not available';
}

function gwaReportCanMarkReviewedUi(string $status): bool
{
    return $status === 'pending';
}

function gwaReportCanCloseUi(string $status): bool
{
    return in_array($status, ['pending', 'reviewed'], true);
}

function gwaReportCanApplyUi(array $report, string $status): bool
{
    return gwaReportCanCloseUi($status) && is_numeric($report['reported_gwa'] ?? null);
}

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'pending', 'reviewed', 'resolved', 'rejected'], true)) {
    $statusFilter = 'all';
}

$reportModel = new GwaIssueReport($pdo);
$reportModel->ensureTableExists();
$stats = $reportModel->getStats();
$reports = $reportModel->getReports($search, $statusFilter, 300);

$reviewsCurrentView = 'gwa_reports';
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();
$pendingReports = (int) ($stats['pending'] ?? 0);
$resolvedReports = (int) ($stats['resolved'] ?? 0);
$rejectedReports = (int) ($stats['rejected'] ?? 0);
$reportedSuggestions = (int) ($stats['with_reported_gwa'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GWA Reports</title>
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
                        <i class="fas fa-flag"></i> GWA Reports
                    </h1>
                    <p>Manual review queue for incorrect OCR and extracted GWA submissions</p>
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
                    <span class="reviews-summary-simple-meta">Submitted OCR and GWA correction reports waiting for review</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">With Proposed GWA</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($reportedSuggestions); ?></strong>
                    <span class="reviews-summary-simple-meta">Reports where the student provided a corrected GWA value</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Resolved Reports</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($resolvedReports); ?></strong>
                    <span class="reviews-summary-simple-meta">Reports already closed after verification or correction</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Rejected Reports</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($rejectedReports); ?></strong>
                    <span class="reviews-summary-simple-meta">Reports closed without applying a change to the student record</span>
                </article>
            </div>

            <section class="reviews-info-panel gwa-reports-panel">
                <div class="provider-reviews-toolbar">
                    <div>
                        <h3>GWA correction queue</h3>
                        <p><?php echo number_format(count($reports)); ?> reports shown</p>
                    </div>
                    <form method="GET" class="provider-reviews-search">
                        <div class="provider-search-field">
                            <i class="fas fa-search"></i>
                            <input
                                type="text"
                                name="search"
                                value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Search by student, email, file, or reason">
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
                        <a href="gwa_reports.php" class="btn btn-outline">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($reports)): ?>
                <div class="provider-reviews-empty">
                    <i class="fas fa-inbox"></i>
                    <h3>No GWA reports found</h3>
                    <p><?php echo ($search !== '' || $statusFilter !== 'all')
                        ? 'Try adjusting the search or filter.'
                        : 'Reported OCR or extracted GWA concerns will appear here for review.'; ?></p>
                </div>
                <?php else: ?>
                <div class="gwa-report-board">
                    <?php foreach ($reports as $report): ?>
                    <?php
                        $studentName = trim(((string) ($report['firstname'] ?? '')) . ' ' . ((string) ($report['lastname'] ?? '')));
                        if ($studentName === '') {
                            $studentName = (string) ($report['username'] ?? 'Student');
                        }
                        $reportStatus = strtolower(trim((string) ($report['status'] ?? 'pending')));
                        $canMarkReviewed = gwaReportCanMarkReviewedUi($reportStatus);
                        $canCloseReport = gwaReportCanCloseUi($reportStatus);
                        $canApplyReportedGwa = gwaReportCanApplyUi($report, $reportStatus);
                        $isClosedReport = in_array($reportStatus, ['resolved', 'rejected'], true);
                        $resolvedDocumentMeta = gwaReportResolveDocumentMeta($pdo, $report);
                        $fileUrl = gwaReportFileUrl($resolvedDocumentMeta['file_path'] ?? null);
                        $documentName = trim((string) ($resolvedDocumentMeta['document_name'] ?? ''));
                        if ($documentName === '') {
                            $documentName = trim((string) ($resolvedDocumentMeta['document_type'] ?? 'Uploaded document'));
                        }
                        if ($documentName === '') {
                            $documentName = 'Uploaded document';
                        }
                        $filePreviewType = gwaReportPreviewType($resolvedDocumentMeta['file_path'] ?? null, $resolvedDocumentMeta['file_name'] ?? $documentName);
                    ?>
                    <article class="gwa-report-card" id="gwa-report-<?php echo (int) $report['id']; ?>">
                        <div class="gwa-report-card-top">
                            <div>
                                <span class="provider-review-label">GWA Report #<?php echo (int) $report['id']; ?></span>
                                <h2><?php echo htmlspecialchars($studentName); ?></h2>
                                <p><?php echo htmlspecialchars(gwaReportValue($report['email'] ?? '')); ?></p>
                            </div>
                            <div class="gwa-report-badges">
                                <span class="gwa-report-status gwa-report-status-<?php echo htmlspecialchars((string) ($report['status'] ?? 'pending')); ?>">
                                    <?php echo htmlspecialchars(gwaReportStatusLabel($reportStatus)); ?>
                                </span>
                                <span class="provider-review-chip <?php echo is_numeric($report['reported_gwa'] ?? null) ? 'good' : 'neutral'; ?>">
                                    <?php echo is_numeric($report['reported_gwa'] ?? null) ? 'Proposed GWA provided' : 'No proposed GWA'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="gwa-value-grid">
                            <div class="gwa-value-item">
                                <span>Current Stored GWA</span>
                                <strong><?php echo htmlspecialchars(gwaReportNumber($report['current_gwa'] ?? null, 'Not saved')); ?></strong>
                            </div>
                            <div class="gwa-value-item">
                                <span>Extracted GWA</span>
                                <strong><?php echo htmlspecialchars(gwaReportNumber($report['extracted_gwa'] ?? null)); ?></strong>
                            </div>
                            <div class="gwa-value-item">
                                <span>Raw OCR Value</span>
                                <strong><?php echo htmlspecialchars(gwaReportNumber($report['raw_ocr_value'] ?? null, 'Not captured')); ?></strong>
                            </div>
                            <div class="gwa-value-item highlight">
                                <span>Reported Correct GWA</span>
                                <strong><?php echo htmlspecialchars(gwaReportNumber($report['reported_gwa'] ?? null, 'Not provided')); ?></strong>
                            </div>
                        </div>

                        <div class="provider-review-meta-grid">
                            <div class="provider-meta-item">
                                <span>Reason</span>
                                <strong><?php echo htmlspecialchars(gwaReportReasonLabel((string) ($report['reason_code'] ?? 'other'))); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>Submitted</span>
                                <strong><?php echo htmlspecialchars(!empty($report['created_at']) ? date('M d, Y h:i A', strtotime((string) $report['created_at'])) : 'Not recorded'); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>School / Course</span>
                                <strong><?php echo htmlspecialchars(gwaReportAcademicLabel($report['school'] ?? '', $report['course'] ?? '')); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>Document</span>
                                <strong><?php echo htmlspecialchars($documentName); ?></strong>
                            </div>
                        </div>

                        <?php if (!empty($report['details'])): ?>
                        <div class="gwa-report-note">
                            <i class="fas fa-circle-info"></i>
                            <div>
                                <strong>Student details</strong>
                                <p><?php echo nl2br(htmlspecialchars((string) $report['details'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($report['admin_notes'])): ?>
                        <div class="gwa-report-admin-history">
                            <strong>Latest admin note</strong>
                            <p><?php echo nl2br(htmlspecialchars((string) $report['admin_notes'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($isClosedReport): ?>
                        <div class="gwa-report-admin-history">
                            <strong>Report closed</strong>
                            <p><?php echo $reportStatus === 'resolved'
                                ? 'This report has already been resolved. Review and rejection actions are no longer available.'
                                : 'This report has already been rejected. Review and resolution actions are no longer available.'; ?></p>
                        </div>
                        <?php endif; ?>

                        <form action="../app/AdminControllers/gwa_issue_process.php" method="POST" class="gwa-report-form">
                            <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                            <?php echo csrfInputField('gwa_report_review'); ?>
                            <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                            <input type="hidden" name="return_search" value="<?php echo htmlspecialchars($search); ?>">
                            <input type="hidden" class="gwa-action-hidden-input">

                            <label for="admin-note-<?php echo (int) $report['id']; ?>" class="gwa-report-form-label">Admin note</label>
                            <textarea
                                id="admin-note-<?php echo (int) $report['id']; ?>"
                                name="admin_notes"
                                class="gwa-admin-notes"
                                placeholder="Add the result of the manual review, what you checked, or why the report was accepted or rejected."
                                <?php echo $isClosedReport ? 'readonly aria-readonly="true"' : ''; ?>><?php echo htmlspecialchars((string) ($report['admin_notes'] ?? '')); ?></textarea>

                            <div class="gwa-report-actions">
                                <?php if ($fileUrl !== null): ?>
                                <button
                                    type="button"
                                    class="btn btn-outline open-gwa-file-modal"
                                    data-file-url="<?php echo htmlspecialchars($fileUrl); ?>"
                                    data-file-name="<?php echo htmlspecialchars($documentName); ?>"
                                    data-file-type="<?php echo htmlspecialchars($filePreviewType); ?>"
                                >
                                    <i class="fas fa-file-arrow-up"></i> View File
                                </button>
                                <?php endif; ?>
                                <?php if ($canMarkReviewed): ?>
                                <button type="submit" name="action" value="reviewed" class="btn btn-outline gwa-action-button" data-action-label="Mark Reviewed">
                                    <i class="fas fa-eye"></i> Mark Reviewed
                                </button>
                                <?php endif; ?>
                                <?php if ($canApplyReportedGwa): ?>
                                <button type="submit" name="action" value="apply_reported_gwa" class="btn btn-primary gwa-action-button" data-action-label="Apply Reported GWA">
                                    <i class="fas fa-check"></i> Apply Reported GWA
                                </button>
                                <?php endif; ?>
                                <?php if ($canCloseReport): ?>
                                <button type="submit" name="action" value="resolved" class="btn btn-outline gwa-action-button" data-action-label="Resolve">
                                    <i class="fas fa-circle-check"></i> Resolve
                                </button>
                                <button type="submit" name="action" value="rejected" class="btn btn-outline danger-action gwa-action-button" data-action-label="Reject Report">
                                    <i class="fas fa-ban"></i> Reject Report
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

    <div id="gwaFilePreviewModal" class="review-file-modal" aria-hidden="true">
        <div class="review-file-modal-content">
            <div class="review-file-modal-header">
                <div class="review-file-heading">
                    <h3><i class="fas fa-file-lines"></i> Uploaded Document</h3>
                    <p id="gwaFilePreviewName">Selected document</p>
                </div>
                <button type="button" class="review-file-close" id="gwaFilePreviewClose" aria-label="Close file preview modal">&times;</button>
            </div>
            <div class="review-file-modal-body">
                <div class="review-file-preview-wrap">
                    <iframe id="gwaFilePreviewFrame" title="GWA report file preview"></iframe>
                    <img id="gwaFilePreviewImage" alt="GWA report file preview">
                </div>
                <p id="gwaFilePreviewFallback" class="review-file-fallback" hidden>Preview is limited for this file type inside the modal.</p>
            </div>
            <div class="review-file-modal-footer">
                <button type="button" class="btn btn-primary" id="gwaFilePreviewCloseButton">Close</button>
            </div>
        </div>
    </div>

    <?php include 'layouts/admin_footer.php'; ?>
    <script>
        const gwaFilePreviewModal = document.getElementById('gwaFilePreviewModal');
        const gwaFilePreviewFrame = document.getElementById('gwaFilePreviewFrame');
        const gwaFilePreviewImage = document.getElementById('gwaFilePreviewImage');
        const gwaFilePreviewName = document.getElementById('gwaFilePreviewName');
        const gwaFilePreviewFallback = document.getElementById('gwaFilePreviewFallback');
        const gwaFilePreviewClose = document.getElementById('gwaFilePreviewClose');
        const gwaFilePreviewCloseButton = document.getElementById('gwaFilePreviewCloseButton');

        function openGwaFilePreviewModal(fileUrl, fileName, fileType) {
            if (!gwaFilePreviewModal || !gwaFilePreviewFrame || !gwaFilePreviewImage || !gwaFilePreviewName || !gwaFilePreviewFallback || !fileUrl) {
                return;
            }

            gwaFilePreviewName.textContent = fileName || 'Uploaded document';
            gwaFilePreviewFrame.style.display = 'none';
            gwaFilePreviewImage.style.display = 'none';
            gwaFilePreviewFrame.removeAttribute('src');
            gwaFilePreviewImage.removeAttribute('src');
            gwaFilePreviewFallback.hidden = true;

            if (fileType === 'image') {
                gwaFilePreviewImage.src = fileUrl;
                gwaFilePreviewImage.style.display = 'block';
            } else {
                gwaFilePreviewFrame.src = fileType === 'pdf' ? fileUrl + '#toolbar=0' : fileUrl;
                gwaFilePreviewFrame.style.display = 'block';
                if (fileType !== 'pdf') {
                    gwaFilePreviewFallback.hidden = false;
                }
            }

            gwaFilePreviewModal.classList.add('active');
        }

        function closeGwaFilePreviewModal() {
            if (!gwaFilePreviewModal || !gwaFilePreviewFrame || !gwaFilePreviewImage) {
                return;
            }

            gwaFilePreviewModal.classList.remove('active');
            gwaFilePreviewFrame.removeAttribute('src');
            gwaFilePreviewImage.removeAttribute('src');
            gwaFilePreviewFrame.style.display = 'none';
            gwaFilePreviewImage.style.display = 'none';
        }

        document.querySelectorAll('.open-gwa-file-modal').forEach((button) => {
            button.addEventListener('click', function() {
                openGwaFilePreviewModal(
                    button.dataset.fileUrl || '',
                    button.dataset.fileName || 'Uploaded document',
                    button.dataset.fileType || 'document'
                );
            });
        });

        if (gwaFilePreviewClose) {
            gwaFilePreviewClose.addEventListener('click', closeGwaFilePreviewModal);
        }

        if (gwaFilePreviewCloseButton) {
            gwaFilePreviewCloseButton.addEventListener('click', closeGwaFilePreviewModal);
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeGwaFilePreviewModal();
            }
        });

        document.addEventListener('click', function(event) {
            if (event.target === gwaFilePreviewModal) {
                closeGwaFilePreviewModal();
            }
        });

        function confirmGwaAction(config) {
            if (window.Swal && typeof window.Swal.fire === 'function') {
                return window.Swal.fire({
                    icon: config.icon || 'question',
                    title: config.title || 'Continue?',
                    text: config.text || '',
                    confirmButtonText: config.confirmButtonText || 'Confirm',
                    showCancelButton: true,
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: config.confirmButtonColor || '#1d4ed8',
                    reverseButtons: true,
                }).then((result) => result.isConfirmed);
            }

            return Promise.resolve(window.confirm(config.text || config.title || 'Continue?'));
        }

        function showGwaActionError(message, focusTarget) {
            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({
                    icon: 'error',
                    title: 'Cannot Continue',
                    text: message,
                    confirmButtonColor: '#c2410c'
                }).then(() => {
                    if (focusTarget) {
                        focusTarget.focus();
                    }
                });
                return;
            }

            window.alert(message);
            if (focusTarget) {
                focusTarget.focus();
            }
        }

        const gwaActionConfigs = {
            reviewed: {
                title: 'Mark Report as Reviewed?',
                text: 'This will keep the GWA report open but mark it as checked.',
                confirmButtonText: 'Mark Reviewed',
                confirmButtonColor: '#2563eb',
                icon: 'question'
            },
            apply_reported_gwa: {
                title: 'Apply Reported GWA?',
                text: 'This will save the reported GWA to the student record and resolve the report.',
                confirmButtonText: 'Apply GWA',
                confirmButtonColor: '#166534',
                icon: 'warning'
            },
            resolved: {
                title: 'Resolve This Report?',
                text: 'Use this when the report has been fully checked and can be closed.',
                confirmButtonText: 'Resolve Report',
                confirmButtonColor: '#166534',
                icon: 'question'
            },
            rejected: {
                title: 'Reject This Report?',
                text: 'Rejecting will close the report without applying a new GWA.',
                confirmButtonText: 'Reject Report',
                confirmButtonColor: '#b91c1c',
                icon: 'warning'
            }
        };

        document.querySelectorAll('.gwa-action-button').forEach((button) => {
            button.addEventListener('click', function(event) {
                event.preventDefault();

                const form = button.closest('.gwa-report-form');
                if (!form) {
                    return;
                }

                const action = button.value || '';
                const noteField = form.querySelector('.gwa-admin-notes');
                const studentName = form.closest('.gwa-report-card')?.querySelector('.gwa-report-card-top h2')?.textContent?.trim() || 'this student';

                if (action === 'rejected' && noteField && noteField.value.trim() === '') {
                    showGwaActionError('Please add a short admin note before rejecting the report.', noteField);
                    return;
                }

                const config = gwaActionConfigs[action] || {
                    title: 'Continue?',
                    text: 'This action will update the GWA report.'
                };

                confirmGwaAction({
                    ...config,
                    text: config.text + ' Student: ' + studentName + '.'
                }).then((confirmed) => {
                    if (!confirmed) {
                        return;
                    }

                    const hiddenActionInput = form.querySelector('.gwa-action-hidden-input');
                    if (hiddenActionInput) {
                        hiddenActionInput.name = 'action';
                        hiddenActionInput.value = action;
                    }

                    HTMLFormElement.prototype.submit.call(form);
                });
            });
        });
    </script>
</body>
</html>
