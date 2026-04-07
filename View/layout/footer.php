<?php
require_once __DIR__ . '/../../Config/csrf.php';
$showStudentWidgets = isset($_SESSION['user_id']) && strtolower(trim((string) ($_SESSION['user_role'] ?? ''))) === 'student';
$userIssueOld = ($showStudentWidgets && isset($_SESSION['user_issue_old']) && is_array($_SESSION['user_issue_old']))
    ? $_SESSION['user_issue_old']
    : [];
$userIssueErrors = ($showStudentWidgets && isset($_SESSION['user_issue_errors']) && is_array($_SESSION['user_issue_errors']))
    ? array_values($_SESSION['user_issue_errors'])
    : [];
$userIssueFlash = ($showStudentWidgets && isset($_SESSION['user_issue_flash']) && is_array($_SESSION['user_issue_flash']))
    ? $_SESSION['user_issue_flash']
    : null;
$userNotifications = [];
$unreadNotificationCount = 0;

if (!function_exists('formatStudentWidgetNotificationTimestamp')) {
    function formatStudentWidgetNotificationTimestamp(?string $value): string
    {
        if (!$value) {
            return 'Recently';
        }

        try {
            $date = new DateTime($value);
            $now = new DateTime();
            $diff = $now->getTimestamp() - $date->getTimestamp();

            if ($diff < 60) {
                return 'Just now';
            }
            if ($diff < 3600) {
                return floor($diff / 60) . ' min ago';
            }
            if ($diff < 86400) {
                return floor($diff / 3600) . ' hour' . (floor($diff / 3600) === 1 ? '' : 's') . ' ago';
            }
            if ($diff < 604800) {
                return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1 ? '' : 's') . ' ago';
            }

            return $date->format('M d, Y');
        } catch (Throwable $e) {
            return 'Recently';
        }
    }
}

if ($showStudentWidgets) {
    try {
        require_once __DIR__ . '/../../Model/Notification.php';
        $notificationModel = new Notification($pdo);
        $userNotifications = $notificationModel->getRecentForUser((int) $_SESSION['user_id'], 5);
        $unreadNotificationCount = $notificationModel->countUnreadForUser((int) $_SESSION['user_id']);
    } catch (Throwable $e) {
        error_log('Footer notification widget error: ' . $e->getMessage());
    }

    unset($_SESSION['user_issue_old'], $_SESSION['user_issue_errors'], $_SESSION['user_issue_flash']);
}
?>
<footer>
    <div class="container">
        <div class="footer-content">
            <div class="footer-about">
                <div class="footer-logo">
                    <i class="fas fa-graduation-cap"></i> Scholarship Finder
                </div>
                <p>An intelligent scholarship matching platform for Filipino students with decision support system.</p>
            </div>
            
            <div class="footer-links">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="profile.php">Profile</a></li>
                    <li><a href="upload.php">Upload</a></li>
                    <li><a href="scholarships.php">Scholarships</a></li>
                    <li><a href="wizard.php">Application Wizard</a></li>
                </ul>
            </div>
            
            <div class="footer-links">
                <h3>About Us</h3>
                <ul>
                    <li><a href="#">Our Mission</a></li>
                    <li><a href="#">Who We Help</a></li>
                    <li><a href="#">How Matching Works</a></li>
                    <li><a href="#">Platform Updates</a></li>
                </ul>
            </div>
        </div>
        
        <div class="copyright">
            &copy; <?php echo date('Y'); ?> Scholarship Finder Platform
        </div>
    </div>
</footer>

<?php if ($showStudentWidgets): ?>
<div class="student-utility-stack">
    <button type="button" class="student-utility-fab notification-center-fab" id="notificationCenterFab" aria-label="Open notifications">
        <i class="fas fa-bell"></i>
        <?php if ($unreadNotificationCount > 0): ?>
        <span class="student-utility-fab-count"><?php echo $unreadNotificationCount > 9 ? '9+' : (int) $unreadNotificationCount; ?></span>
        <?php endif; ?>
    </button>

    <button type="button" class="student-utility-fab report-issue-fab" id="reportIssueFab" aria-label="Report a problem">
        <i class="fas fa-life-ring"></i>
    </button>
</div>

