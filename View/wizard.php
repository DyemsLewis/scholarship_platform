<?php
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Models/UserDocument.php';
require_once __DIR__ . '/../app/Controllers/scholarshipResultController.php';

if (!function_exists('tableHasColumn')) {
    function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . '.' . $columnName;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM information_schema.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = :table_name\n              AND COLUMN_NAME = :column_name\n        ");
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName
        ]);
        $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
        return $cache[$key];
    }
}

if (!function_exists('tableExists')) {
    function tableExists(PDO $pdo, string $tableName): bool
    {
        static $cache = [];
        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM information_schema.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = :table_name\n        ");
        $stmt->execute([':table_name' => $tableName]);
        $cache[$tableName] = ((int) $stmt->fetchColumn()) > 0;
        return $cache[$tableName];
    }
}

$scholarshipId = isset($_GET['scholarship_id']) ? (int) $_GET['scholarship_id'] : 0;

if ($scholarshipId > 0) {
    requireValidEntityUrlToken(
        'scholarship',
        $scholarshipId,
        $_GET['token'] ?? null,
        'apply',
        'scholarships.php',
        'Invalid or expired scholarship application link.'
    );
}

include 'layout/header.php';

$scholarship = null;
$existingApplication = null;
$remoteExamLocations = [];

$applicantNameParts = array_filter([
    trim((string) ($userFirstName ?? '')),
    trim((string) ($userMiddleInitial ?? '')),
    trim((string) ($userLastName ?? ''))
], static fn($value): bool => $value !== '');
$applicantDisplayName = trim(implode(' ', $applicantNameParts));
if (trim((string) ($userSuffix ?? '')) !== '') {
    $applicantDisplayName .= ' ' . trim((string) $userSuffix);
}
$applicantDisplayName = trim($applicantDisplayName) !== ''
    ? trim($applicantDisplayName)
    : (string) $userDisplayName;

$documentSummary = [
    'total_required' => 0,
    'uploaded' => 0,
    'verified' => 0,
    'pending' => 0,
    'missing' => [],
    'requirements' => []
];
$docRequirements = [];
$statusCounts = [
    'verified' => 0,
    'pending' => 0,
    'missing' => 0,
    'rejected' => 0
];
$profileEvaluation = [
    'total' => 0,
    'met' => 0,
    'pending' => 0,
    'failed' => 0,
    'eligible' => true,
    'label' => 'Open profile policy',
    'checks' => []
];
$audienceLabel = 'Open to all applicants';

if ($isLoggedIn && $scholarshipId > 0) {
    try {
        $applicationOpenDateSelect = tableHasColumn($pdo, 'scholarship_data', 'application_open_date')
            ? 'sd.application_open_date'
            : 'NULL AS application_open_date';
        $applicationProcessLabelSelect = tableHasColumn($pdo, 'scholarship_data', 'application_process_label')
            ? 'sd.application_process_label'
            : 'NULL AS application_process_label';
        $postApplicationStepsSelect = tableHasColumn($pdo, 'scholarship_data', 'post_application_steps')
            ? 'sd.post_application_steps'
            : 'NULL AS post_application_steps';
        $renewalConditionsSelect = tableHasColumn($pdo, 'scholarship_data', 'renewal_conditions')
            ? 'sd.renewal_conditions'
            : 'NULL AS renewal_conditions';
        $scholarshipRestrictionsSelect = tableHasColumn($pdo, 'scholarship_data', 'scholarship_restrictions')
            ? 'sd.scholarship_restrictions'
            : 'NULL AS scholarship_restrictions';
        $assessmentRequirementSelect = tableHasColumn($pdo, 'scholarship_data', 'assessment_requirement')
            ? 'sd.assessment_requirement'
            : 'NULL AS assessment_requirement';
        $assessmentLinkSelect = tableHasColumn($pdo, 'scholarship_data', 'assessment_link')
            ? 'sd.assessment_link'
            : 'NULL AS assessment_link';
        $assessmentDetailsSelect = tableHasColumn($pdo, 'scholarship_data', 'assessment_details')
            ? 'sd.assessment_details'
            : 'NULL AS assessment_details';

        $stmt = $pdo->prepare("\n            SELECT\n                s.*,\n                sd.provider,\n                sd.benefits,\n                sd.address,\n                sd.city,\n                sd.province,\n                {$applicationOpenDateSelect},\n                sd.deadline,\n                {$applicationProcessLabelSelect},\n                {$assessmentRequirementSelect},\n                {$assessmentLinkSelect},\n                {$assessmentDetailsSelect},\n                {$postApplicationStepsSelect},\n                {$renewalConditionsSelect},\n                {$scholarshipRestrictionsSelect}\n            FROM scholarships s\n            LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id\n            WHERE s.id = ? AND s.status = 'active'\n            LIMIT 1\n        ");
        $stmt->execute([$scholarshipId]);
        $scholarship = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $scholarship = null;
    }
}

