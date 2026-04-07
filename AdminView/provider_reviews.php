<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once '../Config/db_config.php';
require_once '../Config/access_control.php';
require_once '../Config/url_token.php';
require_once '../Model/StaffAccountProfile.php';

requireRoles(['admin', 'super_admin'], '../AdminView/reviews.php', 'Only administrators can review provider accounts.');
if (!canAccessProviderApprovals()) {
    $_SESSION['error'] = 'You do not have permission to review provider accounts.';
    header('Location: reviews.php');
    exit();
}

function providerReviewsTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
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

function providerReviewsValue($value, string $fallback = 'Not provided'): string
{
    $trimmed = trim((string) ($value ?? ''));
    return $trimmed !== '' ? $trimmed : $fallback;
}

function providerReviewsFileExists(?string $relativePath): bool
{
    $normalized = trim(str_replace('\\', '/', (string) $relativePath), '/');
    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return false;
    }

    return is_file(dirname(__DIR__) . '/public/uploads/' . $normalized);
}

$search = trim((string) ($_GET['search'] ?? ''));
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
$filterOptions = [
    'all' => 'All providers',
    'needs_activation' => 'Needs activation',
    'pending' => 'Pending',
    'verified' => 'Verified',
    'with_file' => 'With verification file',
    'active' => 'Active',
];

if (!array_key_exists($filter, $filterOptions)) {
    $filter = 'all';
}

$userColumns = ['id', 'username', 'email', 'status', 'created_at'];
foreach (['updated_at', 'email_verified_at', 'last_login'] as $optionalColumn) {
    if (providerReviewsTableHasColumn($pdo, 'users', $optionalColumn)) {
        $userColumns[] = $optionalColumn;
    }
}

$providerQuery = "
    SELECT " . implode(', ', $userColumns) . "
    FROM users
    WHERE role = 'provider'
";

$providerQuery .= "
    ORDER BY
        CASE
            WHEN status = 'pending' THEN 0
            WHEN status = 'inactive' THEN 1
            WHEN status = 'suspended' THEN 2
            ELSE 3
        END,
        created_at DESC
";

$stmt = $pdo->prepare($providerQuery);
$stmt->execute();
$providerUsers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$profileModel = new StaffAccountProfile($pdo);
$providerCards = [];

foreach ($providerUsers as $providerUser) {
    $providerId = (int) ($providerUser['id'] ?? 0);
    if ($providerId <= 0) {
        continue;
    }

    $profile = $profileModel->getByUserId($providerId, 'provider', $providerUser) ?? [];
    $organizationName = trim((string) ($profile['organization_name'] ?? ''));
    if ($organizationName === '') {
        $organizationName = 'Unnamed Organization';
    }

    $contactName = $profileModel->buildDisplayName('provider', $profile, $providerUser);
    $contactPosition = trim((string) ($profile['contact_person_position'] ?? ''));
    $organizationEmail = trim((string) ($profile['organization_email'] ?? ($providerUser['email'] ?? '')));
    $website = trim((string) ($profile['website'] ?? ''));
    $city = trim((string) ($profile['city'] ?? ''));
    $province = trim((string) ($profile['province'] ?? ''));
    $location = trim($city . ($city !== '' && $province !== '' ? ', ' : '') . $province, ' ,');
    $verificationUploaded = providerReviewsFileExists($profile['verification_document'] ?? null);
    $isVerified = (int) ($profile['is_verified'] ?? 0) === 1;
    $currentStatus = strtolower((string) ($providerUser['status'] ?? 'inactive'));
    $needsActivation = $currentStatus !== 'active';
    $reviewUrl = buildEntityUrl('view_provider.php', 'user', $providerId, 'view', ['id' => $providerId]);

    $providerCards[] = [
        'id' => $providerId,
        'organization_name' => $organizationName,
        'contact_name' => $contactName,
        'contact_position' => $contactPosition !== '' ? $contactPosition : 'Contact position not set',
        'username' => (string) ($providerUser['username'] ?? ''),
        'organization_email' => providerReviewsValue($organizationEmail),
        'website' => $website,
        'location' => providerReviewsValue($location),
        'status' => $currentStatus,
        'verification_uploaded' => $verificationUploaded,
        'is_verified' => $isVerified,
        'created_at' => (string) ($providerUser['created_at'] ?? ''),
        'review_url' => $reviewUrl,
        'needs_activation' => $needsActivation
    ];
}

