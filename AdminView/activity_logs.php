<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Models/ActivityLog.php';

requireRoles(['super_admin'], '../View/index.php', 'Only super administrators can view activity logs.');

$currentRole = getCurrentSessionRole();
$activityLog = new ActivityLog($pdo);
$activityLog->ensureTableExists();

$search = trim((string) ($_GET['search'] ?? ''));
$entityFilter = trim((string) ($_GET['entity_type'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? ''));
$roleFilter = trim((string) ($_GET['actor_role'] ?? ''));

$filters = [
    'search' => $search,
    'entity_type' => $entityFilter,
    'action' => $actionFilter,
    'actor_role' => $roleFilter,
];

$stats = $activityLog->getStats($currentRole);
$perPage = 10;
$requestedPage = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$totalLogs = $activityLog->countLogs($filters, $currentRole);
$activityTotalPages = max(1, (int) ceil($totalLogs / $perPage));
$activityCurrentPage = max(1, min($requestedPage, $activityTotalPages));
$offset = ($activityCurrentPage - 1) * $perPage;
$logs = $activityLog->getLogs($filters, $perPage, $currentRole, $offset);
$entityTypes = $activityLog->getEntityTypes($currentRole);
$actions = $activityLog->getActions($currentRole);
$showingFrom = $totalLogs > 0 ? ($offset + 1) : 0;
$showingTo = $totalLogs > 0 ? min($offset + $perPage, $totalLogs) : 0;
$pageWindowStart = max(1, $activityCurrentPage - 2);
$pageWindowEnd = min($activityTotalPages, $activityCurrentPage + 2);
$adminStyleVersion = @filemtime(__DIR__ . '/../public/css/admin_style.css') ?: time();
$activityLogsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/activity-logs.css') ?: time();

function activityRoleClass(string $role): string
{
    $normalized = normalizeUserRole($role);
    if ($normalized === 'super_admin') {
        return 'super-admin';
    }
    return $normalized;
}

function activityEntityIcon(string $entityType): string
{
    $map = [
        'authentication' => 'fa-right-to-bracket',
        'application' => 'fa-file-signature',
        'document' => 'fa-file-circle-check',
        'scholarship' => 'fa-graduation-cap',
        'user' => 'fa-users',
    ];

    return $map[$entityType] ?? 'fa-clock-rotate-left';
}

function activityActionClass(string $action): string
{
    $value = strtolower(trim($action));
    if (strpos($value, 'delete') !== false || strpos($value, 'reject') !== false || strpos($value, 'fail') !== false) {
        return 'danger';
    }
    if (strpos($value, 'approve') !== false || strpos($value, 'verify') !== false || strpos($value, 'create') !== false || strpos($value, 'submit') !== false || strpos($value, 'login') !== false) {
        return 'success';
    }
    return 'info';
}

function decodeActivityDetails($details): array
{
    if (!is_string($details) || trim($details) === '') {
        return [];
    }

    $decoded = json_decode($details, true);
    if (!is_array($decoded)) {
        return [];
    }

    $result = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        } elseif (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif ($value === null) {
            $value = 'N/A';
        }

        $label = ucwords(str_replace('_', ' ', (string) $key));
        $result[$label] = (string) $value;
    }

    return $result;
}

function activityQueryString(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    $params = array_filter($params, static function ($value) {
        return $value !== null && $value !== '';
    });

    return http_build_query($params);
}

