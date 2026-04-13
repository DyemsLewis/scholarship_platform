<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Config/helpers.php';
require_once __DIR__ . '/../app/Models/StaffAccountProfile.php';

requireRoles(['admin', 'super_admin'], '../AdminView/admin_dashboard.php', 'Only administrators can review provider legitimacy.');
if (!canAccessProviderApprovals()) {
    $_SESSION['error'] = 'You do not have permission to review provider accounts.';
    header('Location: reviews.php');
    exit();
}

function providerReviewTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
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

function providerReviewValue($value, string $fallback = 'Not provided'): string
{
    $trimmed = trim((string) ($value ?? ''));
    return $trimmed !== '' ? $trimmed : $fallback;
}

function providerReviewDate(?string $value, string $format = 'M d, Y h:i A', string $fallback = 'Not recorded'): string
{
    if (empty($value)) {
        return $fallback;
    }

    $timestamp = strtotime((string) $value);
    return $timestamp ? date($format, $timestamp) : $fallback;
}

function providerReviewEndsWith(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return false;
    }

    $needleLength = strlen($needle);
    if ($needleLength > strlen($haystack)) {
        return false;
    }

    return substr($haystack, -$needleLength) === $needle;
}

function providerReviewDomain(?string $email): string
{
    $trimmed = trim((string) $email);
    if ($trimmed === '' || strpos($trimmed, '@') === false) {
        return '';
    }

    $parts = explode('@', strtolower($trimmed));
    return trim((string) end($parts));
}

function providerReviewWebsiteHost(?string $url): string
{
    $trimmed = trim((string) $url);
    if ($trimmed === '') {
        return '';
    }

    $host = parse_url($trimmed, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }

    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }

    return $host;
}

function providerReviewUsesFreeMailDomain(string $domain): bool
{
    $freeDomains = [
        'gmail.com',
        'yahoo.com',
        'yahoo.com.ph',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'icloud.com',
        'proton.me',
        'protonmail.com',
        'aol.com',
        'gmx.com'
    ];

    return $domain !== '' && in_array($domain, $freeDomains, true);
}

function providerReviewAddress(array $profile): string
{
    $parts = array_filter([
        trim((string) ($profile['house_no'] ?? '')),
        trim((string) ($profile['street'] ?? '')),
        trim((string) ($profile['barangay'] ?? '')),
        trim((string) ($profile['city'] ?? '')),
        trim((string) ($profile['province'] ?? ''))
    ]);

    if (!empty($parts)) {
        return implode(', ', $parts);
    }

    return providerReviewValue($profile['address'] ?? '');
}

function providerReviewLocateUpload(string $candidatePath): array
{
    $projectRoot = dirname(__DIR__);
    $segments = array_map('rawurlencode', explode('/', $candidatePath));
    $publicAbsolutePath = $projectRoot . '/public/uploads/' . $candidatePath;

    if (is_file($publicAbsolutePath)) {
        return [
            'exists' => true,
            'absolute_path' => $publicAbsolutePath,
            'url' => '../public/uploads/' . implode('/', $segments)
        ];
    }

    $legacyAbsolutePath = $projectRoot . '/app/public/uploads/' . $candidatePath;
    if (!is_file($legacyAbsolutePath)) {
        return [
            'exists' => false,
            'absolute_path' => '',
            'url' => ''
        ];
    }

    $publicDirectory = dirname($publicAbsolutePath);
    if (!is_dir($publicDirectory)) {
        @mkdir($publicDirectory, 0775, true);
    }

    if (!is_file($publicAbsolutePath)) {
        @rename($legacyAbsolutePath, $publicAbsolutePath);
    }

    if (is_file($publicAbsolutePath)) {
        return [
            'exists' => true,
            'absolute_path' => $publicAbsolutePath,
            'url' => '../public/uploads/' . implode('/', $segments)
        ];
    }

    return [
        'exists' => true,
        'absolute_path' => $legacyAbsolutePath,
        'url' => '../app/public/uploads/' . implode('/', $segments)
    ];
}

function providerReviewUploadedFileMeta(?string $relativePath): array
{
    $rawPath = trim((string) $relativePath);
    if ($rawPath === '') {
        return [
            'recorded' => false,
            'exists' => false,
            'absolute_path' => '',
            'url' => '',
            'filename' => '',
            'extension' => '',
            'stored_path' => ''
        ];
    }

    $normalized = trim(str_replace('\\', '/', $rawPath), '/');
    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return [
            'recorded' => true,
            'exists' => false,
            'absolute_path' => '',
            'url' => '',
            'filename' => basename($rawPath),
            'extension' => strtolower(pathinfo($rawPath, PATHINFO_EXTENSION)),
            'stored_path' => $rawPath
        ];
    }

    $candidatePaths = [$normalized];
    if (strpos($normalized, 'public/uploads/') === 0) {
        $candidatePaths[] = substr($normalized, strlen('public/uploads/'));
    }
    if (strpos($normalized, 'uploads/') === 0) {
        $candidatePaths[] = substr($normalized, strlen('uploads/'));
    }
    $candidatePaths = array_values(array_unique(array_filter($candidatePaths, static fn($value) => trim((string) $value) !== '')));

    foreach ($candidatePaths as $candidatePath) {
        $resolvedUpload = providerReviewLocateUpload($candidatePath);
        if (empty($resolvedUpload['exists'])) {
            continue;
        }

        return [
            'recorded' => true,
            'exists' => true,
            'absolute_path' => (string) ($resolvedUpload['absolute_path'] ?? ''),
            'url' => (string) ($resolvedUpload['url'] ?? ''),
            'filename' => basename($candidatePath),
            'extension' => strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION)),
            'stored_path' => $rawPath
        ];
    }

    return [
        'recorded' => true,
        'exists' => false,
        'absolute_path' => '',
        'url' => '',
        'filename' => basename($normalized),
        'extension' => strtolower(pathinfo($normalized, PATHINFO_EXTENSION)),
        'stored_path' => $rawPath
    ];
}

