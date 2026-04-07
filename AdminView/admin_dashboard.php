<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once '../Config/db_config.php';
require_once '../Config/access_control.php';
require_once '../Config/provider_scope.php';
require_once '../Config/csrf.php';
require_once '../Model/Notification.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have access to the admin dashboard.');

function safeCount(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function formatRoleLabel(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

function formatDashboardNotificationTime(?string $value): string
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

$currentRole = getCurrentSessionRole();
$canManageUsers = canAccessStaffAccounts();
$canManageScholarships = canAccessStaffScholarships();
$canManageApplications = canAccessStaffApplications();
$canVerifyDocuments = canAccessStaffDocuments();
$canReviewScholarships = canAccessScholarshipApprovals();
$canViewActivityLogs = canAccessStaffLogs();

$username = $_SESSION['admin_username'] ?? $_SESSION['user_username'] ?? 'Admin';
$adminName = $_SESSION['admin_name'] ?? $_SESSION['user_display_name'] ?? $username;
$dashboardUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$scholarshipScope = getProviderScholarshipScopeClause($pdo, 'sd.provider');
$documentScope = getProviderDocumentScopeClause($pdo, 'ud.user_id');

$scholarshipCountSqlBase = "
    SELECT COUNT(*)
    FROM scholarships s
    LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
    WHERE 1=1
";
$applicationCountSqlBase = "
    SELECT COUNT(*)
    FROM applications a
    JOIN scholarships s ON a.scholarship_id = s.id
    LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
    WHERE 1=1
";
$documentCountSqlBase = "
    SELECT COUNT(*)
    FROM user_documents ud
    WHERE 1=1
";

$totalUsers = $canManageUsers ? safeCount($pdo, 'SELECT COUNT(*) FROM users') : 0;
$activeUsers = $canManageUsers ? safeCount($pdo, "SELECT COUNT(*) FROM users WHERE status = 'active'") : 0;
$pendingAccounts = $canManageUsers ? safeCount($pdo, "SELECT COUNT(*) FROM users WHERE status = 'pending'") : 0;
$totalScholarships = $canManageScholarships ? safeCount($pdo, $scholarshipCountSqlBase . $scholarshipScope['sql'], $scholarshipScope['params']) : 0;
$activeScholarships = $canManageScholarships ? safeCount($pdo, $scholarshipCountSqlBase . $scholarshipScope['sql'] . " AND s.status = 'active'", $scholarshipScope['params']) : 0;
$totalApplications = $canManageApplications ? safeCount($pdo, $applicationCountSqlBase . $scholarshipScope['sql'], $scholarshipScope['params']) : 0;
$pendingApplications = $canManageApplications ? safeCount($pdo, $applicationCountSqlBase . $scholarshipScope['sql'] . " AND a.status = 'pending'", $scholarshipScope['params']) : 0;
$totalDocuments = $canVerifyDocuments ? safeCount($pdo, $documentCountSqlBase . $documentScope['sql'], $documentScope['params']) : 0;
$pendingDocuments = $canVerifyDocuments ? safeCount($pdo, $documentCountSqlBase . $documentScope['sql'] . " AND ud.status = 'pending'", $documentScope['params']) : 0;
$verifiedDocuments = $canVerifyDocuments ? safeCount($pdo, $documentCountSqlBase . $documentScope['sql'] . " AND ud.status = 'verified'", $documentScope['params']) : 0;
$pendingScholarshipReviews = $canReviewScholarships ? safeCount(
    $pdo,
    "
        SELECT COUNT(*)
        FROM scholarships s
        LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
        WHERE COALESCE(sd.review_status, 'approved') = 'pending'
    "
) : 0;
$dashboardNotifications = [];
$dashboardUnreadNotifications = 0;
$adminStyleVersion = @filemtime(__DIR__ . '/../public/css/admin_style.css') ?: time();
$adminDashboardStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/admin-dashboard.css') ?: time();

if ($dashboardUserId > 0) {
    try {
        $notificationModel = new Notification($pdo);
        $dashboardNotifications = $notificationModel->getRecentForUser($dashboardUserId, 5);
        $dashboardUnreadNotifications = $notificationModel->countUnreadForUser($dashboardUserId);
    } catch (Throwable $e) {
        $dashboardNotifications = [];
        $dashboardUnreadNotifications = 0;
    }
}

$recentUsers = [];
if ($canManageUsers) {
    try {
        $recentUsersStmt = $pdo->query("
            SELECT
                u.username,
                u.email,
                u.role,
                u.status,
                u.created_at,
                NULLIF(TRIM(CONCAT(COALESCE(sd.firstname, ''), ' ', COALESCE(sd.lastname, ''))), '') AS full_name,
                sd.school
            FROM users u
            LEFT JOIN student_data sd ON u.id = sd.student_id
            WHERE u.role = 'student'
            ORDER BY u.created_at DESC
            LIMIT 5
        ");
        $recentUsers = $recentUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $recentUsers = [];
    }
}

$greeting = 'Welcome';
$currentDate = date('l, F j, Y');

$roleDescriptions = [
    'provider' => 'Manage scholarship postings, application reviews, and document verification from one workspace.',
    'admin' => 'Monitor review queues, account activity, and scholarship records from a single administrative dashboard.',
    'super_admin' => 'Oversee operational queues, account management, and system activity from one administrative dashboard.',
];
$roleDescription = $roleDescriptions[$currentRole] ?? 'Administrative dashboard.';
$dashboardHeroAction = [
    'href' => 'profile.php',
    'label' => 'Open profile',
    'icon' => 'fa-id-card'
];

if ($canManageApplications && $pendingApplications > 0) {
    $dashboardHeroAction = [
        'href' => 'manage_applications.php',
        'label' => 'Review applications',
        'icon' => 'fa-file-lines'
    ];
} elseif ($canReviewScholarships && $pendingScholarshipReviews > 0) {
    $dashboardHeroAction = [
        'href' => 'scholarship_reviews.php',
        'label' => 'Review scholarships',
        'icon' => 'fa-graduation-cap'
    ];
} elseif ($canVerifyDocuments && $pendingDocuments > 0) {
    $dashboardHeroAction = [
        'href' => 'admin_verify_documents.php',
        'label' => 'Verify documents',
        'icon' => 'fa-file-shield'
    ];
} elseif ($canManageUsers && $pendingAccounts > 0) {
    $dashboardHeroAction = [
        'href' => 'manage_users.php',
        'label' => 'Open accounts',
        'icon' => 'fa-users-gear'
    ];
} elseif ($canManageScholarships) {
    $dashboardHeroAction = [
        'href' => 'manage_scholarships.php',
        'label' => 'Manage scholarships',
        'icon' => 'fa-graduation-cap'
    ];
}

$priorityCards = [];
if ($canManageApplications) {
    $priorityCards[] = [
        'tone' => $pendingApplications > 0 ? 'warning' : 'calm',
        'icon' => 'fa-clipboard-check',
        'state' => $pendingApplications > 0 ? 'Needs review' : 'All clear',
        'count' => number_format($pendingApplications),
        'title' => 'Pending applications',
        'description' => $pendingApplications > 0
            ? 'Submitted applications currently awaiting review and final action.'
            : 'There are no applications awaiting action at this time.',
        'meta' => number_format($totalApplications) . ' total applications',
        'href' => 'manage_applications.php',
        'action' => $pendingApplications > 0 ? 'Review applications' : 'Open applications',
    ];
}
if ($canVerifyDocuments) {
    $priorityCards[] = [
        'tone' => $pendingDocuments > 0 ? 'info' : 'calm',
        'icon' => 'fa-file-shield',
        'state' => $pendingDocuments > 0 ? 'Waiting for verification' : 'Up to date',
        'count' => number_format($pendingDocuments),
        'title' => 'Pending documents',
        'description' => $pendingDocuments > 0
            ? 'Uploaded student documents that still require verification.'
            : 'All submitted documents are currently up to date.',
        'meta' => number_format($verifiedDocuments) . ' verified documents',
        'href' => 'admin_verify_documents.php',
        'action' => $pendingDocuments > 0 ? 'Verify documents' : 'Open documents',
    ];
}
if ($canManageUsers) {
    $priorityCards[] = [
        'tone' => $pendingAccounts > 0 ? 'success' : 'calm',
        'icon' => 'fa-user-clock',
        'state' => $pendingAccounts > 0 ? 'Waiting for approval' : 'Stable',
        'count' => number_format($pendingAccounts),
        'title' => 'Pending accounts',
        'description' => $pendingAccounts > 0
            ? 'Accounts pending activation, approval, or follow-up review.'
            : 'There are no pending account approvals at the moment.',
        'meta' => number_format($activeUsers) . ' active accounts',
        'href' => 'manage_users.php',
        'action' => $pendingAccounts > 0 ? 'Open accounts' : 'View accounts',
    ];
} elseif ($canManageScholarships) {
    $priorityCards[] = [
        'tone' => $totalScholarships > 0 ? 'success' : 'info',
        'icon' => 'fa-graduation-cap',
        'state' => $activeScholarships > 0 ? 'Programs live' : 'Setup needed',
        'count' => number_format($totalScholarships),
        'title' => 'Scholarship programs',
        'description' => $totalScholarships > 0
            ? 'Current scholarship records available for review and maintenance.'
            : 'No scholarship programs have been posted yet.',
        'meta' => number_format($activeScholarships) . ' active scholarships',
        'href' => 'manage_scholarships.php',
        'action' => $totalScholarships > 0 ? 'Manage scholarships' : 'Create scholarship',
    ];
}

$snapshotCards = [];
if ($canManageUsers) {
    $snapshotCards[] = [
        'label' => 'Total accounts',
        'value' => number_format($totalUsers),
        'helper' => number_format($activeUsers) . ' active accounts',
        'tone' => 'primary',
    ];
    $snapshotCards[] = [
        'label' => 'Scholarships',
        'value' => number_format($totalScholarships),
        'helper' => number_format($activeScholarships) . ' active',
        'tone' => 'success',
    ];
    $snapshotCards[] = [
        'label' => 'Applications',
        'value' => number_format($totalApplications),
        'helper' => number_format($pendingApplications) . ' pending',
        'tone' => 'warning',
    ];
    $snapshotCards[] = [
        'label' => 'Documents',
        'value' => number_format($totalDocuments),
        'helper' => number_format($verifiedDocuments) . ' verified',
        'tone' => 'info',
    ];
} else {
    $snapshotCards[] = [
        'label' => 'Scholarships',
        'value' => number_format($totalScholarships),
        'helper' => number_format($activeScholarships) . ' active',
        'tone' => 'primary',
    ];
    $snapshotCards[] = [
        'label' => 'Applications',
        'value' => number_format($totalApplications),
        'helper' => number_format($pendingApplications) . ' pending',
        'tone' => 'warning',
    ];
    $snapshotCards[] = [
        'label' => 'Documents',
        'value' => number_format($totalDocuments),
        'helper' => number_format($verifiedDocuments) . ' verified',
        'tone' => 'info',
    ];
    $snapshotCards[] = [
        'label' => 'Live programs',
        'value' => number_format($activeScholarships),
        'helper' => 'Ready for student matching',
        'tone' => 'success',
    ];
}

$actionCards = [];
if ($canManageScholarships) {
    $actionCards[] = [
        'href' => 'manage_scholarships.php',
        'icon' => 'fa-graduation-cap',
        'title' => 'Scholarships',
        'description' => 'Open scholarship records for posting, editing, and maintenance.',
    ];
}
if ($canReviewScholarships) {
    $priorityCards[] = [
        'tone' => $pendingScholarshipReviews > 0 ? 'info' : 'calm',
        'icon' => 'fa-graduation-cap',
        'state' => $pendingScholarshipReviews > 0 ? 'Waiting for validation' : 'Queue clear',
        'count' => number_format($pendingScholarshipReviews),
        'title' => 'Scholarship reviews',
        'description' => $pendingScholarshipReviews > 0
            ? 'Provider-submitted scholarships that still need approval before publication.'
            : 'No provider-submitted scholarships are waiting for review right now.',
        'meta' => number_format($activeScholarships) . ' active scholarships',
        'href' => 'scholarship_reviews.php',
        'action' => $pendingScholarshipReviews > 0 ? 'Open scholarship reviews' : 'View scholarship reviews',
    ];
}
if ($canManageApplications || $canVerifyDocuments) {
    $actionCards[] = [
        'href' => 'reviews.php',
        'icon' => 'fa-list-check',
        'title' => 'Reviews',
        'description' => 'Open the shared review area for applications and document verification.',
    ];
}
if ($canManageUsers) {
    $actionCards[] = [
        'href' => 'manage_users.php',
        'icon' => 'fa-users-gear',
        'title' => 'Accounts',
        'description' => 'Manage student, provider, and admin account records.',
    ];
}
$actionCards[] = [
    'href' => 'profile.php',
    'icon' => 'fa-id-card',
    'title' => 'Profile',
    'description' => 'Update your profile, password, and account details.',
];
if ($canViewActivityLogs) {
    $actionCards[] = [
        'href' => 'activity_logs.php',
        'icon' => 'fa-clock-rotate-left',
        'title' => 'Activity logs',
        'description' => 'Review recent system actions and account-related activity.',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Scholarship Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css?v=<?php echo urlencode((string) $adminStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/admin-dashboard.css?v=<?php echo urlencode((string) $adminDashboardStyleVersion); ?>">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard admin-dashboard-friendly">
        <div class="container">
            <div class="page-header admin-friendly-header">
                <div class="admin-hero-main">
                    <div class="admin-hero-topline">
                        <span class="workspace-kicker">
                            <i class="fas fa-briefcase" aria-hidden="true"></i>
                            Scholarship Operations Workspace
                        </span>
                    </div>
                    <div class="admin-friendly-copy">
                        <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($adminName); ?></h1>
                        <p><?php echo htmlspecialchars($roleDescription); ?></p>
                    </div>
                    <div class="admin-hero-inline-meta">
                        <div class="date-chip-friendly">
                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars($currentDate); ?></span>
                        </div>
                        <a href="<?php echo htmlspecialchars($dashboardHeroAction['href']); ?>" class="admin-hero-action">
                            <i class="fas <?php echo htmlspecialchars($dashboardHeroAction['icon']); ?>" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars($dashboardHeroAction['label']); ?></span>
                        </a>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success dashboard-alert">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
                <p><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error dashboard-alert">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                <p><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
            </div>
            <?php endif; ?>

            <section class="section-panel admin-notifications-panel">
                <div class="section-heading admin-notifications-heading">
                    <div>
                        <h2>Notifications</h2>
                        <p>Recent updates related to your review and management work.</p>
                    </div>
                    <div class="admin-notifications-actions">
                        <span class="admin-notifications-badge"><?php echo (int) $dashboardUnreadNotifications; ?> unread</span>
                        <?php if (!empty($dashboardNotifications) && $dashboardUnreadNotifications > 0): ?>
                        <form method="POST" action="../Controller/notification_controller.php">
                            <input type="hidden" name="action" value="mark_all_read">
                            <?php echo csrfInputField('notification_center'); ?>
                            <input type="hidden" name="redirect" value="../AdminView/admin_dashboard.php">
                            <button type="submit" class="btn btn-outline btn-sm">Mark all read</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($dashboardNotifications)): ?>
                <div class="admin-notifications-empty">
                    <i class="fas fa-inbox" aria-hidden="true"></i>
                    <p>No notifications yet. New review items will appear here.</p>
                </div>
                <?php else: ?>
                <div class="admin-notifications-list">
                    <?php foreach ($dashboardNotifications as $notification): ?>
                    <?php
                    $notificationType = (string) ($notification['type'] ?? 'general');
                    $notificationIcon = 'fa-bell';
                    if (str_contains($notificationType, 'application')) {
                        $notificationIcon = 'fa-file-lines';
                    } elseif (str_contains($notificationType, 'provider')) {
                        $notificationIcon = 'fa-building';
                    } elseif (str_contains($notificationType, 'scholarship')) {
                        $notificationIcon = 'fa-graduation-cap';
                    } elseif (str_contains($notificationType, 'gwa')) {
                        $notificationIcon = 'fa-chart-line';
                    }
                    $notificationLink = trim((string) ($notification['link_url'] ?? ''));
                    ?>
                    <article class="admin-notification-item <?php echo !empty($notification['is_read']) ? 'is-read' : 'is-unread'; ?>">
                        <div class="admin-notification-icon">
                            <i class="fas <?php echo htmlspecialchars($notificationIcon); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="admin-notification-content">
                            <div class="admin-notification-meta">
                                <h3><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Notification')); ?></h3>
                                <span><?php echo htmlspecialchars(formatDashboardNotificationTime($notification['created_at'] ?? null)); ?></span>
                            </div>
                            <p><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></p>
                        </div>
                        <?php if ($notificationLink !== ''): ?>
                        <a href="<?php echo htmlspecialchars($notificationLink); ?>" class="admin-notification-link">Open</a>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <section class="section-panel">
                <div class="priority-grid">
                    <?php foreach ($priorityCards as $card): ?>
                    <article class="priority-card tone-<?php echo htmlspecialchars($card['tone']); ?>">
                        <div class="priority-card-top">
                            <div class="priority-icon">
                                <i class="fas <?php echo htmlspecialchars($card['icon']); ?>" aria-hidden="true"></i>
                            </div>
                            <span class="priority-state"><?php echo htmlspecialchars($card['state']); ?></span>
                        </div>
                        <div class="priority-count"><?php echo htmlspecialchars($card['count']); ?></div>
                        <h3><?php echo htmlspecialchars($card['title']); ?></h3>
                        <p class="priority-description"><?php echo htmlspecialchars($card['description']); ?></p>
                        <p class="priority-meta"><?php echo htmlspecialchars($card['meta']); ?></p>
                        <a href="<?php echo htmlspecialchars($card['href']); ?>" class="btn btn-primary priority-cta"><?php echo htmlspecialchars($card['action']); ?></a>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section-panel section-panel-soft">
                <div class="section-heading">
                    <div>
                        <h2>Quick Snapshot</h2>
                        <p>Key operational totals</p>
                    </div>
                </div>
                <div class="snapshot-grid">
                    <?php foreach ($snapshotCards as $card): ?>
                    <article class="snapshot-card">
                        <span class="snapshot-label"><?php echo htmlspecialchars($card['label']); ?></span>
                        <strong class="snapshot-value"><?php echo htmlspecialchars($card['value']); ?></strong>
                        <span class="snapshot-helper"><?php echo htmlspecialchars($card['helper']); ?></span>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section-panel">
                <div class="section-heading">
                    <div>
                        <h2>Common Tasks</h2>
                        <p>Frequently used tools</p>
                    </div>
                </div>
                <div class="action-grid-calm">
                    <?php foreach ($actionCards as $action): ?>
                    <a href="<?php echo htmlspecialchars($action['href']); ?>" class="action-card-calm">
                        <div class="action-card-icon">
                            <i class="fas <?php echo htmlspecialchars($action['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="action-card-copy">
                            <h3><?php echo htmlspecialchars($action['title']); ?></h3>
                            <p><?php echo htmlspecialchars($action['description']); ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($canManageUsers): ?>
            <section class="section-panel">
                <div class="section-heading">
                    <div>
                        <h2>Recent Registrations</h2>
                        <p>Most recent account registrations</p>
                    </div>
                    <a href="manage_users.php" class="view-all-link">
                        Open account management <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>

                <?php if (!empty($recentUsers)): ?>
                <div class="recent-users-list">
                    <?php foreach ($recentUsers as $user): ?>
                    <?php
                    $displayName = $user['full_name'] ?: ($user['username'] ?: 'User');
                    $initialSource = $displayName !== '' ? $displayName : 'U';
                    $initial = strtoupper(substr($initialSource, 0, 1));
                    $statusClass = 'status-' . strtolower((string) ($user['status'] ?? 'inactive'));
                    ?>
                    <article class="recent-user-card">
                        <div class="recent-user-avatar"><?php echo htmlspecialchars($initial); ?></div>
                        <div class="recent-user-main">
                            <strong><?php echo htmlspecialchars($displayName); ?></strong>
                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                            <div class="recent-user-submeta">
                                <span><?php echo htmlspecialchars(formatRoleLabel((string) ($user['role'] ?? 'user'))); ?></span>
                                <span><?php echo htmlspecialchars($user['school'] ?: 'School not provided'); ?></span>
                            </div>
                        </div>
                        <div class="recent-user-side">
                            <span class="status-pill <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars(ucfirst((string) ($user['status'] ?? 'inactive'))); ?></span>
                            <span class="recent-user-date"><?php echo htmlspecialchars(date('M d, Y', strtotime((string) $user['created_at']))); ?></span>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-friendly-state">
                    <i class="fas fa-user-slash" aria-hidden="true"></i>
                    <h3>No recent registrations yet</h3>
                    <p>New accounts will appear here once students, providers, or admin accounts start registering.</p>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>
    </section>

    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