if ($search !== '') {
    $searchNeedle = strtolower($search);
    $providerCards = array_values(array_filter($providerCards, static function (array $provider) use ($searchNeedle): bool {
        $haystack = strtolower(implode(' ', array_filter([
            $provider['organization_name'] ?? '',
            $provider['contact_name'] ?? '',
            $provider['contact_position'] ?? '',
            $provider['username'] ?? '',
            $provider['organization_email'] ?? '',
            $provider['website'] ?? '',
            $provider['location'] ?? ''
        ], static fn($value) => trim((string) $value) !== '')));

        return $haystack !== '' && strpos($haystack, $searchNeedle) !== false;
    }));
}

if ($filter !== 'all') {
    $providerCards = array_values(array_filter($providerCards, static function (array $provider) use ($filter): bool {
        return match ($filter) {
            'needs_activation' => !empty($provider['needs_activation']),
            'pending' => (string) ($provider['status'] ?? '') === 'pending',
            'verified' => !empty($provider['is_verified']),
            'with_file' => !empty($provider['verification_uploaded']),
            'active' => (string) ($provider['status'] ?? '') === 'active',
            default => true,
        };
    }));
}

$reviewsCurrentView = 'providers';
$totalProviders = count($providerCards);
$pendingProviders = count(array_filter($providerCards, static fn($provider) => !empty($provider['needs_activation'])));
$verifiedProviders = count(array_filter($providerCards, static fn($provider) => !empty($provider['is_verified'])));
$withDocuments = count(array_filter($providerCards, static fn($provider) => !empty($provider['verification_uploaded'])));
$providerToolbarSummary = number_format($totalProviders) . ' provider account' . ($totalProviders === 1 ? '' : 's');
if ($filter !== 'all') {
    $providerToolbarSummary .= ' | ' . $filterOptions[$filter];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Reviews</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../public/css/card-pagination.css">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css?v=2">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard provider-reviews-page">
        <div class="container">
            <div class="page-header">
                <div>
                    <h1>
                        <i class="fas fa-building-shield"></i> Provider Reviews
                    </h1>
                    <p>Organization verification and activation queue</p>
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

            <div class="reviews-summary-grid reviews-summary-simple-grid">
                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Awaiting Activation</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($pendingProviders); ?></strong>
                    <span class="reviews-summary-simple-meta">Provider accounts still under review</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">Verified Providers</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($verifiedProviders); ?></strong>
                    <span class="reviews-summary-simple-meta">Providers already marked as verified</span>
                </article>

                <article class="reviews-summary-card reviews-summary-card-simple">
                    <span class="reviews-summary-simple-label">With Verification File</span>
                    <strong class="reviews-summary-simple-value"><?php echo number_format($withDocuments); ?></strong>
                    <span class="reviews-summary-simple-meta">Providers with an uploaded verification document</span>
                </article>
            </div>

            <section class="reviews-info-panel provider-reviews-panel">
                <div class="provider-reviews-toolbar">
                    <div>
                        <h3>Provider review queue</h3>
                        <p><?php echo htmlspecialchars($providerToolbarSummary); ?></p>
                    </div>
                    <form method="GET" class="provider-reviews-search">
                        <label for="provider-review-search" class="sr-only">Search providers</label>
                        <div class="provider-search-field">
                            <i class="fas fa-search"></i>
                            <input
                                type="text"
                                id="provider-review-search"
                                name="search"
                                value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Search by username or email">
                        </div>
                        <label for="provider-review-filter" class="sr-only">Filter providers</label>
                        <div class="provider-filter-field">
                            <i class="fas fa-filter"></i>
                            <select id="provider-review-filter" name="filter">
                                <?php foreach ($filterOptions as $filterValue => $filterLabel): ?>
                                <option value="<?php echo htmlspecialchars($filterValue); ?>" <?php echo $filter === $filterValue ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($filterLabel); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Search</button>
                        <?php if ($search !== '' || $filter !== 'all'): ?>
                        <a href="provider_reviews.php" class="btn btn-outline">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($providerCards)): ?>
                <div class="provider-reviews-empty">
                    <i class="fas fa-inbox"></i>
                    <h3>No provider accounts found</h3>
                    <p><?php echo ($search !== '' || $filter !== 'all') ? 'Try adjusting the search term or filter.' : 'Provider registrations will appear here for review.'; ?></p>
                </div>
                <?php else: ?>
                <div
                    id="providerReviewBoard"
                    class="provider-review-board"
                    data-pagination="cards"
                    data-page-size="6"
                    data-item-selector=".provider-review-card"
                    data-pagination-label="provider accounts"
                >
                    <?php foreach ($providerCards as $provider): ?>
                    <article class="provider-review-card" id="provider-review-<?php echo (int) $provider['id']; ?>">
                        <div class="provider-review-card-top">
                            <div>
                                <span class="provider-review-label">Provider Account</span>
                                <h2><?php echo htmlspecialchars($provider['organization_name']); ?></h2>
                                <p><?php echo htmlspecialchars($provider['contact_name']); ?> | <?php echo htmlspecialchars($provider['contact_position']); ?></p>
                            </div>
                            <div class="provider-review-badges">
                                <span class="provider-review-status provider-review-status-<?php echo htmlspecialchars($provider['status']); ?>">
                                    <?php echo htmlspecialchars(ucfirst($provider['status'])); ?>
                                </span>
                                <?php if ($provider['is_verified']): ?>
                                <span class="provider-review-chip good">Verified</span>
                                <?php else: ?>
                                <span class="provider-review-chip warning">Needs manual review</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="provider-review-meta-grid">
                            <div class="provider-meta-item">
                                <span>Organization email</span>
                                <strong><?php echo htmlspecialchars($provider['organization_email']); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>Location</span>
                                <strong><?php echo htmlspecialchars($provider['location']); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>Website</span>
                                <strong><?php echo htmlspecialchars(providerReviewsValue($provider['website'])); ?></strong>
                            </div>
                            <div class="provider-meta-item">
                                <span>Registered</span>
                                <strong><?php echo htmlspecialchars($provider['created_at'] !== '' ? date('M d, Y', strtotime($provider['created_at'])) : 'Not recorded'); ?></strong>
                            </div>
                        </div>

                        <div class="provider-review-checks">
                            <span class="provider-review-check <?php echo $provider['verification_uploaded'] ? 'good' : 'warning'; ?>">
                                <i class="fas <?php echo $provider['verification_uploaded'] ? 'fa-file-circle-check' : 'fa-file-circle-xmark'; ?>"></i>
                                <?php echo $provider['verification_uploaded'] ? 'Verification file uploaded' : 'Verification file missing'; ?>
                            </span>
                            <span class="provider-review-check <?php echo $provider['website'] !== '' ? 'good' : 'neutral'; ?>">
                                <i class="fas <?php echo $provider['website'] !== '' ? 'fa-globe' : 'fa-circle-info'; ?>"></i>
                                <?php echo $provider['website'] !== '' ? 'Website provided' : 'Website not provided'; ?>
                            </span>
                            <span class="provider-review-check <?php echo $provider['needs_activation'] ? 'warning' : 'good'; ?>">
                                <i class="fas <?php echo $provider['needs_activation'] ? 'fa-user-clock' : 'fa-user-check'; ?>"></i>
                                <?php echo $provider['needs_activation'] ? 'Awaiting activation decision' : 'Already active'; ?>
                            </span>
                        </div>

                        <div class="provider-review-actions">
                            <a href="<?php echo htmlspecialchars($provider['review_url']); ?>" class="btn btn-primary">
                                <i class="fas fa-arrow-right"></i>
                                Open Review
                            </a>
                            <?php if ($provider['website'] !== '' && filter_var($provider['website'], FILTER_VALIDATE_URL)): ?>
                            <a href="<?php echo htmlspecialchars($provider['website']); ?>" class="btn btn-outline" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-up-right-from-square"></i>
                                Visit Website
                            </a>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>
    </section>

    <?php include 'layouts/admin_footer.php'; ?>
    <script src="../public/js/card-pagination.js"></script>
</body>
</html>
