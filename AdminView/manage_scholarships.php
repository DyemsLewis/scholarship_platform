<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once '../Config/db_config.php';
require_once '../Config/access_control.php';
require_once '../Config/provider_scope.php';
require_once '../Config/url_token.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to manage scholarships.');

function scholarshipTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName
    ]);

    $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$key];
}

function scholarshipReviewLabel(?string $status): string
{
    $normalized = strtolower(trim((string) $status));
    $labels = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected'
    ];

    return $labels[$normalized] ?? 'Approved';
}

function scholarshipPreviewText(?string $text, int $limit = 70): string
{
    $clean = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
    if ($clean === '') {
        return '';
    }

    if (strlen($clean) <= $limit) {
        return $clean;
    }

    return rtrim(substr($clean, 0, $limit - 3)) . '...';
}

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$statusLabels = [
    'all' => 'All statuses',
    'active' => 'Active',
    'inactive' => 'Inactive'
];
if (!array_key_exists($status_filter, $statusLabels)) {
    $status_filter = 'all';
}
$providerScope = getCurrentProviderScope($pdo);
$isProviderView = !empty($providerScope['is_provider']);
$providerOrganization = $providerScope['organization_name'] ?? null;
$reviewWorkflowReady = scholarshipTableHasColumn($pdo, 'scholarship_data', 'review_status');
$currentScholarshipActorRole = getCurrentSessionRole();
$canDeleteScholarships = ($currentScholarshipActorRole === 'super_admin');

$sql = "
    SELECT
        s.*,
        sd.provider,
        sd.deadline,
        sd.image,
        sd.benefits
        " . ($reviewWorkflowReady ? ", COALESCE(sd.review_status, 'approved') AS review_status" : '') . "
    FROM scholarships s
    LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
    WHERE 1=1
";
$params = [];

$providerClause = getProviderScholarshipScopeClause($pdo, 'sd.provider');
$sql .= $providerClause['sql'];
$params = array_merge($params, $providerClause['params']);

