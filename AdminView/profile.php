<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/admin_account_options.php';
require_once __DIR__ . '/../app/Config/password_policy.php';
require_once __DIR__ . '/../app/Models/StaffAccountProfile.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have access to this profile page.');

function formatStaffRoleLabel(string $role): string {
    $normalized = normalizeUserRole($role);
    if ($normalized === 'super_admin') return 'Super Admin';
    if ($normalized === 'provider') return 'Provider';
    return ucfirst($normalized);
}

function formatOrganizationTypeLabel(?string $value): string {
    $map = [
        'government_agency' => 'Government Agency',
        'local_government_unit' => 'Local Government Unit',
        'state_university' => 'State University / College',
        'private_school' => 'Private School / University',
        'foundation' => 'Foundation',
        'nonprofit' => 'Nonprofit / NGO',
        'corporate' => 'Corporate Sponsor',
        'other' => 'Other'
    ];
    $normalized = strtolower(trim((string) $value));
    return $map[$normalized] ?? ($normalized !== '' ? ucwords(str_replace('_', ' ', $normalized)) : 'Not set');
}

function getStaffInitials(string $value): string {
    $parts = preg_split('/\s+/', trim($value));
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials !== '' ? $initials : 'S';
}

function displayValue($value, string $fallback = 'Not set'): string {
    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : $fallback;
}

function normalizeMiddleInitialInputValue($value): string {
    $lettersOnly = preg_replace('/[^A-Za-z]/', '', trim((string) $value)) ?? '';
    return strtoupper(substr($lettersOnly, 0, 1));
}

$currentRole = getCurrentSessionRole();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$isProviderRole = ($currentRole === 'provider');
$isAdminRole = in_array($currentRole, ['admin', 'super_admin'], true);

$stmt = $pdo->prepare("SELECT id, username, email, role, access_level, status, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    $_SESSION['error'] = 'Your account could not be loaded.';
    header('Location: admin_dashboard.php');
    exit();
}

$staffProfileModel = new StaffAccountProfile($pdo);
$storageStatus = $staffProfileModel->getStorageStatus((string) ($account['role'] ?? $currentRole));
$storageReady = (bool) ($storageStatus['ready'] ?? false);
$usingLegacy = !empty($storageStatus['legacy']);
$profile = $staffProfileModel->getByUserId($userId, (string) ($account['role'] ?? $currentRole), $account) ?? [];
$displayName = $staffProfileModel->buildDisplayName((string) ($account['role'] ?? $currentRole), $profile, $account);
$roleLabel = formatStaffRoleLabel((string) ($account['role'] ?? $currentRole));
$memberSince = !empty($account['created_at']) ? date('M Y', strtotime((string) $account['created_at'])) : 'N/A';
$statusValue = strtolower(trim((string) ($account['status'] ?? 'active')));
$statusClassMap = [
    'active' => 'is-active',
    'pending' => 'is-pending',
    'suspended' => 'is-suspended',
    'inactive' => 'is-inactive'
];
$statusIconMap = [
    'active' => 'fa-circle-check',
    'pending' => 'fa-hourglass-half',
    'suspended' => 'fa-circle-pause',
    'inactive' => 'fa-circle-minus'
];
$statusClass = $statusClassMap[$statusValue] ?? 'is-inactive';
$statusIcon = $statusIconMap[$statusValue] ?? 'fa-circle-minus';
$statusLabel = ucwords(str_replace('_', ' ', ($statusValue !== '' ? $statusValue : 'active')));
$storageMessage = $staffProfileModel->getStorageMessage((string) ($account['role'] ?? $currentRole));
$organizationTypeOptions = [
    'government_agency' => 'Government Agency',
    'local_government_unit' => 'Local Government Unit',
    'state_university' => 'State University / College',
    'private_school' => 'Private School / University',
    'foundation' => 'Foundation',
    'nonprofit' => 'Nonprofit / NGO',
    'corporate' => 'Corporate Sponsor',
    'other' => 'Other'
];
$isSuperAdminAccount = $currentRole === 'super_admin' || !empty($profile['is_super_admin']);
$profileCssVersion = @filemtime(__DIR__ . '/../AdminPublic/css/staff-profile.css') ?: time();
$profileJsVersion = @filemtime(__DIR__ . '/../AdminPublic/js/staff-profile.js') ?: time();
$workspaceLabel = $isProviderRole
    ? displayValue($profile['organization_name'] ?? '', 'Organization not set')
    : displayValue($profile['department'] ?? '', 'Department not set');
