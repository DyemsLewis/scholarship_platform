<?php
require_once __DIR__ . '/../../Config/access_control.php';
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
$panelLabel = ($currentRole === 'provider') ? 'Provider Panel' : 'Admin Panel';
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$isReviewsPage = in_array($currentPage, ['reviews.php', 'manage_applications.php', 'view_application.php', 'admin_verify_documents.php', 'provider_reviews.php', 'view_provider.php', 'scholarship_reviews.php', 'gwa_reports.php', 'user_issue_reports.php'], true);

$username = $_SESSION['admin_username'] ?? $_SESSION['user_username'] ?? 'Staff';
$profileName = $_SESSION['admin_name'] ?? $_SESSION['user_display_name'] ?? $username;
$sidebarIdentity = $username !== '' ? $username : $profileName;
$initials = '';
$names = preg_split('/\s+/', trim((string) $sidebarIdentity)) ?: [];
foreach ($names as $name) {
    if ($name === '') {
        continue;
    }
    $initials .= strtoupper(substr($name, 0, 1));
    if (strlen($initials) >= 2) {
        break;
    }
}
$initials = $initials ?: 'A';
$roleLabel = ucwords(str_replace('_', ' ', (string) $currentRole));

$adminNavItems = [
    [
        'show' => true,
        'href' => 'admin_dashboard.php',
        'label' => 'Dashboard',
        'icon' => 'fa-gauge-high',
        'active' => $currentPage === 'admin_dashboard.php',
    ],
    [
        'show' => true,
        'href' => 'profile.php',
        'label' => 'Profile',
        'icon' => 'fa-id-badge',
        'active' => $currentPage === 'profile.php',
    ],
    [
        'show' => $canManageUsers,
        'href' => 'manage_users.php',
        'label' => 'Accounts',
        'icon' => 'fa-users',
        'active' => in_array($currentPage, ['manage_users.php', 'edit_user.php', 'addUsers.php'], true),
    ],
    [
        'show' => $canManageScholarships,
        'href' => 'manage_scholarships.php',
        'label' => 'Scholarships',
        'icon' => 'fa-graduation-cap',
        'active' => in_array($currentPage, ['manage_scholarships.php', 'add_scholarship.php', 'edit_scholarship.php', 'scholarship_map.php'], true),
    ],
    [
        'show' => $canManageApplications || $canVerifyDocuments || $canReviewProviders || $canReviewScholarships || $canReviewGwaReports || $canReviewUserIssueReports,
        'href' => 'reviews.php',
        'label' => 'Reviews',
        'icon' => 'fa-clipboard-check',
        'active' => $isReviewsPage,
    ],
    [
        'show' => $canViewActivityLogs,
        'href' => 'activity_logs.php',
        'label' => 'Logs',
        'icon' => 'fa-clock-rotate-left',
        'active' => $currentPage === 'activity_logs.php',
    ],
];
?>
<div class="admin-shell">
    <aside class="admin-sidebar" data-admin-sidebar>
        <div class="admin-sidebar-inner">
            <div class="admin-sidebar-top">
                <a href="admin_dashboard.php" class="admin-sidebar-brand">
                    <span class="admin-sidebar-brand-icon"><i class="fas fa-lock" aria-hidden="true"></i></span>
                    <span class="admin-sidebar-brand-copy">
                        <strong><?php echo htmlspecialchars($panelLabel); ?></strong>
                        <small>Scholarship Finder</small>
                    </span>
                </a>
                <button type="button" class="admin-sidebar-close" data-admin-sidebar-close aria-label="Close admin navigation">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>

            <div class="admin-sidebar-section-label">Navigation</div>
            <nav class="admin-sidebar-nav" aria-label="Admin navigation">
                <?php foreach ($adminNavItems as $item): ?>
                    <?php if (empty($item['show'])) { continue; } ?>
                    <a href="<?php echo htmlspecialchars($item['href']); ?>" class="admin-sidebar-link <?php echo !empty($item['active']) ? 'active' : ''; ?>">
                        <i class="fas <?php echo htmlspecialchars($item['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="admin-sidebar-profile-card">
                <div class="admin-sidebar-profile-head">
                    <div class="admin-sidebar-avatar"><?php echo htmlspecialchars($initials); ?></div>
                    <div class="admin-sidebar-profile-copy">
                        <strong><?php echo htmlspecialchars($sidebarIdentity); ?></strong>
                        <span><?php echo htmlspecialchars($roleLabel); ?></span>
                    </div>
                </div>
                <div class="admin-sidebar-profile-actions">
                    <a href="profile.php" class="btn btn-outline btn-sm">Open Profile</a>
                    <a href="../View/logout.php" class="btn btn-primary btn-sm">Logout</a>
                </div>
            </div>
        </div>
    </aside>

    <div class="admin-sidebar-backdrop" data-admin-sidebar-backdrop></div>

    <div class="admin-shell-main">
        <div class="admin-mobile-bar">
            <button type="button" class="admin-sidebar-toggle" data-admin-sidebar-toggle aria-label="Open admin navigation" aria-expanded="false">
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>
            <a href="admin_dashboard.php" class="admin-mobile-brand">
                <i class="fas fa-lock" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($panelLabel); ?></span>
            </a>
            <a href="../View/logout.php" class="admin-mobile-logout" aria-label="Logout">
                <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
            </a>
        </div>
