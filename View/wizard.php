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

$wizardDefaultScholarshipImage = resolvePublicUploadUrl(null, '../');
$wizardScholarshipImage = resolvePublicUploadUrl($scholarship['image'] ?? null, '../');
$wizardDescriptionPreview = trim(strip_tags((string) ($scholarship['description'] ?? '')));
if ($wizardDescriptionPreview === '') {
    $wizardDescriptionPreview = 'Review your readiness, required documents, and final confirmation before you submit.';
} elseif (strlen($wizardDescriptionPreview) > 170) {
    $wizardDescriptionPreview = substr($wizardDescriptionPreview, 0, 167) . '...';
}

$formattedRequiredGwa = $requiredGwa !== null
    ? rtrim(rtrim(number_format((float) $requiredGwa, 2), '0'), '.')
    : '';
$gwaDisplayValue = $gwaRequired && $formattedRequiredGwa !== ''
    ? $formattedRequiredGwa . ' or better'
    : 'No minimum GWA';

$documentsSummaryText = $totalRequired > 0
    ? ($uploadedRequired . '/' . $totalRequired . ' uploaded')
    : 'No required documents';

$documentsHelperText = 'Nothing else needed';
if ($totalRequired > 0) {
    if (($missingRequired + $rejectedRequired) > 0) {
        $documentsHelperText = ($missingRequired + $rejectedRequired) . ' need action';
    } elseif ($pendingRequired > 0) {
        $documentsHelperText = $pendingRequired . ' pending review';
    } elseif ($verifiedRequired === $totalRequired) {
        $documentsHelperText = 'All verified';
    } else {
        $documentsHelperText = 'Ready for review';
    }
}

$wizardCardState = 'estimated';
$wizardStatusLabel = 'Preparing';
$wizardStatusIcon = 'fa-hourglass-half';

if ($alreadyApplied) {
    $wizardCardState = 'estimated';
    $wizardStatusLabel = 'Already Applied';
    $wizardStatusIcon = 'fa-check-double';
} elseif ($deadlinePassed) {
    $wizardCardState = 'expired';
    $wizardStatusLabel = 'Closed';
    $wizardStatusIcon = 'fa-calendar-xmark';
} elseif ($applicationNotYetOpen) {
    $wizardCardState = 'estimated';
    $wizardStatusLabel = 'Opens Soon';
    $wizardStatusIcon = 'fa-hourglass-half';
} elseif ($canSubmit) {
    $wizardCardState = 'ready';
    $wizardStatusLabel = 'Ready to Submit';
    $wizardStatusIcon = 'fa-circle-check';
} elseif ($missingRequired > 0 || $rejectedRequired > 0) {
    $wizardCardState = 'docs';
    $wizardStatusLabel = 'Documents Needed';
    $wizardStatusIcon = 'fa-folder-open';
} elseif (($profileEvaluation['pending'] ?? 0) > 0) {
    $wizardCardState = 'profile';
    $wizardStatusLabel = 'Complete Profile';
    $wizardStatusIcon = 'fa-user-gear';
} elseif (($profileEvaluation['failed'] ?? 0) > 0 || ($gwaRequired && $profileHasGwa && !$gwaWithinRequirement)) {
    $wizardCardState = 'ineligible';
    $wizardStatusLabel = 'Not Ready Yet';
    $wizardStatusIcon = 'fa-triangle-exclamation';
} elseif ($pendingRequired > 0) {
    $wizardCardState = 'estimated';
    $wizardStatusLabel = 'Pending Review';
    $wizardStatusIcon = 'fa-clock';
}

