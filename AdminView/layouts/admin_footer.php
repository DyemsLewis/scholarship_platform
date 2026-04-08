<?php
require_once __DIR__ . '/../../app/Config/access_control.php';
$currentRole = getCurrentSessionRole();
$canManageUsers = canAccessStaffAccounts();
$canManageScholarships = canAccessStaffScholarships();
$canManageApplications = canAccessStaffApplications();
$canVerifyDocuments = canAccessStaffDocuments();
$canReviewProviders = canAccessProviderApprovals();
$canReviewScholarships = canAccessScholarshipApprovals();
$canReviewGwaReports = canAccessGwaIssueReports();
$canReviewUserIssueReports = canAccessUserIssueReports();
$canViewActivityLogs = canAccessStaffLogs();
$footerTitle = $currentRole === 'provider' ? 'Scholarship Finder Provider' : 'Scholarship Finder Admin';
$footerDescription = $currentRole === 'provider'
    ? 'Provider scholarship workspace'
    : 'Administrative scholarship workspace';
$adminDashboardUrl = normalizeAppUrl('AdminView/admin_dashboard.php');
$adminProfileUrl = normalizeAppUrl('AdminView/profile.php');
$adminManageUsersUrl = normalizeAppUrl('AdminView/manage_users.php');
$adminManageScholarshipsUrl = normalizeAppUrl('AdminView/manage_scholarships.php');
$adminReviewsUrl = normalizeAppUrl('AdminView/reviews.php');
$adminLogsUrl = normalizeAppUrl('AdminView/activity_logs.php');
?>
        <footer class="admin-footer">
            <div class="container">
                <div class="admin-footer-content">
                    <div class="admin-footer-section admin-footer-brand">
                        <h3><?php echo htmlspecialchars($footerTitle); ?></h3>
                        <p><?php echo htmlspecialchars($footerDescription); ?></p>
                        <span class="admin-footer-role"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $currentRole))); ?></span>
                    </div>
                    <div class="admin-footer-section">
                        <h4>Quick Links</h4>
                        <ul class="admin-footer-links">
                            <li><a href="<?php echo htmlspecialchars($adminDashboardUrl); ?>">Dashboard</a></li>
                            <li><a href="<?php echo htmlspecialchars($adminProfileUrl); ?>">Profile</a></li>
                            <?php if ($canManageUsers): ?>
                            <li><a href="<?php echo htmlspecialchars($adminManageUsersUrl); ?>">Accounts</a></li>
                            <?php endif; ?>
                            <?php if ($canManageScholarships): ?>
                            <li><a href="<?php echo htmlspecialchars($adminManageScholarshipsUrl); ?>">Scholarships</a></li>
                            <?php endif; ?>
                            <?php if ($canManageApplications || $canVerifyDocuments || $canReviewProviders || $canReviewScholarships || $canReviewGwaReports || $canReviewUserIssueReports): ?>
                            <li><a href="<?php echo htmlspecialchars($adminReviewsUrl); ?>">Reviews</a></li>
                            <?php endif; ?>
                            <?php if ($canViewActivityLogs): ?>
                            <li><a href="<?php echo htmlspecialchars($adminLogsUrl); ?>">Logs</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="admin-footer-copyright">
                    &copy; <?php echo date('Y'); ?> Scholarship Finder Admin Panel. All rights reserved.
                </div>
            </div>
        </footer>
<script>
    (function initializeAdminFlashAlerts() {
        const SWEETALERT_CSS = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css';
        const SWEETALERT_JS = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';

        function ensureStylesheet() {
            const existing = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).some((link) =>
                (link.href || '').indexOf('sweetalert2@11') !== -1
            );

            if (!existing) {
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = SWEETALERT_CSS;
                document.head.appendChild(link);
            }
        }

        function collectFlashAlerts() {
            const selectors = [
                '.alert.alert-success',
                '.alert.alert-error',
                '.alert-modern.alert-success-modern',
                '.alert-modern.alert-error-modern'
            ];

            return Array.from(document.querySelectorAll(selectors.join(', ')))
                .filter((element) => !element.hasAttribute('data-swal-processed'))
                .map((element) => {
                    const isSuccess = element.classList.contains('alert-success') || element.classList.contains('alert-success-modern');
                    const listItems = Array.from(element.querySelectorAll('li'))
                        .map((item) => item.textContent.trim())
                        .filter(Boolean);
                    const paragraph = element.querySelector('p');
                    const rawText = paragraph ? paragraph.textContent.trim() : element.textContent.trim();

                    return {
                        element,
                        icon: isSuccess ? 'success' : 'error',
                        title: isSuccess ? 'Success' : 'Please Check',
                        text: listItems.length === 0 ? rawText : '',
                        html: listItems.length > 0
                            ? '<ul style="text-align:left; margin:0; padding-left:1.25rem;">' + listItems.map((item) =>
                                '<li>' + item
                                    .replace(/&/g, '&amp;')
                                    .replace(/</g, '&lt;')
                                    .replace(/>/g, '&gt;') + '</li>'
                              ).join('') + '</ul>'
                            : ''
                    };
                })
                .filter((alert) => (alert.text || alert.html));
        }

        async function showFlashAlerts() {
            if (typeof window.Swal === 'undefined' || typeof window.Swal.fire !== 'function') {
                return;
            }

            const alerts = collectFlashAlerts();
            if (!alerts.length) {
                return;
            }

            for (const alert of alerts) {
                if (alert.element) {
                    alert.element.setAttribute('data-swal-processed', 'true');
                    alert.element.remove();
                }
                await window.Swal.fire({
                    icon: alert.icon,
                    title: alert.title,
                    text: alert.text || undefined,
                    html: alert.html || undefined,
                    confirmButtonColor: alert.icon === 'success' ? '#1d4ed8' : '#c2410c'
                });
            }
        }

        function boot() {
            ensureStylesheet();

            if (typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function') {
                showFlashAlerts();
                return;
            }

            const existingScript = Array.from(document.scripts).find((script) =>
                (script.src || '').indexOf('sweetalert2@11') !== -1
            );

            if (existingScript) {
                existingScript.addEventListener('load', showFlashAlerts, { once: true });
                setTimeout(showFlashAlerts, 150);
                return;
            }

            const script = document.createElement('script');
            script.src = SWEETALERT_JS;
            script.onload = showFlashAlerts;
            document.body.appendChild(script);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot, { once: true });
        } else {
            boot();
        }
    })();
</script>
<script>
    (function initializeAdminSidebar() {
        const sidebar = document.querySelector('[data-admin-sidebar]');
        const toggle = document.querySelector('[data-admin-sidebar-toggle]');
        const closeButton = document.querySelector('[data-admin-sidebar-close]');
        const backdrop = document.querySelector('[data-admin-sidebar-backdrop]');

        if (!sidebar || !toggle) {
            return;
        }

        const setOpen = (open) => {
            document.body.classList.toggle('admin-sidebar-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        toggle.addEventListener('click', () => {
            setOpen(!document.body.classList.contains('admin-sidebar-open'));
        });

        if (closeButton) {
            closeButton.addEventListener('click', () => setOpen(false));
        }

        if (backdrop) {
            backdrop.addEventListener('click', () => setOpen(false));
        }

        window.addEventListener('resize', () => {
            if (window.innerWidth > 980) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        });
    })();
</script>
</div>
</div>
