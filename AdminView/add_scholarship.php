<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/provider_scope.php';
requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to add scholarships.');

$scholarshipOld = isset($_SESSION['scholarship_form_old']) && is_array($_SESSION['scholarship_form_old'])
    ? $_SESSION['scholarship_form_old']
    : [];
if (($scholarshipOld['action'] ?? '') !== 'add') {
    $scholarshipOld = [];
}
unset($_SESSION['scholarship_form_old']);

function scholarshipOldValue(array $oldInput, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $oldInput)) {
        return $default;
    }

    return trim((string) $oldInput[$key]);
}

function scholarshipOldSelected(array $oldInput, string $key, string $expected, string $default = ''): string
{
    $value = scholarshipOldValue($oldInput, $key, $default);
    return $value === $expected ? 'selected' : '';
}

function scholarshipReviewWorkflowReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'scholarship_data'
          AND COLUMN_NAME = 'review_status'
    ");
    $stmt->execute();
    $ready = ((int) $stmt->fetchColumn()) > 0;

    return $ready;
}

$selectedRequirements = [];
$requirementsJson = scholarshipOldValue($scholarshipOld, 'requirements', '[]');
$decodedRequirements = json_decode($requirementsJson, true);
if (is_array($decodedRequirements)) {
    $selectedRequirements = array_values(array_filter(array_map('strval', $decodedRequirements)));
}

$initialRemoteExamSites = [];
$remoteExamSitesJson = scholarshipOldValue($scholarshipOld, 'remote_exam_sites', '[]');
$decodedRemoteExamSites = json_decode($remoteExamSitesJson, true);
if (is_array($decodedRemoteExamSites)) {
    $initialRemoteExamSites = $decodedRemoteExamSites;
}

$statusValue = scholarshipOldValue($scholarshipOld, 'status', 'active');
$assessmentRequirementValue = scholarshipOldValue($scholarshipOld, 'assessment_requirement', 'none');
$allowIfAlreadyAcceptedValue = scholarshipOldValue($scholarshipOld, 'allow_if_already_accepted', '1');
$providerScope = getCurrentProviderScope($pdo);
$isProviderScopedUser = !empty($providerScope['is_provider']);
$currentProviderOrganization = $providerScope['organization_name'] ?? '';
$scholarshipReviewWorkflowReady = scholarshipReviewWorkflowReady($pdo);

