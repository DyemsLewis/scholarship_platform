<?php
require_once __DIR__ . '/../../app/Config/csrf.php';
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
        require_once __DIR__ . '/../../app/Models/Notification.php';
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
                    <li><a href="scholarships.php">Scholarships</a></li>
                    <?php if ($showStudentWidgets): ?>
                    <li><a href="profile.php">Profile</a></li>
                    <li><a href="documents.php">Documents</a></li>
                    <li><a href="applications.php">Applications</a></li>
                    <?php else: ?>
                    <li><a href="how_it_works.php">How It Works</a></li>
                    <li><a href="guest_guide.php">Guest Guide</a></li>
                    <li><a href="faq.php">FAQ</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <div class="footer-links">
                <h3>Learn More</h3>
                <ul>
                    <li><a href="about.php#mission">Our Mission</a></li>
                    <li><a href="about.php#who-we-help">Who We Help</a></li>
                    <li><a href="how_it_works.php">How Matching Works</a></li>
                    <li><a href="faq.php">Common Questions</a></li>
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
        <span class="student-utility-fab-count<?php echo $unreadNotificationCount > 0 ? '' : ' is-hidden'; ?>" id="notificationCenterFabCount"><?php echo $unreadNotificationCount > 9 ? '9+' : (int) $unreadNotificationCount; ?></span>
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
                <span class="notification-center-badge" id="notificationCenterUnreadBadge"><?php echo (int) $unreadNotificationCount; ?> unread</span>
                <form method="POST" action="../app/Controllers/notification_controller.php" class="notification-center-mark-form<?php echo (!empty($userNotifications) && $unreadNotificationCount > 0) ? '' : ' is-hidden'; ?>" id="notificationCenterMarkForm">
                    <input type="hidden" name="action" value="mark_all_read">
                    <?php echo csrfInputField('notification_center'); ?>
                    <input type="hidden" name="redirect" value="" class="notification-center-redirect">
                    <button type="submit" class="btn btn-outline btn-sm">Mark all read</button>
                </form>
                <button type="button" class="report-issue-close" id="notificationCenterClose" aria-label="Close notifications dialog">&times;</button>
            </div>
        </div>

        <div class="notification-center-body" id="notificationCenterBody">
            <?php if (empty($userNotifications)): ?>
            <div class="notification-center-empty" id="notificationCenterEmpty">
                <i class="fas fa-inbox"></i>
                <p>No notifications yet. Updates will appear here as you use the system.</p>
            </div>
            <?php else: ?>
            <div class="notification-center-list" id="notificationCenterList">
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

        <form method="POST" action="../app/Controllers/report_user_issue.php" class="report-issue-form" id="reportIssueForm" novalidate>
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
                        placeholder="Example: Scholarships, Documents, Applications"
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
    const notificationFabCount = document.getElementById('notificationCenterFabCount');
    const notificationUnreadBadge = document.getElementById('notificationCenterUnreadBadge');
    const notificationMarkForm = document.getElementById('notificationCenterMarkForm');
    const notificationCenterBody = document.getElementById('notificationCenterBody');
    const notificationRefreshUrl = <?php echo json_encode(normalizeAppUrl('../app/Controllers/notification_feed.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

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
    let notificationPollTimer = null;
    let notificationRefreshInFlight = false;
    let latestNotificationSignature = '';

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
        refreshNotifications(true);
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

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function notificationIconForType(type) {
        const normalizedType = String(type || '').toLowerCase();
        if (normalizedType.includes('approved') || normalizedType.includes('verified') || normalizedType.includes('resolved')) {
            return 'fa-circle-check';
        }
        if (normalizedType.includes('rejected')) {
            return 'fa-circle-xmark';
        }
        if (normalizedType.includes('reviewed')) {
            return 'fa-clock';
        }
        if (normalizedType.includes('submitted')) {
            return 'fa-paper-plane';
        }
        return 'fa-circle-info';
    }

    function renderNotificationFabCount(unreadCount) {
        if (!notificationFabCount) {
            return;
        }

        if (unreadCount > 0) {
            notificationFabCount.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
            notificationFabCount.classList.remove('is-hidden');
        } else {
            notificationFabCount.textContent = '0';
            notificationFabCount.classList.add('is-hidden');
        }
    }

    function renderNotificationDialogState(payload) {
        if (!notificationCenterBody || !notificationUnreadBadge || !notificationMarkForm) {
            return;
        }

        const unreadCount = Number(payload.unread_count || 0);
        const notifications = Array.isArray(payload.notifications) ? payload.notifications : [];

        notificationUnreadBadge.textContent = `${unreadCount} unread`;
        notificationMarkForm.classList.toggle('is-hidden', !(notifications.length > 0 && unreadCount > 0));

        if (notifications.length === 0) {
            notificationCenterBody.innerHTML = `
                <div class="notification-center-empty" id="notificationCenterEmpty">
                    <i class="fas fa-inbox"></i>
                    <p>No notifications yet. Updates will appear here as you use the system.</p>
                </div>
            `;
            return;
        }

        const itemsHtml = notifications.map((notification) => {
            const icon = notificationIconForType(notification.type);
            const isRead = Boolean(Number(notification.is_read || 0));
            const itemClass = isRead ? 'is-read' : 'is-unread';
            const title = escapeHtml(notification.title || 'Notification');
            const message = escapeHtml(notification.message || '');
            const createdAtLabel = escapeHtml(notification.created_at_label || 'Recently');
            const linkUrl = String(notification.link_url || '').trim();

            if (linkUrl !== '') {
                return `
                    <a class="notification-center-item ${itemClass}" href="${escapeHtml(linkUrl)}">
                        <div class="notification-center-icon">
                            <i class="fas ${icon}"></i>
                        </div>
                        <div class="notification-center-content">
                            <div class="notification-center-meta">
                                <h4>${title}</h4>
                                <span>${createdAtLabel}</span>
                            </div>
                            <p>${message}</p>
                        </div>
                    </a>
                `;
            }

            return `
                <div class="notification-center-item ${itemClass}">
                    <div class="notification-center-icon">
                        <i class="fas ${icon}"></i>
                    </div>
                    <div class="notification-center-content">
                        <div class="notification-center-meta">
                            <h4>${title}</h4>
                            <span>${createdAtLabel}</span>
                        </div>
                        <p>${message}</p>
                    </div>
                </div>
            `;
        }).join('');

        notificationCenterBody.innerHTML = `<div class="notification-center-list" id="notificationCenterList">${itemsHtml}</div>`;
    }

    async function refreshNotifications(force = false) {
        if (!notificationRefreshUrl || notificationRefreshInFlight) {
            return;
        }

        if (!force && document.visibilityState === 'hidden') {
            return;
        }

        notificationRefreshInFlight = true;

        try {
            const response = await fetch(notificationRefreshUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(`Notification refresh failed with ${response.status}`);
            }

            const payload = await response.json();
            if (!payload || payload.success !== true) {
                return;
            }

            const nextSignature = JSON.stringify([
                payload.unread_count || 0,
                Array.isArray(payload.notifications) ? payload.notifications.map((item) => [item.id, item.is_read, item.created_at_label]).flat() : []
            ]);

            if (force || nextSignature !== latestNotificationSignature) {
                latestNotificationSignature = nextSignature;
                renderNotificationFabCount(Number(payload.unread_count || 0));
                renderNotificationDialogState(payload);
            }
        } catch (error) {
            console.error(error);
        } finally {
            notificationRefreshInFlight = false;
        }
    }

    function startNotificationPolling() {
        if (notificationPollTimer !== null) {
            window.clearInterval(notificationPollTimer);
        }

        notificationPollTimer = window.setInterval(() => {
            refreshNotifications(false);
        }, 15000);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshNotifications(true);
        }
    });

    renderNotificationFabCount(<?php echo (int) $unreadNotificationCount; ?>);
    startNotificationPolling();

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

    refreshNotifications(true);
})();
</script>
<?php endif; ?>
