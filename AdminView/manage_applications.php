<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/provider_scope.php';
require_once __DIR__ . '/../app/Config/url_token.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to view applications.');

$statusFilter = $_GET['status'] ?? '';
$validStatuses = ['pending', 'approved', 'rejected'];
$activeFilter = in_array($statusFilter, $validStatuses, true) ? $statusFilter : '';
$providerScope = getCurrentProviderScope($pdo);
$providerApplicationScope = getProviderScholarshipScopeClause($pdo, 'sd2.provider');

$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];

try {
    $statsBaseSql = "
        FROM applications a
        JOIN scholarships s ON a.scholarship_id = s.id
        LEFT JOIN scholarship_data sd2 ON s.id = sd2.scholarship_id
        WHERE 1=1
    ";
    $statsBaseSql .= $providerApplicationScope['sql'];
    $statsBaseParams = $providerApplicationScope['params'];

    $statsStmt = $pdo->prepare("SELECT COUNT(*) " . $statsBaseSql);
    $statsStmt->execute($statsBaseParams);
    $stats['total'] = (int) $statsStmt->fetchColumn();

    foreach (['pending', 'approved', 'rejected'] as $statusName) {
        $statusStmt = $pdo->prepare("SELECT COUNT(*) " . $statsBaseSql . " AND a.status = ?");
        $statusParams = array_merge($statsBaseParams, [$statusName]);
        $statusStmt->execute($statusParams);
        $stats[$statusName] = (int) $statusStmt->fetchColumn();
    }

    $sql = "
        SELECT
            a.id,
            a.status,
            a.applied_at,
            a.updated_at,
            a.probability_score,
            u.username,
            u.email,
            sd.firstname,
            sd.lastname,
            sd.school,
            sd.course,
            s.name AS scholarship_name,
            COALESCE(sd2.provider, 'Not specified') AS provider
        FROM applications a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN student_data sd ON u.id = sd.student_id
        JOIN scholarships s ON a.scholarship_id = s.id
        LEFT JOIN scholarship_data sd2 ON s.id = sd2.scholarship_id
        WHERE 1=1
    ";

    $params = [];
    $sql .= $providerApplicationScope['sql'];
    $params = array_merge($params, $providerApplicationScope['params']);
    if ($activeFilter !== '') {
        $sql .= ' AND a.status = ?';
        $params[] = $activeFilter;
    }

    $sql .= ' ORDER BY a.applied_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $applications = [];
    $_SESSION['error'] = 'Unable to load applications.';
}

$filterOptions = [
    '' => ['label' => 'All', 'count' => $stats['total'], 'icon' => 'fa-layer-group'],
    'pending' => ['label' => 'Pending', 'count' => $stats['pending'], 'icon' => 'fa-clock'],
    'approved' => ['label' => 'Approved', 'count' => $stats['approved'], 'icon' => 'fa-check-circle'],
    'rejected' => ['label' => 'Rejected', 'count' => $stats['rejected'], 'icon' => 'fa-times-circle'],
];