if ($isProviderScopedUser && $scholarshipReviewWorkflowReady) {
    $statusValue = 'inactive';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <title>Add Scholarship - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../AdminPublic/css/manage-scholarship.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* Modern Dashboard Styles - Matching edit_scholarship.php */
        :root {
            --primary: #2c5aa0;
            --primary-dark: #1e3a6b;
            --primary-light: #4a7bc8;
            --secondary: #e63946;
            --success: #2a9d8f;
            --warning: #ffb703;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius: 12px;
        }

        body {
            background: #f5f7fb;
        }

        .page-header-modern {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius);
            padding: 30px;
            margin-bottom: 30px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .page-header-modern::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-header-modern h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header-modern p {
            opacity: 0.9;
            margin-bottom: 0;
            color: rgba(255, 255, 255, 0.92);
            max-width: 760px;
        }

        .back-link-modern {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            margin-bottom: 20px;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }

        .back-link-modern:hover {
            color: white;
            gap: 12px;
        }

        /* Form Layout */
        .form-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 30px;
        }

        .form-main {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .form-sidebar {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        /* Cards */
        .form-card-modern {
            background: white;
            border-radius: 1rem;
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
        }

        .card-header {
            padding: 20px 24px;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h3 i {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .card-body {
            padding: 24px;
        }

        /* Image Upload Section */
        .image-upload-container {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 20px;
        }

        .upload-area {
            border: 2px dashed var(--gray-300);
            border-radius: var(--radius);
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: var(--gray-50);
        }

        .upload-area i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .upload-area p {
            margin: 0;
            color: var(--gray-500);
            font-size: 0.85rem;
        }

        .image-preview {
            margin-top: 15px;
            display: none;
        }

        .image-preview.show {
            display: block;
        }

        .image-preview img {
            max-width: 100%;
            max-height: 150px;
            border-radius: var(--radius);
        }

        .selected-file-name {
            margin-top: 10px;
            padding: 8px 10px;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            background: white;
            color: var(--gray-600);
            font-size: 0.78rem;
            word-break: break-word;
        }

        /* Form Fields */
        .form-group-modern {
            margin-bottom: 20px;
        }

        .form-group-modern label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group-modern label i {
            margin-right: 6px;
            color: var(--primary);
        }

        .form-group-modern input,
        .form-group-modern textarea,
        .form-group-modern select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group-modern input:focus,
        .form-group-modern textarea:focus,
        .form-group-modern select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
        }

        .form-group-modern textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row-modern {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .helper-text {
            font-size: 0.7rem;
            color: var(--gray-500);
            margin-top: 5px;
            display: block;
        }

        .review-workflow-box {
            border: 1px solid #dbe6f4;
            background: linear-gradient(180deg, #f7f9ff 0%, #ffffff 100%);
            border-radius: var(--radius);
            padding: 18px;
        }

        .review-workflow-box strong {
            display: block;
            color: var(--gray-800);
            margin-bottom: 8px;
            font-size: 1rem;
        }

        .review-workflow-box p {
            margin: 0;
            color: var(--gray-600);
            line-height: 1.6;
            font-size: 0.9rem;
        }

        /* Status Toggle */
        .status-toggle {
            display: flex;
            gap: 15px;
            background: var(--gray-50);
            padding: 8px;
            border-radius: 50px;
        }

        .status-option {
            flex: 1;
            text-align: center;
            padding: 10px;
            border-radius: 40px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .status-option.active {
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .status-option.active[data-status="active"] {
            color: var(--success);
        }

        .status-option.active[data-status="inactive"] {
            color: var(--secondary);
        }

        /* Document Requirements Section */
        .requirements-card-header {
            align-items: flex-start;
            flex-direction: column;
            gap: 6px;
        }

        .requirements-card-header small {
            color: var(--gray-500);
            font-size: 0.88rem;
            line-height: 1.45;
            margin-left: 34px;
        }

        .requirements-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid var(--gray-100);
            transition: all 0.3s ease;
        }

        .requirement-item:hover {
            background: var(--gray-50);
        }

        .requirement-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .requirement-info i {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            border-radius: 8px;
            color: var(--primary);
        }

        .requirement-name {
            font-weight: 500;
            color: var(--gray-700);
        }

        .requirement-desc {
            font-size: 0.7rem;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .requirement-check {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        /* Action Buttons */
        .form-actions-modern {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-200);
        }

        .btn-modern {
            padding: 12px 24px;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .btn-primary-modern {
            background: var(--primary);
            color: white;
        }

        .btn-primary-modern:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-outline-modern {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
        }

        .btn-outline-modern:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Alerts */
        .alert-modern {
            padding: 15px 20px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        .alert-success-modern {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .alert-error-modern {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--secondary);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Location Info */
        .location-info {
            background: #eef5ff;
            border-radius: var(--radius);
            padding: 15px;
            margin-top: 15px;
        }

        .location-info i {
            color: var(--primary);
            margin-right: 8px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .form-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .form-row-modern {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .form-actions-modern {
                flex-direction: column;
            }
            
            .btn-modern {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard">
        <div class="container">
            <div class="page-header-modern">
                <a href="manage_scholarships.php" class="back-link-modern">
                    <i class="fas fa-arrow-left"></i>
                    Back to Scholarships
                </a>
                <h1>
                    <i class="fas fa-plus-circle"></i>
                    Add Scholarship
                </h1>
                <p>Create a new scholarship program with complete details, eligibility settings, and document requirements.</p>
            </div>

            <!-- Alerts -->
            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert-modern alert-success-modern">
                <i class="fas fa-check-circle fa-lg"></i>
                <p><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></p>
            </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert-modern alert-error-modern">
                <i class="fas fa-exclamation-circle fa-lg"></i>
                <p><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></p>
            </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['errors'])): ?>
            <div class="alert-modern alert-error-modern">
                <i class="fas fa-exclamation-circle fa-lg"></i>
                <ul style="margin: 0; padding-left: 20px;">
                    <?php foreach($_SESSION['errors'] as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; unset($_SESSION['errors']); ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Main Form -->
            <form method="POST" action="../app/AdminControllers/scholarship_process.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="requirements" id="requirementsInput" value="<?php echo htmlspecialchars($requirementsJson, ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="form-layout">
                    <!-- Main Content -->
                    <div class="form-main">
                        <!-- Basic Information Card -->
                        <div class="form-card-modern">
                            <div class="card-header">
                                <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group-modern">
                                    <label><i class="fas fa-tag"></i> Scholarship Name *</label>
                                    <input type="text" name="name" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'name')); ?>" placeholder="e.g., CHED Merit Scholarship Program" required>
                                </div>

                                <div class="form-group-modern">
                                    <label><i class="fas fa-align-left"></i> Description</label>
                                    <textarea name="description" rows="4" placeholder="Brief description of the scholarship..."><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'description')); ?></textarea>
                                </div>

                                <div class="form-row-modern">
                                      <div class="form-group-modern">
                                          <label><i class="fas fa-building"></i> Provider/Organization *</label>
                                        <input
                                            type="text"
                                            name="provider"
                                            value="<?php echo htmlspecialchars($isProviderScopedUser ? ($currentProviderOrganization !== '' ? $currentProviderOrganization : scholarshipOldValue($scholarshipOld, 'provider')) : scholarshipOldValue($scholarshipOld, 'provider')); ?>"
                                            placeholder="e.g., Commission on Higher Education"
                                            <?php echo $isProviderScopedUser ? 'readonly' : ''; ?>
                                            required
                                        >
                                        <?php if ($isProviderScopedUser): ?>
                                            <small class="helper-text">This is locked to your provider account organization.</small>
                                        <?php endif; ?>
                                      </div>
                                      <div class="form-group-modern">
                                          <label><i class="fas fa-calendar-alt"></i> Application Deadline *</label>
                                        <input type="date" name="deadline" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'deadline')); ?>" min="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                                    </div>
                                </div>

                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-door-open"></i> Application Opening Date</label>
                                        <input type="date" name="application_open_date" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'application_open_date')); ?>">
                                        <small class="helper-text">Optional: use this if applications only start on a specific date.</small>
                                    </div>
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-list-check"></i> Simple Application Process</label>
                                        <input type="text" name="application_process_label" maxlength="150" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'application_process_label')); ?>" placeholder="e.g., Documents + Interview">
                                        <small class="helper-text">Keep this short and student-friendly.</small>
                                    </div>
                                </div>

                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-chart-line"></i> Minimum GWA (1.00 is highest)</label>
                                        <input type="number" name="min_gwa" step="0.01" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'min_gwa')); ?>" min="1.00" max="5.00" placeholder="e.g., 2.00">
                                        <small class="helper-text">Optional: leave blank if there is no GWA requirement</small>
                                    </div>
                                </div>
                                <div class="form-card-modern" style="margin-top: 24px; border: 1px solid var(--gray-200);">
                                    <div class="card-header">
                                        <h3><i class="fas fa-user-graduate"></i> Target Applicants</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-users"></i> Target Applicant Type</label>
                                                <select name="target_applicant_type" id="targetApplicantTypeSelect">
                                                    <option value="all" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'all', 'all'); ?>>All Applicants</option>
                                                    <option value="incoming_freshman" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'incoming_freshman'); ?>>Incoming Freshman</option>
                                                    <option value="current_college" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'current_college'); ?>>Current College Student</option>
                                                    <option value="transferee" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'transferee'); ?>>Transferee</option>
                                                    <option value="continuing_student" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'continuing_student'); ?>>Continuing Student</option>
                                                </select>
                                                <small class="helper-text">Use this when a scholarship is only for incoming or already-enrolled students.</small>
                                            </div>
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-layer-group"></i> Target Year Level</label>
                                                <select name="target_year_level" id="targetYearLevelSelect">
                                                    <option value="any" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', 'any', 'any'); ?>>Any Year Level</option>
                                                    <option value="1st_year" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '1st_year'); ?>>1st Year</option>
                                                    <option value="2nd_year" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '2nd_year'); ?>>2nd Year</option>
                                                    <option value="3rd_year" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '3rd_year'); ?>>3rd Year</option>
                                                    <option value="4th_year" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '4th_year'); ?>>4th Year</option>
                                                    <option value="5th_year_plus" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '5th_year_plus'); ?>>5th Year+</option>
                                                </select>
                                                <small class="helper-text" id="targetYearLevelHelper">Use this only when the scholarship is limited to a specific college year level.</small>
                                            </div>
                                        </div>

                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-clipboard-check"></i> Minimum Admission Status</label>
                                                <select name="required_admission_status">
                                                    <option value="any" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'any', 'any'); ?>>Any Admission Status</option>
                                                    <option value="not_yet_applied" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'not_yet_applied'); ?>>Not Yet Applied</option>
                                                    <option value="applied" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'applied'); ?>>Applied</option>
                                                    <option value="admitted" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'admitted'); ?>>Admitted</option>
                                                    <option value="enrolled" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'enrolled'); ?>>Enrolled</option>
                                                </select>
                                                <small class="helper-text">Example: choose Admitted if applicants must already have an acceptance result.</small>
                                            </div>
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-sitemap"></i> Preferred SHS Strand</label>
                                                <input type="text" id="targetStrandInput" name="target_strand" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'target_strand')); ?>" placeholder="Optional: STEM, ABM, HUMSS">
                                                <small class="helper-text" id="targetStrandHelper">Leave blank if the scholarship is open to any strand.</small>
                                            </div>
                                        </div>

                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-award"></i> Accepted Scholarship Rule</label>
                                                <select name="allow_if_already_accepted" id="allowIfAcceptedSelect">
                                                    <option value="1" <?php echo scholarshipOldSelected($scholarshipOld, 'allow_if_already_accepted', '1', $allowIfAlreadyAcceptedValue); ?>>Can still apply after accepting another scholarship</option>
                                                    <option value="0" <?php echo scholarshipOldSelected($scholarshipOld, 'allow_if_already_accepted', '0', $allowIfAlreadyAcceptedValue); ?>>Cannot apply after accepting another scholarship</option>
                                                </select>
                                                <small class="helper-text" id="allowIfAcceptedHelper">Choose whether students who already accepted another scholarship offer may still submit to this scholarship.</small>
                                            </div>
                                        </div>

                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-flag"></i> Target Citizenship</label>
                                                <select name="target_citizenship">
                                                    <option value="all" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'all', 'all'); ?>>All Citizenship Types</option>
                                                    <option value="filipino" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'filipino'); ?>>Filipino</option>
                                                    <option value="dual_citizen" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'dual_citizen'); ?>>Dual Citizen</option>
                                                    <option value="permanent_resident" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'permanent_resident'); ?>>Permanent Resident</option>
                                                    <option value="other" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'other'); ?>>Other</option>
                                                </select>
                                                <small class="helper-text">Use this only when the scholarship is limited to a specific citizenship or residency profile.</small>
                                            </div>
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-wallet"></i> Target Household Income Bracket</label>
                                                <select name="target_income_bracket">
                                                    <option value="any" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', 'any', 'any'); ?>>Any Income Bracket</option>
                                                    <option value="below_10000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', 'below_10000'); ?>>Below PHP 10,000 / month</option>
                                                    <option value="10000_20000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', '10000_20000'); ?>>PHP 10,000 - 20,000 / month</option>
                                                    <option value="20001_40000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', '20001_40000'); ?>>PHP 20,001 - 40,000 / month</option>
                                                    <option value="40001_80000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', '40001_80000'); ?>>PHP 40,001 - 80,000 / month</option>
                                                    <option value="above_80000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', 'above_80000'); ?>>Above PHP 80,000 / month</option>
                                                </select>
                                                <small class="helper-text">Use this when the scholarship is intended for a specific declared income bracket.</small>
                                            </div>
                                        </div>

                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-hands-helping"></i> Target Special Category</label>
                                                <select name="target_special_category">
                                                    <option value="any" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'any', 'any'); ?>>Any Special Category</option>
                                                    <option value="pwd" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'pwd'); ?>>Person with Disability (PWD)</option>
                                                    <option value="indigenous_peoples" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'indigenous_peoples'); ?>>Indigenous Peoples</option>
                                                    <option value="solo_parent_dependent" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'solo_parent_dependent'); ?>>Dependent of Solo Parent</option>
                                                    <option value="working_student" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'working_student'); ?>>Working Student</option>
                                                    <option value="child_of_ofw" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'child_of_ofw'); ?>>Child of OFW</option>
                                                    <option value="four_ps_beneficiary" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'four_ps_beneficiary'); ?>>4Ps Beneficiary</option>
                                                    <option value="orphan" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'orphan'); ?>>Orphan / Ward</option>
                                                </select>
                                                <small class="helper-text">Choose a category only when the scholarship is reserved for a defined applicant group.</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group-modern">
                                    <label><i class="fas fa-check-circle"></i> Eligibility Requirements</label>
                                    <textarea name="eligibility" rows="4" placeholder="List the eligibility criteria for this scholarship..."><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'eligibility')); ?></textarea>
                                </div>

                                <div class="form-group-modern">
                                    <label><i class="fas fa-gift"></i> Benefits/Provisions</label>
                                    <textarea name="benefits" rows="4" placeholder="List the benefits (e.g., Tuition fee, Monthly stipend, Book allowance)"><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'benefits')); ?></textarea>
                                </div>

                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-laptop"></i> Online Requirement</label>
                                        <select name="assessment_requirement">
                                            <option value="none" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'none', 'none'); ?>>No online exam/assessment</option>
                                            <option value="online_exam" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'online_exam'); ?>>Online Exam</option>
                                            <option value="remote_examination" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'remote_examination'); ?>>Remote Examination</option>
                                            <option value="assessment" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'assessment'); ?>>Online Assessment</option>
                                            <option value="evaluation" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'evaluation'); ?>>Online Evaluation</option>
                                        </select>
                                    </div>
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-link"></i> Exam/Assessment Link</label>
                                        <input type="url" name="assessment_link" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'assessment_link')); ?>" placeholder="https://example.com/assessment">
                                        <small class="helper-text">Optional link for students</small>
                                    </div>
                                </div>
                                <div class="form-group-modern">
                                    <label><i class="fas fa-note-sticky"></i> Assessment Details</label>
                                    <textarea name="assessment_details" rows="3" placeholder="Instructions, schedule, or evaluation notes"><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'assessment_details')); ?></textarea>
                                </div>

                                <div class="form-card-modern" style="margin-top: 24px; border: 1px solid var(--gray-200);">
                                    <div class="card-header">
                                        <h3><i class="fas fa-user-check"></i> Student-Facing Details</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-group-modern">
                                            <label><i class="fas fa-route"></i> What Happens After Applying</label>
                                            <textarea name="post_application_steps" rows="3" placeholder="Example: After submission, the provider reviews documents, shortlists applicants, then releases the final decision."><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'post_application_steps')); ?></textarea>
                                            <small class="helper-text">Explain the next step in simple language so students know what to expect.</small>
                                        </div>

                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-rotate"></i> Renewal Conditions</label>
                                                <textarea name="renewal_conditions" rows="3" placeholder="Example: Maintain a 2.00 GWA, no failing grades, and renew every semester."><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'renewal_conditions')); ?></textarea>
                                            </div>
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-triangle-exclamation"></i> Restrictions / Obligations</label>
                                                <textarea name="scholarship_restrictions" rows="3" placeholder="Example: Full-time enrollment only, selected campuses only, or return-service obligation."><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'scholarship_restrictions')); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div id="remoteExamSitesContainer" class="form-group-modern" style="display: none;">
                                    <label><i class="fas fa-map-location-dot"></i> Remote Examination Sites</label>
                                    <input type="hidden" name="remote_exam_sites" id="remoteExamSitesInput" value="<?php echo htmlspecialchars($remoteExamSitesJson, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div id="remoteExamSitesList" style="display: flex; flex-direction: column; gap: 12px;"></div>
                                    <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px;">
                                        <button type="button" class="btn-modern btn-outline-modern" id="addRemoteExamSiteBtn">
                                            <i class="fas fa-plus"></i> Add Remote Exam Site
                                        </button>
                                    </div>
                                    <small class="helper-text">
                                        Add multiple addresses, then click Set Pin for each site to save map coordinates in the background.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Location Information Card -->
                        <div class="form-card-modern">
                            <div class="card-header">
                                <h3><i class="fas fa-map-marker-alt"></i> Location Information</h3>
                            </div>
                            <div class="card-body">
                                <div class="location-info" style="margin-bottom: 10px;">
                                    <i class="fas fa-info-circle"></i>
                                    <small>Set a pin on the map, use "Set Pin from Address", or use device location. Coordinates are stored in the background.</small>
                                </div>
                                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
                                    <button type="button" class="btn-modern btn-outline-modern open-scholarship-location-modal">
                                        <i class="fas fa-map-pin"></i> Set Pin on Map
                                    </button>
                                </div>
                                <input type="hidden" id="latitudeInput" name="latitude" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'latitude')); ?>">
                                <input type="hidden" id="longitudeInput" name="longitude" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'longitude')); ?>">
                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-location-dot"></i> Address</label>
                                        <input type="text" id="addressInput" name="address" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'address')); ?>" placeholder="Street address">
                                    </div>
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-city"></i> City</label>
                                        <input type="text" id="cityInput" name="city" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'city')); ?>" placeholder="City">
                                    </div>
                                </div>
                                <div class="form-group-modern">
                                    <label><i class="fas fa-map"></i> Province</label>
                                    <input type="text" id="provinceInput" name="province" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'province')); ?>" placeholder="Province">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="form-sidebar">
                        <!-- Status Card -->
                        <div class="form-card-modern">
                            <div class="card-header">
                                <h3><i class="fas <?php echo ($isProviderScopedUser && $scholarshipReviewWorkflowReady) ? 'fa-shield-halved' : 'fa-toggle-on'; ?>"></i> <?php echo ($isProviderScopedUser && $scholarshipReviewWorkflowReady) ? 'Scholarship Review' : 'Scholarship Status'; ?></h3>
                            </div>
                            <div class="card-body">
                                <?php if ($isProviderScopedUser && $scholarshipReviewWorkflowReady): ?>
                                <div class="review-workflow-box">
                                    <strong>Submitted for admin review</strong>
                                    <p>New provider scholarships stay hidden until an administrator confirms the scholarship details and approves publication.</p>
                                </div>
                                <input type="hidden" name="status" id="statusInput" value="inactive">
                                <small class="helper-text" style="display: block; margin-top: 15px;">
                                    <i class="fas fa-info-circle"></i>
                                    You can review the submission status later in Scholarship Reviews.
                                </small>
                                <?php else: ?>
                                <div class="status-toggle">
                                    <div class="status-option <?php echo $statusValue === 'active' ? 'active' : ''; ?>" data-status="active" onclick="setStatus('active')">
                                        <i class="fas fa-check-circle"></i> Active
                                    </div>
                                    <div class="status-option <?php echo $statusValue === 'inactive' ? 'active' : ''; ?>" data-status="inactive" onclick="setStatus('inactive')">
                                        <i class="fas fa-ban"></i> Inactive
                                    </div>
                                </div>
                                <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($statusValue); ?>">
                                <small class="helper-text" style="display: block; margin-top: 15px;">
                                    <i class="fas fa-info-circle"></i> 
                                    Inactive scholarships won't be visible to students
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Image Upload Card -->
                        <div class="form-card-modern">
                            <div class="card-header">
                                <h3><i class="fas fa-image"></i> Provider Image</h3>
                            </div>
                            <div class="card-body">
                                <div class="image-upload-container">
                                    <div class="upload-area" onclick="document.getElementById('provider_image').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Click to upload provider image/logo</p>
                                        <input type="file" id="provider_image" name="provider_image" accept="image/jpeg,image/png,image/gif,image/jpg" style="display: none;">
                                    </div>
                                    <div id="selectedImageName" class="selected-file-name">No image selected</div>
                                    <div id="imagePreview" class="image-preview">
                                        <img id="preview" src="#" alt="Preview">
                                    </div>
                                    <small class="helper-text" style="margin-top: 10px;">
                                        <i class="fas fa-info-circle"></i> 
                                        Allowed: JPG, JPEG, PNG, GIF. Max 2MB. Recommended: 500x500px
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Document Requirements Card -->
                        <div class="form-card-modern">
                            <div class="card-header requirements-card-header">
                                <h3><i class="fas fa-file-alt"></i> Document Requirements</h3>
                                <small>Required documents for applicants</small>
                            </div>
                            <div class="card-body">
                                <div class="requirements-list" id="documentTypesList">
                                    <!-- Document types will be loaded dynamically -->
                                    <div style="text-align: center; padding: 20px; color: var(--gray-500);">
                                        <i class="fas fa-spinner fa-spin"></i> Loading document types...
                                    </div>
                                </div>
                                <small class="helper-text" style="display: block; margin-top: 15px;">
                                    <i class="fas fa-info-circle"></i> 
                                    Check all documents that applicants must submit for this scholarship
                                </small>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions-modern">
                            <button type="submit" class="btn-modern btn-primary-modern">
                                <i class="fas fa-save"></i> Create Scholarship
                            </button>
                            <a href="manage_scholarships.php" class="btn-modern btn-outline-modern">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php
    $locationLatitude = scholarshipOldValue($scholarshipOld, 'latitude');
    $locationLongitude = scholarshipOldValue($scholarshipOld, 'longitude');
    $locationAddress = scholarshipOldValue($scholarshipOld, 'address');
    $locationCity = scholarshipOldValue($scholarshipOld, 'city');
    $locationProvince = scholarshipOldValue($scholarshipOld, 'province');
    include 'partials/scholarship_location_modal.php';
    ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const initialRequirementCodes = <?php echo json_encode($selectedRequirements, JSON_UNESCAPED_UNICODE); ?>;
        const initialRemoteExamSites = <?php echo json_encode($initialRemoteExamSites, JSON_UNESCAPED_UNICODE); ?>;

        function showScholarshipAlert(title, text = '', icon = 'warning') {
            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({
                    icon,
                    title,
                    text,
                    confirmButtonColor: '#2c5aa0'
                });
                return;
            }

            window.alert(text || title);
        }

        // Status toggle
        function setStatus(status) {
            document.getElementById('statusInput').value = status;
            document.querySelectorAll('.status-option').forEach(opt => {
                opt.classList.remove('active');
                if (opt.dataset.status === status) {
                    opt.classList.add('active');
                }
            });
        }

        // Update document requirements
        function updateRequirements() {
            const checkboxes = document.querySelectorAll('.requirement-check:checked');
            const requirements = Array.from(checkboxes).map(cb => cb.value);
            document.getElementById('requirementsInput').value = JSON.stringify(requirements);
        }

        // Image preview
        document.getElementById('provider_image').addEventListener('change', function(e) {
            const preview = document.getElementById('preview');
            const previewContainer = document.getElementById('imagePreview');
            const selectedImageName = document.getElementById('selectedImageName');
            const file = e.target.files[0];
            
            if (file) {
                if (file.size > 2 * 1024 * 1024) {
                    showScholarshipAlert('Image Too Large', 'File size must be less than 2MB.');
                    this.value = '';
                    previewContainer.classList.remove('show');
                    selectedImageName.textContent = 'No image selected';
                    return;
                }
                
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    showScholarshipAlert('Invalid Image File', 'Please upload a valid image file (JPG, JPEG, PNG, GIF).');
                    this.value = '';
                    previewContainer.classList.remove('show');
                    selectedImageName.textContent = 'No image selected';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewContainer.classList.add('show');
                }
                reader.readAsDataURL(file);
                selectedImageName.textContent = `Selected: ${file.name}`;
            } else {
                previewContainer.classList.remove('show');
                selectedImageName.textContent = 'No image selected';
            }
        });

        // Load document types dynamically
        async function loadDocumentTypes() {
            const container = document.getElementById('documentTypesList');
            try {
                const response = await fetch('../app/AdminControllers/get_document_types.php');
                const data = await response.json();
                
                if (data.success && data.documentTypes) {
                    container.innerHTML = '';
                    data.documentTypes.forEach(doc => {
                        const iconClass = resolveRequirementIconClass(doc);

                        const itemDiv = document.createElement('div');
                        itemDiv.className = 'requirement-item';
                        itemDiv.innerHTML = `
                            <div class="requirement-info">
                                <i class="${iconClass}" aria-hidden="true"></i>
                                <div>
                                    <div class="requirement-name">${escapeHtml(doc.name)}</div>
                                    <div class="requirement-desc">${escapeHtml(doc.description || '')}</div>
                                </div>
                            </div>
                            <input type="checkbox" 
                                   class="requirement-check" 
                                   value="${escapeHtml(doc.code)}"
                                   onchange="updateRequirements()">
                        `;
                        const checkbox = itemDiv.querySelector('.requirement-check');
                        if (checkbox && initialRequirementCodes.includes(String(doc.code))) {
                            checkbox.checked = true;
                        }
                        container.appendChild(itemDiv);
                    });
                    updateRequirements();
                } else {
                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--gray-500);"><i class="fas fa-exclamation-triangle"></i> Failed to load document types</div>';
                }
            } catch (error) {
                console.error('Error loading document types:', error);
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--gray-500);"><i class="fas fa-exclamation-triangle"></i> Error loading document types</div>';
            }
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function resolveRequirementIconClass(doc) {
            const code = String(doc && doc.code ? doc.code : '').trim().toLowerCase();
            const name = String(doc && doc.name ? doc.name : '').trim().toLowerCase();
            const description = String(doc && doc.description ? doc.description : '').trim().toLowerCase();
            const haystack = `${code} ${name} ${description}`;

            const directMap = {
                id: 'fas fa-id-card',
                birth_certificate: 'fas fa-certificate',
                grades: 'fas fa-scroll',
                form_138: 'fas fa-file-lines',
                good_moral: 'fas fa-circle-check',
                enrollment: 'fas fa-user-graduate',
                income_tax: 'fas fa-file-invoice-dollar',
                citizenship_proof: 'fas fa-flag',
                income_proof: 'fas fa-wallet',
                special_category_proof: 'fas fa-users',
                certificate_of_indigency: 'fas fa-file-contract',
                voters_id: 'fas fa-id-badge',
                barangay_clearance: 'fas fa-file-circle-check',
                medical_certificate: 'fas fa-file-medical',
                essay: 'fas fa-pen',
                recommendation: 'fas fa-envelope-open-text'
            };

            if (directMap[code]) {
                return directMap[code];
            }

            const keywordMap = [
                { icon: 'fas fa-id-card', keywords: ['valid id', 'government id', 'school id', 'passport', 'license'] },
                { icon: 'fas fa-certificate', keywords: ['birth', 'certificate'] },
                { icon: 'fas fa-scroll', keywords: ['grades', 'grade slip', 'transcript', 'tor', 'academic record', 'report card', 'form 138'] },
                { icon: 'fas fa-circle-check', keywords: ['good moral', 'moral'] },
                { icon: 'fas fa-user-graduate', keywords: ['enrollment', 'enrolment', 'registration form', 'admission'] },
                { icon: 'fas fa-file-invoice-dollar', keywords: ['income tax', 'itr', 'tax return', 'payslip'] },
                { icon: 'fas fa-wallet', keywords: ['income proof', 'household income', 'indigency', 'financial'] },
                { icon: 'fas fa-flag', keywords: ['citizenship', 'residency', 'resident', 'voter'] },
                { icon: 'fas fa-file-medical', keywords: ['medical', 'health'] },
                { icon: 'fas fa-envelope-open-text', keywords: ['recommendation', 'reference letter'] },
                { icon: 'fas fa-pen', keywords: ['essay', 'statement'] },
                { icon: 'fas fa-users', keywords: ['special category', 'pwd', 'solo parent', '4ps', 'ofw', 'indigenous'] }
            ];

            for (const entry of keywordMap) {
                if (entry.keywords.some((keyword) => haystack.includes(keyword))) {
                    return entry.icon;
                }
            }

            return 'fas fa-file-lines';
        }

        function createRemoteExamSiteRow(site = {}) {
            const wrapper = document.createElement('div');
            wrapper.className = 'remote-exam-site-row';
            wrapper.style.cssText = 'border: 1px solid var(--gray-200); border-radius: 10px; padding: 12px; background: #f8fafc;';
            wrapper.innerHTML = `
                <div class="form-row-modern" style="margin-bottom: 10px;">
                    <div class="form-group-modern" style="margin-bottom: 0;">
                        <label>Site Name</label>
                        <input type="text" data-field="site_name" placeholder="e.g., Remote Testing Hub A">
                    </div>
                    <div class="form-group-modern" style="margin-bottom: 0;">
                        <label>Address</label>
                        <input type="text" data-field="address" placeholder="Street / Building">
                    </div>
                </div>
                <div class="form-row-modern" style="margin-bottom: 10px;">
                    <div class="form-group-modern" style="margin-bottom: 0;">
                        <label>City</label>
                        <input type="text" data-field="city" placeholder="City">
                    </div>
                    <div class="form-group-modern" style="margin-bottom: 0;">
                        <label>Province</label>
                        <input type="text" data-field="province" placeholder="Province">
                    </div>
                </div>
                <input type="hidden" data-field="latitude">
                <input type="hidden" data-field="longitude">
                <small class="helper-text" style="display: block; margin-bottom: 10px;">
                    Pin coordinates stay hidden and are saved automatically when you use Set Pin or Use Main Pin.
                </small>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="btn-modern btn-outline-modern set-remote-pin-btn">
                        <i class="fas fa-map-marker-alt"></i> Set Pin
                    </button>
                    <button type="button" class="btn-modern btn-outline-modern use-main-pin-btn">
                        <i class="fas fa-map-pin"></i> Use Main Pin
                    </button>
                    <button type="button" class="btn-modern btn-outline-modern remove-remote-site-btn" style="border-color: #fecaca; color: #b91c1c;">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            `;

            Object.entries(site).forEach(([field, value]) => {
                const input = wrapper.querySelector(`[data-field="${field}"]`);
                if (input && value !== null && value !== undefined) {
                    input.value = value;
                }
            });

            return wrapper;
        }

        function collectRemoteExamSites() {
            const rows = document.querySelectorAll('#remoteExamSitesList .remote-exam-site-row');
            const sites = [];
            rows.forEach((row) => {
                const get = (field) => (row.querySelector(`[data-field="${field}"]`)?.value || '').trim();
                const site = {
                    site_name: get('site_name'),
                    address: get('address'),
                    city: get('city'),
                    province: get('province'),
                    latitude: get('latitude'),
                    longitude: get('longitude')
                };
                if (site.site_name || site.address || site.city || site.province || site.latitude || site.longitude) {
                    sites.push(site);
                }
            });
            return sites;
        }

        function syncRemoteExamSites() {
            const input = document.getElementById('remoteExamSitesInput');
            if (!input) return;
            input.value = JSON.stringify(collectRemoteExamSites());
        }

        function toggleRemoteExamSites() {
            const select = document.querySelector('select[name="assessment_requirement"]');
            const container = document.getElementById('remoteExamSitesContainer');
            if (!select || !container) return;
            container.style.display = select.value === 'remote_examination' ? 'block' : 'none';
        }

        function syncTargetApplicantFields() {
            const applicantTypeSelect = document.getElementById('targetApplicantTypeSelect');
            const yearLevelSelect = document.getElementById('targetYearLevelSelect');
            const yearLevelHelper = document.getElementById('targetYearLevelHelper');
            const targetStrandInput = document.getElementById('targetStrandInput');
            const targetStrandHelper = document.getElementById('targetStrandHelper');
            if (!applicantTypeSelect || !yearLevelSelect || !targetStrandInput) {
                return;
            }

            const applicantType = applicantTypeSelect.value;
            const isIncomingFreshman = applicantType === 'incoming_freshman';
            const isCollegeApplicantType = ['current_college', 'transferee', 'continuing_student'].includes(applicantType);

            if (!isCollegeApplicantType) {
                yearLevelSelect.value = 'any';
            }

            yearLevelSelect.disabled = !isCollegeApplicantType;
            yearLevelSelect.setAttribute('aria-disabled', !isCollegeApplicantType ? 'true' : 'false');

            if (yearLevelHelper) {
                yearLevelHelper.textContent = isCollegeApplicantType
                    ? 'Use this only when the scholarship is limited to a specific college year level.'
                    : 'Target Year Level only applies to current college, transferee, or continuing student scholarships.';
            }

            if (!isIncomingFreshman) {
                targetStrandInput.value = '';
            }

            targetStrandInput.disabled = !isIncomingFreshman;
            targetStrandInput.setAttribute('aria-disabled', !isIncomingFreshman ? 'true' : 'false');

            if (targetStrandHelper) {
                targetStrandHelper.textContent = isIncomingFreshman
                    ? 'Leave blank if the incoming-freshman scholarship is open to any strand.'
                    : 'Preferred SHS Strand only applies to incoming freshman scholarships.';
            }
        }

        function initializeRemoteExamSites(initialSites = []) {
            const list = document.getElementById('remoteExamSitesList');
            const addBtn = document.getElementById('addRemoteExamSiteBtn');
            if (!list || !addBtn) return;

            const addRow = (site = {}) => {
                const row = createRemoteExamSiteRow(site);
                list.appendChild(row);

                row.querySelectorAll('input').forEach((input) => {
                    input.addEventListener('input', syncRemoteExamSites);
                });

                const removeBtn = row.querySelector('.remove-remote-site-btn');
                if (removeBtn) {
                    removeBtn.addEventListener('click', () => {
                        row.remove();
                        syncRemoteExamSites();
                    });
                }

                const useMainPinBtn = row.querySelector('.use-main-pin-btn');
                if (useMainPinBtn) {
                    useMainPinBtn.addEventListener('click', () => {
                        const mainLat = document.getElementById('latitudeInput')?.value || '';
                        const mainLng = document.getElementById('longitudeInput')?.value || '';
                        row.querySelector('[data-field="latitude"]').value = mainLat;
                        row.querySelector('[data-field="longitude"]').value = mainLng;
                        syncRemoteExamSites();
                    });
                }

                const setRemotePinBtn = row.querySelector('.set-remote-pin-btn');
                if (setRemotePinBtn) {
                    setRemotePinBtn.addEventListener('click', () => {
                        if (typeof window.openScholarshipLocationModal !== 'function') {
                            showScholarshipAlert('Map Picker Unavailable', 'Map pin picker is currently unavailable.');
                            return;
                        }

                        window.openScholarshipLocationModal({
                            title: 'Select Remote Examination Site Pin',
                            latInput: row.querySelector('[data-field="latitude"]'),
                            lngInput: row.querySelector('[data-field="longitude"]'),
                            addressInput: row.querySelector('[data-field="address"]'),
                            cityInput: row.querySelector('[data-field="city"]'),
                            provinceInput: row.querySelector('[data-field="province"]'),
                            onApply: function() {
                                syncRemoteExamSites();
                            }
                        });
                    });
                }

                syncRemoteExamSites();
            };

            if (Array.isArray(initialSites) && initialSites.length > 0) {
                initialSites.forEach((site) => addRow(site));
            }

            addBtn.addEventListener('click', () => addRow({}));

            const select = document.querySelector('select[name="assessment_requirement"]');
            if (select) {
                select.addEventListener('change', toggleRemoteExamSites);
            }

            toggleRemoteExamSites();
            if (select && select.value === 'remote_examination' && list.children.length === 0) {
                addRow({});
            }
            syncRemoteExamSites();
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            loadDocumentTypes();
            updateRequirements();
            initializeRemoteExamSites(initialRemoteExamSites);
            syncTargetApplicantFields();
            setStatus(document.getElementById('statusInput').value || 'active');

            const applicantTypeSelect = document.getElementById('targetApplicantTypeSelect');
            if (applicantTypeSelect) {
                applicantTypeSelect.addEventListener('change', syncTargetApplicantFields);
            }
        });
    </script>

    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
