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
        $absolutePath = dirname(__DIR__) . '/public/uploads/' . $candidatePath;
        if (!is_file($absolutePath)) {
            continue;
        }

        $segments = array_map('rawurlencode', explode('/', $candidatePath));
        return [
            'recorded' => true,
            'exists' => true,
            'absolute_path' => $absolutePath,
            'url' => '../public/uploads/' . implode('/', $segments),
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
$coordinatesLabel = $hasPinnedLocation ? $providerLatitude . ', ' . $providerLongitude : 'No map pin recorded';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <title>Provider Review - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css?v=2">
    <link rel="stylesheet" href="../AdminPublic/css/view-provider.css?v=<?php echo urlencode((string) @filemtime(__DIR__ . '/../AdminPublic/css/view-provider.css')); ?>">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard provider-review-page">
        <div class="container">
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

            <div class="page-header-modern provider-header-modern">
                <div class="provider-header-shell">
                    <div class="provider-header-copy">
                        <a href="provider_reviews.php" class="back-link-modern"><i class="fas fa-arrow-left"></i> Back to Provider Reviews</a>
                        <h1><?php echo htmlspecialchars($organizationName); ?></h1>
                        <p>Review organization details, contact information, and the uploaded verification file before activating this provider account.</p>

                        <div class="provider-header-chips">
                            <span class="provider-chip provider-chip-status provider-chip-<?php echo htmlspecialchars($currentStatus); ?>">
                                Account: <?php echo htmlspecialchars(ucfirst($currentStatus)); ?>
                            </span>
                            <span class="provider-chip <?php echo $isVerified ? 'provider-chip-good' : 'provider-chip-warning'; ?>">
                                <?php echo $isVerified ? 'Verified' : 'Needs Manual Review'; ?>
                            </span>
                            <span class="provider-chip provider-chip-neutral">
                                Source: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $profileSource !== '' ? $profileSource : 'provider_data'))); ?>
                            </span>
                        </div>
                    </div>

                    <div class="provider-header-actions">
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
                        <?php else: ?>
                        <div class="provider-active-note">
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
                        <a href="<?php echo htmlspecialchars($verificationMeta['url']); ?>" class="btn btn-outline provider-action-button" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-file-arrow-down"></i>
                            Open Verification File
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($profileSource === 'staff_profiles'): ?>
            <div class="provider-review-note">
                <i class="fas fa-circle-info"></i>
                <p>This provider is using the legacy <code>staff_profiles</code> storage fallback. If a verification file is missing here, move the provider to <code>provider_data</code> for full review fields.</p>
            </div>
            <?php endif; ?>

            <div class="provider-review-layout">
                <main class="provider-review-main">
                    <section class="form-card-modern provider-panel provider-summary-panel">
                        <div class="card-header">
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

                    <section class="form-card-modern provider-panel">
                        <div class="card-header">
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
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerLocationName, $hasPinnedLocation ? 'Coordinates only' : 'Not provided')); ?></div>
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
                            <div class="detail-card">
                                <span class="detail-label">Coordinates</span>
                                <div class="detail-value"><?php echo htmlspecialchars($coordinatesLabel); ?></div>
                            </div>
                            <div class="detail-card full">
                                <span class="detail-label">Organization Description</span>
                                <div class="detail-value <?php echo $hasDescription ? '' : 'muted'; ?>"><?php echo nl2br(htmlspecialchars(providerReviewValue($providerProfile['description'] ?? '', 'No organization description was provided.'))); ?></div>
                            </div>
                        </div>
                        </div>
                    </section>

                    <section class="form-card-modern provider-panel">
                        <div class="card-header">
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
                                <div class="detail-value"><?php echo htmlspecialchars(providerReviewValue($providerProfile['mobile_number'] ?? '')); ?></div>
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

                    <section class="form-card-modern provider-panel">
                        <div class="card-header">
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
                                <p>Available file</p>
                            </div>
                            <a href="<?php echo htmlspecialchars($verificationMeta['url']); ?>" class="btn btn-outline" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-up-right-from-square"></i>
                                Open Full File
                            </a>
                        </div>

                        <?php if ($isImagePreview): ?>
                        <div class="document-preview image-preview">
                            <img src="<?php echo htmlspecialchars($verificationMeta['url']); ?>" alt="Provider verification document preview">
                        </div>
                        <?php elseif ($isPdfPreview): ?>
                        <div class="document-preview pdf-preview">
                            <iframe src="<?php echo htmlspecialchars($verificationMeta['url']); ?>#toolbar=0" title="Provider verification document preview"></iframe>
                        </div>
                        <?php else: ?>
                        <div class="document-preview document-preview-fallback">
                            <i class="fas fa-file-lines"></i>
                            <p>This file type does not support inline preview here, but it can still be opened in a new tab.</p>
                        </div>
                        <?php endif; ?>
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

                <aside class="provider-review-aside">
                    <section class="form-card-modern provider-side-card">
                        <div class="card-header">
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
                        </div>
                    </section>

                    <section class="form-card-modern provider-side-card">
                        <div class="card-header">
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

                    <section class="form-card-modern provider-side-card">
                        <div class="card-header">
                            <div>
                                <h3><i class="fas fa-shield-heart"></i> Review Reminder</h3>
                                <small>Before activation</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="review-reminder">Check the verification file, organization website, and contact details before activating this provider account.</p>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </section>

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
    </script>
    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