$filterLabel = $activeFilter === '' ? 'All applications' : ucfirst($activeFilter) . ' applications';
$adminStyleVersion = @filemtime(__DIR__ . '/../public/css/admin_style.css') ?: time();
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();
$manageApplicationsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/manage-applications.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Applications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css?v=<?php echo urlencode((string) $adminStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/manage-applications.css?v=<?php echo urlencode((string) $manageApplicationsStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css?v=<?php echo urlencode((string) $reviewsStyleVersion); ?>">
</head>
<body class="admin-applications-page">
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard">
        <div class="container">
            <?php
            $applicationsVisibleCount = count($applications);
            $applicationsHeaderCopy = !empty($providerScope['is_provider']) && !empty($providerScope['organization_name'])
                ? 'Applications submitted to scholarships under ' . $providerScope['organization_name'] . '.'
                : 'Review scholarship applications, applicant records, and final decisions.';
            ?>
            <div class="page-header applications-page-header">
                <div class="applications-page-header-copy">
                    <h1>
                        <i class="fas fa-file-signature"></i>Manage Applications
                    </h1>
                    <p><?php echo htmlspecialchars($applicationsHeaderCopy); ?></p>
                </div>
            </div>

            <?php $reviewsCurrentView = 'applications'; include 'layouts/reviews_nav.php'; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="applications-summary-grid">
                <article class="application-summary-card">
                    <div class="application-summary-copy">
                        <span class="application-summary-label">Total Applications</span>
                        <strong class="application-summary-value"><?php echo number_format($stats['total']); ?></strong>
                        <span class="application-summary-meta">All submitted records</span>
                    </div>
                </article>
                <article class="application-summary-card">
                    <div class="application-summary-copy">
                        <span class="application-summary-label">Pending</span>
                        <strong class="application-summary-value"><?php echo number_format($stats['pending']); ?></strong>
                        <span class="application-summary-meta">Awaiting review</span>
                    </div>
                </article>
                <article class="application-summary-card">
                    <div class="application-summary-copy">
                        <span class="application-summary-label">Approved</span>
                        <strong class="application-summary-value"><?php echo number_format($stats['approved']); ?></strong>
                        <span class="application-summary-meta">Completed decisions</span>
                    </div>
                </article>
                <article class="application-summary-card">
                    <div class="application-summary-copy">
                        <span class="application-summary-label">Rejected</span>
                        <strong class="application-summary-value"><?php echo number_format($stats['rejected']); ?></strong>
                        <span class="application-summary-meta">Closed records</span>
                    </div>
                </article>
            </div>

            <div class="applications-toolbar-card">
                <div class="applications-toolbar-copy">
                    <h2>Application Queue</h2>
                    <p><?php echo htmlspecialchars($filterLabel); ?> | <?php echo number_format($applicationsVisibleCount); ?> visible record<?php echo $applicationsVisibleCount === 1 ? '' : 's'; ?></p>
                </div>
                <div class="applications-filter-pills">
                    <?php foreach ($filterOptions as $filterValue => $option): ?>
                        <?php
                        $filterHref = $filterValue === '' ? 'manage_applications.php' : 'manage_applications.php?status=' . urlencode($filterValue);
                        $isActiveFilter = $activeFilter === $filterValue;
                        ?>
                        <a href="<?php echo htmlspecialchars($filterHref); ?>" class="applications-filter-pill <?php echo $isActiveFilter ? 'active' : ''; ?>">
                            <i class="fas <?php echo htmlspecialchars($option['icon']); ?>" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars($option['label']); ?></span>
                            <strong><?php echo number_format($option['count']); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (empty($applications)): ?>
                <div class="applications-empty-state">
                    <div class="applications-empty-icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <h3>No applications found</h3>
                    <p>No records match the selected filter.</p>
                </div>
            <?php else: ?>
                <div class="applications-board">
                    <?php foreach ($applications as $application): ?>
                        <?php
                        $applicationId = (int) $application['id'];
                        $fullName = trim(($application['firstname'] ?? '') . ' ' . ($application['lastname'] ?? ''));
                        if ($fullName === '') {
                            $fullName = $application['username'];
                        }
                        $initialsSource = preg_split('/\s+/', trim($fullName)) ?: [];
                        $initials = '';
                        foreach ($initialsSource as $namePart) {
                            if ($namePart === '') {
                                continue;
                            }
                            $initials .= strtoupper(substr($namePart, 0, 1));
                            if (strlen($initials) >= 2) {
                                break;
                            }
                        }
                        $formattedProbability = $application['probability_score'] !== null
                            ? round((float) $application['probability_score'], 1) . '%'
                            : 'Not scored';
                        $scoreLabel = $application['probability_score'] !== null ? 'Profile score' : 'Profile score unavailable';
                        $statusValue = (string) ($application['status'] ?? 'pending');
                        $courseLabel = trim((string) ($application['course'] ?? '')) ?: 'Course not provided';
                        $schoolLabel = trim((string) ($application['school'] ?? '')) ?: 'School not provided';
                        $viewApplicationUrl = buildEntityUrl('view_application.php', 'application', $applicationId, 'view', ['id' => $applicationId]);
                        ?>
                        <article class="application-record-card status-<?php echo htmlspecialchars($statusValue); ?>">
                            <div class="application-record-head">
                                <div class="application-record-applicant">
                                    <div class="application-record-avatar"><?php echo htmlspecialchars($initials ?: 'A'); ?></div>
                                    <div class="application-record-identity">
                                        <h3><?php echo htmlspecialchars($fullName); ?></h3>
                                        <p><?php echo htmlspecialchars($application['email']); ?></p>
                                        <div class="application-record-submeta">
                                            <span><i class="fas fa-graduation-cap"></i><?php echo htmlspecialchars($courseLabel); ?></span>
                                            <span><i class="fas fa-school"></i><?php echo htmlspecialchars($schoolLabel); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="application-record-badges">
                                    <span class="application-status-pill status-<?php echo htmlspecialchars($statusValue); ?>">
                                        <i class="fas <?php echo $statusValue === 'approved' ? 'fa-circle-check' : ($statusValue === 'rejected' ? 'fa-circle-xmark' : 'fa-clock'); ?>"></i>
                                        <?php echo htmlspecialchars(ucfirst($statusValue)); ?>
                                    </span>
                                    <span class="application-score-pill<?php echo $application['probability_score'] !== null ? '' : ' muted'; ?>">
                                        <span class="application-score-label"><?php echo htmlspecialchars($scoreLabel); ?></span>
                                        <strong><?php echo htmlspecialchars($formattedProbability); ?></strong>
                                    </span>
                                </div>
                            </div>

                            <div class="application-record-grid">
                                <div class="application-record-meta">
                                    <span class="application-record-label">Scholarship</span>
                                    <strong><?php echo htmlspecialchars($application['scholarship_name']); ?></strong>
                                </div>
                                <div class="application-record-meta">
                                    <span class="application-record-label">Provider</span>
                                    <strong><?php echo htmlspecialchars($application['provider']); ?></strong>
                                </div>
                                <div class="application-record-meta">
                                    <span class="application-record-label">Submitted</span>
                                    <strong><?php echo htmlspecialchars(date('M d, Y', strtotime($application['applied_at']))); ?></strong>
                                    <span class="application-record-note"><?php echo htmlspecialchars(date('h:i A', strtotime($application['applied_at']))); ?></span>
                                </div>
                                <div class="application-record-meta">
                                    <span class="application-record-label">Last Updated</span>
                                    <strong><?php echo htmlspecialchars(date('M d, Y', strtotime($application['updated_at'] ?: $application['applied_at']))); ?></strong>
                                    <span class="application-record-note"><?php echo htmlspecialchars(date('h:i A', strtotime($application['updated_at'] ?: $application['applied_at']))); ?></span>
                                </div>
                            </div>

                            <div class="application-record-actions">
                                <a href="<?php echo htmlspecialchars($viewApplicationUrl); ?>" class="btn btn-primary">
                                    <i class="fas fa-eye"></i> Open Review
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>