if ($search !== '') {
    $sql .= " AND (s.name LIKE ? OR sd.provider LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status_filter !== 'all') {
    $sql .= " AND s.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$scholarships = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($scholarships);
$active_count = count(array_filter($scholarships, static fn($s) => ($s['status'] ?? '') === 'active'));
$inactive_count = $total - $active_count;
$pending_review_count = $reviewWorkflowReady
    ? count(array_filter($scholarships, static fn($s) => strtolower((string) ($s['review_status'] ?? 'approved')) === 'pending'))
    : 0;

$today = new DateTime();
$upcoming_deadlines = 0;
foreach ($scholarships as $s) {
    if (!empty($s['deadline'])) {
        $deadline = new DateTime((string) $s['deadline']);
        if ($deadline > $today) {
            $diff = $today->diff($deadline)->days;
            if ($diff <= 30) {
                $upcoming_deadlines++;
            }
        }
    }
}

$scholarshipSummaryText = 'Showing ' . number_format($total) . ' scholarship' . ($total === 1 ? '' : 's');
if ($search !== '') {
    $scholarshipSummaryText .= ' for "' . $search . '"';
}
if ($status_filter !== 'all') {
    $scholarshipSummaryText .= ' with ' . strtolower($statusLabels[$status_filter]) . ' status';
}
$scholarshipSummaryText .= '.';

$scholarshipCssVersion = @filemtime(__DIR__ . '/../AdminPublic/css/manage-scholarship.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Scholarships - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../public/css/card-pagination.css">
    <link rel="stylesheet" href="../AdminPublic/css/manage-scholarship.css?v=<?php echo urlencode((string) $scholarshipCssVersion); ?>">
</head>
<body>
<?php include 'layouts/admin_header.php'; ?>

<section class="admin-dashboard scholarships-dashboard">
    <div class="container">
        <div class="scholarships-hero">
            <div class="scholarships-hero-main">
                <h1><i class="fas fa-graduation-cap"></i> Scholarships</h1>
                <p>
                    <?php if ($isProviderView && $providerOrganization): ?>
                        Review, update, and monitor scholarship postings under <?php echo htmlspecialchars($providerOrganization); ?>.
                    <?php elseif ($isProviderView): ?>
                        Complete your provider organization profile to begin managing scholarship postings.
                    <?php else: ?>
                        Review scholarship postings, monitor publication status, and manage review-ready opportunities.
                    <?php endif; ?>
                </p>
            </div>
            <div class="scholarships-hero-actions">
                <a href="add_scholarship.php" class="btn-add">
                    <i class="fas fa-plus"></i>
                    Add New Scholarship
                </a>
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

        <div class="scholarships-overview">
            <div class="scholarship-metric-card">
                <div class="scholarship-metric-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="scholarship-metric-copy">
                    <h3><?php echo $total; ?></h3>
                    <p>Total Scholarships</p>
                </div>
            </div>
            <div class="scholarship-metric-card">
                <div class="scholarship-metric-icon"><i class="fas fa-check-circle"></i></div>
                <div class="scholarship-metric-copy">
                    <h3><?php echo $active_count; ?></h3>
                    <p>Active</p>
                </div>
            </div>
            <div class="scholarship-metric-card">
                <div class="scholarship-metric-icon"><i class="fas fa-pause-circle"></i></div>
                <div class="scholarship-metric-copy">
                    <h3><?php echo $inactive_count; ?></h3>
                    <p>Inactive</p>
                </div>
            </div>
            <div class="scholarship-metric-card">
                <div class="scholarship-metric-icon"><i class="fas <?php echo $reviewWorkflowReady ? 'fa-hourglass-half' : 'fa-calendar-alt'; ?>"></i></div>
                <div class="scholarship-metric-copy">
                    <h3><?php echo $reviewWorkflowReady ? $pending_review_count : $upcoming_deadlines; ?></h3>
                    <p><?php echo $reviewWorkflowReady ? 'Pending Review' : 'Upcoming Deadlines'; ?></p>
                </div>
            </div>
        </div>

        <div class="scholarships-control-panel">
            <div class="scholarships-toolbar-copy">
                <h2>Search and Filter</h2>
                <p><?php echo htmlspecialchars($scholarshipSummaryText); ?></p>
            </div>

            <form method="GET" class="scholarships-filter-form">
                <div class="search-wrapper scholarships-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search by name or provider..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="scholarships-filter-field">
                    <label for="status-filter">Status</label>
                    <div class="scholarships-select-wrap">
                        <select name="status" id="status-filter" class="scholarships-select">
                            <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $status_filter === $value ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fas fa-chevron-down scholarships-select-icon" aria-hidden="true"></i>
                    </div>
                </div>

                <div class="scholarships-filter-actions">
                    <button type="submit" class="btn-search">
                        <i class="fas fa-sliders"></i> Apply
                    </button>
                    <?php if ($search !== '' || $status_filter !== 'all'): ?>
                    <a href="manage_scholarships.php" class="btn-clear">
                        <i class="fas fa-times"></i> Reset
                    </a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="scholarships-filter-tabs">
                <a href="?<?php echo $search !== '' ? 'search=' . urlencode($search) . '&' : ''; ?>status=all" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> All
                </a>
                <a href="?<?php echo $search !== '' ? 'search=' . urlencode($search) . '&' : ''; ?>status=active" class="filter-tab <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle"></i> Active
                </a>
                <a href="?<?php echo $search !== '' ? 'search=' . urlencode($search) . '&' : ''; ?>status=inactive" class="filter-tab <?php echo $status_filter === 'inactive' ? 'active' : ''; ?>">
                    <i class="fas fa-pause-circle"></i> Inactive
                </a>
            </div>
        </div>

        <div class="scholarships-table-panel">
            <div class="scholarships-table-head">
                <div>
                    <span class="scholarships-table-kicker"><?php echo $isProviderView ? 'My Postings' : 'Directory'; ?></span>
                    <h2>Scholarship List</h2>
                    <p><?php echo htmlspecialchars($reviewWorkflowReady ? 'Review status and publication status are tracked together for each scholarship posting.' : 'Publication status and deadlines are tracked for each scholarship posting.'); ?></p>
                </div>
            </div>

            <div class="table-container">
                <table
                    id="scholarshipListTable"
                    class="admin-table scholarships-admin-table"
                    data-pagination="cards"
                    data-page-size="8"
                    data-item-selector=".scholarship-list-row"
                    data-pagination-label="scholarships"
                >
                    <colgroup>
                        <col class="col-scholarship">
                        <col class="col-provider">
                        <col class="col-gwa">
                        <col class="col-deadline">
                        <?php if ($reviewWorkflowReady): ?>
                        <col class="col-review">
                        <?php endif; ?>
                        <col class="col-status">
                        <col class="col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Scholarship</th>
                            <th>Provider</th>
                            <th>GWA Requirement</th>
                            <th>Deadline</th>
                            <?php if ($reviewWorkflowReady): ?>
                            <th>Review</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($scholarships)): ?>
                        <tr>
                            <td colspan="<?php echo $reviewWorkflowReady ? '7' : '6'; ?>">
                                <div class="empty-state">
                                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                                    <h3>No scholarships found</h3>
                                    <p>
                                        <?php if ($search !== ''): ?>
                                            No scholarships match your current search.
                                        <?php else: ?>
                                            Start by creating the first scholarship posting for this workspace.
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($search === ''): ?>
                                    <a href="add_scholarship.php" class="btn-add scholarship-empty-action">
                                        <i class="fas fa-plus"></i>
                                        Add Scholarship
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($scholarships as $scholarship): ?>
                                <?php
                                $scholarshipId = (int) $scholarship['id'];
                                $editScholarshipUrl = buildEntityUrl('edit_scholarship.php', 'scholarship', $scholarshipId, 'edit', ['id' => $scholarshipId]);
                                $deleteScholarshipToken = buildEntityUrlToken('scholarship', $scholarshipId, 'delete');
                                $requiredGwa = null;
                                if (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') {
                                    $requiredGwa = (float) $scholarship['min_gwa'];
                                } elseif (isset($scholarship['max_gwa']) && $scholarship['max_gwa'] !== null && $scholarship['max_gwa'] !== '') {
                                    $requiredGwa = (float) $scholarship['max_gwa'];
                                }
                                ?>
                                <tr class="scholarship-list-row">
                                    <td class="scholarship-list-primary">
                                        <div class="scholarship-cell">
                                            <?php if (!empty($scholarship['image'])): ?>
                                            <img src="../public/uploads/<?php echo htmlspecialchars($scholarship['image']); ?>"
                                                 alt="<?php echo htmlspecialchars($scholarship['name']); ?>"
                                                 class="scholarship-image"
                                                 onerror="this.src='../public/uploads/scholarship-default.jpg'">
                                            <?php else: ?>
                                            <div class="scholarship-image scholarship-image-placeholder">
                                                <i class="fas fa-graduation-cap"></i>
                                            </div>
                                            <?php endif; ?>
                                            <div class="scholarship-info">
                                                <span class="scholarship-name"><?php echo htmlspecialchars($scholarship['name']); ?></span>
                                                <?php $benefitPreview = scholarshipPreviewText($scholarship['benefits'] ?? ''); ?>
                                                <?php if ($benefitPreview !== ''): ?>
                                                <span class="scholarship-meta-line"><?php echo htmlspecialchars($benefitPreview); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="scholarship-list-provider">
                                        <span class="provider-badge">
                                            <i class="fas fa-building"></i>
                                            <span class="provider-badge-text"><?php echo htmlspecialchars($scholarship['provider'] ?? 'Not specified'); ?></span>
                                        </span>
                                    </td>
                                    <td class="scholarship-list-gwa">
                                        <span class="gwa-range">
                                            <?php if ($requiredGwa !== null): ?>
                                                <span class="gwa-max">Up to <?php echo number_format($requiredGwa, 2); ?></span>
                                            <?php else: ?>
                                                <span class="gwa-max">Any</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="scholarship-list-deadline">
                                        <div class="deadline-cell">
                                            <?php if (!empty($scholarship['deadline'])): ?>
                                                <span class="deadline-date">
                                                    <i class="far fa-calendar-alt deadline-icon"></i>
                                                    <?php echo date('M d, Y', strtotime((string) $scholarship['deadline'])); ?>
                                                </span>
                                                <?php
                                                $deadlineNow = new DateTime((string) $scholarship['deadline']);
                                                $days_left = $today->diff($deadlineNow)->days;
                                                if ($deadlineNow < $today):
                                                ?>
                                                    <span class="deadline-badge expired"><i class="fas fa-clock"></i> Expired</span>
                                                <?php elseif ($days_left <= 7): ?>
                                                    <span class="deadline-badge urgent"><i class="fas fa-exclamation-triangle"></i> <?php echo $days_left; ?> days left</span>
                                                <?php elseif ($days_left <= 30): ?>
                                                    <span class="deadline-badge upcoming"><i class="fas fa-hourglass-half"></i> <?php echo $days_left; ?> days left</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="deadline-none"><i class="fas fa-infinity"></i> No deadline</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <?php if ($reviewWorkflowReady): ?>
                                    <td class="scholarship-list-review">
                                        <?php $reviewStatus = strtolower((string) ($scholarship['review_status'] ?? 'approved')); ?>
                                        <div class="list-badge-stack">
                                            <span class="status-badge review-<?php echo htmlspecialchars($reviewStatus); ?>">
                                                <i class="fas fa-<?php echo $reviewStatus === 'approved' ? 'circle-check' : ($reviewStatus === 'rejected' ? 'ban' : 'hourglass-half'); ?>"></i>
                                                <?php echo htmlspecialchars(scholarshipReviewLabel($reviewStatus)); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                    <td class="scholarship-list-status">
                                        <div class="list-badge-stack">
                                            <span class="status-badge <?php echo htmlspecialchars((string) $scholarship['status']); ?>">
                                                <i class="fas fa-<?php echo ($scholarship['status'] ?? '') === 'active' ? 'check-circle' : 'pause-circle'; ?>"></i>
                                                <?php echo htmlspecialchars(ucfirst((string) $scholarship['status'])); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="scholarship-list-actions">
                                        <div class="action-buttons scholarship-actions">
                                            <a href="<?php echo htmlspecialchars($editScholarshipUrl); ?>" class="action-btn edit" title="Edit Scholarship">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($canDeleteScholarships): ?>
                                            <form method="POST" action="../AdminController/scholarship_process.php" class="scholarship-delete-form scholarship-inline-form" data-scholarship-name="<?php echo htmlspecialchars($scholarship['name']); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $scholarshipId; ?>">
                                                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($deleteScholarshipToken); ?>">
                                                <button type="submit" class="action-btn delete" title="Delete Scholarship">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script src="../public/js/card-pagination.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.scholarship-delete-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const scholarshipName = form.getAttribute('data-scholarship-name') || 'this scholarship';

        const result = await Swal.fire({
            title: 'Delete Scholarship?',
            html: `Are you sure you want to delete <strong>${scholarshipName}</strong>?<br><br>This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel'
        });

        if (!result.isConfirmed) {
            return;
        }

        Swal.fire({
            title: 'Deleting...',
            text: 'Please wait',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        form.submit();
    });
});
</script>

<?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
