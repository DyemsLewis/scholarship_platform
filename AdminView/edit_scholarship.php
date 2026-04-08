<?php
// edit_scholarship.php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/provider_scope.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Models/Scholarship.php';
require_once __DIR__ . '/../app/Models/ScholarshipData.php';
require_once __DIR__ . '/../app/Models/ScholarshipLocation.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to edit scholarships.');

$scholarshipIdParam = $_GET['id'] ?? null;
if ($scholarshipIdParam === null || $scholarshipIdParam === '' || !is_numeric($scholarshipIdParam)) {
    header('Location: manage_scholarships.php');
    exit();
}
$scholarship_id = (int) $scholarshipIdParam;
requireValidEntityUrlToken('scholarship', $scholarship_id, $_GET['token'] ?? null, 'edit', 'manage_scholarships.php', 'Invalid or expired scholarship access link.');

// Initialize models
$scholarshipModel = new Scholarship($pdo);
$scholarshipDataModel = new ScholarshipData($pdo);
$scholarshipLocationModel = new ScholarshipLocation($pdo);

// Get scholarship details with all related data
$scholarship = $scholarshipModel->find($scholarship_id);
if (!$scholarship) {
    $_SESSION['error'] = 'Scholarship not found';
    header('Location: manage_scholarships.php');
    exit();
}

// Get scholarship data
$scholarshipData = $scholarshipDataModel->getByScholarshipId($scholarship_id);
$scholarshipLocation = $scholarshipLocationModel->getByScholarshipId($scholarship_id);

if (!providerCanAccessScholarship($pdo, $scholarship_id)) {
    $_SESSION['error'] = 'You can only edit scholarships posted by your organization.';
    header('Location: manage_scholarships.php');
    exit();
}

// Get document types for requirements
$stmt = $pdo->query("SELECT * FROM document_types ORDER BY name");
$documentTypes = $stmt->fetchAll();

// Get current document requirements for this scholarship
$stmt = $pdo->prepare("
    SELECT dr.*, dt.name as document_name 
    FROM document_requirements dr
    JOIN document_types dt ON dr.document_type = dt.code
    WHERE dr.scholarship_id = ?
");
$stmt->execute([$scholarship_id]);
$currentRequirements = $stmt->fetchAll();
$requiredDocs = array_column($currentRequirements, 'document_type');

$remoteExamSites = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, site_name, address, city, province, latitude, longitude
        FROM scholarship_remote_exam_locations
        WHERE scholarship_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$scholarship_id]);
    $remoteExamSites = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $remoteExamSites = [];
}

$scholarshipOld = isset($_SESSION['scholarship_form_old']) && is_array($_SESSION['scholarship_form_old'])
    ? $_SESSION['scholarship_form_old']
    : [];