$wizardNextTone = 'info';
$wizardNextMessage = 'Work through the three steps below to review your application before you submit.';
if ($alreadyApplied && !empty($existingApplication['applied_at'])) {
    $wizardNextMessage = 'You already submitted this scholarship on ' . date('F d, Y h:i A', strtotime((string) $existingApplication['applied_at'])) . '.';
} elseif ($canSubmit) {
    $wizardNextTone = 'success';
    $wizardNextMessage = 'Your profile and required documents are ready. Finish the final review when you are ready to submit.';
} elseif ($deadlinePassed) {
    $wizardNextTone = 'muted';
    $wizardNextMessage = 'This scholarship is already closed, so new submissions are no longer accepted.';
} elseif ($applicationNotYetOpen) {
    $wizardNextTone = 'info';
    $wizardNextMessage = 'Applications open on ' . $applicationOpenDateLabel . '. You can still prepare your profile and documents now.';
} elseif ($blockReason !== '') {
    $wizardNextTone = 'warning';
    $wizardNextMessage = $blockReason;
}

$wizardSupportChips = [];
if ($applicationProcessLabel !== '') {
    $wizardSupportChips[] = [
        'icon' => 'fa-list-check',
        'label' => $applicationProcessLabel
    ];
}
if ($assessmentLabel !== 'None') {
    $wizardSupportChips[] = [
        'icon' => 'fa-file-signature',
        'label' => $assessmentLabel
    ];
}
$wizardSupportChips[] = [
    'icon' => 'fa-calendar-days',
    'label' => $applicationNotYetOpen ? ('Opens ' . $applicationOpenDateLabel) : 'Applications open now'
];

$wizardSignalChips = [];
if (($missingRequired + $rejectedRequired) > 0) {
    $wizardSignalChips[] = [
        'tone' => 'warning',
        'icon' => 'fa-triangle-exclamation',
        'label' => ($missingRequired + $rejectedRequired) . ' document issue(s)'
    ];
}
if (($profileEvaluation['pending'] ?? 0) > 0) {
    $wizardSignalChips[] = [
        'tone' => 'warning',
        'icon' => 'fa-user-gear',
        'label' => (int) ($profileEvaluation['pending'] ?? 0) . ' profile item(s) to complete'
    ];
}
if ($pendingRequired > 0) {
    $wizardSignalChips[] = [
        'tone' => 'info',
        'icon' => 'fa-clock',
        'label' => $pendingRequired . ' pending review'
    ];
}

$assessmentDetails = trim((string) ($scholarship['assessment_details'] ?? ''));