$positionLabel = $isProviderRole
    ? displayValue($profile['contact_person_position'] ?? '', 'Contact position not set')
    : displayValue($profile['position'] ?? '', 'Position not set');
$providerContactName = trim(
    displayValue($profile['contact_person_firstname'] ?? '', '') . ' ' .
    displayValue($profile['contact_person_lastname'] ?? '', '')
);
$providerContactName = $providerContactName !== '' ? $providerContactName : 'Not set';
$providerLocationSummary = implode(', ', array_filter([
    trim((string) ($profile['city'] ?? '')),
    trim((string) ($profile['province'] ?? ''))
]));
$providerLocationSummary = $providerLocationSummary !== '' ? $providerLocationSummary : 'Location not set';
$scopeLabel = $isProviderRole
    ? (!empty($profile['is_verified']) ? 'Verified Organization' : 'Pending Verification')
    : ($isSuperAdminAccount ? 'Full System' : 'Admin Workspace');
$resolvedAdminPositionProfile = !$isProviderRole
    ? resolveAdminPositionProfile(
        (string) ($account['role'] ?? $currentRole),
        (string) ($profile['position'] ?? $profile['position_title'] ?? ''),
        isset($profile['access_level']) ? (int) $profile['access_level'] : getRoleAccessLevel((string) ($account['role'] ?? $currentRole))
    )
    : null;
$responsibilityItems = [
    'Accounts' => $isProviderRole ? !empty($profile['can_manage_users']) : !empty($resolvedAdminPositionProfile['can_manage_users']),
    'Scholarships' => $isProviderRole ? !empty($profile['can_manage_scholarships']) : !empty($resolvedAdminPositionProfile['can_manage_scholarships']),
    'Reviews' => $isProviderRole ? !empty($profile['can_review_documents']) : !empty($resolvedAdminPositionProfile['can_review_documents']),
    'Reports' => $isProviderRole ? !empty($profile['can_view_reports']) : !empty($resolvedAdminPositionProfile['can_view_reports'])
];
$enabledResponsibilities = array_values(array_keys(array_filter($responsibilityItems)));
$enabledResponsibilityCount = count($enabledResponsibilities);

$_SESSION['user_display_name'] = $displayName;
$_SESSION['admin_name'] = $displayName;
$_SESSION['admin_username'] = (string) ($account['username'] ?? ($isProviderRole ? 'Provider' : 'Admin'));
$_SESSION['admin_email'] = (string) ($account['email'] ?? '');
$_SESSION['admin_role'] = normalizeUserRole($account['role'] ?? $currentRole);
syncStaffPermissionSessionMeta((string) ($account['role'] ?? $currentRole), $profile);
$profilePageTitle = $isProviderRole ? 'Provider Profile' : 'Admin Profile';
$profilePageDescription = $isProviderRole
    ? 'Provider profile, organization record, and verification details.'
    : 'Administrative identity, responsibilities, and account settings.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($profilePageTitle); ?> | Scholarship Finder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../AdminPublic/css/staff-profile.css?v=<?php echo urlencode((string) $profileCssVersion); ?>">
