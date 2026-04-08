<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/url_token.php';

requireRoles(['admin', 'super_admin'], '../AdminView/reviews.php', 'Only administrators can review scholarship submissions.');
if (!canAccessScholarshipApprovals()) {
    $_SESSION['error'] = 'You do not have permission to review scholarship submissions.';
    header('Location: reviews.php');
    exit();
}

function scholarshipReviewsTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
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

function scholarshipReviewValue($value, string $fallback = 'Not provided'): string
{
    $trimmed = trim((string) ($value ?? ''));
    return $trimmed !== '' ? $trimmed : $fallback;
}

function scholarshipReviewStatusLabel(string $status): string
{
    $normalized = strtolower(trim($status));
    $labels = [
        'pending' => 'Pending review',
        'approved' => 'Approved',
        'rejected' => 'Rejected'
    ];

    return $labels[$normalized] ?? ucfirst($normalized ?: 'Approved');
}

function scholarshipReviewStatusClass(string $status): string
{
    $normalized = strtolower(trim($status));
    return in_array($normalized, ['pending', 'approved', 'rejected'], true) ? $normalized : 'approved';
}

$reviewsCurrentView = 'scholarships';
$search = trim((string) ($_GET['search'] ?? ''));
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'pending')));
$allowedFilters = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'pending';
}
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();

$workflowReady = scholarshipReviewsTableHasColumn($pdo, 'scholarship_data', 'review_status');
$reviewRows = [];
$summary = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];