if (($scholarshipOld['action'] ?? '') !== 'update' || (int) ($scholarshipOld['id'] ?? 0) !== (int) $scholarship_id) {
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

$selectedRequirements = $requiredDocs;
if (array_key_exists('requirements', $scholarshipOld)) {
    $decodedRequirements = json_decode((string) $scholarshipOld['requirements'], true);
    $selectedRequirements = is_array($decodedRequirements)
        ? array_values(array_filter(array_map('strval', $decodedRequirements)))
        : [];
}

$initialRemoteExamSites = $remoteExamSites;
if (array_key_exists('remote_exam_sites', $scholarshipOld)) {
    $decodedRemoteExamSites = json_decode((string) $scholarshipOld['remote_exam_sites'], true);
    $initialRemoteExamSites = is_array($decodedRemoteExamSites) ? $decodedRemoteExamSites : [];
}

$statusValue = scholarshipOldValue($scholarshipOld, 'status', (string) ($scholarship['status'] ?? 'active'));
$assessmentRequirementValue = scholarshipOldValue($scholarshipOld, 'assessment_requirement', strtolower((string) ($scholarshipData['assessment_requirement'] ?? 'none')));
$currentImageValue = scholarshipOldValue($scholarshipOld, 'current_image', (string) ($scholarshipData['image'] ?? ''));
$removeImageChecked = scholarshipOldValue($scholarshipOld, 'remove_image') !== '';
$providerScope = getCurrentProviderScope($pdo);
$isProviderScopedUser = !empty($providerScope['is_provider']);
$currentProviderOrganization = $providerScope['organization_name'] ?? '';
$scholarshipReviewWorkflowReady = scholarshipReviewWorkflowReady($pdo);
$reviewStatusValue = strtolower(trim((string) ($scholarshipData['review_status'] ?? 'approved')));
$canReviewScholarship = canAccessScholarshipApprovals();
$reviewActionToken = buildEntityUrlToken('scholarship', $scholarship_id, 'review');
$adminReviewStateVisible = !$isProviderScopedUser && $scholarshipReviewWorkflowReady && $canReviewScholarship && in_array($reviewStatusValue, ['pending', 'rejected'], true);

if ($isProviderScopedUser && $scholarshipReviewWorkflowReady && in_array($reviewStatusValue, ['pending', 'rejected'], true)) {
    $statusValue = 'inactive';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <title>Edit Scholarship - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* Modern Dashboard Styles */
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
            background: var(--gray-50);
        }

        /* Page Header */
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
        }

        .page-header-modern p {
            opacity: 0.9;
            margin-bottom: 0;
        }

        .back-link-modern {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            margin-bottom: 20px;
            transition: all 0.3s ease;
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
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card-header {
            padding: 20px 24px;
            background: white;
            border-bottom: 2px solid var(--gray-100);
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

        .current-image-preview {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 15px;
            background: white;
            border-radius: var(--radius);
            margin-bottom: 20px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .current-image-preview img {
            width: 96px;
            height: 96px;
            object-fit: contain;
            border-radius: var(--radius);
            border: 2px solid var(--gray-200);
            background: var(--gray-50);
            flex-shrink: 0;
        }

        .image-info p {
            margin: 0 0 5px 0;
            color: var(--gray-600);
            font-size: 0.85rem;
        }

        .image-info {
            flex: 1;
            min-width: 0;
        }

        .current-image-name {
            margin: 0 0 6px 0;
            color: var(--gray-600);
            font-size: 0.82rem;
            line-height: 1.35;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .remove-checkbox {
            margin-top: 10px;
        }

        .remove-checkbox label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--secondary);
            font-size: 0.85rem;
            cursor: pointer;
        }

        .remove-checkbox input {
            margin-top: 2px;
            flex-shrink: 0;
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

        .review-action-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary-modern:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
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
            background: linear-gradient(135deg, #e8f4fd, #d9eafb);
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
            <!-- Modern Page Header -->
            <div class="page-header-modern">
                <a href="manage_scholarships.php" class="back-link-modern">
                    <i class="fas fa-arrow-left"></i> Back to Scholarships
                </a>
                <h1><i class="fas fa-edit"></i> Edit Scholarship</h1>
                <p>Update scholarship information and manage document requirements</p>
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
            <form id="editScholarshipForm" method="POST" action="../app/AdminControllers/scholarship_process.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo $scholarship['id']; ?>">
                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars(buildEntityUrlToken('scholarship', (int) $scholarship['id'], 'update')); ?>">
                <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($currentImageValue); ?>">
                <input type="hidden" name="requirements" id="requirementsInput" value="<?php echo htmlspecialchars(json_encode($selectedRequirements), ENT_QUOTES, 'UTF-8'); ?>">
                
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
                                    <input type="text" name="name" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'name', (string) $scholarship['name'])); ?>" required>
                                </div>

                                <div class="form-group-modern">
                                    <label><i class="fas fa-align-left"></i> Description</label>
                                    <textarea name="description" rows="4"><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'description', (string) ($scholarship['description'] ?? ''))); ?></textarea>
                                </div>

                                  <div class="form-row-modern">
                                      <div class="form-group-modern">
                                          <label><i class="fas fa-building"></i> Provider/Organization *</label>
                                        <input
                                            type="text"
                                            name="provider"
                                            value="<?php echo htmlspecialchars($isProviderScopedUser ? ($currentProviderOrganization !== '' ? $currentProviderOrganization : scholarshipOldValue($scholarshipOld, 'provider', (string) ($scholarshipData['provider'] ?? ''))) : scholarshipOldValue($scholarshipOld, 'provider', (string) ($scholarshipData['provider'] ?? ''))); ?>"
                                            <?php echo $isProviderScopedUser ? 'readonly' : ''; ?>
                                            required
                                        >
                                        <?php if ($isProviderScopedUser): ?>
                                            <small class="helper-text">This is locked to your provider account organization.</small>
                                        <?php endif; ?>
                                      </div>
                                      <div class="form-group-modern">
                                          <label><i class="fas fa-calendar-alt"></i> Application Deadline *</label>
                                        <input type="date" name="deadline" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'deadline', (string) ($scholarshipData['deadline'] ?? ''))); ?>" min="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                                    </div>
                                </div>

                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-chart-line"></i> Minimum GWA (1.00 is highest)</label>
                                        <input type="number" name="min_gwa" step="0.01" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'min_gwa', (string) (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null ? $scholarship['min_gwa'] : ($scholarship['max_gwa'] ?? '')))); ?>" min="1.00" max="5.00" placeholder="e.g., 2.00">
                                        <small class="helper-text">Optional: leave blank if there is no GWA requirement</small>
                                    </div>
                                </div>
                                <input type="hidden" name="max_gwa" value="">

                                <div class="form-card-modern" style="margin-top: 24px; border: 1px solid var(--gray-200); box-shadow: none;">
                                    <div class="card-header">
                                        <h3><i class="fas fa-user-graduate"></i> Target Applicants</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-users"></i> Target Applicant Type</label>
                                                <select name="target_applicant_type">
                                                    <option value="all" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'all', (string) ($scholarshipData['target_applicant_type'] ?? 'all')); ?>>All Applicants</option>
                                                    <option value="incoming_freshman" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'incoming_freshman', (string) ($scholarshipData['target_applicant_type'] ?? 'all')); ?>>Incoming Freshman</option>
                                                    <option value="current_college" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'current_college', (string) ($scholarshipData['target_applicant_type'] ?? 'all')); ?>>Current College Student</option>
                                                    <option value="transferee" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'transferee', (string) ($scholarshipData['target_applicant_type'] ?? 'all')); ?>>Transferee</option>
                                                    <option value="continuing_student" <?php echo scholarshipOldSelected($scholarshipOld, 'target_applicant_type', 'continuing_student', (string) ($scholarshipData['target_applicant_type'] ?? 'all')); ?>>Continuing Student</option>
                                                </select>
                                                <small class="helper-text">Use this when a scholarship is only for incoming or already-enrolled students.</small>
                                            </div>
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-layer-group"></i> Target Year Level</label>
                                                <select name="target_year_level">
                                                    <option value="any" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', 'any', (string) ($scholarshipData['target_year_level'] ?? 'any')); ?>>Any Year Level</option>
                                                    <option value="1st_year" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '1st_year', (string) ($scholarshipData['target_year_level'] ?? 'any')); ?>>1st Year</option>
                                                    <option value="2nd_year" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '2nd_year', (string) ($scholarshipData['target_year_level'] ?? 'any')); ?>>2nd Year</option>
                                                    <option value="3rd_year" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '3rd_year', (string) ($scholarshipData['target_year_level'] ?? 'any')); ?>>3rd Year</option>
                                                    <option value="4th_year" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '4th_year', (string) ($scholarshipData['target_year_level'] ?? 'any')); ?>>4th Year</option>
                                                    <option value="5th_year_plus" <?php echo scholarshipOldSelected($scholarshipOld, 'target_year_level', '5th_year_plus', (string) ($scholarshipData['target_year_level'] ?? 'any')); ?>>5th Year+</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-clipboard-check"></i> Minimum Admission Status</label>
                                                <select name="required_admission_status">
                                                    <option value="any" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'any', (string) ($scholarshipData['required_admission_status'] ?? 'any')); ?>>Any Admission Status</option>
                                                    <option value="not_yet_applied" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'not_yet_applied', (string) ($scholarshipData['required_admission_status'] ?? 'any')); ?>>Not Yet Applied</option>
                                                    <option value="applied" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'applied', (string) ($scholarshipData['required_admission_status'] ?? 'any')); ?>>Applied</option>
                                                    <option value="admitted" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'admitted', (string) ($scholarshipData['required_admission_status'] ?? 'any')); ?>>Admitted</option>
                                                    <option value="enrolled" <?php echo scholarshipOldSelected($scholarshipOld, 'required_admission_status', 'enrolled', (string) ($scholarshipData['required_admission_status'] ?? 'any')); ?>>Enrolled</option>
                                                </select>
                                                <small class="helper-text">Example: choose Admitted if applicants must already have an acceptance result.</small>
                                            </div>
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-sitemap"></i> Preferred SHS Strand</label>
                                                <input type="text" name="target_strand" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'target_strand', (string) ($scholarshipData['target_strand'] ?? ''))); ?>" placeholder="Optional: STEM, ABM, HUMSS">
                                                <small class="helper-text">Leave blank if the scholarship is open to any strand.</small>
                                            </div>
                                        </div>

                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-flag"></i> Target Citizenship</label>
                                                <select name="target_citizenship">
                                                    <option value="all" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'all', (string) ($scholarshipData['target_citizenship'] ?? 'all')); ?>>All Citizenship Types</option>
                                                    <option value="filipino" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'filipino', (string) ($scholarshipData['target_citizenship'] ?? 'all')); ?>>Filipino</option>
                                                    <option value="dual_citizen" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'dual_citizen', (string) ($scholarshipData['target_citizenship'] ?? 'all')); ?>>Dual Citizen</option>
                                                    <option value="permanent_resident" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'permanent_resident', (string) ($scholarshipData['target_citizenship'] ?? 'all')); ?>>Permanent Resident</option>
                                                    <option value="other" <?php echo scholarshipOldSelected($scholarshipOld, 'target_citizenship', 'other', (string) ($scholarshipData['target_citizenship'] ?? 'all')); ?>>Other</option>
                                                </select>
                                                <small class="helper-text">Use this only when the scholarship is limited to a specific citizenship or residency profile.</small>
                                            </div>
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-wallet"></i> Target Household Income Bracket</label>
                                                <select name="target_income_bracket">
                                                    <option value="any" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', 'any', (string) ($scholarshipData['target_income_bracket'] ?? 'any')); ?>>Any Income Bracket</option>
                                                    <option value="below_10000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', 'below_10000', (string) ($scholarshipData['target_income_bracket'] ?? 'any')); ?>>Below PHP 10,000 / month</option>
                                                    <option value="10000_20000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', '10000_20000', (string) ($scholarshipData['target_income_bracket'] ?? 'any')); ?>>PHP 10,000 - 20,000 / month</option>
                                                    <option value="20001_40000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', '20001_40000', (string) ($scholarshipData['target_income_bracket'] ?? 'any')); ?>>PHP 20,001 - 40,000 / month</option>
                                                    <option value="40001_80000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', '40001_80000', (string) ($scholarshipData['target_income_bracket'] ?? 'any')); ?>>PHP 40,001 - 80,000 / month</option>
                                                    <option value="above_80000" <?php echo scholarshipOldSelected($scholarshipOld, 'target_income_bracket', 'above_80000', (string) ($scholarshipData['target_income_bracket'] ?? 'any')); ?>>Above PHP 80,000 / month</option>
                                                </select>
                                                <small class="helper-text">Use this when the scholarship is intended for a specific declared income bracket.</small>
                                            </div>
                                        </div>

                                        <div class="form-row-modern">
                                            <div class="form-group-modern">
                                                <label><i class="fas fa-hands-helping"></i> Target Special Category</label>
                                                <select name="target_special_category">
                                                    <option value="any" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'any', (string) ($scholarshipData['target_special_category'] ?? 'any')); ?>>Any Special Category</option>
                                                    <option value="pwd" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'pwd', (string) ($scholarshipData['target_special_category'] ?? 'any')); ?>>Person with Disability (PWD)</option>
                                                    <option value="indigenous_peoples" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'indigenous_peoples', (string) ($scholarshipData['target_special_category'] ?? 'any')); ?>>Indigenous Peoples</option>
                                                    <option value="solo_parent_dependent" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'solo_parent_dependent', (string) ($scholarshipData['target_special_category'] ?? 'any')); ?>>Dependent of Solo Parent</option>
                                                    <option value="working_student" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'working_student', (string) ($scholarshipData['target_special_category'] ?? 'any')); ?>>Working Student</option>
                                                    <option value="child_of_ofw" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'child_of_ofw', (string) ($scholarshipData['target_special_category'] ?? 'any')); ?>>Child of OFW</option>
                                                    <option value="four_ps_beneficiary" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'four_ps_beneficiary', (string) ($scholarshipData['target_special_category'] ?? 'any')); ?>>4Ps Beneficiary</option>
                                                    <option value="orphan" <?php echo scholarshipOldSelected($scholarshipOld, 'target_special_category', 'orphan', (string) ($scholarshipData['target_special_category'] ?? 'any')); ?>>Orphan / Ward</option>
                                                </select>
                                                <small class="helper-text">Choose a category only when the scholarship is reserved for a defined applicant group.</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group-modern">
                                    <label><i class="fas fa-check-circle"></i> Eligibility Requirements</label>
                                    <textarea name="eligibility" rows="4" placeholder="List the eligibility criteria for this scholarship..."><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'eligibility', (string) ($scholarship['eligibility'] ?? ''))); ?></textarea>
                                </div>

                                <div class="form-group-modern">
                                    <label><i class="fas fa-gift"></i> Benefits/Provisions</label>
                                    <textarea name="benefits" rows="4" placeholder="List the benefits (e.g., Tuition fee, Monthly stipend, Book allowance)"><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'benefits', (string) ($scholarshipData['benefits'] ?? ''))); ?></textarea>
                                </div>

                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-laptop"></i> Online Requirement</label>
                                        <select name="assessment_requirement">
                                            <option value="none" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'none', $assessmentRequirementValue); ?>>No online exam/assessment</option>
                                            <option value="online_exam" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'online_exam', $assessmentRequirementValue); ?>>Online Exam</option>
                                            <option value="remote_examination" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'remote_examination', $assessmentRequirementValue); ?>>Remote Examination</option>
                                            <option value="assessment" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'assessment', $assessmentRequirementValue); ?>>Online Assessment</option>
                                            <option value="evaluation" <?php echo scholarshipOldSelected($scholarshipOld, 'assessment_requirement', 'evaluation', $assessmentRequirementValue); ?>>Online Evaluation</option>
                                        </select>
                                    </div>
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-link"></i> Exam/Assessment Link</label>
                                        <input type="url" name="assessment_link" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'assessment_link', (string) ($scholarshipData['assessment_link'] ?? ''))); ?>" placeholder="https://example.com/assessment">
                                        <small class="helper-text">Optional link for students</small>
                                    </div>
                                </div>
                                <div class="form-group-modern">
                                    <label><i class="fas fa-note-sticky"></i> Assessment Details</label>
                                    <textarea name="assessment_details" rows="3" placeholder="Instructions, schedule, or evaluation notes"><?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'assessment_details', (string) ($scholarshipData['assessment_details'] ?? ''))); ?></textarea>
                                </div>

                                <div id="remoteExamSitesContainer" class="form-group-modern" style="display: none;">
                                    <label><i class="fas fa-map-location-dot"></i> Remote Examination Sites</label>
                                    <input type="hidden" name="remote_exam_sites" id="remoteExamSitesInput" value="<?php echo htmlspecialchars(json_encode($initialRemoteExamSites), ENT_QUOTES, 'UTF-8'); ?>">
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
                                <input type="hidden" id="latitudeInput" name="latitude" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'latitude', (string) ($scholarshipLocation['latitude'] ?? ''))); ?>">
                                <input type="hidden" id="longitudeInput" name="longitude" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'longitude', (string) ($scholarshipLocation['longitude'] ?? ''))); ?>">
                                <div class="form-row-modern">
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-location-dot"></i> Address</label>
                                        <input type="text" id="addressInput" name="address" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'address', (string) ($scholarshipData['address'] ?? ''))); ?>" placeholder="Street address">
                                    </div>
                                    <div class="form-group-modern">
                                        <label><i class="fas fa-city"></i> City</label>
                                        <input type="text" id="cityInput" name="city" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'city', (string) ($scholarshipData['city'] ?? ''))); ?>" placeholder="City">
                                    </div>
                                </div>
                                <div class="form-group-modern">
                                    <label><i class="fas fa-map"></i> Province</label>
                                    <input type="text" id="provinceInput" name="province" value="<?php echo htmlspecialchars(scholarshipOldValue($scholarshipOld, 'province', (string) ($scholarshipData['province'] ?? ''))); ?>" placeholder="Province">
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
                                    <?php if ($reviewStatusValue === 'approved'): ?>
                                    <strong>Approved for publication</strong>
                                    <p>This scholarship has already passed review. Changes made here update the current scholarship record.</p>
                                    <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($statusValue); ?>">
                                    <?php elseif ($reviewStatusValue === 'rejected'): ?>
                                    <strong>Needs revision</strong>
                                    <p>This scholarship was marked for revision. Saving changes will keep it hidden and send it back to the admin review queue.</p>
                                    <input type="hidden" name="status" id="statusInput" value="inactive">
                                    <?php else: ?>
                                    <strong>Pending admin review</strong>
                                    <p>This scholarship is still waiting for approval. It remains hidden from students until the review is completed.</p>
                                    <input type="hidden" name="status" id="statusInput" value="inactive">
                                    <?php endif; ?>
                                </div>
                                <small class="helper-text" style="display: block; margin-top: 15px;">
                                    <i class="fas fa-info-circle"></i>
                                    Review status is managed from the Scholarship Reviews queue.
                                </small>
                                <?php elseif ($adminReviewStateVisible): ?>
                                <div class="review-workflow-box">
                                    <strong><?php echo $reviewStatusValue === 'rejected' ? 'Rejected submission' : 'Pending scholarship review'; ?></strong>
                                    <p>
                                        <?php if ($reviewStatusValue === 'rejected'): ?>
                                        This scholarship is currently marked for revision. Approving it will publish the scholarship, while rejecting keeps it hidden.
                                        <?php else: ?>
                                        This provider-submitted scholarship is waiting for administrative approval before publication.
                                        <?php endif; ?>
                                    </p>
                                    <input type="hidden" name="status" id="statusInput" value="<?php echo htmlspecialchars($statusValue); ?>">
                                    <div class="review-action-stack">
                                        <button type="button" class="btn-modern btn-primary-modern" data-review-action="approve" data-review-form="approveScholarshipReviewForm">
                                            <i class="fas fa-circle-check"></i> Approve Scholarship
                                        </button>
                                        <button type="button" class="btn-modern btn-outline-modern" data-review-action="reject" data-review-form="rejectScholarshipReviewForm">
                                            <i class="fas fa-ban"></i> Reject for Revision
                                        </button>
                                    </div>
                                </div>
                                <small class="helper-text" style="display: block; margin-top: 15px;">
                                    <i class="fas fa-info-circle"></i>
                                    Use the review actions above after confirming the scholarship details.
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
                                    <?php if(!empty($currentImageValue)): ?>
                                    <div class="current-image-preview">
                                        <img src="../public/uploads/<?php echo htmlspecialchars($currentImageValue); ?>" 
                                             alt="Provider image">
                                        <div class="image-info">
                                            <p><strong>Current Image:</strong></p>
                                            <p class="current-image-name"><?php echo htmlspecialchars($currentImageValue); ?></p>
                                            <div class="remove-checkbox">
                                                <label>
                                                    <input type="checkbox" name="remove_image" value="1" <?php echo $removeImageChecked ? 'checked' : ''; ?>> 
                                                    <i class="fas fa-trash-alt"></i> Remove image
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="current-image-preview">
                                        <img src="../public/uploads/scholarship-default.jpg" alt="No image">
                                        <div class="image-info">
                                            <p><strong>No image uploaded yet</strong></p>
                                            <p><i class="fas fa-info-circle"></i> Upload a provider logo or image</p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="upload-area" onclick="document.getElementById('provider_image').click()">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Click to upload new image</p>
                                        <input type="file" id="provider_image" name="provider_image" accept="image/jpeg,image/png,image/gif,image/jpg" style="display: none;">
                                    </div>
                                    <div id="selectedImageName" class="selected-file-name">No new image selected</div>
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
                            <div class="card-header">
                                <h3><i class="fas fa-file-alt"></i> Document Requirements</h3>
                                <small style="color: var(--gray-500);">Required documents for applicants</small>
                            </div>
                            <div class="card-body">
                                <div class="requirements-list">
                                    <?php foreach($documentTypes as $doc): ?>
                                    <div class="requirement-item">
                                        <div class="requirement-info">
                                            <i class="fas fa-file-<?php 
                                                echo match($doc['code']) {
                                                    'id' => 'id-card',
                                                    'birth_certificate' => 'baby-carriage',
                                                    'grades' => 'graduation-cap',
                                                    'good_moral' => 'hand-peace',
                                                    'enrollment' => 'school',
                                                    'income_tax' => 'file-invoice-dollar',
                                                    'citizenship_proof' => 'contract',
                                                    'income_proof' => 'invoice-dollar',
                                                    'special_category_proof' => 'signature',
                                                    default => 'file-alt'
                                                };
                                            ?>"></i>
                                            <div>
                                                <div class="requirement-name"><?php echo htmlspecialchars($doc['name']); ?></div>
                                                <div class="requirement-desc"><?php echo htmlspecialchars($doc['description'] ?? ''); ?></div>
                                            </div>
                                        </div>
                                        <input type="checkbox" 
                                               class="requirement-check" 
                                               value="<?php echo $doc['code']; ?>"
                                               <?php echo in_array($doc['code'], $selectedRequirements, true) ? 'checked' : ''; ?>
                                               onchange="updateRequirements()">
                                    </div>
                                    <?php endforeach; ?>
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
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <a href="manage_scholarships.php" class="btn-modern btn-outline-modern">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </div>
                </div>
            </form>
            <?php if ($adminReviewStateVisible): ?>
            <form id="approveScholarshipReviewForm" method="POST" action="../app/AdminControllers/scholarship_process.php" style="display: none;">
                <input type="hidden" name="action" value="approve_review">
                <input type="hidden" name="id" value="<?php echo $scholarship_id; ?>">
                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($reviewActionToken); ?>">
                <input type="hidden" name="redirect_target" value="edit">
            </form>
            <form id="rejectScholarshipReviewForm" method="POST" action="../app/AdminControllers/scholarship_process.php" style="display: none;">
                <input type="hidden" name="action" value="reject_review">
                <input type="hidden" name="id" value="<?php echo $scholarship_id; ?>">
                <input type="hidden" name="entity_token" value="<?php echo htmlspecialchars($reviewActionToken); ?>">
                <input type="hidden" name="redirect_target" value="edit">
            </form>
            <?php endif; ?>
        </div>
    </section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php