$wizardEligibilityChecks = [];
$wizardEligibilityChecks[] = [
    'title' => 'GWA requirement',
    'state' => !$gwaRequired ? 'ok' : (!$profileHasGwa ? 'attention' : ($gwaWithinRequirement ? 'ok' : 'blocked')),
    'icon' => !$gwaRequired ? 'fa-circle-check' : (!$profileHasGwa ? 'fa-chart-line' : ($gwaWithinRequirement ? 'fa-circle-check' : 'fa-ban')),
    'detail' => !$gwaRequired
        ? 'This scholarship does not set a fixed minimum GWA.'
        : (!$profileHasGwa
            ? 'Upload your academic record so the system can read your GWA.'
            : ($gwaWithinRequirement
                ? ('Your current GWA of ' . number_format((float) $userGWA, 2) . ' meets the required ' . $formattedRequiredGwa . ' or better.')
                : ('Your current GWA of ' . number_format((float) $userGWA, 2) . ' is above the required ' . $formattedRequiredGwa . '.')))
];
$wizardEligibilityChecks[] = [
    'title' => 'Applicant profile',
    'state' => (($profileEvaluation['failed'] ?? 0) > 0) ? 'blocked' : ((($profileEvaluation['pending'] ?? 0) > 0) ? 'attention' : 'ok'),
    'icon' => (($profileEvaluation['failed'] ?? 0) > 0) ? 'fa-user-xmark' : ((($profileEvaluation['pending'] ?? 0) > 0) ? 'fa-user-gear' : 'fa-user-check'),
    'detail' => (($profileEvaluation['failed'] ?? 0) > 0)
        ? (string) ($profileEvaluation['label'] ?? 'Your current profile does not match this scholarship policy.')
        : ((($profileEvaluation['pending'] ?? 0) > 0)
            ? (string) ($profileEvaluation['label'] ?? 'Complete your applicant profile first.')
            : 'Your current applicant profile matches the target audience for this scholarship.')
];
$wizardEligibilityChecks[] = [
    'title' => 'Required documents',
    'state' => $totalRequired === 0 ? 'ok' : ($rejectedRequired > 0 ? 'blocked' : ($missingRequired > 0 ? 'attention' : ($pendingRequired > 0 ? 'info' : 'ok'))),
    'icon' => $totalRequired === 0 ? 'fa-circle-check' : ($rejectedRequired > 0 ? 'fa-xmark-circle' : ($missingRequired > 0 ? 'fa-folder-open' : ($pendingRequired > 0 ? 'fa-clock' : 'fa-circle-check'))),
    'detail' => $totalRequired === 0
        ? 'This scholarship does not list required documents.'
        : ($rejectedRequired > 0
            ? 'Re-upload rejected required documents before you continue.'
            : ($missingRequired > 0
                ? 'Upload all missing required documents before you continue.'
                : ($pendingRequired > 0
                    ? ($pendingRequired . ' required document(s) are still pending review, but you can continue.')
                    : 'All required documents are uploaded.')))
];
$wizardEligibilityChecks[] = [
    'title' => 'Application window',
    'state' => $alreadyApplied ? 'info' : ($deadlinePassed ? 'blocked' : ($applicationNotYetOpen ? 'attention' : 'ok')),
    'icon' => $alreadyApplied ? 'fa-check-double' : ($deadlinePassed ? 'fa-calendar-xmark' : ($applicationNotYetOpen ? 'fa-hourglass-half' : 'fa-calendar-check')),
    'detail' => $alreadyApplied
        ? ('You already applied on ' . date('F d, Y h:i A', strtotime((string) ($existingApplication['applied_at'] ?? 'now'))) . '.')
        : ($deadlinePassed
            ? 'The deadline has passed for this scholarship.'
            : ($applicationNotYetOpen
                ? ('Applications open on ' . $applicationOpenDateLabel . '.')
                : ('Applications are open until ' . $deadlineLabel . '.')))
];