if ($workflowReady) {
    $summaryStmt = $pdo->query("
        SELECT COALESCE(review_status, 'approved') AS review_status, COUNT(*) AS total
        FROM scholarship_data
        GROUP BY COALESCE(review_status, 'approved')
    ");
    foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim((string) ($row['review_status'] ?? 'approved')));
        if (array_key_exists($key, $summary)) {
            $summary[$key] = (int) ($row['total'] ?? 0);
        }
    }

    $sql = "
        SELECT
            s.id,
            s.name,
            s.description,
            s.status AS publication_status,
            s.created_at,
            s.updated_at,
            sd.provider,
            sd.deadline,
            sd.address,
            sd.city,
            sd.province,
            sd.target_applicant_type,
            COALESCE(sd.review_status, 'approved') AS review_status,
            " . (scholarshipReviewsTableHasColumn($pdo, 'scholarship_data', 'review_notes') ? 'sd.review_notes' : "NULL AS review_notes") . ",
            " . (scholarshipReviewsTableHasColumn($pdo, 'scholarship_data', 'reviewed_at') ? 'sd.reviewed_at' : "NULL AS reviewed_at") . "
        FROM scholarships s
        LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
        WHERE 1=1
    ";

    $params = [];
    if ($filter !== 'all') {
        $sql .= " AND COALESCE(sd.review_status, 'approved') = ?";
        $params[] = $filter;
    }

    if ($search !== '') {
        $sql .= " AND (
            s.name LIKE ?
            OR COALESCE(sd.provider, '') LIKE ?
            OR COALESCE(sd.city, '') LIKE ?
            OR COALESCE(sd.province, '') LIKE ?
        )";
        $needle = '%' . $search . '%';
        $params[] = $needle;
        $params[] = $needle;
        $params[] = $needle;
        $params[] = $needle;
    }

    $sql .= "
        ORDER BY
            CASE COALESCE(sd.review_status, 'approved')
                WHEN 'pending' THEN 0
                WHEN 'rejected' THEN 1
                ELSE 2
            END,
            s.updated_at DESC,
            s.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reviewRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <title>Scholarship Reviews</title>
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
                        <i class="fas fa-graduation-cap"></i> Scholarship Reviews
                    </h1>
                    <p>Provider-submitted scholarship approval queue</p>
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

            <?php if (!$workflowReady): ?>
            <section class="reviews-info-panel">
                <h3>Scholarship review workflow is not ready</h3>
                <p>Run the scholarship review migration first so provider-submitted scholarships can be held for approval before publication.</p>
            </section>
            <?php else: ?>
            <div class="reviews-summary-grid reviews-summary-simple-grid">
                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Pending Reviews</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($summary['pending']); ?></strong>
                    <span class="reviews-summary-simple-meta">Scholarships waiting for approval</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Approved Submissions</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($summary['approved']); ?></strong>
                    <span class="reviews-summary-simple-meta">Scholarships already cleared for publication</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Rejected Submissions</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($summary['rejected']); ?></strong>
                    <span class="reviews-summary-simple-meta">Scholarships that need revision before publication</span>
                </article>
            </div>

            <section class="reviews-info-panel scholarship-reviews-panel">
                <div class="provider-reviews-toolbar">
                    <div>
                        <h3>Scholarship review queue</h3>
                        <p><?php echo number_format(count($reviewRows)); ?> scholarship records</p>
                    </div>
                    <form method="GET" class="provider-reviews-search">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <label for="scholarship-review-search" class="sr-only">Search scholarships</label>
                        <div class="provider-search-field">
                            <i class="fas fa-search"></i>
                            <input
                                type="text"
                                id="scholarship-review-search"
                                name="search"
                                value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Search scholarship or provider">
                        </div>
                        <button type="submit" class="btn btn-primary">Search</button>
                        <?php if ($search !== ''): ?>
                        <a href="scholarship_reviews.php?filter=<?php echo urlencode($filter); ?>" class="btn btn-outline">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="scholarship-review-filter-row">
                    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
                    <a href="?filter=<?php echo urlencode($value); ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo $filter === $value ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($label); ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($reviewRows)): ?>
                <div class="provider-reviews-empty">
                    <i class="fas fa-inbox"></i>
                    <h3>No scholarship submissions found</h3>
                    <p>There are no scholarship records in this review state right now.</p>
                </div>
                <?php else: ?>
                <div class="scholarship-review-board">
                    <?php foreach ($reviewRows as $row): ?>
                        <?php
                        $scholarshipId = (int) ($row['id'] ?? 0);
                        $reviewStatus = strtolower(trim((string) ($row['review_status'] ?? 'approved')));
                        $editUrl = buildEntityUrl('edit_scholarship.php', 'scholarship', $scholarshipId, 'edit', ['id' => $scholarshipId]);
                        $reviewToken = buildEntityUrlToken('scholarship', $scholarshipId, 'review');
                        $location = trim(implode(', ', array_filter([
                            trim((string) ($row['city'] ?? '')),
                            trim((string) ($row['province'] ?? ''))
                        ])));
                        ?>
                        <article class="scholarship-review-card">
                            <div class="provider-review-card-top">
                                <div>
                                    <span class="provider-review-label">Scholarship submission</span>
                                    <h2><?php echo htmlspecialchars((string) ($row['name'] ?? 'Untitled Scholarship')); ?></h2>
                                    <p><?php echo htmlspecialchars(scholarshipReviewValue($row['provider'] ?? '', 'Provider not set')); ?></p>
                                </div>
                                <div class="provider-review-badges">
                                    <span class="provider-review-status provider-review-status-<?php echo htmlspecialchars(scholarshipReviewStatusClass($reviewStatus)); ?>">
                                        <?php echo htmlspecialchars(scholarshipReviewStatusLabel($reviewStatus)); ?>
                                    </span>
                                    <span class="provider-review-chip neutral">
                                        <?php echo htmlspecialchars(ucfirst((string) ($row['publication_status'] ?? 'inactive'))); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="provider-review-meta-grid">
                                <div class="provider-meta-item">
                                    <span>Deadline</span>
                                    <strong><?php echo htmlspecialchars(!empty($row['deadline']) ? date('M d, Y', strtotime((string) $row['deadline'])) : 'No deadline set'); ?></strong>
                                </div>
                                <div class="provider-meta-item">
                                    <span>Applicant type</span>
                                    <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', scholarshipReviewValue($row['target_applicant_type'] ?? '', 'All')))); ?></strong>
                                </div>
                                <div class="provider-meta-item">
                                    <span>Location</span>
                                    <strong><?php echo htmlspecialchars($location !== '' ? $location : scholarshipReviewValue($row['address'] ?? '')); ?></strong>
                                </div>
                                <div class="provider-meta-item">
                                    <span>Last updated</span>
                                    <strong><?php echo htmlspecialchars(!empty($row['updated_at']) ? date('M d, Y h:i A', strtotime((string) $row['updated_at'])) : 'Not recorded'); ?></strong>
                                </div>
                            </div>

                            <?php if (trim((string) ($row['description'] ?? '')) !== ''): ?>
                            <p class="scholarship-review-description"><?php echo htmlspecialchars((string) $row['description']); ?></p>
                            <?php endif; ?>

                            <?php if (trim((string) ($row['review_notes'] ?? '')) !== ''): ?>
                            <div class="scholarship-review-note">
                                <i class="fas fa-note-sticky"></i>
                                <span><?php echo htmlspecialchars((string) $row['review_notes']); ?></span>
                            </div>
                            <?php endif; ?>

                            <div class="provider-review-actions scholarship-review-actions">
                                <a href="<?php echo htmlspecialchars($editUrl); ?>" class="btn btn-outline">
                                    <i class="fas fa-eye"></i>
                                    Review details
                                </a>

                                <?php if ($reviewStatus !== 'approved'): ?>
                                <form method="POST" action="../app/AdminControllers/scholarship_process.php" class="inline-review-form">
                                    <input type="hidden" name="action" value="approve_review">
                                    <input type="hidden" name="id" value="<?php echo $scholarshipId; ?>">
                                    <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($reviewToken); ?>">
                                    <input type="hidden" name="redirect_target" value="reviews">
                                    <button type="button" class="btn btn-primary" data-scholarship-review-action="approve">
                                        <i class="fas fa-circle-check"></i>
                                        Approve
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if ($reviewStatus !== 'rejected'): ?>
                                <form method="POST" action="../app/AdminControllers/scholarship_process.php" class="inline-review-form">
                                    <input type="hidden" name="action" value="reject_review">
                                    <input type="hidden" name="id" value="<?php echo $scholarshipId; ?>">
                                    <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($reviewToken); ?>">
                                    <input type="hidden" name="redirect_target" value="reviews">
                                    <button type="button" class="btn btn-outline" data-scholarship-review-action="reject">
                                        <i class="fas fa-ban"></i>
                                        Reject
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.querySelectorAll('.inline-review-form [data-scholarship-review-action]').forEach((button) => {
            button.addEventListener('click', async () => {
                const form = button.closest('form');
                if (!form) {
                    return;
                }

                const isApprove = button.dataset.scholarshipReviewAction === 'approve';
                const title = isApprove ? 'Approve Scholarship?' : 'Reject Scholarship?';
                const text = isApprove
                    ? 'This will approve and publish the scholarship.'
                    : 'This will mark the scholarship as rejected for revision.';

                if (window.Swal && typeof window.Swal.fire === 'function') {
                    const result = await window.Swal.fire({
                        icon: 'question',
                        title,
                        text,
                        showCancelButton: true,
                        confirmButtonColor: isApprove ? '#1d4ed8' : '#c2410c',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: isApprove ? 'Approve' : 'Reject'
                    });

                    if (result.isConfirmed) {
                        form.submit();
                    }
                    return;
                }

                if (window.confirm(text)) {
                    form.submit();
                }
            });
        });
    </script>
    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