$providerIdParam = $_GET['id'] ?? null;
if ($providerIdParam === null || $providerIdParam === '' || !is_numeric($providerIdParam)) {
    $_SESSION['error'] = 'Invalid provider account selected.';
    header('Location: provider_reviews.php');
    exit();
}

$providerId = (int) $providerIdParam;
requireValidEntityUrlToken('user', $providerId, $_GET['token'] ?? null, 'view', 'provider_reviews.php', 'Invalid or expired provider review link.');

$userColumns = ['id', 'username', 'email', 'status', 'role', 'created_at'];
foreach (['updated_at', 'email_verified_at', 'last_login'] as $optionalColumn) {
    if (providerReviewTableHasColumn($pdo, 'users', $optionalColumn)) {
        $userColumns[] = $optionalColumn;
    }
}

$stmt = $pdo->prepare("
    SELECT " . implode(', ', $userColumns) . "
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$providerId]);
$providerUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$providerUser || strtolower((string) ($providerUser['role'] ?? '')) !== 'provider') {
    $_SESSION['error'] = 'Provider account not found.';
    header('Location: provider_reviews.php');
    exit();
}

$profileModel = new StaffAccountProfile($pdo);
$providerProfile = $profileModel->getByUserId($providerId, 'provider', $providerUser);

if (!$providerProfile) {
    $_SESSION['error'] = 'No provider profile information is available for this account yet.';
    header('Location: provider_reviews.php');
    exit();
}