if ($isLoggedIn && $scholarship) {
    $scholarshipService = new ScholarshipService($pdo);
    $profileEvaluation = $scholarshipService->evaluateProfileRequirements($scholarship, [
        'applicant_type' => $userApplicantType,
        'year_level' => $userYearLevel,
        'admission_status' => $userAdmissionStatus,
        'shs_strand' => $userShsStrand,
        'course' => $userCourse
    ]);

    $audienceParts = [];
    if (!empty($scholarship['target_applicant_type']) && strtolower((string) $scholarship['target_applicant_type']) !== 'all') {
        $audienceParts[] = formatApplicantTypeLabel($scholarship['target_applicant_type']);
    }
    if (!empty($scholarship['target_year_level']) && strtolower((string) $scholarship['target_year_level']) !== 'any') {
        $audienceParts[] = formatYearLevelLabel($scholarship['target_year_level']);
    }
    if (!empty($scholarship['required_admission_status']) && strtolower((string) $scholarship['required_admission_status']) !== 'any') {
        $audienceParts[] = formatAdmissionStatusLabel($scholarship['required_admission_status']) . '+';
    }
    if (!empty($scholarship['target_strand'])) {
        $audienceParts[] = strtoupper((string) $scholarship['target_strand']);
    }
    if (!empty($audienceParts)) {
        $audienceLabel = implode(' / ', $audienceParts);
    }

    try {
        $stmt = $pdo->prepare("\n            SELECT id, status, applied_at\n            FROM applications\n            WHERE user_id = ? AND scholarship_id = ?\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->execute([(int) $_SESSION['user_id'], $scholarshipId]);
        $existingApplication = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $existingApplication = null;
    }

    try {
        $documentModel = new UserDocument($pdo);
        $documentSummary = $documentModel->checkScholarshipRequirements((int) $_SESSION['user_id'], $scholarshipId);
        $docRequirements = $documentSummary['requirements'] ?? [];
    } catch (Throwable $e) {
        $docRequirements = [];
    }

    if (tableExists($pdo, 'scholarship_remote_exam_locations')) {
        try {
            $stmt = $pdo->prepare("\n                SELECT site_name, address, city, province\n                FROM scholarship_remote_exam_locations\n                WHERE scholarship_id = ?\n                ORDER BY id ASC\n            ");
            $stmt->execute([$scholarshipId]);
            $remoteExamLocations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $remoteExamLocations = [];
        }
    }
}

foreach ($docRequirements as $requirement) {
    $status = strtolower((string) ($requirement['status'] ?? 'missing'));
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

$totalRequired = (int) ($documentSummary['total_required'] ?? 0);
$uploadedRequired = (int) ($documentSummary['uploaded'] ?? 0);
$verifiedRequired = (int) ($documentSummary['verified'] ?? 0);
$pendingRequired = (int) ($documentSummary['pending'] ?? 0);
$missingRequired = (int) $statusCounts['missing'];
$rejectedRequired = (int) $statusCounts['rejected'];

$requiredGwa = null;
if ($scholarship) {
    if (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') {
        $requiredGwa = (float) $scholarship['min_gwa'];
    } elseif (isset($scholarship['max_gwa']) && $scholarship['max_gwa'] !== null && $scholarship['max_gwa'] !== '') {
        $requiredGwa = (float) $scholarship['max_gwa'];
    }
}

$profileHasGwa = ($userGWA !== null && $userGWA !== '');
$gwaRequired = ($requiredGwa !== null);
$gwaWithinRequirement = !$gwaRequired || ($profileHasGwa && (float) $userGWA <= (float) $requiredGwa);
$showShsDetails = !in_array((string) ($userEnrollmentStatus ?? ''), ['currently_enrolled', 'regular', 'irregular'], true);

$deadlinePassed = false;
$deadlineLabel = 'Open / no deadline';
if ($scholarship && !empty($scholarship['deadline'])) {
    try {
        $deadlineDate = new DateTime((string) $scholarship['deadline']);
        $deadlineDate->setTime(23, 59, 59);
        $deadlineLabel = $deadlineDate->format('M d, Y');
        $deadlinePassed = $deadlineDate < new DateTime();
    } catch (Throwable $e) {
        $deadlinePassed = false;
    }
}

$applicationNotYetOpen = false;
$applicationOpenDateLabel = 'Open now';
if ($scholarship && !empty($scholarship['application_open_date'])) {
    try {
        $applicationOpenDate = new DateTime((string) $scholarship['application_open_date']);
        $applicationOpenDate->setTime(0, 0, 0);
        $applicationOpenDateLabel = $applicationOpenDate->format('M d, Y');
        $applicationNotYetOpen = $applicationOpenDate > new DateTime();
    } catch (Throwable $e) {
        $applicationNotYetOpen = false;
        $applicationOpenDateLabel = 'Open now';
    }
}

$alreadyApplied = $existingApplication !== null;
$documentsReady = ($missingRequired === 0 && $rejectedRequired === 0);
$profileRulesReady = !empty($profileEvaluation['eligible']);

$canSubmit = $isLoggedIn
    && $scholarship !== null
    && !$alreadyApplied
    && !$applicationNotYetOpen
    && !$deadlinePassed
    && $documentsReady
    && $profileRulesReady
    && $gwaWithinRequirement;

$blockReason = '';
if (!$isLoggedIn) {
    $blockReason = 'Please login before applying.';
} elseif (!$scholarship) {
    $blockReason = 'Scholarship not found or inactive.';
} elseif ($alreadyApplied) {
    $blockReason = 'You already submitted this scholarship application.';
} elseif ($applicationNotYetOpen) {
    $blockReason = 'This scholarship opens on ' . $applicationOpenDateLabel . '.';
} elseif ($deadlinePassed) {
    $blockReason = 'This scholarship is already closed.';
} elseif ($missingRequired > 0) {
    $blockReason = 'Upload all missing required documents first.';
} elseif ($rejectedRequired > 0) {
    $blockReason = 'Re-upload rejected required documents first.';
} elseif (($profileEvaluation['pending'] ?? 0) > 0) {
    $blockReason = 'Complete your applicant profile first.';
} elseif (($profileEvaluation['failed'] ?? 0) > 0) {
    $blockReason = 'Your profile does not match this scholarship policy.';
} elseif ($gwaRequired && !$profileHasGwa) {
    $blockReason = 'Upload your TOR/grades first to set your GWA.';
} elseif ($gwaRequired && !$gwaWithinRequirement) {
    $blockReason = 'Your GWA is above the required limit.';
}

$assessmentRaw = 'none';
if (is_array($scholarship) && isset($scholarship['assessment_requirement'])) {
    $assessmentRaw = (string) $scholarship['assessment_requirement'];
}
$assessmentType = strtolower(trim($assessmentRaw));
$assessmentLabel = 'None';
if ($assessmentType === 'online_exam') {
    $assessmentLabel = 'Online Exam';
} elseif ($assessmentType === 'remote_examination') {
    $assessmentLabel = 'Remote Examination';
} elseif ($assessmentType === 'assessment') {
    $assessmentLabel = 'Online Assessment';
} elseif ($assessmentType === 'evaluation') {
    $assessmentLabel = 'Online Evaluation';
}

$applicationProcessLabel = trim((string) ($scholarship['application_process_label'] ?? ''));
if ($applicationProcessLabel === '') {
    if ($assessmentType !== 'none' && $assessmentLabel !== 'None') {
        $applicationProcessLabel = 'Documents + ' . $assessmentLabel;
    } elseif ($totalRequired > 0) {
        $applicationProcessLabel = 'Documents + Provider Review';
    } else {
        $applicationProcessLabel = 'Provider Review';
    }
}

$postApplicationSteps = trim((string) ($scholarship['post_application_steps'] ?? ''));
if ($postApplicationSteps === '') {
    $postApplicationSteps = 'After submission, the provider reviews your profile and documents, then sends the result through the system.';
}

$renewalConditions = trim((string) ($scholarship['renewal_conditions'] ?? ''));
$scholarshipRestrictions = trim((string) ($scholarship['scholarship_restrictions'] ?? ''));

$addressParts = [];
if (is_array($scholarship)) {
    if (!empty($scholarship['address'])) $addressParts[] = (string) $scholarship['address'];
    if (!empty($scholarship['city'])) $addressParts[] = (string) $scholarship['city'];
    if (!empty($scholarship['province'])) $addressParts[] = (string) $scholarship['province'];
}
$scholarshipAddress = implode(', ', $addressParts);
$remoteExamMapUrl = $scholarshipId > 0
    ? buildEntityUrl('remote_exam_map.php', 'scholarship', $scholarshipId, 'view', ['id' => $scholarshipId])
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Wizard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/wizard-style.css')); ?>">
</head>
<body>
<section class="dashboard wizard-page user-page-shell">
    <div class="container">
        <?php if (!$isLoggedIn): ?>
            <div class="guest-warning">
                <i class="fas fa-lock"></i>
                <div>
                    <h3>Login Required</h3>
                    <p>You need to login to open the application wizard.</p>
                    <a href="login.php" class="btn btn-primary">Login Now</a>
                </div>
            </div>
        <?php elseif (!$scholarship): ?>
            <div class="wizard-page-header wizard-page-header-empty app-page-hero">
                <div class="wizard-page-header-copy app-page-hero-copy">
                    <h1>
                        <i class="fas fa-file-signature"></i>
                        Application Wizard
                        <span class="wizard-role-badge app-page-hero-badge">
                            <i class="fas fa-hourglass-half"></i>
                            Waiting for Selection
                        </span>
                    </h1>
                    <p>Choose a scholarship first and we will load the full application steps here.</p>
                </div>
                <div class="app-page-hero-side">
                    <a href="scholarships.php" class="btn btn-white wizard-back-link app-page-hero-action">
                        <i class="fas fa-graduation-cap"></i>
                        Browse Scholarships
                    </a>
                </div>
            </div>

            <div class="wizard-empty-shell">
                <div class="form-card-modern wizard-empty-state">
                    <div class="wizard-empty-illustration">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <span class="wizard-empty-tag">No scholarship selected</span>
                    <h2>Pick a scholarship to start your application</h2>
                    <p>
                        Open the wizard from any active scholarship card. Once you choose one, this page will show
                        your eligibility check, required documents, and final submission review.
                    </p>
                    <div class="wizard-empty-actions">
                        <a href="scholarships.php" class="btn btn-primary">
                            <i class="fas fa-compass"></i>
                            View Scholarships
                        </a>
                        <a href="upload.php" class="btn btn-outline">
                            <i class="fas fa-scroll"></i>
                            Upload TOR / Grades
                        </a>
                    </div>
                </div>

                <div class="form-card-modern wizard-empty-preview">
                    <div class="card-header">
                        <h3><i class="fas fa-route"></i> What Happens in the Wizard</h3>
                    </div>
                    <div class="card-body">
                        <div class="wizard-empty-steps">
                            <div class="wizard-empty-step">
                                <span class="wizard-empty-step-number">1</span>
                                <div>
                                    <h4>Eligibility Check</h4>
                                    <p>Review your profile, GWA, and scholarship rules before you proceed.</p>
                                </div>
                            </div>
                            <div class="wizard-empty-step">
                                <span class="wizard-empty-step-number">2</span>
                                <div>
                                    <h4>Document Review</h4>
                                    <p>See which required documents are verified, pending, missing, or rejected.</p>
                                </div>
                            </div>
                            <div class="wizard-empty-step">
                                <span class="wizard-empty-step-number">3</span>
                                <div>
                                    <h4>Review and Submit</h4>
                                    <p>Confirm your details and send your application for verification.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="wizard-page-header app-page-hero">
                <div class="wizard-page-header-copy app-page-hero-copy">
                    <h1>
                        <i class="fas fa-file-signature"></i>
                        Application Wizard
                        <span class="wizard-role-badge app-page-hero-badge">
                            <i class="fas fa-graduation-cap"></i>
                            <?php echo htmlspecialchars((string) $scholarship['name']); ?>
                        </span>
                    </h1>
                    <p>Review your readiness, confirm documents, and submit your scholarship application.</p>
                </div>
                <div class="app-page-hero-side">
                    <a href="scholarships.php" class="btn btn-white wizard-back-link app-page-hero-action">
                        <i class="fas fa-arrow-left"></i>
                        Back to Scholarships
                    </a>
                </div>
            </div>

            <div class="wizard-layout">
                <div class="wizard-main-column">
                    <?php if ($alreadyApplied): ?>
                        <div class="wizard-alert info">
                            <i class="fas fa-circle-info"></i>
                            <div>
                                <h4>Application Already Submitted</h4>
                                <p>
                                    Submitted on <strong><?php echo date('F d, Y h:i A', strtotime((string) $existingApplication['applied_at'])); ?></strong>
                                    with status <strong><?php echo htmlspecialchars(ucfirst((string) $existingApplication['status'])); ?></strong>.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($missingRequired > 0): ?>
                        <div class="wizard-alert danger">
                            <i class="fas fa-triangle-exclamation"></i>
                            <div>
                                <h4>Missing Required Documents</h4>
                                <p>You must upload all missing required documents before submission.</p>
                            </div>
                            <a href="documents.php" class="btn btn-outline">Open Documents</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($rejectedRequired > 0): ?>
                        <div class="wizard-alert danger">
                            <i class="fas fa-xmark-circle"></i>
                            <div>
                                <h4>Rejected Documents Found</h4>
                                <p>Re-upload rejected required documents before submitting your application.</p>
                            </div>
                            <a href="documents.php" class="btn btn-outline">Re-upload</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($pendingRequired > 0): ?>
                        <div class="wizard-alert warning">
                            <i class="fas fa-clock"></i>
                            <div>
                                <h4>Pending Verification</h4>
                                <p>You can still apply while <?php echo $pendingRequired; ?> required document(s) are pending review.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (($profileEvaluation['pending'] ?? 0) > 0): ?>
                        <div class="wizard-alert warning">
                            <i class="fas fa-user-gear"></i>
                            <div>
                                <h4>Profile Details Needed</h4>
                                <p><?php echo htmlspecialchars((string) ($profileEvaluation['label'] ?? 'Complete your profile to continue.')); ?></p>
                            </div>
                            <a href="profile.php" class="btn btn-outline">Open Profile</a>
                        </div>
                    <?php elseif (($profileEvaluation['failed'] ?? 0) > 0): ?>
                        <div class="wizard-alert danger">
                            <i class="fas fa-user-xmark"></i>
                            <div>
                                <h4>Profile Policy Not Met</h4>
                                <p><?php echo htmlspecialchars((string) ($profileEvaluation['label'] ?? 'Your current profile does not match this scholarship policy.')); ?></p>
                            </div>
                            <a href="profile.php" class="btn btn-outline">Review Profile</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($deadlinePassed): ?>
                        <div class="wizard-alert danger">
                            <i class="fas fa-calendar-xmark"></i>
                            <div>
                                <h4>Application Closed</h4>
                                <p>The deadline has passed for this scholarship.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($applicationNotYetOpen): ?>
                        <div class="wizard-alert info">
                            <i class="fas fa-hourglass-half"></i>
                            <div>
                                <h4>Applications Open Soon</h4>
                                <p>This scholarship starts accepting applications on <?php echo htmlspecialchars($applicationOpenDateLabel); ?>. You can still use this page to prepare your profile and documents.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-card-modern wizard-overview-card">
                        <div class="card-header">
                            <h3><i class="fas fa-list-check"></i> Application Overview</h3>
                        </div>
                        <div class="card-body">
                            <div class="wizard-intro">
                                <div>
                                    <h2><?php echo htmlspecialchars((string) $scholarship['name']); ?></h2>
                                    <p><?php echo htmlspecialchars((string) ($scholarship['provider'] ?? 'Provider not specified')); ?></p>
                                </div>
                                <div class="wizard-meta-grid">
                                    <div class="meta-card">
                                        <span class="meta-label">Application Opens</span>
                                        <strong><?php echo htmlspecialchars($applicationOpenDateLabel); ?></strong>
                                    </div>
                                    <div class="meta-card">
                                        <span class="meta-label">Deadline</span>
                                        <strong><?php echo htmlspecialchars($deadlineLabel); ?></strong>
                                    </div>
                                    <div class="meta-card">
                                        <span class="meta-label">Target Applicants</span>
                                        <strong><?php echo htmlspecialchars($audienceLabel); ?></strong>
                                    </div>
                                    <div class="meta-card">
                                        <span class="meta-label">Documents</span>
                                        <strong><?php echo $uploadedRequired; ?>/<?php echo $totalRequired; ?> uploaded</strong>
                                    </div>
                                    <?php if ($gwaRequired): ?>
                                    <div class="meta-card">
                                        <span class="meta-label">Required GWA</span>
                                        <strong><= <?php echo htmlspecialchars(number_format((float) $requiredGwa, 2)); ?></strong>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="wizard-stepper" role="tablist" aria-label="Application steps">
                                <button type="button" class="wizard-step-pill is-active" data-step-target="1"><span>1</span> Eligibility</button>
                                <button type="button" class="wizard-step-pill" data-step-target="2"><span>2</span> Documents</button>
                                <button type="button" class="wizard-step-pill" data-step-target="3"><span>3</span> Review & Submit</button>
                            </div>
                        </div>
                    </div>

                    <form id="applicationForm" class="wizard-form" method="POST" action="../app/Controllers/submit_application.php" data-can-submit="<?php echo $canSubmit ? '1' : '0'; ?>" data-block-reason="<?php echo htmlspecialchars($blockReason, ENT_QUOTES); ?>">
                        <div class="wizard-step-panel is-active form-card-modern" data-step-panel="1">
                            <div class="card-header">
                                <h3><i class="fas fa-user-check"></i> Eligibility Check</h3>
                            </div>
                            <div class="card-body">
                                <p class="panel-subtitle">Confirm your academic profile against this scholarship's requirements.</p>

                                <div class="panel-grid">
                                    <div class="info-card">
                                        <h4><i class="fas fa-user"></i> Your Profile</h4>
                                        <ul class="info-list">
                                            <li><strong>Name:</strong> <?php echo htmlspecialchars((string) $applicantDisplayName); ?></li>
                                            <li><strong>Email:</strong> <?php echo htmlspecialchars((string) $userEmail); ?></li>
                                            <li><strong>Applicant Type:</strong> <?php echo htmlspecialchars(formatApplicantTypeLabel($userApplicantType ?: '')); ?></li>
                                            <li><strong>School:</strong> <?php echo htmlspecialchars((string) ($userSchool ?: 'Not set')); ?></li>
                                            <li><strong>Course:</strong> <?php echo htmlspecialchars((string) ($userCourse ?: 'Not set')); ?></li>
                                            <li><strong>Admission Status:</strong> <?php echo htmlspecialchars(formatAdmissionStatusLabel($userAdmissionStatus ?: '')); ?></li>
                                            <li><strong>Year Level:</strong> <?php echo htmlspecialchars(formatYearLevelLabel($userYearLevel ?: '')); ?></li>
                                            <?php if ($showShsDetails): ?>
                                            <li><strong>SHS Strand:</strong> <?php echo htmlspecialchars((string) ($userShsStrand ?: 'Not set')); ?></li>
                                            <?php endif; ?>
                                            <li><strong>GWA:</strong> <?php echo $profileHasGwa ? htmlspecialchars(number_format((float) $userGWA, 2)) : '<span class="pill-badge warning">Not uploaded</span>'; ?></li>
                                        </ul>
                                    </div>

                                    <div class="info-card">
                                        <h4><i class="fas fa-clipboard-check"></i> Scholarship Rules</h4>
                                        <ul class="info-list">
                                            <li><strong>Required GWA:</strong> <?php echo $gwaRequired ? ('<= ' . htmlspecialchars(number_format((float) $requiredGwa, 2))) : 'No fixed requirement'; ?></li>
                                            <li><strong>Target Applicants:</strong> <?php echo htmlspecialchars($audienceLabel); ?></li>
                                            <li><strong>Application Opens:</strong> <?php echo htmlspecialchars($applicationOpenDateLabel); ?></li>
                                            <li><strong>Process:</strong> <?php echo htmlspecialchars($applicationProcessLabel); ?></li>
                                            <li><strong>Assessment:</strong> <?php echo htmlspecialchars($assessmentLabel); ?></li>
                                            <li><strong>Deadline:</strong> <?php echo htmlspecialchars($deadlineLabel); ?></li>
                                            <li><strong>Status:</strong> <?php echo $deadlinePassed ? '<span class="pill-badge danger">Closed</span>' : ($applicationNotYetOpen ? '<span class="pill-badge warning">Opens Soon</span>' : '<span class="pill-badge success">Open</span>'); ?></li>
                                        </ul>
                                    </div>
                                </div>

                                <?php if (($profileEvaluation['total'] ?? 0) > 0): ?>
                                    <div class="info-card" style="margin-top: 16px;">
                                        <h4><i class="fas fa-user-shield"></i> Profile Policy Checks</h4>
                                        <ul class="info-list">
                                            <?php foreach (($profileEvaluation['checks'] ?? []) as $check): ?>
                                                <li>
                                                    <strong><?php echo htmlspecialchars((string) ($check['label'] ?? 'Policy')); ?>:</strong>
                                                    <?php echo htmlspecialchars((string) ($check['detail'] ?? '')); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <div class="wizard-navigation">
                                    <a href="scholarships.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Scholarships</a>
                                    <button type="button" class="btn btn-primary" data-next-step="2">Continue to Documents <i class="fas fa-arrow-right"></i></button>
                                </div>
                            </div>
                        </div>

                        <div class="wizard-step-panel form-card-modern" data-step-panel="2">
                            <div class="card-header">
                                <h3><i class="fas fa-folder-open"></i> Required Documents</h3>
                            </div>
                            <div class="card-body">
                                <p class="panel-subtitle">Missing and rejected documents block submission. Pending documents are allowed.</p>

                                <div class="document-summary-grid">
                                    <div class="summary-tile"><span class="tile-label">Required</span><span class="tile-value"><?php echo $totalRequired; ?></span></div>
                                    <div class="summary-tile"><span class="tile-label">Verified</span><span class="tile-value"><?php echo $verifiedRequired; ?></span></div>
                                    <div class="summary-tile"><span class="tile-label">Pending</span><span class="tile-value"><?php echo $pendingRequired; ?></span></div>
                                    <div class="summary-tile <?php echo ($missingRequired + $rejectedRequired) > 0 ? 'danger' : ''; ?>"><span class="tile-label">Need Action</span><span class="tile-value"><?php echo $missingRequired + $rejectedRequired; ?></span></div>
                                </div>

                                <?php if ($totalRequired === 0): ?>
                                    <div class="wizard-alert info">
                                        <i class="fas fa-circle-info"></i>
                                        <div>
                                            <h4>No Required Documents Configured</h4>
                                            <p>This scholarship currently has no required documents configured.</p>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="document-list">
                                    <?php foreach ($docRequirements as $requirement): ?>
                                        <?php
                                        $docStatus = strtolower((string) ($requirement['status'] ?? 'missing'));
                                        $docStatusClass = in_array($docStatus, ['verified', 'pending', 'missing', 'rejected'], true) ? $docStatus : 'missing';
                                        $docStatusLabel = ucfirst($docStatusClass);
                                        $docIcon = 'fa-circle-question';
                                        if ($docStatusClass === 'verified') $docIcon = 'fa-check-circle';
                                        if ($docStatusClass === 'pending') $docIcon = 'fa-clock';
                                        if ($docStatusClass === 'missing') $docIcon = 'fa-triangle-exclamation';
                                        if ($docStatusClass === 'rejected') $docIcon = 'fa-xmark-circle';
                                        $docFile = $requirement['document'] ?? null;
                                        ?>
                                        <div class="document-row <?php echo $docStatusClass; ?>">
                                            <div class="document-meta">
                                                <h4><?php echo htmlspecialchars((string) ($requirement['name'] ?? 'Required Document')); ?></h4>
                                                <p>Code: <code><?php echo htmlspecialchars((string) ($requirement['type'] ?? 'N/A')); ?></code></p>
                                                <?php if (!empty($docFile['file_name'])): ?><p>File: <?php echo htmlspecialchars((string) $docFile['file_name']); ?></p><?php endif; ?>
                                                <?php if (!empty($docFile['uploaded_at'])): ?><p>Uploaded: <?php echo date('M d, Y h:i A', strtotime((string) $docFile['uploaded_at'])); ?></p><?php endif; ?>
                                                <?php if (!empty($docFile['admin_notes'])): ?><p class="doc-note"><strong>Admin note:</strong> <?php echo htmlspecialchars((string) $docFile['admin_notes']); ?></p><?php endif; ?>
                                            </div>
                                            <div class="document-state">
                                                <span class="pill-badge <?php echo $docStatusClass; ?>"><i class="fas <?php echo $docIcon; ?>"></i> <?php echo htmlspecialchars($docStatusLabel); ?></span>
                                                <?php if (in_array($docStatusClass, ['missing', 'rejected'], true)): ?>
                                                    <a href="documents.php" class="btn btn-outline btn-sm">Upload / Replace</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="wizard-navigation">
                                    <button type="button" class="btn btn-outline" data-prev-step="1"><i class="fas fa-arrow-left"></i> Back</button>
                                    <button type="button" class="btn btn-primary" data-next-step="3">Continue to Review <i class="fas fa-arrow-right"></i></button>
                                </div>
                            </div>
                        </div>

                        <div class="wizard-step-panel form-card-modern" data-step-panel="3">
                            <div class="card-header">
                                <h3><i class="fas fa-paper-plane"></i> Review and Submit</h3>
                            </div>
                            <div class="card-body">
                                <p class="panel-subtitle">Final check before submitting your scholarship application.</p>

                                <div class="review-grid">
                                    <div class="review-card"><span>Applicant</span><strong><?php echo htmlspecialchars((string) $applicantDisplayName); ?></strong></div>
                                    <div class="review-card"><span>Scholarship</span><strong><?php echo htmlspecialchars((string) $scholarship['name']); ?></strong></div>
                                    <div class="review-card"><span>Profile Policy</span><strong><?php echo htmlspecialchars((string) ($profileEvaluation['label'] ?? 'Open profile policy')); ?></strong></div>
                                    <div class="review-card"><span>Documents</span><strong><?php echo $documentsReady ? 'Ready (pending allowed)' : 'Not ready'; ?></strong></div>
                                    <div class="review-card"><span>Application Opens</span><strong><?php echo htmlspecialchars($applicationOpenDateLabel); ?></strong></div>
                                    <div class="review-card"><span>Deadline</span><strong><?php echo htmlspecialchars($deadlineLabel); ?></strong></div>
                                </div>

                                <div class="terms-box">
                                    <h4>Terms and Confirmation</h4>
                                    <label class="checkbox-line"><input type="checkbox" id="agreeTerms" name="agree_terms" value="1" required><span>I agree to the scholarship terms and verification policy.</span></label>
                                    <label class="checkbox-line"><input type="checkbox" id="confirmInfo" name="confirm_info" value="1" required><span>I confirm all submitted information is true and complete.</span></label>
                                </div>

                                <div class="wizard-navigation">
                                    <button type="button" class="btn btn-outline" data-prev-step="2"><i class="fas fa-arrow-left"></i> Back</button>
                                    <?php if ($canSubmit): ?>
                                        <button type="submit" class="btn btn-primary" id="submitApplication"><i class="fas fa-paper-plane"></i> Submit Application</button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-primary" id="submitApplication" disabled title="<?php echo htmlspecialchars($blockReason); ?>"><i class="fas fa-ban"></i> Not Ready to Submit</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="scholarship_id" value="<?php echo (int) $scholarshipId; ?>">
                        <input type="hidden" id="missingDocsCount" value="<?php echo (int) $missingRequired; ?>">
                        <input type="hidden" id="rejectedDocsCount" value="<?php echo (int) $rejectedRequired; ?>">
                        <input type="hidden" id="wizardProfilePendingCount" value="<?php echo (int) ($profileEvaluation['pending'] ?? 0); ?>">
                        <input type="hidden" id="wizardProfileFailedCount" value="<?php echo (int) ($profileEvaluation['failed'] ?? 0); ?>">
                        <input type="hidden" id="wizardGwaRequired" value="<?php echo $gwaRequired ? '1' : '0'; ?>">
                        <input type="hidden" id="wizardHasGwa" value="<?php echo $profileHasGwa ? '1' : '0'; ?>">
                        <input type="hidden" id="wizardGwaWithinRequirement" value="<?php echo $gwaWithinRequirement ? '1' : '0'; ?>">
                    </form>
                </div>

                <aside class="wizard-sidebar">
                    <div class="form-card-modern">
                        <div class="card-header">
                            <h3><i class="fas fa-circle-info"></i> Scholarship Snapshot</h3>
                        </div>
                        <div class="card-body">
                            <div class="summary-avatar">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="summary-name"><?php echo htmlspecialchars((string) $scholarship['name']); ?></div>
                            <div class="summary-email"><?php echo htmlspecialchars((string) ($scholarship['provider'] ?? 'Provider not specified')); ?></div>
                            <div class="summary-role">
                                <i class="fas fa-calendar-days"></i>
                                Deadline: <?php echo htmlspecialchars($deadlineLabel); ?>
                            </div>

                            <ul class="sidebar-list">
                                <li><strong>Application Opens:</strong> <?php echo htmlspecialchars($applicationOpenDateLabel); ?></li>
                                <li><strong>Process:</strong> <?php echo htmlspecialchars($applicationProcessLabel); ?></li>
                                <li><strong>Assessment:</strong> <?php echo htmlspecialchars($assessmentLabel); ?></li>
                                <?php if ($scholarshipAddress !== ''): ?><li><strong>Address:</strong> <?php echo htmlspecialchars($scholarshipAddress); ?></li><?php endif; ?>
                                <li><strong>After You Apply:</strong> <?php echo htmlspecialchars($postApplicationSteps); ?></li>
                                <?php if ($renewalConditions !== ''): ?><li><strong>Renewal:</strong> <?php echo htmlspecialchars($renewalConditions); ?></li><?php endif; ?>
                                <?php if ($scholarshipRestrictions !== ''): ?><li><strong>Restrictions:</strong> <?php echo htmlspecialchars($scholarshipRestrictions); ?></li><?php endif; ?>
                            </ul>
                            <?php if (!empty($scholarship['assessment_link'])): ?>
                                <a href="<?php echo htmlspecialchars((string) $scholarship['assessment_link']); ?>" class="sidebar-link" target="_blank" rel="noopener noreferrer"><i class="fas fa-up-right-from-square"></i> Open Assessment Link</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($assessmentType === 'remote_examination' && !empty($remoteExamLocations)): ?>
                        <div class="form-card-modern">
                            <div class="card-header">
                                <h3><i class="fas fa-location-dot"></i> Remote Exam Sites</h3>
                            </div>
                            <div class="card-body">
                                <ul class="sidebar-list">
                                    <?php foreach (array_slice($remoteExamLocations, 0, 4) as $site): ?>
                                        <?php
                                        $parts = [];
                                        if (!empty($site['site_name'])) $parts[] = (string) $site['site_name'];
                                        if (!empty($site['address'])) $parts[] = (string) $site['address'];
                                        if (!empty($site['city'])) $parts[] = (string) $site['city'];
                                        if (!empty($site['province'])) $parts[] = (string) $site['province'];
                                        $siteLabel = implode(', ', $parts);
                                        ?>
                                        <?php if ($siteLabel !== ''): ?><li><?php echo htmlspecialchars($siteLabel); ?></li><?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                                <a href="<?php echo htmlspecialchars($remoteExamMapUrl); ?>" class="sidebar-link">
                                    <i class="fas fa-map-location-dot"></i> View all sites on map
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-card-modern">
                        <div class="card-header">
                            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <a href="documents.php" class="sidebar-link"><i class="fas fa-file-upload"></i> Manage Documents</a>
                            <a href="upload.php" class="sidebar-link"><i class="fas fa-scroll"></i> Upload TOR / Grades</a>
                            <a href="scholarships.php" class="sidebar-link"><i class="fas fa-graduation-cap"></i> Back to Scholarships</a>
                        </div>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include 'layout/footer.php'; ?>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/script.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/wizard.js')); ?>"></script>
</body>
</html>