$wizardProfileRuleCards = [];
foreach (($profileEvaluation['checks'] ?? []) as $check) {
    $profileCheckStatus = strtolower((string) ($check['status'] ?? 'info'));
    $profileCheckTone = 'info';
    if ($profileCheckStatus === 'met') {
        $profileCheckTone = 'ok';
    } elseif ($profileCheckStatus === 'pending') {
        $profileCheckTone = 'attention';
    } elseif ($profileCheckStatus === 'failed') {
        $profileCheckTone = 'blocked';
    }

    $wizardProfileRuleCards[] = [
        'tone' => $profileCheckTone,
        'label' => (string) ($check['label'] ?? 'Policy check'),
        'detail' => (string) ($check['detail'] ?? '')
    ];
}
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
            <div class="wizard-page-header wizard-page-header-selected app-page-hero">
                <div class="wizard-page-header-copy app-page-hero-copy">
                    <h1>
                        <i class="fas fa-file-signature"></i>
                        Application Wizard
                        <span class="wizard-role-badge app-page-hero-badge">
                            <i class="fas fa-graduation-cap"></i>
                            Scholarship Selected
                        </span>
                    </h1>
                    <p>Review your eligibility, documents, and final submission for <?php echo htmlspecialchars((string) $scholarship['name']); ?>.</p>
                </div>
                <div class="app-page-hero-side">
                    <a href="scholarships.php" class="btn btn-white wizard-back-link app-page-hero-action">
                        <i class="fas fa-arrow-left"></i>
                        Back to Scholarships
                    </a>
                </div>
            </div>

            <div class="wizard-selected-shell">
                <div class="wizard-selected-card state-<?php echo htmlspecialchars($wizardCardState); ?>">
                    <div class="wizard-selected-card-inner">
                        <div class="wizard-selected-brand">
                            <div class="wizard-selected-kicker">
                                <span class="wizard-brand-pill">
                                    <i class="fas fa-file-signature"></i>
                                    Scholarship Wizard
                                </span>
                                <span class="wizard-selected-status state-<?php echo htmlspecialchars($wizardCardState); ?>">
                                    <i class="fas <?php echo htmlspecialchars($wizardStatusIcon); ?>"></i>
                                    <?php echo htmlspecialchars($wizardStatusLabel); ?>
                                </span>
                            </div>

                            <div class="wizard-logo-stage">
                                <img
                                    src="<?php echo htmlspecialchars($wizardScholarshipImage); ?>"
                                    alt="<?php echo htmlspecialchars((string) $scholarship['name']); ?>"
                                    class="wizard-logo-image"
                                    onerror="this.src='<?php echo htmlspecialchars($wizardDefaultScholarshipImage); ?>'"
                                >
                            </div>
                        </div>

                        <div class="wizard-selected-content">
                            <div class="wizard-selected-header">
                                <div class="wizard-selected-title-wrap">
                                    <h1 class="wizard-selected-title"><?php echo htmlspecialchars((string) $scholarship['name']); ?></h1>
                                    <p class="wizard-selected-description"><?php echo htmlspecialchars($wizardDescriptionPreview); ?></p>
                                </div>


                            </div>

                            <div class="wizard-provider-summary">
                                <div class="wizard-provider-line">
                                    <span class="wizard-provider-label">Provider</span>
                                    <strong class="wizard-provider-inline-name"><?php echo htmlspecialchars((string) ($scholarship['provider'] ?? 'Provider not specified')); ?></strong>
                                </div>
                                <p class="wizard-provider-inline-note">We'll guide you through the same three steps every scholarship uses: eligibility, documents, and final review.</p>

                                <?php if (!empty($wizardSupportChips)): ?>
                                    <div class="wizard-support-chip-row wizard-support-chip-row-inline">
                                        <?php foreach ($wizardSupportChips as $supportChip): ?>
                                            <span class="wizard-support-chip">
                                                <i class="fas <?php echo htmlspecialchars((string) $supportChip['icon']); ?>"></i>
                                                <?php echo htmlspecialchars((string) $supportChip['label']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="wizard-selected-facts">
                                <div class="wizard-selected-fact">
                                    <span class="wizard-selected-fact-label">Minimum GWA</span>
                                    <strong><?php echo htmlspecialchars($gwaDisplayValue); ?></strong>
                                </div>
                                <div class="wizard-selected-fact">
                                    <span class="wizard-selected-fact-label">Deadline</span>
                                    <strong><?php echo htmlspecialchars($deadlineLabel); ?></strong>
                                </div>
                                <div class="wizard-selected-fact">
                                    <span class="wizard-selected-fact-label">Audience</span>
                                    <strong><?php echo htmlspecialchars($audienceLabel); ?></strong>
                                </div>
                                <div class="wizard-selected-fact">
                                    <span class="wizard-selected-fact-label">Application Opens</span>
                                    <strong><?php echo htmlspecialchars($applicationOpenDateLabel); ?></strong>
                                    <small><?php echo $applicationNotYetOpen ? 'Prepare early' : 'Open now'; ?></small>
                                </div>
                            </div>

                            <div class="wizard-next-step-banner is-<?php echo htmlspecialchars($wizardNextTone); ?>">
                                <i class="fas <?php echo htmlspecialchars($wizardStatusIcon); ?>"></i>
                                <span><?php echo htmlspecialchars($wizardNextMessage); ?></span>
                            </div>

                            <?php if (!empty($wizardSignalChips)): ?>
                                <div class="wizard-signal-chip-row">
                                    <?php foreach ($wizardSignalChips as $signalChip): ?>
                                        <span class="wizard-signal-chip is-<?php echo htmlspecialchars((string) $signalChip['tone']); ?>">
                                            <i class="fas <?php echo htmlspecialchars((string) $signalChip['icon']); ?>"></i>
                                            <?php echo htmlspecialchars((string) $signalChip['label']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>


                            <div class="wizard-stepper wizard-stepper-card" role="tablist" aria-label="Application steps">
                                <button type="button" class="wizard-step-pill is-active" data-step-target="1"><span>1</span> Eligibility</button>
                                <button type="button" class="wizard-step-pill" data-step-target="2"><span>2</span> Documents</button>
                                <button type="button" class="wizard-step-pill" data-step-target="3"><span>3</span> Review & Submit</button>
                            </div>
                        </div>
                    </div>
                </div>

                <form id="applicationForm" class="wizard-form wizard-form-selected" method="POST" action="../app/Controllers/submit_application.php" data-can-submit="<?php echo $canSubmit ? '1' : '0'; ?>" data-block-reason="<?php echo htmlspecialchars($blockReason, ENT_QUOTES); ?>">
                    <div class="wizard-step-panel is-active form-card-modern wizard-step-surface" data-step-panel="1">
                        <div class="card-header wizard-step-header">
                            <div>
                                <h3><i class="fas fa-user-check"></i> Step 1: Eligibility</h3>
                                <p>Check your profile against the rules before moving to documents.</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="wizard-panel-grid">
                                <div class="wizard-section-card">
                                    <h4>Your information</h4>
                                    <dl class="wizard-detail-list">
                                        <div><dt>Name</dt><dd><?php echo htmlspecialchars((string) $applicantDisplayName); ?></dd></div>
                                        <div><dt>Email</dt><dd><?php echo htmlspecialchars((string) $userEmail); ?></dd></div>
                                        <div><dt>Applicant Type</dt><dd><?php echo htmlspecialchars(formatApplicantTypeLabel($userApplicantType ?: '')); ?></dd></div>
                                        <div><dt>School</dt><dd><?php echo htmlspecialchars((string) ($userSchool ?: 'Not set')); ?></dd></div>
                                        <div><dt>Course</dt><dd><?php echo htmlspecialchars((string) ($userCourse ?: 'Not set')); ?></dd></div>
                                        <div><dt>Admission Status</dt><dd><?php echo htmlspecialchars(formatAdmissionStatusLabel($userAdmissionStatus ?: '')); ?></dd></div>
                                        <div><dt>Year Level</dt><dd><?php echo htmlspecialchars(formatYearLevelLabel($userYearLevel ?: '')); ?></dd></div>
                                        <?php if ($showShsDetails): ?>
                                            <div><dt>SHS Strand</dt><dd><?php echo htmlspecialchars((string) ($userShsStrand ?: 'Not set')); ?></dd></div>
                                        <?php endif; ?>
                                        <div><dt>GWA</dt><dd><?php echo $profileHasGwa ? htmlspecialchars(number_format((float) $userGWA, 2)) : 'Not uploaded'; ?></dd></div>
                                    </dl>
                                </div>

                                <div class="wizard-section-card">
                                    <h4>Checks before you continue</h4>
                                    <div class="wizard-check-list">
                                        <?php foreach ($wizardEligibilityChecks as $eligibilityCheck): ?>
                                            <div class="wizard-check-item is-<?php echo htmlspecialchars((string) $eligibilityCheck['state']); ?>">
                                                <div class="wizard-check-icon">
                                                    <i class="fas <?php echo htmlspecialchars((string) $eligibilityCheck['icon']); ?>"></i>
                                                </div>
                                                <div class="wizard-check-copy">
                                                    <strong><?php echo htmlspecialchars((string) $eligibilityCheck['title']); ?></strong>
                                                    <p><?php echo htmlspecialchars((string) $eligibilityCheck['detail']); ?></p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="wizard-inline-tools wizard-inline-tools-profile">
                                <span class="wizard-inline-tools-label">Need to update your profile?</span>
                                <a href="profile.php" class="wizard-inline-action wizard-inline-action-card">
                                    <i class="fas fa-user-pen"></i>
                                    Open Profile
                                </a>
                            </div>

                            <?php if (!empty($wizardProfileRuleCards)): ?>
                                <div class="wizard-section-card wizard-section-card-wide">
                                    <h4>Audience rules</h4>
                                    <div class="wizard-rule-grid">
                                        <?php foreach ($wizardProfileRuleCards as $profileRule): ?>
                                            <div class="wizard-rule-card is-<?php echo htmlspecialchars((string) $profileRule['tone']); ?>">
                                                <strong><?php echo htmlspecialchars((string) $profileRule['label']); ?></strong>
                                                <p><?php echo htmlspecialchars((string) $profileRule['detail']); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="wizard-navigation wizard-navigation-forward">
                                <button type="button" class="btn btn-primary" data-next-step="2">Continue to Documents <i class="fas fa-arrow-right"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="wizard-step-panel form-card-modern wizard-step-surface" data-step-panel="2">
                        <div class="card-header wizard-step-header">
                            <div>
                                <h3><i class="fas fa-folder-open"></i> Step 2: Documents</h3>
                                <p>Missing and rejected documents stop submission. Pending documents can still move forward.</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="wizard-doc-summary">
                                <div class="wizard-doc-stat">
                                    <span>Required</span>
                                    <strong><?php echo $totalRequired; ?></strong>
                                </div>
                                <div class="wizard-doc-stat">
                                    <span>Verified</span>
                                    <strong><?php echo $verifiedRequired; ?></strong>
                                </div>
                                <div class="wizard-doc-stat">
                                    <span>Pending</span>
                                    <strong><?php echo $pendingRequired; ?></strong>
                                </div>
                                <div class="wizard-doc-stat is-warning">
                                    <span>Need Action</span>
                                    <strong><?php echo $missingRequired + $rejectedRequired; ?></strong>
                                </div>
                            </div>

                            <div class="wizard-inline-tools wizard-inline-tools-docs">
                                <span class="wizard-inline-tools-label">Need to upload or replace a file?</span>
                                <a href="documents.php" class="wizard-inline-action wizard-inline-action-card">
                                    <i class="fas fa-file-upload"></i>
                                    Manage Documents
                                </a>
                            </div>

                            <?php if ($totalRequired === 0): ?>
                                <div class="wizard-next-step-banner is-info">
                                    <i class="fas fa-circle-info"></i>
                                    <span>This scholarship currently has no required documents configured.</span>
                                </div>
                            <?php else: ?>
                                <div class="wizard-doc-list-modern">
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
                                        <div class="wizard-doc-item-modern is-<?php echo $docStatusClass; ?>">
                                            <div class="wizard-doc-main">
                                                <div class="wizard-doc-icon">
                                                    <i class="fas <?php echo $docIcon; ?>"></i>
                                                </div>
                                                <div class="wizard-doc-copy">
                                                    <strong><?php echo htmlspecialchars((string) ($requirement['name'] ?? 'Required Document')); ?></strong>
                                                    <p>Code: <code><?php echo htmlspecialchars((string) ($requirement['type'] ?? 'N/A')); ?></code></p>
                                                    <?php if (!empty($docFile['file_name'])): ?><p>File: <?php echo htmlspecialchars((string) $docFile['file_name']); ?></p><?php endif; ?>
                                                    <?php if (!empty($docFile['uploaded_at'])): ?><p>Uploaded: <?php echo date('M d, Y h:i A', strtotime((string) $docFile['uploaded_at'])); ?></p><?php endif; ?>
                                                    <?php if (!empty($docFile['admin_notes'])): ?><p class="wizard-doc-note"><strong>Admin note:</strong> <?php echo htmlspecialchars((string) $docFile['admin_notes']); ?></p><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="wizard-doc-side">
                                                <span class="pill-badge <?php echo $docStatusClass; ?>">
                                                    <i class="fas <?php echo $docIcon; ?>"></i>
                                                    <?php echo htmlspecialchars($docStatusLabel); ?>
                                                </span>
                                                <?php if (in_array($docStatusClass, ['missing', 'rejected'], true)): ?>
                                                    <a href="documents.php" class="wizard-inline-action">Upload / Replace</a>
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

                    <div class="wizard-step-panel form-card-modern wizard-step-surface" data-step-panel="3">
                        <div class="card-header wizard-step-header">
                            <div>
                                <h3><i class="fas fa-paper-plane"></i> Step 3: Review & Submit</h3>
                                <p>Confirm the essentials one last time before you send your application.</p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="wizard-review-grid-modern">
                                <div class="wizard-review-card">
                                    <span class="wizard-review-label">Applicant</span>
                                    <strong class="wizard-review-value"><?php echo htmlspecialchars((string) $applicantDisplayName); ?></strong>
                                </div>
                                <div class="wizard-review-card">
                                    <span class="wizard-review-label">Scholarship</span>
                                    <strong class="wizard-review-value"><?php echo htmlspecialchars((string) $scholarship['name']); ?></strong>
                                </div>
                                <div class="wizard-review-card">
                                    <span class="wizard-review-label">Profile</span>
                                    <strong class="wizard-review-value"><?php echo htmlspecialchars((string) ($profileEvaluation['label'] ?? 'Open profile policy')); ?></strong>
                                </div>
                                <div class="wizard-review-card">
                                    <span class="wizard-review-label">Documents</span>
                                    <strong class="wizard-review-value"><?php echo $documentsReady ? 'Ready (pending allowed)' : 'Not ready'; ?></strong>
                                </div>
                                <div class="wizard-review-card">
                                    <span class="wizard-review-label">Application Opens</span>
                                    <strong class="wizard-review-value"><?php echo htmlspecialchars($applicationOpenDateLabel); ?></strong>
                                </div>
                                <div class="wizard-review-card">
                                    <span class="wizard-review-label">Deadline</span>
                                    <strong class="wizard-review-value"><?php echo htmlspecialchars($deadlineLabel); ?></strong>
                                </div>
                            </div>

                            <div class="wizard-section-card wizard-section-card-wide wizard-submit-panel">
                                <h4>After you submit</h4>
                                <div class="wizard-submit-meta">
                                    <div>
                                        <span class="wizard-submit-label">Process</span>
                                        <strong class="wizard-submit-value"><?php echo htmlspecialchars($applicationProcessLabel); ?></strong>
                                    </div>
                                    <?php if ($assessmentLabel !== 'None'): ?>
                                        <div>
                                            <span class="wizard-submit-label">Assessment</span>
                                            <strong class="wizard-submit-value"><?php echo htmlspecialchars($assessmentLabel); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <span class="wizard-submit-label">Next step</span>
                                        <strong class="wizard-submit-value"><?php echo htmlspecialchars($postApplicationSteps); ?></strong>
                                    </div>
                                    <?php if ($assessmentDetails !== ''): ?>
                                        <div>
                                            <span class="wizard-submit-label">Assessment details</span>
                                            <strong class="wizard-submit-value"><?php echo htmlspecialchars($assessmentDetails); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($assessmentType === 'remote_examination' && !empty($remoteExamLocations)): ?>
                                        <div>
                                            <span class="wizard-submit-label">Exam sites</span>
                                            <strong class="wizard-submit-value"><a href="<?php echo htmlspecialchars($remoteExamMapUrl); ?>" class="wizard-inline-action">View all sites on map</a></strong>
                                        </div>
                                    <?php elseif (!empty($scholarship['assessment_link'])): ?>
                                        <div>
                                            <span class="wizard-submit-label">Assessment link</span>
                                            <strong class="wizard-submit-value"><a href="<?php echo htmlspecialchars((string) $scholarship['assessment_link']); ?>" class="wizard-inline-action" target="_blank" rel="noopener noreferrer">Open assessment page</a></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
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
        <?php endif; ?>
    </div>
</section>

<?php include 'layout/footer.php'; ?>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/script.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/wizard.js')); ?>"></script>
</body>
</html>