<div class="notification-center-modal" id="notificationCenterModal" aria-hidden="true">
    <div class="notification-center-dialog" role="dialog" aria-modal="true" aria-labelledby="notificationCenterTitle">
        <div class="notification-center-dialog-header">
            <div>
                <h3 id="notificationCenterTitle">Notifications</h3>
                <p>Recent updates about your applications, documents, and GWA reports.</p>
            </div>
            <div class="notification-center-dialog-actions">
                <span class="notification-center-badge"><?php echo (int) $unreadNotificationCount; ?> unread</span>
                <?php if (!empty($userNotifications) && $unreadNotificationCount > 0): ?>
                <form method="POST" action="../Controller/notification_controller.php" class="notification-center-mark-form">
                    <input type="hidden" name="action" value="mark_all_read">
                    <?php echo csrfInputField('notification_center'); ?>
                    <input type="hidden" name="redirect" value="" class="notification-center-redirect">
                    <button type="submit" class="btn btn-outline btn-sm">Mark all read</button>
                </form>
                <?php endif; ?>
                <button type="button" class="report-issue-close" id="notificationCenterClose" aria-label="Close notifications dialog">&times;</button>
            </div>
        </div>

        <div class="notification-center-body">
            <?php if (empty($userNotifications)): ?>
            <div class="notification-center-empty">
                <i class="fas fa-inbox"></i>
                <p>No notifications yet. Updates will appear here as you use the system.</p>
            </div>
            <?php else: ?>
            <div class="notification-center-list">
                <?php foreach ($userNotifications as $notification): ?>
                <?php
                    $notificationType = (string) ($notification['type'] ?? 'general');
                    $notificationIcon = 'fa-circle-info';
                    if (str_contains($notificationType, 'approved') || str_contains($notificationType, 'verified') || str_contains($notificationType, 'resolved')) {
                        $notificationIcon = 'fa-circle-check';
                    } elseif (str_contains($notificationType, 'rejected')) {
                        $notificationIcon = 'fa-circle-xmark';
                    } elseif (str_contains($notificationType, 'reviewed')) {
                        $notificationIcon = 'fa-clock';
                    } elseif (str_contains($notificationType, 'submitted')) {
                        $notificationIcon = 'fa-paper-plane';
                    }
                    $notificationLink = trim((string) ($notification['link_url'] ?? ''));
                    $notificationTag = $notificationLink !== '' ? 'a' : 'div';
                ?>
                <<?php echo $notificationTag; ?>
                    class="notification-center-item <?php echo !empty($notification['is_read']) ? 'is-read' : 'is-unread'; ?>"
                    <?php if ($notificationLink !== ''): ?>
                    href="<?php echo htmlspecialchars($notificationLink); ?>"
                    <?php endif; ?>>
                    <div class="notification-center-icon">
                        <i class="fas <?php echo $notificationIcon; ?>"></i>
                    </div>
                    <div class="notification-center-content">
                        <div class="notification-center-meta">
                            <h4><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Notification')); ?></h4>
                            <span><?php echo htmlspecialchars(formatStudentWidgetNotificationTimestamp($notification['created_at'] ?? null)); ?></span>
                        </div>
                        <p><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></p>
                    </div>
                </<?php echo $notificationTag; ?>>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="report-issue-modal" id="reportIssueModal" aria-hidden="true">
    <div class="report-issue-dialog" role="dialog" aria-modal="true" aria-labelledby="reportIssueTitle">
        <div class="report-issue-dialog-header">
            <div>
                <h3 id="reportIssueTitle">Report a Problem</h3>
                <p>Send a quick note if something in the system is not working as expected.</p>
            </div>
            <button type="button" class="report-issue-close" id="reportIssueClose" aria-label="Close report issue dialog">&times;</button>
        </div>

        <form method="POST" action="../Controller/report_user_issue.php" class="report-issue-form" id="reportIssueForm" novalidate>
            <?php echo csrfInputField('user_issue_report'); ?>
            <input type="hidden" name="reported_url" id="reportIssueReportedUrl" value="">
            <input type="hidden" name="redirect_url" id="reportIssueRedirectUrl" value="">

            <div class="report-issue-grid">
                <div class="report-issue-field">
                    <label for="reportIssueCategory">Category</label>
                    <select name="category" id="reportIssueCategory" required>
                        <?php
                        $issueCategoryOptions = [
                            'account' => 'Account',
                            'documents' => 'Documents',
                            'applications' => 'Applications',
                            'scholarships' => 'Scholarships',
                            'mapping' => 'Mapping',
                            'ocr' => 'OCR / GWA',
                            'notifications' => 'Notifications',
                            'other' => 'Other',
                        ];
                        $selectedIssueCategory = (string) ($userIssueOld['category'] ?? 'other');
                        foreach ($issueCategoryOptions as $issueCategoryValue => $issueCategoryLabel):
                        ?>
                        <option value="<?php echo htmlspecialchars($issueCategoryValue); ?>" <?php echo $selectedIssueCategory === $issueCategoryValue ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($issueCategoryLabel); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="report-issue-field">
                    <label for="reportIssuePageContext">Page or Feature</label>
                    <input
                        type="text"
                        name="page_context"
                        id="reportIssuePageContext"
                        maxlength="180"
                        placeholder="Example: Scholarships, Documents, Wizard"
                        value="<?php echo htmlspecialchars((string) ($userIssueOld['page_context'] ?? '')); ?>">
                </div>
            </div>

            <div class="report-issue-field">
                <label for="reportIssueSubject">Subject</label>
                <input
                    type="text"
                    name="subject"
                    id="reportIssueSubject"
                    maxlength="180"
                    placeholder="Short summary of the problem"
                    value="<?php echo htmlspecialchars((string) ($userIssueOld['subject'] ?? '')); ?>"
                    required>
            </div>

            <div class="report-issue-field">
                <label for="reportIssueDetails">What happened?</label>
                <textarea
                    name="details"
                    id="reportIssueDetails"
                    rows="5"
                    maxlength="3000"
                    placeholder="Describe what you expected, what happened instead, and anything that might help us reproduce it."
                    required><?php echo htmlspecialchars((string) ($userIssueOld['details'] ?? '')); ?></textarea>
            </div>

            <div class="report-issue-actions">
                <button type="button" class="btn btn-outline" id="reportIssueCancel">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Submit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const notificationModal = document.getElementById('notificationCenterModal');
    const notificationOpenButton = document.getElementById('notificationCenterFab');
    const notificationCloseButton = document.getElementById('notificationCenterClose');
    const notificationRedirectInputs = document.querySelectorAll('.notification-center-redirect');

    const issueModal = document.getElementById('reportIssueModal');
    const issueOpenButton = document.getElementById('reportIssueFab');
    const issueCloseButton = document.getElementById('reportIssueClose');
    const issueCancelButton = document.getElementById('reportIssueCancel');
    const issueForm = document.getElementById('reportIssueForm');
    const reportedUrlField = document.getElementById('reportIssueReportedUrl');
    const redirectUrlField = document.getElementById('reportIssueRedirectUrl');
    const issueCategory = document.getElementById('reportIssueCategory');
    const issueSubject = document.getElementById('reportIssueSubject');
    const issueDetails = document.getElementById('reportIssueDetails');

    if (!notificationModal || !notificationOpenButton || !issueModal || !issueOpenButton || !issueForm) {
        return;
    }

    const issueErrors = <?php echo json_encode($userIssueErrors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const issueFlash = <?php echo json_encode($userIssueFlash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    function syncBodyLock() {
        if (document.querySelector('.notification-center-modal.active, .report-issue-modal.active')) {
            document.body.classList.add('report-issue-open');
        } else {
            document.body.classList.remove('report-issue-open');
        }
    }

    function closeModal(modal) {
        if (!modal) {
            return;
        }

        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        syncBodyLock();
    }

    function openModal(modal) {
        closeModal(notificationModal);
        closeModal(issueModal);

        if (!modal) {
            return;
        }

        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        syncBodyLock();
    }

    function syncIssueContext() {
        if (reportedUrlField) {
            reportedUrlField.value = window.location.href;
        }

        if (redirectUrlField) {
            redirectUrlField.value = window.location.pathname + window.location.search;
        }

        notificationRedirectInputs.forEach(function (input) {
            input.value = window.location.pathname + window.location.search;
        });
    }

    notificationOpenButton.addEventListener('click', function () {
        syncIssueContext();
        openModal(notificationModal);
    });

    issueOpenButton.addEventListener('click', function () {
        syncIssueContext();
        openModal(issueModal);
    });

    if (notificationCloseButton) {
        notificationCloseButton.addEventListener('click', function () {
            closeModal(notificationModal);
        });
    }

    if (issueCloseButton) {
        issueCloseButton.addEventListener('click', function () {
            closeModal(issueModal);
        });
    }

    if (issueCancelButton) {
        issueCancelButton.addEventListener('click', function () {
            closeModal(issueModal);
        });
    }

    [notificationModal, issueModal].forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        if (notificationModal.classList.contains('active')) {
            closeModal(notificationModal);
        } else if (issueModal.classList.contains('active')) {
            closeModal(issueModal);
        }
    });

    issueForm.addEventListener('submit', function (event) {
        syncIssueContext();

        const categoryValue = issueCategory ? issueCategory.value.trim() : '';
        const subjectValue = issueSubject ? issueSubject.value.trim() : '';
        const detailsValue = issueDetails ? issueDetails.value.trim() : '';

        if (categoryValue === '' || subjectValue === '' || detailsValue === '') {
            event.preventDefault();

            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({
                    icon: 'error',
                    title: 'Incomplete Report',
                    text: 'Please complete the category, subject, and details before submitting.',
                    confirmButtonColor: '#2c5aa0'
                });
            }

            if (categoryValue === '' && issueCategory) {
                issueCategory.focus();
            } else if (subjectValue === '' && issueSubject) {
                issueSubject.focus();
            } else if (issueDetails) {
                issueDetails.focus();
            }
        }
    });

    syncIssueContext();

    if (Array.isArray(issueErrors) && issueErrors.length > 0) {
        openModal(issueModal);
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                icon: 'error',
                title: 'Problem Report',
                html: issueErrors.map(function (item) { return String(item); }).join('<br>'),
                confirmButtonColor: '#2c5aa0'
            });
        }
    } else if (issueFlash && issueFlash.message && window.Swal && typeof window.Swal.fire === 'function') {
        window.Swal.fire({
            icon: issueFlash.type === 'success' ? 'success' : 'error',
            title: issueFlash.title || 'Problem Report',
            text: issueFlash.message,
            confirmButtonColor: '#2c5aa0'
        });
    }
})();
</script>
<?php endif; ?>