$providerDisplayName = $profileModel->buildDisplayName('provider', $providerProfile, $providerUser);
$organizationName = providerReviewValue($providerProfile['organization_name'] ?? '', 'Unnamed Organization');
$organizationType = providerReviewValue(ucwords(str_replace('_', ' ', (string) ($providerProfile['organization_type'] ?? ''))));
$organizationEmail = trim((string) ($providerProfile['organization_email'] ?? ($providerUser['email'] ?? '')));
$organizationEmailDomain = providerReviewDomain($organizationEmail);
$loginEmailDomain = providerReviewDomain((string) ($providerUser['email'] ?? ''));
$website = trim((string) ($providerProfile['website'] ?? ''));
$websiteHost = providerReviewWebsiteHost($website);
$websiteAligned = $websiteHost !== '' && $organizationEmailDomain !== '' && (
    $websiteHost === $organizationEmailDomain ||
    providerReviewEndsWith($websiteHost, '.' . $organizationEmailDomain) ||
    providerReviewEndsWith($organizationEmailDomain, '.' . $websiteHost)
);
$usesFreeMail = providerReviewUsesFreeMailDomain($organizationEmailDomain);
$addressLabel = providerReviewAddress($providerProfile);
$providerLatitude = trim((string) ($providerProfile['latitude'] ?? ''));
$providerLongitude = trim((string) ($providerProfile['longitude'] ?? ''));
$providerLocationName = trim((string) ($providerProfile['location_name'] ?? ''));
$hasPinnedLocation = $providerLatitude !== '' && $providerLongitude !== '';
$hasAddress = trim($addressLabel) !== '' && $addressLabel !== 'Not provided';
$hasDescription = trim((string) ($providerProfile['description'] ?? '')) !== '';
$verificationMeta = providerReviewUploadedFileMeta($providerProfile['verification_document'] ?? null);
$hasStoredVerificationDocument = !empty($verificationMeta['recorded']);
$hasVerificationDocument = $verificationMeta['exists'];
$isImagePreview = in_array($verificationMeta['extension'], ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$isPdfPreview = $verificationMeta['extension'] === 'pdf';
$isVerified = (int) ($providerProfile['is_verified'] ?? 0) === 1;
$profileSource = (string) ($providerProfile['profile_source'] ?? '');
$currentStatus = strtolower((string) ($providerUser['status'] ?? 'inactive'));
$providerStatusToken = buildEntityUrlToken('user', $providerId, 'update_status');
$providerRequestUpdateToken = buildEntityUrlToken('user', $providerId, 'request_update');
$providerStatusActionUrl = '../app/AdminControllers/user_process.php';
$reviewsCurrentView = 'providers';
$canManageAccounts = canAccessStaffAccounts();
$reviewStats = [
    'good' => 0,
    'neutral' => 0,
    'warning' => 0
];

$legitimacySignals = [
    [
        'icon' => 'fa-file-circle-check',
        'label' => 'Verification document',
        'status' => $hasVerificationDocument ? 'good' : 'warning',
        'message' => $hasVerificationDocument
            ? 'A verification file was uploaded and is ready to inspect.'
            : 'No verification file is on record for this provider yet.'
    ],
    [
        'icon' => 'fa-envelope-open-text',
        'label' => 'Organization email',
        'status' => $organizationEmail === ''
            ? 'warning'
            : ($usesFreeMail ? 'neutral' : 'good'),
        'message' => $organizationEmail === ''
            ? 'No organization email was provided.'
            : ($usesFreeMail
                ? 'The provider is using a public email domain, so extra manual checking is recommended.'
                : 'The provider is using an organization-specific email domain.')
    ],
    [
        'icon' => 'fa-globe',
        'label' => 'Website presence',
        'status' => $website !== '' ? 'good' : 'neutral',
        'message' => $website !== ''
            ? ($websiteAligned
                ? 'The website domain aligns with the organization email domain.'
                : 'A website is provided and can be cross-checked manually.')
            : 'No official website was provided.'
    ],
    [
        'icon' => 'fa-location-dot',
        'label' => 'Address details',
        'status' => $hasAddress ? 'good' : 'neutral',
        'message' => $hasAddress
            ? 'The provider submitted a complete address for manual validation.'
            : 'Address details are incomplete, so physical location is harder to confirm.'
    ],
    [
        'icon' => 'fa-user-check',
        'label' => 'Manual verification',
        'status' => $isVerified ? 'good' : 'warning',
        'message' => $isVerified
            ? 'This provider has already been marked as verified in the system.'
            : 'This provider still needs manual confirmation before being treated as verified.'
    ]
];

foreach ($legitimacySignals as $signal) {
    if (isset($reviewStats[$signal['status']])) {
        $reviewStats[$signal['status']]++;
    }
}

$editProviderUrl = buildEntityUrl('edit_user.php', 'user', $providerId, 'edit', ['id' => $providerId]);
$providerStatusLabel = ucfirst($currentStatus);
$providerVerificationLabel = $hasVerificationDocument
    ? 'Uploaded'
    : ($hasStoredVerificationDocument ? 'Stored path issue' : 'Missing');
$providerReviewIssues = [];
if (!$hasVerificationDocument) {
    $providerReviewIssues[] = 'verification file';
}
if ($organizationEmail === '') {
    $providerReviewIssues[] = 'organization email';
}
if (!$hasAddress) {
    $providerReviewIssues[] = 'address details';
}
$providerReviewTone = ($currentStatus === 'active' || empty($providerReviewIssues)) ? 'ready' : 'attention';
if ($currentStatus === 'active') {
    $providerReadinessLabel = 'Account already active';
    $providerReadinessNote = 'This provider already has access. Continue checking the organization profile and uploaded file for record accuracy.';
} elseif (empty($providerReviewIssues)) {
    $providerReadinessLabel = 'Ready for activation review';
    $providerReadinessNote = 'Core review details are present. Cross-check the organization profile, contact ownership, and verification file before activating the account.';
} else {
    $providerReadinessLabel = 'Needs manual review';
    $providerReadinessNote = 'Check the ' . implode(', ', $providerReviewIssues) . ' before activating this provider account.';
}
$providerSourceLabel = ucwords(str_replace('_', ' ', $profileSource !== '' ? $profileSource : 'provider_data'));
$providerReviewDescription = 'Review the provider profile, contact ownership, and verification proof before granting provider access.';
$providerContactSummary = providerReviewValue($providerDisplayName) . ' | ' . providerReviewValue($providerProfile['contact_person_position'] ?? '', 'Contact position not set');
$providerLocationSummary = trim(implode(', ', array_filter([
    trim((string) ($providerProfile['city'] ?? '')),
    trim((string) ($providerProfile['province'] ?? ''))
])));
if ($providerLocationSummary === '') {
    $providerLocationSummary = 'Location not set';
}
$providerFlashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : '';
$providerFlashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);
$adminStyleVersion = @filemtime(__DIR__ . '/../public/css/admin_style.css') ?: time();
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();
$viewApplicationStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/view-application.css') ?: time();
$viewProviderStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/view-provider.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <title>Provider Review - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css?v=<?php echo urlencode((string) $adminStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css?v=<?php echo urlencode((string) $reviewsStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/view-application.css?v=<?php echo urlencode((string) $viewApplicationStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/view-provider.css?v=<?php echo urlencode((string) $viewProviderStyleVersion); ?>">
</head>
<body class="application-review-screen provider-review-screen">
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard provider-review-page">
        <div class="container">
            <?php if ($providerFlashSuccess !== ''): ?>
            <div class="alert alert-success application-review-alert">
                <i class="fas fa-check-circle"></i>
                <p><?php echo htmlspecialchars($providerFlashSuccess); ?></p>
            </div>
            <?php endif; ?>

            <?php if ($providerFlashError !== ''): ?>
            <div class="alert alert-error application-review-alert">
                <i class="fas fa-exclamation-circle"></i>
                <p><?php echo htmlspecialchars($providerFlashError); ?></p>
            </div>
            <?php endif; ?>

            <div class="page-header provider-review-page-header">
                <div>
                    <h1><i class="fas fa-building-shield"></i> Provider Review</h1>
                    <p>Review organization legitimacy, contact ownership, and the uploaded verification file before granting provider access.</p>
                </div>
                <a href="provider_reviews.php" class="app-review-header-back provider-review-page-back">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Provider Reviews</span>
                </a>
            </div>

            <?php $reviewsCurrentView = 'providers'; include 'layouts/reviews_nav.php'; ?>

            <section class="app-review-shell provider-review-shell state-<?php echo htmlspecialchars($providerReviewTone); ?>">
                <div class="app-review-shell-inner">
                    <div class="app-review-shell-brand">
                        <div class="app-review-shell-kicker-group">
                            <span class="app-review-brand-pill">
                                <i class="fas fa-building-shield"></i>
                                Review Workspace
                            </span>
                            <span class="app-review-shell-status">
                                <i class="fas <?php echo htmlspecialchars($providerReviewTone === 'ready' ? 'fa-circle-check' : 'fa-triangle-exclamation'); ?>"></i>
                                <?php echo htmlspecialchars($providerReadinessLabel); ?>
                            </span>
                        </div>

                        <div class="app-review-avatar-stage">
                            <span class="app-review-avatar-mark provider-review-avatar-mark">
                                <?php echo htmlspecialchars(getUserInitials($organizationName)); ?>
                            </span>
                        </div>
                    </div>

                    <div class="app-review-shell-content">
                        <div class="app-review-shell-head">
                            <div class="app-review-shell-title-wrap">
                                <h1><?php echo htmlspecialchars($organizationName); ?></h1>
                                <p><?php echo htmlspecialchars($providerContactSummary); ?></p>
                            </div>
                            <div class="app-review-shell-head-actions">
                                <span class="provider-review-source-chip">
                                    <i class="fas fa-database"></i>
                                    <?php echo htmlspecialchars($providerSourceLabel); ?>
                                </span>
                            </div>
                        </div>

                        <div class="app-review-provider-summary">
                            <div class="app-review-provider-line">
                                <span class="app-review-provider-label">Organization Type</span>
                                <strong class="app-review-provider-inline-name"><?php echo htmlspecialchars($organizationType); ?></strong>
                            </div>
                            <p class="app-review-provider-inline-note"><?php echo htmlspecialchars($providerReviewDescription); ?></p>
                        </div>

                        <div class="app-review-summary-strip provider-review-summary-strip">
                            <article class="app-review-summary-tile">
                                <span>Account Status</span>
                                <strong><?php echo htmlspecialchars($providerStatusLabel); ?></strong>
                            </article>
                            <article class="app-review-summary-tile">
                                <span>Manual Verification</span>
                                <strong><?php echo $isVerified ? 'Verified' : 'Pending'; ?></strong>
                            </article>
                            <article class="app-review-summary-tile">
                                <span>Verification File</span>
                                <strong><?php echo htmlspecialchars($providerVerificationLabel); ?></strong>
                            </article>
                        </div>

                        <div class="app-review-next-step-banner is-<?php echo htmlspecialchars($providerReviewTone === 'ready' ? 'success' : 'warning'); ?>">
                            <i class="fas <?php echo htmlspecialchars($providerReviewTone === 'ready' ? 'fa-circle-check' : 'fa-shield-halved'); ?>"></i>
                            <span><?php echo htmlspecialchars($providerReadinessNote); ?></span>
                        </div>

                        <div class="provider-review-action-row">
                            <?php if ($currentStatus !== 'active'): ?>
                            <form method="POST" action="<?php echo htmlspecialchars($providerStatusActionUrl); ?>" class="provider-activate-form" data-provider-activate-form="true">
                                <input type="hidden" name="action" value="update_status">
                                <?php echo csrfInputField('admin_account_management'); ?>
                                <input type="hidden" name="user_id" value="<?php echo $providerId; ?>">
                                <input type="hidden" name="status" value="active">
                                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($providerStatusToken); ?>">
                                <input type="hidden" name="redirect_target" value="provider_review">
                                <button type="submit" class="btn btn-primary provider-activate-button">
                                    <i class="fas fa-circle-check"></i>
                                    Activate Provider
                                </button>
                            </form>
                            <form method="POST" action="<?php echo htmlspecialchars($providerStatusActionUrl); ?>" class="provider-request-update-form" data-provider-request-form="true">
                                <input type="hidden" name="action" value="request_provider_update">
                                <?php echo csrfInputField('admin_account_management'); ?>
                                <input type="hidden" name="user_id" value="<?php echo $providerId; ?>">
                                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($providerRequestUpdateToken); ?>">
                                <input type="hidden" name="redirect_target" value="provider_review">
                                <input type="hidden" name="request_note" value="">
                                <input type="hidden" name="request_verification_file" value="0">
                                <button type="button" class="btn btn-outline provider-action-button" data-provider-request-trigger="true">
                                    <i class="fas fa-envelope-open-text"></i>
                                    Request Update
                                </button>
                            </form>
                            <?php else: ?>
                            <div class="provider-action-status">
                                <i class="fas fa-circle-check"></i>
                                Provider account is active
                            </div>
                            <?php endif; ?>
                            <?php if ($canManageAccounts): ?>
                            <a href="<?php echo htmlspecialchars($editProviderUrl); ?>" class="btn btn-outline provider-action-button">
                                <i class="fas fa-pen-to-square"></i>
                                Edit Account
                            </a>
                            <?php endif; ?>
                            <?php if ($website !== ''): ?>
                            <a href="<?php echo htmlspecialchars($website); ?>" class="btn btn-outline provider-action-button" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-up-right-from-square"></i>
                                Visit Website
                            </a>
                            <?php endif; ?>
                            <?php if ($hasVerificationDocument): ?>
                            <button type="button" class="btn btn-outline provider-action-button provider-open-file-modal" data-provider-file-modal-open="true">
                                <i class="fas fa-file-arrow-down"></i>
                                View Verification File
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </section>

            <?php if ($profileSource === 'staff_profiles'): ?>
            <div class="provider-review-note">
                <i class="fas fa-circle-info"></i>
                <p>This provider is using the legacy <code>staff_profiles</code> storage fallback. If a verification file is missing here, move the provider to <code>provider_data</code> for full review fields.</p>
            </div>
            <?php endif; ?>

            <div class="provider-review-layout review-layout">
                <main class="provider-review-main review-main">
                    <section id="providerSnapshot" class="form-card-modern provider-panel provider-summary-panel provider-panel-accent-summary">
                        <div class="card-header review-panel-header">
                            <div>
                                <h3><i class="fas fa-list-check"></i> Review Snapshot</h3>
                                <small>Provider legitimacy signals</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="provider-summary-head">
                                <div class="provider-avatar"><?php echo htmlspecialchars(getUserInitials($organizationName)); ?></div>
                                <div>
                                    <h2><?php echo htmlspecialchars($organizationName); ?></h2>
                                    <p><?php echo htmlspecialchars(providerReviewValue($providerDisplayName)); ?> | <?php echo htmlspecialchars(providerReviewValue($providerProfile['contact_person_position'] ?? '', 'Contact position not set')); ?></p>
                                </div>
                            </div>

                            <div class="provider-stat-grid">
                                <article class="provider-stat-card">
                                    <span class="provider-stat-value"><?php echo number_format($reviewStats['good']); ?></span>
                                    <span class="provider-stat-label">Strong signals</span>
                                </article>
                                <article class="provider-stat-card">
                                    <span class="provider-stat-value"><?php echo number_format($reviewStats['neutral']); ?></span>
                                    <span class="provider-stat-label">Needs cross-checking</span>
                                </article>
                                <article class="provider-stat-card">
                                    <span class="provider-stat-value"><?php echo number_format($reviewStats['warning']); ?></span>
                                    <span class="provider-stat-label">Missing or risky</span>
                                </article>
                            </div>

                            <div class="signal-grid">
                                <?php foreach ($legitimacySignals as $signal): ?>
                                <article class="signal-card signal-card-<?php echo htmlspecialchars($signal['status']); ?>">
                                    <div class="signal-icon"><i class="fas <?php echo htmlspecialchars($signal['icon']); ?>"></i></div>
                                    <div class="signal-copy">
                                        <h3><?php echo htmlspecialchars($signal['label']); ?></h3>
                                        <p><?php echo htmlspecialchars($signal['message']); ?></p>
                                    </div>
                                </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                    <section id="providerOrganization" class="form-card-modern provider-panel provider-panel-accent-profile">
                        <div class="card-header review-panel-header">
                            <div>
                                <h3><i class="fas fa-building"></i> Organization Details</h3>
                                <small>Organization information</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="provider-detail-grid">
                            <div class="detail-card">
                                <span class="detail-label">Organization Name</span>
                                <div class="detail-value"><?php echo htmlspecialchars($organizationName); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Organization Type</span>
                                <div class="detail-value"><?php echo htmlspecialchars($organizationType); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Organization Email</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($organizationEmail)); ?></div>
                                <?php if ($organizationEmailDomain !== ''): ?>
                                <div class="detail-meta"><?php echo htmlspecialchars($organizationEmailDomain); ?><?php echo $usesFreeMail ? ' | Public email domain' : ' | Organization domain'; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Website</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($website)); ?></div>
                                <?php if ($websiteHost !== ''): ?>
                                <div class="detail-meta"><?php echo htmlspecialchars($websiteHost); ?><?php echo $websiteAligned ? ' | Matches email domain' : ' | Review manually'; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="detail-card full">
                                <span class="detail-label">Address</span>
                                <div class="detail-value"><?php echo htmlspecialchars($addressLabel); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Map Pin</span>
                                <div class="detail-value"><?php echo htmlspecialchars($hasPinnedLocation ? 'Pin selected' : 'No pin recorded'); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Pinned Location</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerLocationName, $hasPinnedLocation ? 'Map pin recorded' : 'Not provided')); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">City</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerProfile['city'] ?? '')); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Province</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerProfile['province'] ?? '')); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">ZIP Code</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerProfile['zip_code'] ?? '')); ?></div>
                            </div>
                            <div class="detail-card full">
                                <span class="detail-label">Organization Description</span>
                                <div class="detail-value <?php echo $hasDescription ? '' : 'muted'; ?>"><?php echo nl2br(htmlspecialchars(providerReviewValue($providerProfile['description'] ?? '', 'No organization description was provided.'))); ?></div>
                            </div>
                        </div>
                        </div>
                    </section>

                    <section id="providerContact" class="form-card-modern provider-panel provider-panel-accent-program">
                        <div class="card-header review-panel-header">
                            <div>
                                <h3><i class="fas fa-address-card"></i> Contact and Account Information</h3>
                                <small>Contact and login details</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="provider-detail-grid">
                            <div class="detail-card">
                                <span class="detail-label">Contact Person</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerDisplayName)); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Position</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerProfile['contact_person_position'] ?? '')); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Phone Number</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerProfile['phone_number'] ?? '')); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Mobile Number</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue(formatPhilippineMobileNumber($providerProfile['mobile_number'] ?? ''))); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Login Username</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerUser['username'] ?? '')); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Login Email</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerUser['email'] ?? '')); ?></div>
                                <?php if ($loginEmailDomain !== ''): ?>
                                <div class="detail-meta"><?php echo htmlspecialchars($loginEmailDomain); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Email Verified</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewDate($providerUser['email_verified_at'] ?? null, 'M d, Y h:i A', 'Not verified yet')); ?></div>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Last Login</span>
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewDate($providerUser['last_login'] ?? null, 'M d, Y h:i A', 'No login yet')); ?></div>
                            </div>
                        </div>
                        </div>
                    </section>

                    <section id="providerVerification" class="form-card-modern provider-panel provider-panel-accent-timeline">
                        <div class="card-header review-panel-header">
                            <div>
                                <h3><i class="fas fa-file-lines"></i> Verification File</h3>
                                <small>Uploaded file</small>
                            </div>
                        </div>
                        <div class="card-body">
                        <?php if ($hasVerificationDocument): ?>
                        <div class="document-summary-bar">
                            <div>
                                <strong><?php echo htmlspecialchars($verificationMeta['filename']); ?></strong>
                                <p>Available file. Use the button to inspect the full verification document in a modal.</p>
                            </div>
                            <button type="button" class="btn btn-outline provider-open-file-modal" data-provider-file-modal-open="true">
                                <i class="fas fa-up-right-from-square"></i>
                                View Full File
                            </button>
                        </div>
                        <?php elseif ($hasStoredVerificationDocument): ?>
                        <div class="document-preview document-preview-fallback">
                            <i class="fas fa-file-circle-exclamation"></i>
                            <p>A verification file is recorded for this provider, but it could not be loaded from storage. Check the saved upload path or re-upload the file.</p>
                        </div>
                        <?php else: ?>
                        <div class="document-preview document-preview-fallback">
                            <i class="fas fa-file-circle-xmark"></i>
                            <p>No verification document has been uploaded for this provider account yet.</p>
                        </div>
                        <?php endif; ?>
                        </div>
                    </section>
                </main>

                <aside class="provider-review-aside review-sidebar sidebar-stack">
                    <section class="form-card-modern provider-side-card provider-panel-accent-summary">
                        <div class="card-header review-panel-header">
                            <div>
                                <h3><i class="fas fa-clipboard-check"></i> Review Summary</h3>
                                <small>Activation checklist</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <ul class="side-list">
                            <li>
                                <span>Account Status</span>
                                <strong><?php echo htmlspecialchars(ucfirst($currentStatus)); ?></strong>
                            </li>
                            <li>
                                <span>Manual Verification</span>
                                <strong><?php echo $isVerified ? 'Verified' : 'Pending'; ?></strong>
                            </li>
                            <li>
                                <span>Verification File</span>
                                <strong><?php echo $hasVerificationDocument ? 'Uploaded' : 'Missing'; ?></strong>
                            </li>
                            <li>
                                <span>Website</span>
                                <strong><?php echo $website !== '' ? 'Provided' : 'Missing'; ?></strong>
                            </li>
                            <li>
                                <span>Address</span>
                                <strong><?php echo $hasAddress ? 'Provided' : 'Incomplete'; ?></strong>
                            </li>
                            </ul>
                            <div class="review-note info provider-inline-note">
                                Profile source: <?php echo htmlspecialchars($providerSourceLabel); ?>
                            </div>
                        </div>
                    </section>

                    <section class="form-card-modern provider-side-card provider-panel-accent-timeline">
                        <div class="card-header review-panel-header">
                            <div>
                                <h3><i class="fas fa-clock-rotate-left"></i> Timeline</h3>
                                <small>Account activity</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <ul class="side-list">
                            <li>
                                <span>Account Created</span>
                                <strong><?php echo htmlspecialchars(providerReviewDate($providerUser['created_at'] ?? null)); ?></strong>
                            </li>
                            <li>
                                <span>Last Updated</span>
                                <strong><?php echo htmlspecialchars(providerReviewDate($providerUser['updated_at'] ?? null, 'M d, Y h:i A', 'Not recorded')); ?></strong>
                            </li>
                            <li>
                                <span>Verified At</span>
                                <strong><?php echo htmlspecialchars(providerReviewDate($providerProfile['verified_at'] ?? null, 'M d, Y h:i A', 'Not reviewed yet')); ?></strong>
                            </li>
                            </ul>
                        </div>
                    </section>

                    <section class="form-card-modern provider-side-card provider-panel-accent-program">
                        <div class="card-header review-panel-header">
                            <div>
                                <h3><i class="fas fa-shield-heart"></i> Review Reminder</h3>
                                <small>Before activation</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="review-reminder">Check the verification file, organization website, and contact details before activating this provider account.</p>
                            <div class="review-note <?php echo htmlspecialchars($providerReviewTone === 'ready' ? 'ready' : 'warning'); ?> provider-inline-note">
                                <?php echo htmlspecialchars($providerReadinessNote); ?>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </section>

    <?php if ($hasVerificationDocument): ?>
    <div class="provider-file-modal" id="providerFileModal" aria-hidden="true">
        <div class="provider-file-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="providerFileModalTitle">
            <div class="provider-file-modal-header">
                <div>
                    <h3 id="providerFileModalTitle">Verification File</h3>
                    <p><?php echo htmlspecialchars($verificationMeta['filename']); ?></p>
                </div>
                <button type="button" class="provider-file-modal-close" id="providerFileModalClose" aria-label="Close verification file preview">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="provider-file-modal-body">
                <?php if ($isImagePreview): ?>
                <div class="provider-file-modal-preview-wrap" id="providerFileModalPreviewWrap">
                    <img src="<?php echo htmlspecialchars($verificationMeta['url']); ?>" id="providerFileModalImage" alt="Provider verification file full preview">
                </div>
                <?php elseif ($isPdfPreview): ?>
                <div class="provider-file-modal-preview-wrap" id="providerFileModalPreviewWrap">
                    <iframe src="<?php echo htmlspecialchars($verificationMeta['url']); ?>#toolbar=1&navpanes=0" id="providerFileModalFrame" title="Provider verification file full preview"></iframe>
                </div>
                <?php else: ?>
                <div class="provider-file-modal-preview provider-file-modal-preview-fallback">
                    <i class="fas fa-file-lines"></i>
                    <p>This file type cannot be previewed in the modal.</p>
                    <a href="<?php echo htmlspecialchars($verificationMeta['url']); ?>" class="btn btn-outline" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-up-right-from-square"></i>
                        Open in New Tab
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <div class="provider-file-modal-footer">
                <div class="provider-file-modal-zoom-controls" aria-label="Verification file zoom controls">
                    <button type="button" class="btn btn-outline provider-file-modal-zoom-btn" id="providerFileModalZoomOut" title="Zoom out">
                        <i class="fas fa-magnifying-glass-minus"></i>
                    </button>
                    <span class="provider-file-modal-zoom-level" id="providerFileModalZoomLevel">100%</span>
                    <button type="button" class="btn btn-outline provider-file-modal-zoom-btn" id="providerFileModalZoomIn" title="Zoom in">
                        <i class="fas fa-magnifying-glass-plus"></i>
                    </button>
                    <button type="button" class="btn btn-outline provider-file-modal-reset-btn" id="providerFileModalZoomReset">Reset</button>
                </div>
                <button type="button" class="btn btn-outline" id="providerFileModalFooterClose">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const providerActivateForm = document.querySelector('[data-provider-activate-form="true"]');
        if (providerActivateForm) {
            providerActivateForm.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (window.Swal && typeof window.Swal.fire === 'function') {
                    const result = await window.Swal.fire({
                        icon: 'question',
                        title: 'Activate Provider?',
                        text: 'This will activate the provider account and allow access to provider features.',
                        showCancelButton: true,
                        confirmButtonColor: '#1d4ed8',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Activate'
                    });

                    if (result.isConfirmed) {
                        providerActivateForm.submit();
                    }
                    return;
                }

                if (window.confirm('Activate this provider account?')) {
                    providerActivateForm.submit();
                }
            });
        }

        const providerRequestForm = document.querySelector('[data-provider-request-form="true"]');
        const providerRequestTrigger = document.querySelector('[data-provider-request-trigger="true"]');
        if (providerRequestForm && providerRequestTrigger) {
            providerRequestTrigger.addEventListener('click', async () => {
                const noteInput = providerRequestForm.querySelector('input[name="request_note"]');
                const fileInput = providerRequestForm.querySelector('input[name="request_verification_file"]');

                if (window.Swal && typeof window.Swal.fire === 'function') {
                    const result = await window.Swal.fire({
                        title: 'Request Provider Update',
                        html: `
                            <div style="text-align:left;">
                                <p style="margin:0 0 12px; color:#475569;">Send an email note so the provider knows what must be submitted before activation.</p>
                                <textarea id="providerRequestNote" class="swal2-textarea" placeholder="Example: Please reply with your official organization website and a clearer business permit." style="display:block; width:100%; min-height:140px; margin:0; box-sizing:border-box;"></textarea>
                                <label style="display:flex; align-items:flex-start; gap:10px; margin-top:12px; font-size:0.95rem; color:#334155;">
                                    <input type="checkbox" id="providerRequestFile" style="margin-top:3px;">
                                    <span>Ask the provider to send another verification file by email.</span>
                                </label>
                            </div>
                        `,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#1d4ed8',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Send Request',
                        focusConfirm: false,
                        preConfirm: () => {
                            const noteValue = (document.getElementById('providerRequestNote')?.value || '').trim();
                            const requestFileValue = !!document.getElementById('providerRequestFile')?.checked;
                            if (noteValue === '' && !requestFileValue) {
                                window.Swal.showValidationMessage('Add a note or ask for a replacement verification file.');
                                return false;
                            }
                            return {
                                note: noteValue,
                                requestFile: requestFileValue
                            };
                        }
                    });

                    if (!result.isConfirmed || !result.value) {
                        return;
                    }

                    if (noteInput) {
                        noteInput.value = result.value.note || '';
                    }
                    if (fileInput) {
                        fileInput.value = result.value.requestFile ? '1' : '0';
                    }
                    providerRequestForm.submit();
                    return;
                }

                const fallbackNote = window.prompt('Enter the update note for this provider:', '');
                if (fallbackNote === null) {
                    return;
                }
                const trimmedNote = fallbackNote.trim();
                const requestFile = window.confirm('Should the provider send a replacement verification file by email?');
                if (trimmedNote === '' && !requestFile) {
                    window.alert('Add a note or request a replacement verification file before sending the update notice.');
                    return;
                }

                if (noteInput) {
                    noteInput.value = trimmedNote;
                }
                if (fileInput) {
                    fileInput.value = requestFile ? '1' : '0';
                }
                providerRequestForm.submit();
            });
        }

        const providerFileModal = document.getElementById('providerFileModal');
        if (providerFileModal) {
            const openButtons = document.querySelectorAll('[data-provider-file-modal-open="true"]');
            const closeButtons = [
                document.getElementById('providerFileModalClose'),
                document.getElementById('providerFileModalFooterClose')
            ].filter(Boolean);
            const providerFileModalPreviewWrap = document.getElementById('providerFileModalPreviewWrap');
            const providerFileModalImage = document.getElementById('providerFileModalImage');
            const providerFileModalFrame = document.getElementById('providerFileModalFrame');
            const providerFileModalZoomOut = document.getElementById('providerFileModalZoomOut');
            const providerFileModalZoomIn = document.getElementById('providerFileModalZoomIn');
            const providerFileModalZoomReset = document.getElementById('providerFileModalZoomReset');
            const providerFileModalZoomLevel = document.getElementById('providerFileModalZoomLevel');
            const providerFilePreviewType = <?php echo json_encode($isImagePreview ? 'image' : ($isPdfPreview ? 'pdf' : 'document')); ?>;
            let providerFilePreviewZoom = 1;

            const updateProviderFileZoomControls = () => {
                const isZoomable = providerFilePreviewType === 'image' || providerFilePreviewType === 'pdf';

                if (providerFileModalZoomOut) {
                    providerFileModalZoomOut.disabled = !isZoomable;
                }
                if (providerFileModalZoomIn) {
                    providerFileModalZoomIn.disabled = !isZoomable;
                }
                if (providerFileModalZoomReset) {
                    providerFileModalZoomReset.disabled = !isZoomable || Math.abs(providerFilePreviewZoom - 1) < 0.01;
                }
                if (providerFileModalZoomLevel) {
                    providerFileModalZoomLevel.textContent = isZoomable
                        ? `${Math.round(providerFilePreviewZoom * 100)}%`
                        : 'N/A';
                }
            };

            const applyProviderFileZoom = () => {
                if (providerFileModalPreviewWrap) {
                    providerFileModalPreviewWrap.classList.toggle('is-zoomed', providerFilePreviewZoom > 1.01);
                }

                if (providerFilePreviewType === 'image' && providerFileModalImage) {
                    providerFileModalImage.style.transform = `scale(${providerFilePreviewZoom})`;
                    providerFileModalImage.style.transformOrigin = 'top left';
                    return;
                }

                if (providerFilePreviewType === 'pdf' && providerFileModalFrame) {
                    providerFileModalFrame.style.zoom = String(providerFilePreviewZoom);
                    if (providerFileModalFrame.style.zoom !== String(providerFilePreviewZoom)) {
                        providerFileModalFrame.style.transform = `scale(${providerFilePreviewZoom})`;
                        providerFileModalFrame.style.transformOrigin = 'top left';
                    } else {
                        providerFileModalFrame.style.transform = '';
                        providerFileModalFrame.style.transformOrigin = '';
                    }
                }
            };

            const resetProviderFileZoom = () => {
                providerFilePreviewZoom = 1;
                applyProviderFileZoom();
                if (providerFileModalPreviewWrap) {
                    providerFileModalPreviewWrap.scrollTop = 0;
                    providerFileModalPreviewWrap.scrollLeft = 0;
                }
                updateProviderFileZoomControls();
            };

            const changeProviderFileZoom = (delta) => {
                if (providerFilePreviewType !== 'image' && providerFilePreviewType !== 'pdf') {
                    return;
                }

                providerFilePreviewZoom = Math.max(0.5, Math.min(3, Number((providerFilePreviewZoom + delta).toFixed(2))));
                applyProviderFileZoom();
                updateProviderFileZoomControls();
            };

            const openProviderFileModal = () => {
                resetProviderFileZoom();
                providerFileModal.classList.add('active');
                providerFileModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('provider-file-modal-open');
            };

            const closeProviderFileModal = () => {
                providerFileModal.classList.remove('active');
                providerFileModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('provider-file-modal-open');
                resetProviderFileZoom();
            };

            openButtons.forEach((button) => {
                button.addEventListener('click', openProviderFileModal);
            });

            closeButtons.forEach((button) => {
                button.addEventListener('click', closeProviderFileModal);
            });

            providerFileModal.addEventListener('click', (event) => {
                if (event.target === providerFileModal) {
                    closeProviderFileModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && providerFileModal.classList.contains('active')) {
                    closeProviderFileModal();
                }
            });

            if (providerFileModalZoomOut) {
                providerFileModalZoomOut.addEventListener('click', () => changeProviderFileZoom(-0.25));
            }

            if (providerFileModalZoomIn) {
                providerFileModalZoomIn.addEventListener('click', () => changeProviderFileZoom(0.25));
            }

            if (providerFileModalZoomReset) {
                providerFileModalZoomReset.addEventListener('click', resetProviderFileZoom);
            }

            updateProviderFileZoomControls();
        }
    </script>
    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