</head>
<body>
<?php include 'layouts/admin_header.php'; ?>
<section class="admin-dashboard staff-profile-page">
    <div class="container">
        <div class="staff-page-hero">
            <div class="profile-header-copy">
                <h2><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($profilePageTitle); ?></h2>
                <p><?php echo htmlspecialchars($profilePageDescription); ?></p>
            </div>
        </div>

        <?php if (!$storageReady): ?>
        <div class="staff-profile-alert"><i class="fas fa-triangle-exclamation"></i><div><strong>Profile storage is not ready.</strong><br><?php echo htmlspecialchars($storageMessage); ?></div></div>
        <?php elseif ($usingLegacy): ?>
        <div class="staff-profile-alert is-info"><i class="fas fa-circle-info"></i><div><strong>Legacy fallback is active.</strong><br><?php echo htmlspecialchars($storageMessage); ?></div></div>
        <?php endif; ?>

        <div class="staff-profile-card">
            <div class="staff-profile-tab-bar">
                <button type="button" class="staff-profile-tab active" id="tabView" data-profile-tab="view" aria-controls="profileViewContent" aria-selected="true"><i class="fas fa-user"></i> View Profile</button>
                <button type="button" class="staff-profile-tab" id="tabEdit" data-profile-tab="edit" aria-controls="profileEditContent" aria-selected="false"><i class="fas fa-pen-to-square"></i> Edit Profile</button>
                <button type="button" class="staff-profile-tab" id="tabPassword" data-profile-tab="password" aria-controls="profilePasswordContent" aria-selected="false"><i class="fas fa-lock"></i> Security</button>
            </div>
            <div class="staff-profile-tab-body">
                <div id="profileViewContent" data-profile-panel="view">
                    <div class="staff-view-layout <?php echo !$isProviderRole ? 'is-admin-balance' : ''; ?>">
                        <aside class="staff-summary-column <?php echo !$isProviderRole ? 'is-single-card' : ''; ?>">
                            <div class="staff-summary-card">
                                <div class="staff-summary-banner <?php echo $isProviderRole ? 'is-provider' : 'is-admin'; ?>">
                                    <div class="staff-profile-avatar"><?php echo htmlspecialchars(getStaffInitials($displayName)); ?></div>
                                    <div class="staff-summary-head">
                                        <span class="staff-role-pill"><i class="fas <?php echo $isProviderRole ? 'fa-building' : 'fa-user-shield'; ?>"></i><?php echo htmlspecialchars($roleLabel); ?></span>
                                        <h3 id="staffDisplayName"><?php echo htmlspecialchars($displayName); ?></h3>
                                        <p><?php echo htmlspecialchars($positionLabel); ?></p>
                                    </div>
                                </div>
                                <div class="staff-summary-body">
                                    <div class="staff-summary-list">
                                        <?php if ($isProviderRole): ?>
                                            <div class="staff-summary-item">
                                                <span class="staff-summary-label">Workspace</span>
                                                <strong><?php echo htmlspecialchars($workspaceLabel); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <div class="staff-summary-item">
                                            <span class="staff-summary-label">Email</span>
                                            <strong id="staffEmail"><?php echo htmlspecialchars((string) ($account['email'] ?? '')); ?></strong>
                                        </div>
                                        <div class="staff-summary-item">
                                            <span class="staff-summary-label">Username</span>
                                            <strong id="staffUsername"><?php echo htmlspecialchars((string) ($account['username'] ?? '')); ?></strong>
                                        </div>
                                        <div class="staff-summary-item">
                                            <span class="staff-summary-label">Status</span>
                                            <strong><span class="staff-status-chip <?php echo htmlspecialchars($statusClass); ?>"><i class="fas <?php echo htmlspecialchars($statusIcon); ?>"></i><?php echo htmlspecialchars($statusLabel); ?></span></strong>
                                        </div>
                                    </div>
                                    <div class="staff-summary-actions">
                                        <button type="button" class="btn btn-primary" data-profile-tab="edit"><i class="fas fa-pen-to-square"></i> Edit Profile</button>
                                        <button type="button" class="btn btn-outline" data-profile-tab="password"><i class="fas fa-lock"></i> Security</button>
                                    </div>
                                </div>
                            </div>

                            <?php if ($isProviderRole): ?>
                                <div class="staff-side-card">
                                    <div class="staff-side-card-head">
                                        <h4>Registration Status</h4>
                                        <p>Current provider verification standing</p>
                                    </div>
                                    <div class="staff-side-metrics">
                                        <div class="staff-side-metric">
                                            <span>Member Since</span>
                                            <strong><?php echo htmlspecialchars($memberSince); ?></strong>
                                        </div>
                                        <div class="staff-side-metric">
                                            <span>Verification</span>
                                            <strong><?php echo htmlspecialchars($scopeLabel); ?></strong>
                                        </div>
                                        <div class="staff-side-metric">
                                            <span>Location</span>
                                            <strong><?php echo htmlspecialchars($providerLocationSummary); ?></strong>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            </aside>

                        <div class="staff-content-column">
                            <?php if ($isProviderRole): ?>
                            <div class="staff-overview-grid">
                                <div class="staff-overview-card">
                                    <span class="staff-overview-label">Role</span>
                                    <strong><?php echo htmlspecialchars($roleLabel); ?></strong>
                                    <p><?php echo htmlspecialchars($isProviderRole ? 'External scholarship organization account' : 'Administrative workspace access'); ?></p>
                                </div>
                                <div class="staff-overview-card">
                                    <span class="staff-overview-label"><?php echo $isProviderRole ? 'Organization Type' : 'Office'; ?></span>
                                    <strong><?php echo htmlspecialchars($isProviderRole ? formatOrganizationTypeLabel($profile['organization_type'] ?? '') : $workspaceLabel); ?></strong>
                                    <p><?php echo htmlspecialchars($positionLabel); ?></p>
                                </div>
                                <div class="staff-overview-card">
                                    <span class="staff-overview-label"><?php echo $isProviderRole ? 'Contact' : 'Phone Number'; ?></span>
                                    <strong><?php echo htmlspecialchars($isProviderRole ? $providerContactName : displayValue($profile['phone_number'] ?? '')); ?></strong>
                                    <p><?php echo htmlspecialchars($isProviderRole ? displayValue($profile['organization_email'] ?? ($account['email'] ?? '')) : 'Primary admin contact'); ?></p>
                                </div>
                                <div class="staff-overview-card">
                                    <span class="staff-overview-label"><?php echo $isProviderRole ? 'Verification' : 'Reviews'; ?></span>
                                    <strong><?php echo htmlspecialchars($isProviderRole ? (!empty($profile['is_verified']) ? 'Verified' : 'Pending') : (!empty($profile['can_review_documents']) ? 'Enabled' : 'Limited')); ?></strong>
                                    <p><?php echo htmlspecialchars($isProviderRole ? 'Provider record status' : 'Document and application review access'); ?></p>
                                </div>
                            </div>
                            <div class="staff-section-card">
                                <div class="staff-section-head">
                                    <div>
                                        <h4><i class="fas fa-address-card"></i> Account Details</h4>
                                        <p>Shared login and contact record</p>
                                    </div>
                                </div>
                                <div class="staff-info-grid">
                                    <div class="staff-info-card"><div class="staff-info-label">Username</div><div class="staff-info-value"><?php echo htmlspecialchars((string) ($account['username'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Email</div><div class="staff-info-value"><?php echo htmlspecialchars((string) ($account['email'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Role</div><div class="staff-info-value"><?php echo htmlspecialchars($roleLabel); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Status</div><div class="staff-info-value"><?php echo htmlspecialchars($statusLabel); ?></div></div>
                                </div>
                            </div>

                            <div class="staff-section-card">
                                <div class="staff-section-head">
                                    <div>
                                        <h4><i class="fas fa-building-columns"></i> Provider Contact</h4>
                                        <p>Primary contact and organization communication details</p>
                                    </div>
                                </div>
                                <div class="staff-info-grid">
                                    <div class="staff-info-card"><div class="staff-info-label">Organization</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['organization_name'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Organization Type</div><div class="staff-info-value"><?php echo htmlspecialchars(formatOrganizationTypeLabel($profile['organization_type'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Contact Person</div><div class="staff-info-value"><?php echo htmlspecialchars($providerContactName); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Contact Position</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['contact_person_position'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Phone Number</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['phone_number'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Mobile Number</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['mobile_number'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Organization Email</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['organization_email'] ?? ($account['email'] ?? ''))); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Website</div><div class="staff-info-value"><?php echo !empty($profile['website']) ? '<a href="' . htmlspecialchars($profile['website']) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($profile['website']) . '</a>' : 'Not set'; ?></div></div>
                                </div>
                            </div>

                            <div class="staff-section-card">
                                <div class="staff-section-head">
                                    <div>
                                        <h4><i class="fas fa-location-dot"></i> Address and Organization Record</h4>
                                        <p>Registered address and provider profile details</p>
                                    </div>
                                </div>
                                <div class="staff-info-grid">
                                    <div class="staff-info-card is-full"><div class="staff-info-label">Address</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['address'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">House No.</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['house_no'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Street</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['street'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Barangay</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['barangay'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">City</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['city'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Province</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['province'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Zip Code</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['zip_code'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Verification</div><div class="staff-info-value"><?php echo !empty($profile['is_verified']) ? 'Verified' : 'Pending'; ?></div></div>
                                    <div class="staff-info-card is-full"><div class="staff-info-label">Description</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['description'] ?? '')); ?></div></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="staff-section-card">
                                <div class="staff-section-head">
                                    <div>
                                        <h4><i class="fas fa-user-shield"></i> Administrative Profile</h4>
                                    </div>
                                </div>
                                <div class="staff-info-grid">
                                    <div class="staff-info-card"><div class="staff-info-label">First Name</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['firstname'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Last Name</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['lastname'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Middle Initial</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['middleinitial'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Suffix</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['suffix'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Phone Number</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['phone_number'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Member Since</div><div class="staff-info-value"><?php echo htmlspecialchars($memberSince); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Position</div><div class="staff-info-value"><?php echo htmlspecialchars(displayValue($profile['position'] ?? '')); ?></div></div>
                                    <div class="staff-info-card"><div class="staff-info-label">Access Scope</div><div class="staff-info-value"><?php echo htmlspecialchars($scopeLabel); ?></div></div>
                                </div>
                                <div class="staff-inline-summary">
                                    <div class="staff-inline-block is-wide">
                                        <span class="staff-inline-label">Responsibilities</span>
                                        <?php if (!empty($enabledResponsibilities)): ?>
                                            <div class="staff-responsibility-tags">
                                                <?php foreach ($enabledResponsibilities as $label): ?>
                                                    <span class="staff-responsibility-tag"><?php echo htmlspecialchars($label); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="staff-inline-note">No additional responsibilities assigned.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div id="profileEditContent" data-profile-panel="edit" hidden>
                    <?php if (!$storageReady): ?>
                    <div class="staff-profile-empty"><i class="fas fa-database"></i><div><strong>Profile editing is not available yet.</strong><br><?php echo htmlspecialchars($storageMessage); ?></div></div>
                    <?php else: ?>
                    <div class="staff-panel-shell">
                        <div class="staff-panel-heading">
                            <div>
                                <h4><?php echo $isProviderRole ? 'Edit Provider Profile' : 'Edit Profile'; ?></h4>
                                <p><?php echo $isProviderRole ? 'Update organization and contact details used for provider review.' : 'Update the identity details shown in the admin panel.'; ?></p>
                            </div>
                        </div>
                        <form id="editStaffProfileForm" class="staff-edit-form">
                            <?php if ($isProviderRole): ?>
                            <div class="staff-form-panel">
                                <h4>Provider Organization</h4>
                                <p>Organization details</p>
                                <div class="staff-form-grid">
                                    <div class="staff-form-group"><label for="organization_name">Organization Name *</label><input type="text" id="organization_name" name="organization_name" value="<?php echo htmlspecialchars((string) ($profile['organization_name'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="organization_type">Organization Type *</label><select id="organization_type" name="organization_type" required><option value="">Select organization type</option><?php foreach ($organizationTypeOptions as $value => $label): ?><option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($profile['organization_type'] ?? '') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></div>
                                    <div class="staff-form-group"><label for="contact_person_firstname">Contact First Name *</label><input type="text" id="contact_person_firstname" name="contact_person_firstname" value="<?php echo htmlspecialchars((string) ($profile['contact_person_firstname'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="contact_person_lastname">Contact Last Name *</label><input type="text" id="contact_person_lastname" name="contact_person_lastname" value="<?php echo htmlspecialchars((string) ($profile['contact_person_lastname'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="contact_person_position">Contact Position *</label><input type="text" id="contact_person_position" name="contact_person_position" value="<?php echo htmlspecialchars((string) ($profile['contact_person_position'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="phone_number">Phone Number *</label><input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars((string) ($profile['phone_number'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="mobile_number">Mobile Number</label><input type="text" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars((string) ($profile['mobile_number'] ?? '')); ?>"></div>
                                    <div class="staff-form-group"><label for="organization_email">Organization Email *</label><input type="email" id="organization_email" name="organization_email" value="<?php echo htmlspecialchars((string) ($profile['organization_email'] ?? ($account['email'] ?? ''))); ?>" required></div>
                                    <div class="staff-form-group"><label for="website">Website</label><input type="url" id="website" name="website" value="<?php echo htmlspecialchars((string) ($profile['website'] ?? '')); ?>" placeholder="https://example.org"></div>
                                    <div class="staff-form-group is-full"><label for="address">Address *</label><input type="text" id="address" name="address" value="<?php echo htmlspecialchars((string) ($profile['address'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="house_no">House No.</label><input type="text" id="house_no" name="house_no" value="<?php echo htmlspecialchars((string) ($profile['house_no'] ?? '')); ?>"></div>
                                    <div class="staff-form-group"><label for="street">Street</label><input type="text" id="street" name="street" value="<?php echo htmlspecialchars((string) ($profile['street'] ?? '')); ?>"></div>
                                    <div class="staff-form-group"><label for="barangay">Barangay</label><input type="text" id="barangay" name="barangay" value="<?php echo htmlspecialchars((string) ($profile['barangay'] ?? '')); ?>"></div>
                                    <div class="staff-form-group"><label for="city">City / Municipality *</label><input type="text" id="city" name="city" value="<?php echo htmlspecialchars((string) ($profile['city'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="province">Province *</label><input type="text" id="province" name="province" value="<?php echo htmlspecialchars((string) ($profile['province'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="zip_code">Zip Code</label><input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars((string) ($profile['zip_code'] ?? '')); ?>"></div>
                                    <div class="staff-form-group is-full"><label for="description">Description</label><textarea id="description" name="description" placeholder="Describe the organization and scholarship coverage."><?php echo htmlspecialchars((string) ($profile['description'] ?? '')); ?></textarea></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="staff-form-panel">
                                <h4>Identity</h4>
                                <p>Personal details</p>
                                <div class="staff-name-grid">
                                    <div class="staff-form-group"><label for="firstname">First Name *</label><input type="text" id="firstname" name="firstname" value="<?php echo htmlspecialchars((string) ($profile['firstname'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="middleinitial">Middle Initial</label><input type="text" id="middleinitial" name="middleinitial" value="<?php echo htmlspecialchars(normalizeMiddleInitialInputValue($profile['middleinitial'] ?? '')); ?>" maxlength="1"></div>
                                    <div class="staff-form-group"><label for="lastname">Last Name *</label><input type="text" id="lastname" name="lastname" value="<?php echo htmlspecialchars((string) ($profile['lastname'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label for="suffix">Suffix</label><input type="text" id="suffix" name="suffix" value="<?php echo htmlspecialchars((string) ($profile['suffix'] ?? '')); ?>" maxlength="20"></div>
                                </div>
                                <div class="staff-form-grid is-top-spaced">
                                    <div class="staff-form-group"><label for="phone_number">Phone Number *</label><input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars((string) ($profile['phone_number'] ?? '')); ?>" required></div>
                                    <div class="staff-form-group"><label>Email</label><input type="email" value="<?php echo htmlspecialchars((string) ($account['email'] ?? '')); ?>" disabled></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="staff-form-actions">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Profile</button>
                                <button type="button" class="btn btn-outline" data-profile-tab="view"><i class="fas fa-times"></i> Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="profilePasswordContent" data-profile-panel="password" hidden>
                    <div class="staff-panel-shell">
                        <div class="staff-panel-heading">
                            <div>
                                <h4>Security</h4>
                                <p>Update your password for account access.</p>
                            </div>
                        </div>
                        <div class="staff-form-panel">
                            <div class="staff-password-note">
                                <?php echo htmlspecialchars(passwordPolicyHint()); ?>
                            </div>
                            <form id="changeStaffPasswordForm" class="staff-edit-form">
                                <input type="hidden" name="action" value="change_password">
                                <div class="staff-form-grid">
                                    <div class="staff-form-group">
                                        <label for="currentPassword">Current Password *</label>
                                        <input type="password" id="currentPassword" name="current_password" required>
                                    </div>
                                    <div class="staff-form-group">
                                        <label for="newPassword">New Password *</label>
                                        <input type="password" id="newPassword" name="new_password" required>
                                    </div>
                                    <div class="staff-form-group">
                                        <label for="confirmPassword">Confirm New Password *</label>
                                        <input type="password" id="confirmPassword" name="confirm_password" required>
                                    </div>
                                </div>
                                <div class="staff-form-actions">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
                                    <button type="button" class="btn btn-outline" data-profile-tab="view"><i class="fas fa-arrow-left"></i> Back</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'layouts/admin_footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../public/js/password-policy.js?v=<?php echo urlencode((string) (@filemtime(__DIR__ . '/../public/js/password-policy.js') ?: time())); ?>"></script>
<script src="../AdminPublic/js/staff-profile.js?v=<?php echo urlencode((string) $profileJsVersion); ?>"></script>
</body>
</html>
