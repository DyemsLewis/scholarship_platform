<?php
session_start();
require_once '../Config/db_config.php';
require_once '../Config/access_control.php';
require_once '../Config/provider_scope.php';
require_once '../Model/GwaIssueReport.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to access reviews.');

function reviewsTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName
    ]);

    $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$key];
}

function reviewsCount(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$currentRole = getCurrentSessionRole();
$canManageApplications = canAccessStaffApplications();
$canVerifyDocuments = canAccessStaffDocuments();
$canReviewProviders = canAccessProviderApprovals();
$canReviewScholarships = canAccessScholarshipApprovals();
$canReviewGwaReports = canAccessGwaIssueReports();
$applicationScope = getProviderScholarshipScopeClause($pdo, 'sd.provider');
$documentScope = getProviderDocumentScopeClause($pdo, 'ud.user_id');

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

$pendingApplications = $canManageApplications ? reviewsCount(
    $pdo,
    $applicationCountSqlBase . $applicationScope['sql'] . " AND a.status = 'pending'",
    $applicationScope['params']
) : 0;
$totalApplications = $canManageApplications ? reviewsCount(
    $pdo,
    $applicationCountSqlBase . $applicationScope['sql'],
    $applicationScope['params']
) : 0;
$pendingDocuments = $canVerifyDocuments ? reviewsCount(
    $pdo,
    $documentCountSqlBase . $documentScope['sql'] . " AND ud.status = 'pending'",
    $documentScope['params']
) : 0;
$verifiedDocuments = $canVerifyDocuments ? reviewsCount(
    $pdo,
    $documentCountSqlBase . $documentScope['sql'] . " AND ud.status = 'verified'",
    $documentScope['params']
) : 0;
$pendingProviders = $canReviewProviders ? reviewsCount(
    $pdo,
    "SELECT COUNT(*) FROM users WHERE role = 'provider' AND status <> 'active'"
) : 0;
$verifiedProviders = $canReviewProviders && reviewsTableHasColumn($pdo, 'provider_data', 'is_verified') ? reviewsCount(
    $pdo,
    "SELECT COUNT(*) FROM provider_data WHERE is_verified = 1"
) : 0;
$pendingScholarshipReviews = ($canReviewScholarships && reviewsTableHasColumn($pdo, 'scholarship_data', 'review_status')) ? reviewsCount(
    $pdo,
    "
        SELECT COUNT(*)
        FROM scholarships s
        LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
        WHERE sd.review_status = 'pending'
    "
) : 0;
$gwaReportStats = [
    'pending' => 0,
    'resolved' => 0,
    'with_reported_gwa' => 0,
];
if ($canReviewGwaReports) {
    $gwaIssueReportModel = new GwaIssueReport($pdo);
    $gwaReportStats = $gwaIssueReportModel->getStats();
}
$rejectedScholarshipReviews = ($canReviewScholarships && reviewsTableHasColumn($pdo, 'scholarship_data', 'review_status')) ? reviewsCount(
    $pdo,
    "
        SELECT COUNT(*)
        FROM scholarships s
        LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
        WHERE sd.review_status = 'rejected'
    "
) : 0;

$reviewQueueCards = [];
if ($canManageApplications) {
    $reviewQueueCards[] = [
        'tone' => 'applications',
        'icon' => 'fa-file-lines',
        'title' => 'Applications',
        'pending' => $pendingApplications,
        'meta' => number_format($totalApplications) . ' total applications',
        'description' => 'Application queue',
        'href' => 'manage_applications.php',
        'cta' => 'Open Applications'
    ];
}
if ($canVerifyDocuments) {
    $reviewQueueCards[] = [
        'tone' => 'documents',
        'icon' => 'fa-folder-open',
        'title' => 'Documents',
        'pending' => $pendingDocuments,
        'meta' => number_format($verifiedDocuments) . ' verified documents',
        'description' => 'Document verification',
        'href' => 'admin_verify_documents.php',
        'cta' => 'Open Documents'
    ];
}
if ($canReviewScholarships) {
    $reviewQueueCards[] = [
        'tone' => 'scholarships',
        'icon' => 'fa-graduation-cap',
        'title' => 'Scholarships',
        'pending' => $pendingScholarshipReviews,
        'meta' => number_format($rejectedScholarshipReviews) . ' rejected submissions',
        'description' => 'Scholarship review queue',
        'href' => 'scholarship_reviews.php',
        'cta' => 'Open Scholarship Reviews'
    ];
}
if ($canReviewProviders) {
    $reviewQueueCards[] = [
        'tone' => 'providers',
        'icon' => 'fa-building-shield',
        'title' => 'Providers',
        'pending' => $pendingProviders,
        'meta' => number_format($verifiedProviders) . ' verified providers',
        'description' => 'Provider approval queue',
        'href' => 'provider_reviews.php',
        'cta' => 'Open Provider Reviews'
    ];
}
if ($canReviewGwaReports) {
    $reviewQueueCards[] = [
        'tone' => 'gwa',
        'icon' => 'fa-flag',
        'title' => 'GWA Reports',
        'pending' => (int) ($gwaReportStats['pending'] ?? 0),
        'meta' => number_format((int) ($gwaReportStats['resolved'] ?? 0)) . ' resolved reports',
        'description' => 'OCR and extracted GWA corrections',
        'href' => 'gwa_reports.php',
        'cta' => 'Open GWA Reports'
    ];
}

$totalPendingReviewItems = 0;
foreach ($reviewQueueCards as $queueCard) {
    $totalPendingReviewItems += (int) ($queueCard['pending'] ?? 0);
}
$reviewQueueCount = count($reviewQueueCards);
$clearedQueueCount = count(array_filter($reviewQueueCards, static fn(array $card): bool => (int) ($card['pending'] ?? 0) === 0));
$reviewsWorkspaceLabel = $currentRole === 'provider' ? 'Provider review workspace' : 'Administrative review workspace';

$reviewsCurrentView = 'overview';
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css?v=<?php echo urlencode((string) $reviewsStyleVersion); ?>">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard reviews-overview-page">
        <div class="container">
            <div class="reviews-overview-hero">
                <div class="reviews-overview-main">
                    <span class="reviews-overview-kicker">Review Center</span>
                    <h1><i class="fas fa-list-check"></i> Reviews</h1>
                    <p>Applications, documents, scholarship submissions, and provider approvals in one review workspace.</p>
                    <div class="reviews-overview-pills">
                        <span class="reviews-overview-pill"><i class="fas fa-hourglass-half"></i><?php echo number_format($totalPendingReviewItems); ?> pending</span>
                        <span class="reviews-overview-pill"><i class="fas fa-layer-group"></i><?php echo number_format($reviewQueueCount); ?> review areas</span>
                        <span class="reviews-overview-pill"><i class="fas fa-circle-check"></i><?php echo number_format($clearedQueueCount); ?> clear</span>
                    </div>
                </div>
                <div class="reviews-overview-side">
                    <div class="reviews-overview-side-card">
                        <span>Workspace</span>
                        <strong><?php echo htmlspecialchars($reviewsWorkspaceLabel); ?></strong>
                    </div>
                    <div class="reviews-overview-side-card">
                        <span>Pending Items</span>
                        <strong><?php echo number_format($totalPendingReviewItems); ?></strong>
                    </div>
                    <div class="reviews-overview-side-card">
                        <span>Queues Clear</span>
                        <strong><?php echo number_format($clearedQueueCount); ?></strong>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <p><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <p><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
            </div>
            <?php endif; ?>

            <?php include 'layouts/reviews_nav.php'; ?>

            <div class="reviews-overview-metrics">
                <article class="reviews-overview-metric-card">
                    <span class="reviews-overview-metric-label">Pending Total</span>
                    <strong class="reviews-overview-metric-value"><?php echo number_format($totalPendingReviewItems); ?></strong>
                    <p>Open review items</p>
                </article>
                <article class="reviews-overview-metric-card">
                    <span class="reviews-overview-metric-label">Review Areas</span>
                    <strong class="reviews-overview-metric-value"><?php echo number_format($reviewQueueCount); ?></strong>
                    <p>Available queues</p>
                </article>
                <article class="reviews-overview-metric-card">
                    <span class="reviews-overview-metric-label">Queues Clear</span>
                    <strong class="reviews-overview-metric-value"><?php echo number_format($clearedQueueCount); ?></strong>
                    <p>No pending items</p>
                </article>
            </div>

            <div class="reviews-workboard">
                <?php foreach ($reviewQueueCards as $queueCard): ?>
                <article class="reviews-work-card">
                    <div class="reviews-work-top">
                        <div class="reviews-work-icon">
                            <i class="fas <?php echo htmlspecialchars($queueCard['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <span class="reviews-work-badge"><?php echo (int) $queueCard['pending'] > 0 ? 'Needs attention' : 'Clear'; ?></span>
                    </div>
                    <strong class="reviews-work-number"><?php echo number_format((int) $queueCard['pending']); ?></strong>
                    <h2><?php echo htmlspecialchars($queueCard['title']); ?></h2>
                    <p><?php echo htmlspecialchars($queueCard['description']); ?></p>
                    <span class="reviews-work-meta"><?php echo htmlspecialchars($queueCard['meta']); ?></span>
                    <a href="<?php echo htmlspecialchars($queueCard['href']); ?>" class="btn btn-primary"><?php echo htmlspecialchars($queueCard['cta']); ?></a>
                </article>
                <?php endforeach; ?>
            </div>

        </div>
    </section>

    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>