function activityDisplayDate(?string $value, string $format, string $fallback = 'Not available'): string
{
    if (!$value) {
        return $fallback;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : $fallback;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css?v=<?php echo urlencode((string) $adminStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/activity-logs.css?v=<?php echo urlencode((string) $activityLogsStyleVersion); ?>">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard activity-dashboard-page">
        <div class="container">
            <div class="activity-workspace-hero">
                <div class="activity-workspace-main">
                    <h1>Activity Logs</h1>
                    <p>Audit trail for account administration, scholarship management, application decisions, document verification, and sign-in activity.</p>
                </div>
            </div>

            <div class="activity-overview-strip">
                <div class="activity-overview-card">
                    <span class="activity-overview-label">Total Events</span>
                    <strong><?php echo number_format($stats['total']); ?></strong>
                    <span class="activity-overview-meta">All recorded entries</span>
                </div>
                <div class="activity-overview-card">
                    <span class="activity-overview-label">Today</span>
                    <strong><?php echo number_format($stats['today']); ?></strong>
                    <span class="activity-overview-meta">Events recorded today</span>
                </div>
                <div class="activity-overview-card">
                    <span class="activity-overview-label">Scholarships</span>
                    <strong><?php echo number_format($stats['scholarship']); ?></strong>
                    <span class="activity-overview-meta">Posting and maintenance activity</span>
                </div>
                <div class="activity-overview-card">
                    <span class="activity-overview-label">Applications</span>
                    <strong><?php echo number_format($stats['application']); ?></strong>
                    <span class="activity-overview-meta">Submission and decision activity</span>
                </div>
                <div class="activity-overview-card">
                    <span class="activity-overview-label">Documents</span>
                    <strong><?php echo number_format($stats['document']); ?></strong>
                    <span class="activity-overview-meta">Upload and verification activity</span>
                </div>
            </div>

            <div class="activity-surface activity-filter-panel">
                <div class="activity-surface-heading">
                    <div>
                        <h2>Filter Activity</h2>
                        <p>Search by actor, record, action, or role.</p>
                    </div>
                    <?php if ($search !== '' || $entityFilter !== '' || $actionFilter !== '' || $roleFilter !== ''): ?>
                        <a href="activity_logs.php" class="activity-button activity-button-secondary activity-clear-all">
                            <i class="fas fa-rotate-left"></i> Reset Filters
                        </a>
                    <?php endif; ?>
                </div>
                <form method="GET" action="activity_logs.php" class="activity-filter-form">
                    <div class="activity-search-grid">
                        <div class="activity-field">
                            <label for="activitySearch">Search</label>
                            <input type="text" id="activitySearch" name="search" class="activity-input" placeholder="Search actor, scholarship, target, or description" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="activity-field">
                            <label for="entityType">Entity</label>
                            <select id="entityType" name="entity_type" class="activity-select">
                                <option value="">All entities</option>
                                <?php foreach ($entityTypes as $entityType): ?>
                                    <option value="<?php echo htmlspecialchars($entityType); ?>" <?php echo $entityFilter === $entityType ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $entityType))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="activity-field">
                            <label for="actionType">Action</label>
                            <select id="actionType" name="action" class="activity-select">
                                <option value="">All actions</option>
                                <?php foreach ($actions as $action): ?>
                                    <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $actionFilter === $action ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $action))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="activity-field">
                            <label for="actorRole">Actor Role</label>
                            <select id="actorRole" name="actor_role" class="activity-select">
                                <option value="">All roles</option>
                                <?php foreach (['student', 'provider', 'admin', 'super_admin'] as $roleOption): ?>
                                    <option value="<?php echo htmlspecialchars($roleOption); ?>" <?php echo $roleFilter === $roleOption ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $roleOption))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="activity-actions">
                            <div class="activity-action-buttons">
                                <button type="submit" class="activity-button activity-button-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <?php if ($search !== '' || $entityFilter !== '' || $actionFilter !== '' || $roleFilter !== ''): ?>
                                    <a href="activity_logs.php" class="activity-button activity-button-secondary">
                                        <i class="fas fa-rotate-left"></i> Clear
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
                <div class="activity-filter-summary">
                    <span class="activity-result-copy">
                        Showing <?php echo number_format($showingFrom); ?>-<?php echo number_format($showingTo); ?> of <?php echo number_format($totalLogs); ?> records
                    </span>
                </div>
            </div>

            <div class="activity-surface activity-feed-panel">
                <div class="activity-surface-heading">
                    <div>
                        <h2>Activity Feed</h2>
                        <p>Recent activity entries listed from newest to oldest.</p>
                    </div>
                    <span class="activity-feed-page-tag">Page <?php echo number_format($activityCurrentPage); ?> of <?php echo number_format($activityTotalPages); ?></span>
                </div>

                <?php if (empty($logs)): ?>
                    <div class="empty-state activity-empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-clock-rotate-left"></i>
                        </div>
                        <h3>No activity found</h3>
                        <p>New events will appear here once users and staff start taking actions in the system.</p>
                    </div>
                <?php else: ?>
                    <div class="activity-feed">
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $actorName = trim((string) ($log['actor_name'] ?? ''));
                            if ($actorName === '') {
                                $actorName = 'System / Unknown';
                            }
                            $entityName = trim((string) ($log['entity_name'] ?? ''));
                            $targetName = trim((string) ($log['target_name'] ?? ''));
                            $entityLabel = ucwords(str_replace('_', ' ', (string) ($log['entity_type'] ?? 'item')));
                            $actionLabel = ucwords(str_replace('_', ' ', (string) ($log['action'] ?? 'action')));
                            $actionTone = activityActionClass((string) ($log['action'] ?? ''));
                            ?>
                            <article class="activity-entry">
                                <div class="activity-entry-rail">
                                    <span class="activity-entry-dot <?php echo htmlspecialchars($actionTone); ?>"></span>
                                    <span class="activity-entry-date"><?php echo htmlspecialchars(activityDisplayDate((string) ($log['created_at'] ?? ''), 'M d, Y')); ?></span>
                                    <span class="activity-entry-time"><?php echo htmlspecialchars(activityDisplayDate((string) ($log['created_at'] ?? ''), 'h:i A')); ?></span>
                                </div>
                                <div class="activity-entry-body">
                                    <div class="activity-entry-header">
                                        <div class="activity-entry-title-wrap">
                                            <div class="activity-entry-actor-line">
                                                <strong class="activity-actor-name"><?php echo htmlspecialchars($actorName); ?></strong>
                                                <span class="activity-role-badge <?php echo htmlspecialchars(activityRoleClass((string) ($log['actor_role'] ?? 'guest'))); ?>">
                                                    <i class="fas fa-user-shield"></i>
                                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($log['actor_role'] ?? 'guest')))); ?>
                                                </span>
                                            </div>
                                            <div class="activity-description"><?php echo htmlspecialchars((string) ($log['description'] ?? 'Activity recorded')); ?></div>
                                        </div>
                                        <div class="activity-entry-badges">
                                            <span class="activity-action-badge <?php echo htmlspecialchars($actionTone); ?>">
                                                <i class="fas fa-bolt"></i>
                                                <?php echo htmlspecialchars($actionLabel); ?>
                                            </span>
                                            <span class="activity-entity-badge">
                                                <i class="fas <?php echo htmlspecialchars(activityEntityIcon((string) ($log['entity_type'] ?? ''))); ?>"></i>
                                                <?php echo htmlspecialchars($entityLabel); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="activity-context-grid">
                                        <?php if ($entityName !== ''): ?>
                                            <div class="activity-context-card">
                                                <span class="activity-context-label">Record</span>
                                                <strong><?php echo htmlspecialchars($entityName); ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($targetName !== ''): ?>
                                            <div class="activity-context-card">
                                                <span class="activity-context-label">Affected User</span>
                                                <strong><?php echo htmlspecialchars($targetName); ?></strong>
                                            </div>
                                        <?php endif; ?>

                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="activity-pagination">
                <div class="activity-pagination-copy">
                    <span class="activity-pagination-label">Activity Log Pages</span>
                    <span>Showing <?php echo number_format($showingFrom); ?>-<?php echo number_format($showingTo); ?> of <?php echo number_format($totalLogs); ?> records</span>
                </div>
                <div class="activity-pagination-actions">
                    <?php if ($activityCurrentPage > 1): ?>
                        <a href="activity_logs.php?<?php echo htmlspecialchars(activityQueryString($filters, ['page' => $activityCurrentPage - 1])); ?>" class="activity-button activity-button-secondary">
                            <i class="fas fa-arrow-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <span class="activity-button activity-button-secondary is-disabled" aria-disabled="true">
                            <i class="fas fa-arrow-left"></i> Previous
                        </span>
                    <?php endif; ?>

                    <?php if ($activityTotalPages > 1): ?>
                        <div class="activity-page-links" aria-label="Activity log pages">
                            <?php for ($page = $pageWindowStart; $page <= $pageWindowEnd; $page++): ?>
                                <?php if ($page === $activityCurrentPage): ?>
                                    <span class="activity-page-link is-current" aria-current="page"><?php echo number_format($page); ?></span>
                                <?php else: ?>
                                    <a href="activity_logs.php?<?php echo htmlspecialchars(activityQueryString($filters, ['page' => $page])); ?>" class="activity-page-link">
                                        <?php echo number_format($page); ?>
                                    </a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>

                    <span class="activity-page-indicator">Page <?php echo number_format($activityCurrentPage); ?> of <?php echo number_format($activityTotalPages); ?></span>

                    <?php if ($activityCurrentPage < $activityTotalPages): ?>
                        <a href="activity_logs.php?<?php echo htmlspecialchars(activityQueryString($filters, ['page' => $activityCurrentPage + 1])); ?>" class="activity-button activity-button-primary">
                            Next <i class="fas fa-arrow-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="activity-button activity-button-primary is-disabled" aria-disabled="true">
                            Next <i class="fas fa-arrow-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>