$locationLatitude = scholarshipOldValue($scholarshipOld, 'latitude', (string) ($scholarshipLocation['latitude'] ?? ''));
$locationLongitude = scholarshipOldValue($scholarshipOld, 'longitude', (string) ($scholarshipLocation['longitude'] ?? ''));
$locationAddress = scholarshipOldValue($scholarshipOld, 'address', (string) ($scholarshipData['address'] ?? ''));
$locationCity = scholarshipOldValue($scholarshipOld, 'city', (string) ($scholarshipData['city'] ?? ''));
$locationProvince = scholarshipOldValue($scholarshipOld, 'province', (string) ($scholarshipData['province'] ?? ''));
include 'partials/scholarship_location_modal.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
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

    async function confirmScholarshipReviewAction(action, formId) {
        const form = document.getElementById(formId);
        if (!form) {
            return;
        }

        const isApprove = action === 'approve';
        const title = isApprove ? 'Approve Scholarship?' : 'Reject Scholarship?';
        const text = isApprove
            ? 'This will approve and publish the scholarship.'
            : 'This will mark the scholarship for revision and keep it hidden.';

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
        console.log('Requirements updated:', requirements); // Debug log
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
                selectedImageName.textContent = 'No new image selected';
                return;
            }
            
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!validTypes.includes(file.type)) {
                showScholarshipAlert('Invalid Image File', 'Please upload a valid image file (JPG, JPEG, PNG, GIF).');
                this.value = '';
                previewContainer.classList.remove('show');
                selectedImageName.textContent = 'No new image selected';
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
            selectedImageName.textContent = 'No new image selected';
        }
    });

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
            <div class="form-row-modern" style="margin-bottom: 10px;">
                <div class="form-group-modern" style="margin-bottom: 0;">
                    <label>Pin Latitude (optional)</label>
                    <input type="number" step="0.00000001" data-field="latitude" placeholder="e.g., 14.59950000">
                </div>
                <div class="form-group-modern" style="margin-bottom: 0;">
                    <label>Pin Longitude (optional)</label>
                    <input type="number" step="0.00000001" data-field="longitude" placeholder="e.g., 120.98420000">
                </div>
            </div>
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

    function serializeScholarshipForm(form) {
        const snapshot = [];
        const elements = form.querySelectorAll('input, select, textarea');

        elements.forEach((element) => {
            if (!element.name || element.disabled) {
                return;
            }

            if (element.type === 'file') {
                const files = Array.from(element.files || []).map((file) => ({
                    name: file.name,
                    size: file.size,
                    lastModified: file.lastModified,
                    type: file.type
                }));
                snapshot.push([element.name, files]);
                return;
            }

            if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
                return;
            }

            snapshot.push([element.name, element.value]);
        });

        snapshot.sort((a, b) => {
            if (a[0] === b[0]) {
                return JSON.stringify(a[1]).localeCompare(JSON.stringify(b[1]));
            }
            return a[0].localeCompare(b[0]);
        });

        return JSON.stringify(snapshot);
    }

    function showNoChangeAlert() {
        showScholarshipAlert('No Changes Detected', 'Update at least one field before saving.', 'info');
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        const editScholarshipForm = document.getElementById('editScholarshipForm');
        document.querySelectorAll('[data-review-action][data-review-form]').forEach((button) => {
            button.addEventListener('click', () => {
                confirmScholarshipReviewAction(button.dataset.reviewAction, button.dataset.reviewForm);
            });
        });

        // Initialize requirements from checkboxes
        updateRequirements();
        
        // Add event listeners to all requirement checkboxes
        const checkboxes = document.querySelectorAll('.requirement-check');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateRequirements);
        });

        const initialRemoteExamSites = <?php echo json_encode($initialRemoteExamSites, JSON_UNESCAPED_UNICODE); ?>;
        initializeRemoteExamSites(initialRemoteExamSites);
        setStatus(document.getElementById('statusInput').value || 'active');

        if (editScholarshipForm) {
            const initialFormSnapshot = serializeScholarshipForm(editScholarshipForm);
            editScholarshipForm.addEventListener('submit', function(event) {
                syncRemoteExamSites();
                updateRequirements();
                const currentSnapshot = serializeScholarshipForm(editScholarshipForm);
                if (currentSnapshot === initialFormSnapshot) {
                    event.preventDefault();
                    showNoChangeAlert();
                }
            });
        }
        
        console.log('Page loaded, requirements initialized');
    });
</script>

<?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
