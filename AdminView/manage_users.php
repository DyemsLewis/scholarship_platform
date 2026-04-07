<?php 
require_once __DIR__ . '/../Config/session_bootstrap.php';
    require_once '../Config/db_config.php';
    require_once '../Config/access_control.php';
    require_once '../Config/csrf.php';
    require_once '../Config/url_token.php';
    require_once '../Model/StaffAccountProfile.php';
    
    requireRoles(['super_admin'], '../AdminView/admin_dashboard.php', 'Only super administrators can access account management.');
    $actorRole = getCurrentSessionRole();
    $canManageStaffAccounts = true;

    function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool {
        static $cache = [];
        $key = $tableName . '.' . $columnName;
        if (isset($cache[$key])) {
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

    function getUserStatusOptions(PDO $pdo): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $fallback = [
            'active' => 'Active',
            'inactive' => 'Inactive'
        ];

        if (!tableHasColumn($pdo, 'users', 'status')) {
            $cached = $fallback;
            return $cached;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
            $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            $type = strtolower((string) ($column['Type'] ?? ''));

            if (strpos($type, 'enum(') === 0) {
                preg_match_all("/'([^']+)'/", $type, $matches);
                $values = array_values(array_filter(array_map('trim', $matches[1] ?? [])));
                if (!empty($values)) {
                    $cached = [];
                    foreach ($values as $value) {
                        $cached[$value] = ucwords(str_replace('_', ' ', $value));
                    }
                    return $cached;
                }
            }
        } catch (Throwable $e) {
            error_log('manage_users status options error: ' . $e->getMessage());
        }

        $cached = [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'pending' => 'Pending',
            'suspended' => 'Suspended'
        ];
        return $cached;
    }
    
    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $role_filter = $_GET['role'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $statusOptions = getUserStatusOptions($pdo);

    $allowedRoleFilters = ['student', 'admin', 'provider'];
    if ($role_filter !== '' && !in_array($role_filter, $allowedRoleFilters, true)) {
        $role_filter = '';
    }
    if (!$canManageStaffAccounts && in_array($role_filter, ['admin', 'provider'], true)) {
        $role_filter = '';
    }
    if ($status_filter !== '' && !array_key_exists($status_filter, $statusOptions)) {
        $status_filter = '';
    }
    
    $studentQuery = "
        SELECT 
            u.id,
            u.username,
            u.email,
            u.status,
            u.role,
            u.created_at,
            sd.firstname,
            sd.lastname,
            sd.school,
            sd.course,
            sd.gwa,
            CONCAT(sd.firstname, ' ', sd.lastname) as full_name
        FROM users u 
        LEFT JOIN student_data sd ON u.id = sd.student_id 
        WHERE u.role = 'student'
    ";
    
    $studentParams = [];
    
    if ($search) {
        $studentQuery .= " AND (u.username LIKE ? OR u.email LIKE ? OR sd.firstname LIKE ? OR sd.lastname LIKE ?)";
        $search_param = "%$search%";
        $studentParams = array_merge($studentParams, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($status_filter && !in_array($role_filter, ['admin', 'provider'], true)) {
        $studentQuery .= " AND u.status = ?";
        $studentParams[] = $status_filter;
    }
    
    $studentQuery .= " ORDER BY u.created_at DESC";
    
    $stmt = $pdo->prepare($studentQuery);
    $stmt->execute($studentParams);
    $students = $stmt->fetchAll();
    
    $hasStaffProfilesTable = tableHasColumn($pdo, 'staff_profiles', 'user_id');
    $staffOptionalColumns = [
        'firstname',
        'lastname',
        'suffix',
        'organization_name',
        'organization_type',
        'position_title',
        'department',
        'staff_id_no',
        'city',
        'province',
        'website'
    ];
    $adminExtraSelect = [];
    foreach ($staffOptionalColumns as $column) {
        if ($hasStaffProfilesTable && tableHasColumn($pdo, 'staff_profiles', $column)) {
            $adminExtraSelect[] = "sp.{$column}";
        } else {
            $adminExtraSelect[] = "NULL AS {$column}";
        }
    }

    $adminQuery = "
        SELECT 
            u.id,
            u.username,
            u.email,
            u.status,
            u.role,
            u.created_at,
            " . implode(",\n            ", $adminExtraSelect) . "
        FROM users u 
        " . ($hasStaffProfilesTable ? "LEFT JOIN staff_profiles sp ON u.id = sp.user_id" : "") . "
        WHERE u.role IN ('admin', 'super_admin', 'provider')
    ";
    
    $adminParams = [];
    
    if ($role_filter === 'admin') {
        $adminQuery .= " AND u.role IN ('admin', 'super_admin')";
    } elseif ($role_filter === 'provider') {
        $adminQuery .= " AND u.role = 'provider'";
    }
    
    if ($status_filter) {
        $adminQuery .= " AND u.status = ?";
        $adminParams[] = $status_filter;
    }
    
    $adminQuery .= " ORDER BY u.created_at DESC";
    
    $admins = [];
    if ($canManageStaffAccounts) {
        $stmt = $pdo->prepare($adminQuery);
        $stmt->execute($adminParams);
        $admins = $stmt->fetchAll();

        $staffProfileModel = new StaffAccountProfile($pdo);
        foreach ($admins as &$admin) {
            $profile = $staffProfileModel->getByUserId((int) ($admin['id'] ?? 0), (string) ($admin['role'] ?? ''), $admin);
            if (!$profile) {
                continue;
            }

            if (($admin['role'] ?? '') === 'provider') {
                $admin['firstname'] = (string) ($profile['contact_person_firstname'] ?? '');
                $admin['lastname'] = (string) ($profile['contact_person_lastname'] ?? '');
                $admin['suffix'] = '';
                $admin['organization_name'] = (string) ($profile['organization_name'] ?? '');
                $admin['organization_type'] = (string) ($profile['organization_type'] ?? '');
                $admin['position_title'] = (string) ($profile['contact_person_position'] ?? '');
                $admin['department'] = '';
                $admin['staff_id_no'] = '';
                $admin['city'] = (string) ($profile['city'] ?? '');
                $admin['province'] = (string) ($profile['province'] ?? '');
                $admin['website'] = (string) ($profile['website'] ?? '');
            } else {
                $admin['firstname'] = (string) ($profile['firstname'] ?? '');
                $admin['lastname'] = (string) ($profile['lastname'] ?? '');
                $admin['suffix'] = (string) ($profile['suffix'] ?? '');
                $admin['organization_name'] = '';
                $admin['organization_type'] = 'internal_administration';
                $admin['position_title'] = (string) ($profile['position'] ?? '');
                $admin['department'] = (string) ($profile['department'] ?? '');
                $admin['staff_id_no'] = '';
                $admin['city'] = '';
                $admin['province'] = '';
                $admin['website'] = '';
                $admin['access_level'] = (int) ($profile['access_level'] ?? ($admin['role'] === 'super_admin' ? 90 : 70));
            }
        }
        unset($admin);

        if ($search !== '') {
            $searchNeedle = strtolower(trim($search));
            $admins = array_values(array_filter($admins, static function ($admin) use ($searchNeedle) {
                $haystack = implode(' ', array_filter([
                    (string) ($admin['username'] ?? ''),
                    (string) ($admin['email'] ?? ''),
                    (string) ($admin['firstname'] ?? ''),
                    (string) ($admin['lastname'] ?? ''),
                    (string) ($admin['organization_name'] ?? ''),
                    (string) ($admin['organization_type'] ?? ''),
                    (string) ($admin['position_title'] ?? ''),
                    (string) ($admin['department'] ?? ''),
                    (string) ($admin['city'] ?? ''),
                    (string) ($admin['province'] ?? ''),
                    (string) ($admin['website'] ?? '')
                ], static fn($value) => trim($value) !== ''));

                return $haystack !== '' && stripos(strtolower($haystack), $searchNeedle) !== false;
            }));
        }
    }
    
    // Get statistics (only from students for student-specific stats, or all users for totals)
    $total_users = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $active_users = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
    $pending_accounts = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $admin_count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'super_admin')")->fetchColumn();
    $provider_count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'provider'")->fetchColumn();
    $student_count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
    $internalStaffAccounts = [];
    $providerAccounts = [];
    foreach ($admins as $accountRow) {
        if (strtolower((string) ($accountRow['role'] ?? '')) === 'provider') {
            $providerAccounts[] = $accountRow;
        } else {
            $internalStaffAccounts[] = $accountRow;
        }
    }

    $showStudentSection = (!$role_filter || $role_filter === 'student');
    $showInternalStaffSection = ($canManageStaffAccounts && (!$role_filter || $role_filter === 'admin'));
    $showProviderSection = ($canManageStaffAccounts && (!$role_filter || $role_filter === 'provider'));
    $visibleAccountCount = ($showStudentSection ? count($students) : 0)
        + ($showInternalStaffSection ? count($internalStaffAccounts) : 0)
        + ($showProviderSection ? count($providerAccounts) : 0);

    $roleFilterOptions = [
        '' => 'All visible groups',
        'student' => 'Students',
    ];
if ($canManageStaffAccounts) {
    $roleFilterOptions['admin'] = 'Admin accounts';
    $roleFilterOptions['provider'] = 'Providers';
}

    $currentSessionUserId = (int) ($_SESSION['user_id'] ?? 0);

    $statusFilterOptions = ['' => 'All statuses'] + $statusOptions;
    $selectedRoleLabel = $role_filter !== '' ? ($roleFilterOptions[$role_filter] ?? ucwords(str_replace('_', ' ', $role_filter))) : '';
    $selectedStatusLabel = $status_filter !== '' ? ($statusFilterOptions[$status_filter] ?? ucwords(str_replace('_', ' ', $status_filter))) : '';

    $summaryParts = [];
    if ($search !== '') {
        $summaryParts[] = 'search "' . $search . '"';
    }
    if ($selectedRoleLabel !== '') {
        $summaryParts[] = strtolower($selectedRoleLabel);
    }
    if ($selectedStatusLabel !== '') {
        $summaryParts[] = strtolower($selectedStatusLabel) . ' status';
    }

    $accountsSummaryText = 'Showing ' . number_format($visibleAccountCount) . ' record' . ($visibleAccountCount === 1 ? '' : 's');
    $accountsSummaryText .= !empty($summaryParts)
        ? ' for ' . implode(' | ', $summaryParts) . '.'
        : ' across the visible account sections.';
    $accountsCssVersion = @filemtime(__DIR__ . '/../AdminPublic/css/manage-accounts.css') ?: time();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../AdminPublic/css/manage-users.css">
    <link rel="stylesheet" href="../AdminPublic/css/manage-accounts.css?v=<?php echo urlencode((string) $accountsCssVersion); ?>">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard accounts-dashboard">
        <div class="container">
            <div class="accounts-hero">
                <div class="accounts-hero-main">
                    <h1><i class="fas fa-users"></i> Accounts</h1>
                    <p>Manage student records, admin accounts, and provider organizations.</p>
                </div>
                <?php if($canManageStaffAccounts): ?>
                <div class="accounts-hero-actions">
                    <a href="addUsers.php" class="btn-add">
                        <i class="fas fa-plus"></i>
                        Add Admin Account
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Alert Messages -->
            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <p><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <p><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['errors']) && is_array($_SESSION['errors'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <ul class="accounts-error-list">
                    <?php foreach($_SESSION['errors'] as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; unset($_SESSION['errors']); ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <div class="accounts-overview">
                <article class="account-metric-card">
                    <span class="account-metric-label">Total accounts</span>
                    <strong class="account-metric-value"><?php echo number_format($total_users); ?></strong>
                    <span class="account-metric-meta">Students, providers, admins, and super admins</span>
                </article>
                <article class="account-metric-card">
                    <span class="account-metric-label">Active now</span>
                    <strong class="account-metric-value"><?php echo number_format($active_users); ?></strong>
                    <span class="account-metric-meta"><?php echo number_format($pending_accounts); ?> awaiting review</span>
                </article>
                <article class="account-metric-card">
                    <span class="account-metric-label">Students</span>
                    <strong class="account-metric-value"><?php echo number_format($student_count); ?></strong>
                    <span class="account-metric-meta">Academic profiles and scholarship applicants</span>
                </article>
                <article class="account-metric-card">
                    <?php if ($canManageStaffAccounts): ?>
                    <span class="account-metric-label">Admins</span>
                    <strong class="account-metric-value"><?php echo number_format($admin_count); ?></strong>
                    <span class="account-metric-meta"><?php echo number_format($provider_count); ?> provider organizations on file</span>
                    <?php else: ?>
                    <span class="account-metric-label">Visible accounts</span>
                    <strong class="account-metric-value"><?php echo number_format(count($students)); ?></strong>
                    <span class="account-metric-meta">Accounts currently available in your workspace</span>
                    <?php endif; ?>
                </article>
            </div>

            <div class="accounts-toolbar accounts-control-panel">
                <div class="accounts-toolbar-copy">
                    <h2>Search and Filter</h2>
                    <p><?php echo htmlspecialchars($accountsSummaryText); ?></p>
                </div>

                <form method="GET" class="accounts-filter-form<?php echo $canManageStaffAccounts ? '' : ' accounts-filter-form--no-role'; ?>">
                    <div class="search-wrapper accounts-search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search by name, username, email, organization, or contact..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <?php if ($canManageStaffAccounts): ?>
                    <div class="filter-field">
                        <label for="role-filter">Account Group</label>
                        <div class="filter-select-wrap">
                            <select name="role" id="role-filter" class="filter-select">
                                <?php foreach ($roleFilterOptions as $optionValue => $optionLabel): ?>
                                <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo $role_filter === $optionValue ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($optionLabel); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down filter-select-icon" aria-hidden="true"></i>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="filter-field">
                        <label for="status-filter">Status</label>
                        <div class="filter-select-wrap">
                            <select name="status" id="status-filter" class="filter-select">
                                <?php foreach ($statusFilterOptions as $optionValue => $optionLabel): ?>
                                <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo $status_filter === $optionValue ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($optionLabel); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down filter-select-icon" aria-hidden="true"></i>
                        </div>
                    </div>

                    <div class="accounts-filter-actions">
                        <button type="submit" class="btn-search">
                            <i class="fas fa-sliders"></i> Apply
                        </button>
                        <?php if($search || $role_filter || $status_filter): ?>
                        <a href="manage_users.php" class="btn-clear">
                            <i class="fas fa-times"></i> Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="accounts-filter-summary<?php echo ($search || $role_filter || $status_filter) ? '' : ' is-muted'; ?>">
                    <i class="fas fa-filter" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($accountsSummaryText); ?></span>
                </div>
            </div>
            
            <!-- ===================== STUDENTS TABLE ===================== -->
            <?php if($showStudentSection): ?>
            <div class="accounts-group-panel">
                <div class="accounts-group-head">
                    <div>
                        <h2>
                            <i class="fas fa-graduation-cap"></i>
                            Student Records
                            <span class="section-count"><?php echo number_format(count($students)); ?> shown</span>
                        </h2>
                    </div>
                </div>
                
                <div class="accounts-table-shell table-container-modern">
                <div class="table-responsive">
                    <table class="admin-table-modern">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>School</th>
                                <th>Course</th>
                                <th>GWA</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($students)): ?>
                            <tr>
                                <td colspan="8" class="table-empty-cell">
                                    <div class="empty-state-modern">
                                        <i class="fas fa-inbox"></i>
                                        <p>No student accounts found matching your criteria</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach($students as $user): 
                                    $displayName = $user['full_name'] ?? $user['username'] ?? 'Unknown';
                                    $initials = strtoupper(substr($displayName, 0, 1));
                                    $studentId = (int) $user['id'];
                                    $editUserUrl = buildEntityUrl('edit_user.php', 'user', $studentId, 'edit', ['id' => $studentId]);
                                    $updateStatusToken = buildEntityUrlToken('user', $studentId, 'update_status');
                                    $deleteUserToken = buildEntityUrlToken('user', $studentId, 'delete');
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-cell-modern">
                                            <div class="user-avatar-modern">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div class="user-info-modern">
                                                <div class="user-name-modern"><?php echo htmlspecialchars($displayName); ?></div>
                                                <div class="user-email-modern"><?php echo htmlspecialchars($user['email']); ?></div>
                                                <?php if (!empty($user['username'])): ?>
                                                    <div class="account-username-meta">Username: <?php echo htmlspecialchars((string) $user['username']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($user['school']): ?>
                                            <span class="account-primary-text"><?php echo htmlspecialchars($user['school']); ?></span>
                                        <?php else: ?>
                                            <span class="account-muted-dash">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($user['course']): ?>
                                            <span class="account-primary-text"><?php echo htmlspecialchars($user['course']); ?></span>
                                        <?php else: ?>
                                            <span class="account-muted-dash">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($user['gwa']): ?>
                                            <span class="gwa-badge-modern"><?php echo number_format($user['gwa'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="account-muted-dash">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-modern badge-role-student">
                                            <i class="fas fa-user-graduate"></i>
                                            Student
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" action="../AdminController/user_process.php" class="account-inline-form">
                                            <input type="hidden" name="action" value="update_status">
                                            <?php echo csrfInputField('admin_account_management'); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $studentId; ?>">
                                            <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($updateStatusToken); ?>">
                                            <select name="status" onchange="this.form.submit()" class="status-select-modern status-<?php echo htmlspecialchars((string) $user['status']); ?>">
                                                <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                                                <option value="<?php echo htmlspecialchars($statusValue); ?>" <?php echo ($user['status'] ?? '') == $statusValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($statusLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="account-date-text">
                                        <i class="far fa-calendar-alt account-date-icon"></i>
                                        <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons-modern">
                                            <a href="<?php echo htmlspecialchars($editUserUrl); ?>" 
                                               class="btn-icon btn-icon-edit" 
                                               data-tooltip="Edit Account">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="POST" action="../AdminController/user_process.php" class="account-inline-form account-delete-form" data-account-type="student account" data-account-name="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <?php echo csrfInputField('admin_account_management'); ?>
                                                <input type="hidden" name="user_id" value="<?php echo $studentId; ?>">
                                                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($deleteUserToken); ?>">
                                                <button type="button" 
                                                        class="btn-icon btn-icon-delete" 
                                                        data-account-delete-trigger="true"
                                                        data-tooltip="Delete Account"
                                                        <?php echo $studentId == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
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
            <?php endif; ?>

            <?php if(!$canManageStaffAccounts): ?>
            <div class="alert alert-info accounts-access-note">
                <i class="fas fa-info-circle"></i>
                <p>Admin and provider account management is restricted to super administrators.</p>
            </div>
            <?php endif; ?>
            
            <?php if($showInternalStaffSection): ?>
            <div class="accounts-section-gap"></div>

            <div class="accounts-group-panel">
                <div class="accounts-group-head">
                    <div>
                        <h2>
                            <i class="fas fa-user-shield"></i>
                            Admin Accounts
                            <span class="section-count"><?php echo number_format(count($internalStaffAccounts)); ?> shown</span>
                        </h2>
                    </div>
                </div>
                
                <div class="accounts-table-shell table-container-modern">
                <div class="table-responsive">
                    <table class="admin-table-modern">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Position</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($internalStaffAccounts)): ?>
                            <tr>
                                <td colspan="6" class="table-empty-cell">
                                    <div class="empty-state-modern">
                                        <i class="fas fa-inbox"></i>
                                        <p>No admin accounts found</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach($internalStaffAccounts as $admin): 
                                    $staffId = (int) $admin['id'];
                                    $isCurrentAdminAccount = $staffId === $currentSessionUserId;
                                    $staffRole = strtolower((string) ($admin['role'] ?? 'admin'));
                                    $editStaffUrl = buildEntityUrl('edit_user.php', 'user', $staffId, 'edit', ['id' => $staffId]);
                                    $staffStatusToken = buildEntityUrlToken('user', $staffId, 'update_status');
                                    $deleteStaffToken = buildEntityUrlToken('user', $staffId, 'delete');
                                    $staffLabel = $staffRole === 'super_admin' ? 'Super Admin' : 'Admin';
                                    $staffDisplayName = trim((string) ($admin['firstname'] ?? '') . ' ' . (string) ($admin['lastname'] ?? ''));
                                    if (trim((string) ($admin['suffix'] ?? '')) != '') {
                                        $staffDisplayName .= ' ' . trim((string) $admin['suffix']);
                                    }
                                    if ($staffDisplayName == '') {
                                        $staffDisplayName = (string) ($admin['username'] ?? 'Admin');
                                    }
                                    $initials = strtoupper(substr($staffDisplayName, 0, 1));
                                    $position = trim((string) ($admin['position_title'] ?? ''));
                                    if ($position == '') {
                                        $position = $staffRole === 'super_admin' ? 'Super Administrator' : 'Administrator';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-cell-modern">
                                            <div class="admin-avatar-modern">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div class="user-info-modern">
                                                <div class="user-name-modern"><?php echo htmlspecialchars($staffDisplayName); ?></div>
                                                <div class="user-email-modern"><?php echo htmlspecialchars($admin['email']); ?></div>
                                                <?php if (!empty($admin['username'])): ?>
                                                    <div class="account-username-meta">Username: <?php echo htmlspecialchars((string) ($admin['username'] ?? '')); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="account-primary-text"><?php echo htmlspecialchars($position); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge-modern badge-role-admin">
                                            <i class="fas fa-user-shield"></i>
                                            <?php echo htmlspecialchars($staffLabel); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($isCurrentAdminAccount): ?>
                                        <span class="badge-modern badge-status-<?php echo htmlspecialchars((string) ($admin['status'] ?? 'inactive')); ?>">
                                            <?php echo htmlspecialchars($statusOptions[(string) ($admin['status'] ?? '')] ?? ucwords(str_replace('_', ' ', (string) ($admin['status'] ?? 'inactive')))); ?>
                                        </span>
                                        <?php else: ?>
                                        <form method="POST" action="../AdminController/user_process.php" class="account-inline-form">
                                            <input type="hidden" name="action" value="update_status">
                                            <?php echo csrfInputField('admin_account_management'); ?>
                                            <input type="hidden" name="user_id" value="<?php echo $staffId; ?>">
                                            <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($staffStatusToken); ?>">
                                            <select name="status" onchange="this.form.submit()" class="status-select-modern status-<?php echo htmlspecialchars((string) $admin['status']); ?>">
                                                <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                                                <option value="<?php echo htmlspecialchars($statusValue); ?>" <?php echo ($admin['status'] ?? '') == $statusValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($statusLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="account-date-text">
                                        <i class="far fa-calendar-alt account-date-icon"></i>
                                        <?php echo date('M d, Y', strtotime($admin['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons-modern">
                                            <?php if(!$isCurrentAdminAccount): ?>
                                            <a href="<?php echo htmlspecialchars($editStaffUrl); ?>" 
                                               class="btn-icon btn-icon-edit" 
                                               data-tooltip="Edit Account">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="POST" action="../AdminController/user_process.php" class="account-inline-form account-delete-form" data-account-type="admin account" data-account-name="<?php echo htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <?php echo csrfInputField('admin_account_management'); ?>
                                                <input type="hidden" name="user_id" value="<?php echo $staffId; ?>">
                                                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($deleteStaffToken); ?>">
                                                <button type="button" 
                                                        class="btn-icon btn-icon-delete" 
                                                        data-account-delete-trigger="true"
                                                        data-tooltip="Delete Account">
                                                        <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <span class="account-meta-note">Current account</span>
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
            <?php endif; ?>

            <?php if($showProviderSection): ?>
            <div class="accounts-section-gap"></div>

            <div class="accounts-group-panel">
                <div class="accounts-group-head">
                    <div>
                        <h2>
                            <i class="fas fa-building"></i>
                            Provider Organizations
                            <span class="section-count"><?php echo number_format(count($providerAccounts)); ?> shown</span>
                        </h2>
                    </div>
                </div>
                
                <div class="accounts-table-shell table-container-modern">
                <div class="table-responsive">
                    <table class="admin-table-modern">
                        <thead>
                            <tr>
                                <th>Organization</th>
                                <th>Location</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($providerAccounts)): ?>
                            <tr>
                                <td colspan="7" class="table-empty-cell">
                                    <div class="empty-state-modern">
                                        <i class="fas fa-inbox"></i>
                                        <p>No provider organizations found</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach($providerAccounts as $provider): 
                                    $providerId = (int) $provider['id'];
                                    $providerReviewsUrl = 'provider_reviews.php#provider-review-' . $providerId;
                                    $editProviderUrl = buildEntityUrl('edit_user.php', 'user', $providerId, 'edit', ['id' => $providerId]);
                                    $deleteProviderToken = buildEntityUrlToken('user', $providerId, 'delete');
                                    $providerDisplayName = trim((string) ($provider['firstname'] ?? '') . ' ' . (string) ($provider['lastname'] ?? ''));
                                    if ($providerDisplayName == '') {
                                        $providerDisplayName = (string) ($provider['username'] ?? 'Provider');
                                    }
                                    $organization = trim((string) ($provider['organization_name'] ?? ''));
                                    if ($organization == '') {
                                        $organization = 'Provider Organization';
                                    }
                                    $organizationType = trim((string) ($provider['organization_type'] ?? ''));
                                    $city = trim((string) ($provider['city'] ?? ''));
                                    $province = trim((string) ($provider['province'] ?? ''));
                                    $locationLabel = trim($city . ($city !== '' && $province !== '' ? ', ' : '') . $province, ' ,');
                                    $contactPosition = trim((string) ($provider['position_title'] ?? ''));
                                    if ($contactPosition == '') {
                                        $contactPosition = 'Scholarship Provider';
                                    }
                                    $initials = strtoupper(substr($organization, 0, 1));
                                ?>
                                <tr>
                                    <td>
                                        <div class="user-cell-modern">
                                            <div class="admin-avatar-modern">
                                                <?php echo htmlspecialchars($initials); ?>
                                            </div>
                                            <div class="user-info-modern">
                                                <div class="user-name-modern"><?php echo htmlspecialchars($organization); ?></div>
                                                <div class="user-email-modern"><?php echo htmlspecialchars($provider['email']); ?></div>
                                                <?php if (!empty($provider['username'])): ?>
                                                    <div class="account-username-meta">Username: <?php echo htmlspecialchars((string) ($provider['username'] ?? '')); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="account-primary-text"><?php echo htmlspecialchars($locationLabel !== '' ? $locationLabel : 'Not set'); ?></span>
                                        <?php if($organizationType !== ''): ?>
                                        <div class="account-meta-note"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $organizationType))); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="account-primary-text"><?php echo htmlspecialchars($providerDisplayName); ?></span>
                                        <div class="account-meta-note"><?php echo htmlspecialchars($contactPosition); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge-modern badge-role-admin">
                                            <i class="fas fa-building"></i>
                                            Provider
                                        </span>
                                    </td>
                                    <td>
                                        <div class="provider-status-block">
                                            <span class="badge-modern badge-status-<?php echo htmlspecialchars((string) ($provider['status'] ?? 'inactive')); ?>">
                                                <?php echo htmlspecialchars($statusOptions[(string) ($provider['status'] ?? '')] ?? ucwords(str_replace('_', ' ', (string) ($provider['status'] ?? 'inactive')))); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="account-date-text">
                                        <i class="far fa-calendar-alt account-date-icon"></i>
                                        <?php echo date('M d, Y', strtotime($provider['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons-modern">
                                            <a href="<?php echo htmlspecialchars($providerReviewsUrl); ?>"
                                               class="btn-inline-view"
                                               data-tooltip="Open Provider Reviews">
                                                <i class="fas fa-eye"></i>
                                                Reviews
                                            </a>
                                            <a href="<?php echo htmlspecialchars($editProviderUrl); ?>" 
                                               class="btn-icon btn-icon-edit" 
                                               data-tooltip="Edit Account">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if($providerId != $_SESSION['user_id']): ?>
                                            <form method="POST" action="../AdminController/user_process.php" class="account-inline-form account-delete-form" data-account-type="provider account" data-account-name="<?php echo htmlspecialchars($organization, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <?php echo csrfInputField('admin_account_management'); ?>
                                                <input type="hidden" name="user_id" value="<?php echo $providerId; ?>">
                                                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($deleteProviderToken); ?>">
                                                <button type="button" 
                                                        class="btn-icon btn-icon-delete" 
                                                        data-account-delete-trigger="true"
                                                        data-tooltip="Delete Account">
                                                    <i class="fas fa-trash-alt"></i>
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
            <?php endif; ?>
        </div>
    </section>

    <?php include 'layouts/admin_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const deleteButtons = document.querySelectorAll('[data-account-delete-trigger="true"]');

            deleteButtons.forEach((button) => {
                button.addEventListener('click', function () {
                    if (button.disabled) {
                        return;
                    }

                    const form = button.closest('.account-delete-form');
                    if (!form) {
                        return;
                    }

                    const accountType = form.getAttribute('data-account-type') || 'account';
                    const accountName = form.getAttribute('data-account-name') || '';
                    const titleTarget = accountName ? `"${accountName}"` : `this ${accountType}`;
                    const message = `Delete ${titleTarget}?`;
                    const detail = 'This action cannot be undone and will permanently remove the account record.';

                    if (window.Swal && typeof window.Swal.fire === 'function') {
                        window.Swal.fire({
                            title: message,
                            text: detail,
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#d33',
                            cancelButtonColor: '#64748b',
                            confirmButtonText: 'Yes, delete account',
                            cancelButtonText: 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                form.submit();
                            }
                        });
                        return;
                    }

                    if (window.confirm(message + '\n\n' + detail)) {
                        form.submit();
                    }
                });
            });
        });
    </script>
</body>
</html>

