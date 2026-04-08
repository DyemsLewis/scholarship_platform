<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/provider_scope.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/helpers.php';
require_once __DIR__ . '/../app/Models/UserDocument.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to view applications.');

$appIdParam = $_GET['id'] ?? null;
if ($appIdParam === null || $appIdParam === '' || !is_numeric($appIdParam)) {
    $_SESSION['error'] = 'Invalid application ID.';
    header('Location: manage_applications.php');
    exit();
}

$applicationId = (int) $appIdParam;
requireValidEntityUrlToken('application', $applicationId, $_GET['token'] ?? null, 'view', 'manage_applications.php', 'Invalid or expired application access link.');

function appValue($value, string $fallback = 'Not specified'): string
{
    $trimmed = trim((string) ($value ?? ''));
    return $trimmed !== '' ? $trimmed : $fallback;
}

function appDate(?string $value, string $format = 'M d, Y h:i A', string $fallback = 'Not specified'): string
{
    if (empty($value)) {
        return $fallback;
    }

    $timestamp = strtotime((string) $value);
    return $timestamp ? date($format, $timestamp) : $fallback;
}

function appAddress(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['house_no'] ?? '')),
        trim((string) ($row['street'] ?? '')),
        trim((string) ($row['barangay'] ?? '')),
        trim((string) ($row['city'] ?? '')),
        trim((string) ($row['province'] ?? ''))
    ]);

    if (!empty($parts)) {
        return implode(', ', $parts);
    }

    return appValue($row['address'] ?? '');
}

function appHasValue($value): bool
{
    return trim((string) ($value ?? '')) !== '';
}

function appTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
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

$applicationRejectionReasonSelect = appTableHasColumn($pdo, 'applications', 'rejection_reason')
    ? 'a.rejection_reason AS application_rejection_reason,'
    : 'NULL AS application_rejection_reason,';
$applicationStudentResponseStatusSelect = appTableHasColumn($pdo, 'applications', 'student_response_status')
    ? 'a.student_response_status AS student_response_status,'
    : 'NULL AS student_response_status,';
$applicationStudentRespondedAtSelect = appTableHasColumn($pdo, 'applications', 'student_responded_at')
    ? 'a.student_responded_at AS student_responded_at,'
    : 'NULL AS student_responded_at,';

try {
    $stmt = $pdo->prepare("
        SELECT
            a.id AS application_id,
            a.status AS application_status,
            a.probability_score,
            {$applicationRejectionReasonSelect}
            {$applicationStudentResponseStatusSelect}
            {$applicationStudentRespondedAtSelect}
            a.applied_at,
            a.updated_at,
            u.id AS user_id,
            u.username AS user_name,
            u.email,
            u.status AS user_status,
            sd.*,
            s.id AS scholarship_id,
            s.name AS scholarship_name,
            sd2.provider,
            sd2.deadline,
            sd2.benefits,
            s.description AS scholarship_description,
            sl.location_name AS student_location_name
        FROM applications a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN student_data sd ON u.id = sd.student_id
        JOIN scholarships s ON a.scholarship_id = s.id
        LEFT JOIN scholarship_data sd2 ON s.id = sd2.scholarship_id
        LEFT JOIN student_location sl ON u.id = sl.student_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    $_SESSION['error'] = 'Unable to load application details right now.';
    header('Location: manage_applications.php');
    exit();
}

if (!$application) {
    $_SESSION['error'] = 'Application not found.';
    header('Location: manage_applications.php');
    exit();
}

if (!providerCanAccessApplication($pdo, $applicationId)) {
    $_SESSION['error'] = 'You can only review applications submitted to your scholarship programs.';
    header('Location: manage_applications.php');
    exit();
}

$fullName = trim(implode(' ', array_filter([
    $application['firstname'] ?? '',
    $application['middleinitial'] ?? '',
    $application['lastname'] ?? '',
    $application['suffix'] ?? ''
])));
if ($fullName === '') {
    $fullName = appValue($application['user_name'] ?? '', 'Unknown User');
}

$documentsModel = new UserDocument($pdo);
$applicantDocuments = [];
$requirementsSummary = [
    'total_required' => 0,
    'uploaded' => 0,
    'verified' => 0,
    'pending' => 0,
    'requirements' => []
];

try {
    $applicantDocuments = $documentsModel->getUserDocuments((int) $application['user_id']);
    $requirementsSummary = $documentsModel->checkScholarshipRequirements((int) $application['user_id'], (int) $application['scholarship_id']);
} catch (Throwable $e) {
    error_log('Application document review load failed: ' . $e->getMessage());
}

$documentStats = ['total' => count($applicantDocuments), 'verified' => 0, 'pending' => 0, 'rejected' => 0];
foreach ($applicantDocuments as $document) {
    $status = (string) ($document['status'] ?? '');
    if (isset($documentStats[$status])) {
        $documentStats[$status]++;
    }
}

$requirementStatusCounts = [
    'verified' => 0,
    'pending' => 0,
    'missing' => 0,
    'rejected' => 0,
];
foreach (($requirementsSummary['requirements'] ?? []) as $requirementItem) {
    $requirementStatus = (string) ($requirementItem['status'] ?? 'missing');
    if (isset($requirementStatusCounts[$requirementStatus])) {
        $requirementStatusCounts[$requirementStatus]++;
    }
}

$applicationStatus = (string) ($application['application_status'] ?? 'pending');
$applicationRejectionReason = trim((string) ($application['application_rejection_reason'] ?? ''));
$studentResponseStatus = strtolower(trim((string) ($application['student_response_status'] ?? '')));
$studentRespondedAt = !empty($application['student_responded_at']) ? (string) $application['student_responded_at'] : null;
$studentAccepted = strtolower($applicationStatus) === 'approved' && $studentResponseStatus === 'accepted';
$statusIconMap = ['pending' => 'fa-clock', 'approved' => 'fa-circle-check', 'rejected' => 'fa-circle-xmark'];
$requirementsVerifiedCount = (int) ($requirementsSummary['verified'] ?? 0);
$requirementsTotalCount = (int) ($requirementsSummary['total_required'] ?? 0);
$requirementsPendingCount = (int) ($requirementsSummary['pending'] ?? 0);
$requirementsRemainingCount = max($requirementsTotalCount - $requirementsVerifiedCount, 0);
$lastUpdatedValue = !empty($application['updated_at']) ? $application['updated_at'] : ($application['applied_at'] ?? null);
$scholarshipProvider = appValue($application['provider'] ?? '');
$reviewReadinessLabel = $requirementsTotalCount > 0
    ? $requirementsVerifiedCount . '/' . $requirementsTotalCount . ' verified'
    : 'No required documents';
$applicantProgramLabel = formatApplicantTypeLabel($application['applicant_type'] ?? '');
$courseLabel = appValue($application['course'] ?? '', 'Course not set');
$schoolLabel = appValue($application['school'] ?? '', 'School not set');
$currentGwaLabel = is_numeric($application['gwa'] ?? null) ? number_format((float) $application['gwa'], 2) : 'Not set';
$yearLevelLabel = formatYearLevelLabel($application['year_level'] ?? '');
$enrollmentStatusLabel = formatEnrollmentStatusLabel($application['enrollment_status'] ?? '');
$academicStandingLabel = formatAcademicStandingLabel($application['academic_standing'] ?? '');
$admissionStatusLabel = appHasValue($application['admission_status'] ?? null)
    ? formatAdmissionStatusLabel($application['admission_status'] ?? '')
    : 'Not set';
$formattedGenderLabel = appHasValue($application['gender'] ?? null)
    ? ucwords(str_replace('_', ' ', (string) $application['gender']))
    : 'Not set';
$citizenshipLabel = appHasValue($application['citizenship'] ?? null)
    ? formatCitizenshipLabel($application['citizenship'] ?? '')
    : 'Not set';
$householdIncomeLabel = appHasValue($application['household_income_bracket'] ?? null)
    ? formatHouseholdIncomeBracketLabel($application['household_income_bracket'] ?? '')
    : 'Not set';
$specialCategoryLabel = appHasValue($application['special_category'] ?? null)
    ? formatSpecialCategoryLabel($application['special_category'] ?? '')
    : 'Not set';
$birthAndAgeParts = [];
if (appHasValue($application['birthdate'] ?? null)) {
    $birthAndAgeParts[] = appDate($application['birthdate'] ?? null, 'F j, Y');
}
if (appHasValue($application['age'] ?? null)) {
    $birthAndAgeParts[] = trim((string) $application['age']) . ' years old';
}
$birthAndAgeLabel = !empty($birthAndAgeParts) ? implode(' / ', $birthAndAgeParts) : 'Not set';
$addressLabel = appAddress($application);
$cityProvinceParts = array_filter([
    trim((string) ($application['city'] ?? '')),
    trim((string) ($application['province'] ?? ''))
]);
$cityProvinceLabel = !empty($cityProvinceParts) ? implode(', ', $cityProvinceParts) : 'Not set';
$profileEducationMetaParts = [];
if ($schoolLabel !== 'School not set') {
    $profileEducationMetaParts[] = $schoolLabel;
}
if ($yearLevelLabel !== 'Not set') {
    $profileEducationMetaParts[] = $yearLevelLabel;
}
$profileResidenceMeta = appHasValue($application['barangay'] ?? null)
    ? 'Barangay ' . trim((string) $application['barangay'])
    : 'Address on record';
$profileApplicantMeta = $admissionStatusLabel !== 'Not set' ? $admissionStatusLabel : 'Screening profile';
$profileHighlightItems = [
    ['label' => 'Applicant Type', 'value' => $applicantProgramLabel, 'meta' => $profileApplicantMeta],
    ['label' => 'Academic Record', 'value' => $currentGwaLabel, 'meta' => $academicStandingLabel],
    ['label' => 'Current Program', 'value' => $courseLabel, 'meta' => !empty($profileEducationMetaParts) ? implode(' / ', $profileEducationMetaParts) : 'Academic profile'],
    ['label' => 'Residence', 'value' => $cityProvinceLabel, 'meta' => $profileResidenceMeta],
];
$identityFields = [
    ['label' => 'Applicant Name', 'value' => $fullName],
    ['label' => 'Email Address', 'value' => appValue($application['email'] ?? '')],
    ['label' => 'Mobile Number', 'value' => appValue($application['mobile_number'] ?? '')],
    ['label' => 'Gender', 'value' => $formattedGenderLabel],
    ['label' => 'Birthdate / Age', 'value' => $birthAndAgeLabel],
    ['label' => 'Citizenship', 'value' => $citizenshipLabel],
    ['label' => 'Applicant Type', 'value' => $applicantProgramLabel],
    ['label' => 'Applicant Account', 'value' => ucfirst((string) ($application['user_status'] ?? 'inactive'))],
];
$academicFields = [
    ['label' => 'Current GWA', 'value' => $currentGwaLabel],
    ['label' => 'School / Institution', 'value' => $schoolLabel],
    ['label' => 'Course / Program', 'value' => $courseLabel],
    ['label' => 'Year Level', 'value' => $yearLevelLabel],
    ['label' => 'Enrollment Status', 'value' => $enrollmentStatusLabel],
    ['label' => 'Academic Standing', 'value' => $academicStandingLabel],
];
$admissionsFields = [];
if (appHasValue($application['admission_status'] ?? null)) {
    $admissionsFields[] = ['label' => 'Admission Status', 'value' => $admissionStatusLabel];
}
if (appHasValue($application['target_college'] ?? null)) {
    $admissionsFields[] = ['label' => 'Target College', 'value' => appValue($application['target_college'] ?? '')];
}
if (appHasValue($application['target_course'] ?? null)) {
    $admissionsFields[] = ['label' => 'Target Course', 'value' => appValue($application['target_course'] ?? '')];
}
$backgroundFields = [];
if (appHasValue($application['shs_school'] ?? null)) {
    $backgroundFields[] = ['label' => 'Senior High School', 'value' => appValue($application['shs_school'] ?? '')];
}
if (appHasValue($application['shs_strand'] ?? null)) {
    $backgroundFields[] = ['label' => 'SHS Strand', 'value' => appValue($application['shs_strand'] ?? '')];
}
if (appHasValue($application['shs_graduation_year'] ?? null)) {
    $backgroundFields[] = ['label' => 'SHS Graduation Year', 'value' => appValue($application['shs_graduation_year'] ?? '')];
}
if (appHasValue($application['shs_average'] ?? null)) {
    $backgroundFields[] = ['label' => 'SHS Average', 'value' => appValue($application['shs_average'] ?? '')];
}
$contextFields = [
    ['label' => 'Complete Address', 'value' => $addressLabel, 'full' => true],
    ['label' => 'City / Province', 'value' => $cityProvinceLabel],
];
if (appHasValue($application['barangay'] ?? null)) {
    $contextFields[] = ['label' => 'Barangay', 'value' => trim((string) ($application['barangay'] ?? ''))];
}
$contextFields[] = ['label' => 'Household Income', 'value' => $householdIncomeLabel];
$contextFields[] = ['label' => 'Special Category', 'value' => $specialCategoryLabel];
$requirementsCompletionPercent = $requirementsTotalCount > 0
    ? (int) round(($requirementsVerifiedCount / max($requirementsTotalCount, 1)) * 100)
    : 100;
$requirementsCompletionPercent = max(0, min(100, $requirementsCompletionPercent));
$studentResponseLabel = $studentAccepted
    ? 'Accepted'
    : (strtolower($applicationStatus) === 'approved' ? 'Awaiting student confirmation' : 'Not available');
$studentResponseNote = $studentAccepted
    ? 'Accepted on ' . appDate($studentRespondedAt, 'F j, Y g:i A', 'the latest response date') . '.'
    : (strtolower($applicationStatus) === 'approved'
        ? 'Waiting for the student to confirm the approved scholarship.'
        : 'Student confirmation becomes available after approval.');
$requirementsRemainingLabel = $requirementsTotalCount > 0
    ? ($requirementsRemainingCount > 0
        ? $requirementsRemainingCount . ' pending requirement' . ($requirementsRemainingCount === 1 ? '' : 's')
        : 'All required documents verified')
    : 'No required documents configured';
$decisionReadinessTone = 'ready';
$decisionReadinessLabel = 'Ready for review';
$decisionReadinessNote = 'This application can proceed through the review workflow.';
if ($requirementsTotalCount > 0 && $requirementsVerifiedCount < $requirementsTotalCount) {
    $decisionReadinessTone = 'attention';
    $decisionReadinessLabel = 'Additional verification needed';
    $decisionReadinessNote = $requirementsRemainingLabel . ' before approval can be completed.';
}
if ($studentAccepted) {
    $decisionReadinessLabel = 'Acceptance recorded';
    $decisionReadinessNote = 'The approved scholarship offer has already been accepted by the student.';
} elseif ($applicationStatus === 'approved') {
    $decisionReadinessLabel = 'Approved and waiting for student confirmation';
    $decisionReadinessNote = 'The application is approved. Waiting for the student to accept the scholarship.';
} elseif ($applicationStatus === 'rejected') {
    $decisionReadinessTone = 'attention';
    $decisionReadinessLabel = 'Decision completed';
    $decisionReadinessNote = 'The application has already been rejected.';
}
$canApproveApplication = $applicationStatus === 'pending' && ($requirementsTotalCount === 0 || $requirementsVerifiedCount >= $requirementsTotalCount);
$approvalBlockMessage = 'Approval is unavailable while required documents are pending, missing, or rejected.';
$adminStyleVersion = @filemtime(__DIR__ . '/../public/css/admin_style.css') ?: time();
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();
$viewApplicationStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/view-application.css') ?: time();
$applicationActionParams = ['id' => (int) $application['application_id']];
$approveUrl = buildEntityUrl('../app/AdminControllers/application_process.php', 'application', (int) $application['application_id'], 'approve', array_merge(['action' => 'approve'], $applicationActionParams));
$rejectUrl = buildEntityUrl('../app/AdminControllers/application_process.php', 'application', (int) $application['application_id'], 'reject', array_merge(['action' => 'reject'], $applicationActionParams));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Review - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css?v=<?php echo urlencode((string) $adminStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css?v=<?php echo urlencode((string) $reviewsStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/view-application.css?v=<?php echo urlencode((string) $viewApplicationStyleVersion); ?>">
</head>
<body class="application-review-screen">
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard application-review-page">
        <div class="container">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success application-review-alert">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error application-review-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php $reviewsCurrentView = 'applications'; include 'layouts/reviews_nav.php'; ?>

            <div class="review-page-toolbar">
                <a href="manage_applications.php" class="review-page-back">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Applications</span>
                </a>
                <div class="review-page-jump-links" aria-label="Application review sections">
                    <a href="#applicantProfile" class="review-jump-link">Applicant</a>
                    <a href="#requiredDocuments" class="review-jump-link">Requirements</a>
                    <a href="#decisionSummary" class="review-jump-link">Decision</a>
                </div>
            </div>

            <div class="review-top-grid">
                <section class="review-spotlight-card">
                    <div class="review-spotlight-header">
                        <span class="review-spotlight-kicker"><i class="fas fa-file-signature"></i> Application Review</span>
                        <span class="review-spotlight-program"><?php echo htmlspecialchars($application['scholarship_name']); ?></span>
                    </div>
                    <div class="review-spotlight-main">
                        <div class="review-spotlight-copy">
                            <h1><?php echo htmlspecialchars($fullName); ?></h1>
                            <p>Applied to <strong><?php echo htmlspecialchars($application['scholarship_name']); ?></strong> under <strong><?php echo htmlspecialchars($scholarshipProvider); ?></strong>.</p>
                            <div class="review-spotlight-tags">
                                <span class="review-spotlight-tag"><i class="fas fa-user-graduate"></i><?php echo htmlspecialchars($applicantProgramLabel); ?></span>
                                <span class="review-spotlight-tag"><i class="fas fa-school"></i><?php echo htmlspecialchars($schoolLabel); ?></span>
                                <span class="review-spotlight-tag"><i class="fas fa-book-open"></i><?php echo htmlspecialchars($courseLabel); ?></span>
                                <span class="review-spotlight-tag"><i class="fas fa-layer-group"></i><?php echo htmlspecialchars(formatYearLevelLabel($application['year_level'] ?? '')); ?></span>
                                <span class="review-spotlight-tag"><i class="fas fa-user-check"></i><?php echo htmlspecialchars(formatEnrollmentStatusLabel($application['enrollment_status'] ?? '')); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="review-spotlight-metrics">
                        <div class="review-spotlight-metric">
                            <span class="review-spotlight-label">Submitted</span>
                            <strong><?php echo htmlspecialchars(appDate($application['applied_at'] ?? null)); ?></strong>
                        </div>
                        <div class="review-spotlight-metric">
                            <span class="review-spotlight-label">Last Updated</span>
                            <strong><?php echo htmlspecialchars(appDate($lastUpdatedValue)); ?></strong>
                        </div>
                        <div class="review-spotlight-metric">
                            <span class="review-spotlight-label">Current GWA</span>
                            <strong><?php echo is_numeric($application['gwa'] ?? null) ? htmlspecialchars(number_format((float) $application['gwa'], 2)) : 'Not set'; ?></strong>
                        </div>
                        <div class="review-spotlight-metric">
                            <span class="review-spotlight-label">Documents on File</span>
                            <strong><?php echo number_format($documentStats['total']); ?></strong>
                        </div>
                    </div>
                </section>

                <aside class="review-decision-desk tone-<?php echo htmlspecialchars($decisionReadinessTone); ?>" id="decisionSummary">
                    <div class="review-decision-desk-header">
                        <span class="review-decision-desk-label">Decision Desk</span>
                        <span class="hero-status-pill"><i class="fas <?php echo htmlspecialchars($statusIconMap[$applicationStatus] ?? 'fa-circle-info'); ?>"></i> <?php echo htmlspecialchars(ucfirst($applicationStatus)); ?></span>
                    </div>
                    <h2><?php echo htmlspecialchars($decisionReadinessLabel); ?></h2>
                    <p><?php echo htmlspecialchars($decisionReadinessNote); ?></p>

                    <div class="review-decision-progress">
                        <div class="review-decision-progress-top">
                            <span>Requirement progress</span>
                            <strong><?php echo $requirementsCompletionPercent; ?>%</strong>
                        </div>
                        <div class="review-decision-progress-bar" aria-hidden="true">
                            <span style="width: <?php echo $requirementsCompletionPercent; ?>%;"></span>
                        </div>
                        <span class="review-decision-progress-note"><?php echo htmlspecialchars($requirementsRemainingLabel); ?></span>
                    </div>

                    <div class="review-decision-stats">
                        <div class="review-decision-stat">
                            <span>Verified</span>
                            <strong><?php echo $requirementsVerifiedCount; ?>/<?php echo $requirementsTotalCount; ?></strong>
                        </div>
                        <div class="review-decision-stat">
                            <span>Pending</span>
                            <strong><?php echo $requirementsPendingCount; ?></strong>
                        </div>
                        <div class="review-decision-stat">
                            <span>Score</span>
                            <strong><?php echo $application['probability_score'] !== null ? htmlspecialchars(number_format((float) $application['probability_score'], 1) . '%') : 'N/A'; ?></strong>
                        </div>
                    </div>

                    <?php if ($applicationStatus === 'approved'): ?>
                        <div class="review-note ready"><strong>Student Response:</strong> <?php echo htmlspecialchars($studentResponseNote); ?></div>
                    <?php endif; ?>

                    <?php if ($applicationStatus === 'pending'): ?>
                        <div class="review-decision-actions">
                            <?php if ($canApproveApplication): ?>
                                <button
                                    type="button"
                                    class="btn btn-success application-decision-trigger"
                                    data-action="approve"
                                    data-action-url="<?php echo htmlspecialchars($approveUrl); ?>"
                                    data-applicant-name="<?php echo htmlspecialchars($fullName); ?>"
                                    data-scholarship-name="<?php echo htmlspecialchars((string) $application['scholarship_name']); ?>"
                                >
                                    <i class="fas fa-check"></i> Approve Application
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-success btn-disabled" disabled title="<?php echo htmlspecialchars($approvalBlockMessage); ?>">
                                    <i class="fas fa-lock"></i> Approve Application
                                </button>
                            <?php endif; ?>
                            <button
                                type="button"
                                class="btn btn-danger application-decision-trigger"
                                data-action="reject"
                                data-action-url="<?php echo htmlspecialchars($rejectUrl); ?>"
                                data-applicant-name="<?php echo htmlspecialchars($fullName); ?>"
                                data-scholarship-name="<?php echo htmlspecialchars((string) $application['scholarship_name']); ?>"
                            >
                                <i class="fas fa-times"></i> Reject Application
                            </button>
                        </div>
                        <?php if (!$canApproveApplication): ?>
                            <div class="review-note warning"><?php echo htmlspecialchars($approvalBlockMessage); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($applicationStatus === 'rejected' && $applicationRejectionReason !== ''): ?>
                        <div class="review-note rejection"><strong>Rejection reason:</strong> <?php echo nl2br(htmlspecialchars($applicationRejectionReason)); ?></div>
                    <?php endif; ?>
                </aside>
            </div>

            <div class="review-snapshot-grid">
                <article class="review-snapshot-card tone-academic">
                    <span class="review-snapshot-label">Academic Profile</span>
                    <strong><?php echo htmlspecialchars($courseLabel); ?></strong>
                    <p><?php echo htmlspecialchars($schoolLabel); ?></p>
                </article>
                <article class="review-snapshot-card tone-documents">
                    <span class="review-snapshot-label">Requirements</span>
                    <strong><?php echo htmlspecialchars($reviewReadinessLabel); ?></strong>
                    <p><?php echo htmlspecialchars($requirementsRemainingLabel); ?></p>
                </article>
                <article class="review-snapshot-card tone-activity">
                    <span class="review-snapshot-label">Document Activity</span>
                    <strong><?php echo number_format($documentStats['verified']); ?> verified</strong>
                    <p><?php echo number_format($documentStats['pending']); ?> pending review</p>
                </article>
                <article class="review-snapshot-card tone-status">
                    <span class="review-snapshot-label">Applicant Account</span>
                    <strong><?php echo htmlspecialchars(ucfirst((string) ($application['user_status'] ?? 'inactive'))); ?></strong>
                    <p><?php echo htmlspecialchars(appDate($lastUpdatedValue)); ?></p>
                </article>
            </div>

            <div class="review-layout">
                <div class="review-main">
                    <div class="review-panel review-panel-profile" id="applicantProfile">
                        <div class="review-panel-header">
                            <div>
                                <h2>Applicant Profile</h2>
                                <p>Identity, academic standing, education pathway, and household details for application review.</p>
                            </div>
                        </div>
                        <div class="profile-review-highlights">
                            <?php foreach ($profileHighlightItems as $highlightItem): ?>
                                <article class="profile-highlight-card">
                                    <span class="profile-highlight-label"><?php echo htmlspecialchars($highlightItem['label']); ?></span>
                                    <strong class="profile-highlight-value"><?php echo htmlspecialchars($highlightItem['value']); ?></strong>
                                    <span class="profile-highlight-meta"><?php echo htmlspecialchars($highlightItem['meta']); ?></span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <div class="section-stack">
                            <section class="profile-review-section">
                                <h3 class="subsection-title"><i class="fas fa-id-card"></i> Identity and Contact</h3>
                                <div class="detail-grid">
                                    <?php foreach ($identityFields as $field): ?>
                                        <div class="detail-card">
                                            <span class="detail-label"><?php echo htmlspecialchars($field['label']); ?></span>
                                            <div class="detail-value"><?php echo htmlspecialchars($field['value']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>

                            <section class="profile-review-section">
                                <h3 class="subsection-title"><i class="fas fa-graduation-cap"></i> Current Academic Record</h3>
                                <div class="detail-grid">
                                    <?php foreach ($academicFields as $field): ?>
                                        <div class="detail-card">
                                            <span class="detail-label"><?php echo htmlspecialchars($field['label']); ?></span>
                                            <div class="detail-value"><?php echo htmlspecialchars($field['value']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>

                            <?php if (!empty($admissionsFields)): ?>
                            <section class="profile-review-section">
                                <h3 class="subsection-title"><i class="fas fa-route"></i> Admissions and Transition</h3>
                                <div class="detail-grid">
                                    <?php foreach ($admissionsFields as $field): ?>
                                        <div class="detail-card">
                                            <span class="detail-label"><?php echo htmlspecialchars($field['label']); ?></span>
                                            <div class="detail-value"><?php echo htmlspecialchars($field['value']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <?php if (!empty($backgroundFields)): ?>
                            <section class="profile-review-section">
                                <h3 class="subsection-title"><i class="fas fa-school"></i> Prior Academic Background</h3>
                                <div class="detail-grid">
                                    <?php foreach ($backgroundFields as $field): ?>
                                        <div class="detail-card">
                                            <span class="detail-label"><?php echo htmlspecialchars($field['label']); ?></span>
                                            <div class="detail-value"><?php echo htmlspecialchars($field['value']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <section class="profile-review-section">
                                <h3 class="subsection-title"><i class="fas fa-house-user"></i> Residence and Household Context</h3>
                                <div class="detail-grid">
                                    <?php foreach ($contextFields as $field): ?>
                                        <div class="detail-card<?php echo !empty($field['full']) ? ' full' : ''; ?>">
                                            <span class="detail-label"><?php echo htmlspecialchars($field['label']); ?></span>
                                            <div class="detail-value<?php echo !empty($field['muted']) ? ' muted' : ''; ?>">
                                                <?php echo nl2br(htmlspecialchars($field['value'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div class="review-panel" id="requiredDocuments">
                        <div class="review-panel-header">
                            <div><h2>Required Documents</h2><p>Verification status for scholarship requirements</p></div>
                            <span class="status-pill info"><?php echo $requirementsVerifiedCount; ?>/<?php echo $requirementsTotalCount; ?> verified</span>
                        </div>
                        <?php if ((int) ($requirementsSummary['total_required'] ?? 0) === 0): ?>
                            <div class="empty-state-inline"><i class="fas fa-list-check"></i><h3>No requirements configured</h3><p>This scholarship does not currently have document requirements in the system.</p></div>
                        <?php else: ?>
                            <div class="requirements-summary-strip">
                                <div class="requirements-summary-item tone-total">
                                    <span class="requirements-summary-label">Total Required</span>
                                    <strong><?php echo $requirementsTotalCount; ?></strong>
                                </div>
                                <div class="requirements-summary-item tone-missing">
                                    <span class="requirements-summary-label">Missing / Reupload</span>
                                    <strong><?php echo $requirementStatusCounts['missing'] + $requirementStatusCounts['rejected']; ?></strong>
                                </div>
                                <div class="requirements-summary-item tone-review">
                                    <span class="requirements-summary-label">For Review</span>
                                    <strong><?php echo $requirementStatusCounts['pending'] + $requirementStatusCounts['verified']; ?></strong>
                                </div>
                            </div>

                            <div class="requirements-review-list">
                                <?php foreach (($requirementsSummary['requirements'] ?? []) as $index => $requirement): ?>
                                    <?php
                                    $reqDoc = $requirement['document'] ?? null;
                                    $reqStatus = (string) ($requirement['status'] ?? 'missing');
                                    $reqHref = is_array($reqDoc) ? resolveStoredFileUrl($reqDoc['file_path'] ?? null, '../') : null;
                                    $reqNumber = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                                    $reqFileName = is_array($reqDoc) ? (string) ($reqDoc['file_name'] ?? 'Uploaded file') : '';
                                    $hasReqActions = $reqHref !== '' || ($reqStatus === 'pending' && is_array($reqDoc));
                                    $reqSupportText = 'No file uploaded yet.';
                                    if ($reqFileName !== '') {
                                        $reqSupportText = $reqFileName;
                                    } elseif ($reqStatus === 'rejected') {
                                        $reqSupportText = 'Previously submitted document needs correction.';
                                    }
                                    ?>
                                    <article class="requirement-review-item status-<?php echo htmlspecialchars($reqStatus); ?><?php echo $hasReqActions ? '' : ' no-actions'; ?>">
                                        <div class="requirement-review-main">
                                            <div class="requirement-review-number"><?php echo htmlspecialchars($reqNumber); ?></div>
                                            <div class="requirement-review-copy">
                                                <div class="requirement-review-head">
                                                    <div class="requirement-review-title-group">
                                                        <h4><?php echo htmlspecialchars((string) ($requirement['name'] ?? 'Requirement')); ?></h4>
                                                        <p><?php echo htmlspecialchars($reqSupportText); ?></p>
                                                    </div>
                                                </div>
                                                <div class="requirement-review-meta">
                                                    <?php if ($reqHref !== ''): ?>
                                                        <span class="requirement-meta-chip"><i class="fas fa-file-lines"></i>Document available</span>
                                                    <?php else: ?>
                                                        <span class="requirement-meta-chip muted"><i class="fas fa-cloud-arrow-up"></i>Upload still required</span>
                                                    <?php endif; ?>
                                                    <?php if ($reqStatus === 'pending'): ?>
                                                        <span class="requirement-meta-chip pending"><i class="fas fa-hourglass-half"></i>Awaiting verification</span>
                                                    <?php elseif ($reqStatus === 'verified'): ?>
                                                        <span class="requirement-meta-chip verified"><i class="fas fa-circle-check"></i>Requirement cleared</span>
                                                    <?php elseif ($reqStatus === 'rejected'): ?>
                                                        <span class="requirement-meta-chip rejected"><i class="fas fa-rotate-left"></i>Needs resubmission</span>
                                                    <?php else: ?>
                                                        <span class="requirement-meta-chip muted"><i class="fas fa-circle-exclamation"></i>Still incomplete</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($reqStatus === 'rejected' && is_array($reqDoc) && !empty($reqDoc['rejection_reason'])): ?>
                                                    <div class="review-note rejection"><strong>Rejection reason:</strong> <?php echo htmlspecialchars((string) $reqDoc['rejection_reason']); ?></div>
                                                <?php elseif ($reqStatus === 'missing'): ?>
                                                    <div class="review-note warning">This requirement is still missing from the application record.</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($hasReqActions): ?>
                                            <div class="requirement-review-actions">
                                                <?php if ($reqHref !== ''): ?>
                                                    <?php
                                                    $reqPreviewType = storedFilePreviewType(
                                                        $reqDoc['file_path'] ?? null,
                                                        $reqDoc['file_name'] ?? null,
                                                        $reqDoc['mime_type'] ?? null
                                                    );
                                                    ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline open-file-modal"
                                                        data-file-url="<?php echo htmlspecialchars($reqHref); ?>"
                                                        data-file-name="<?php echo htmlspecialchars((string) ($reqDoc['file_name'] ?? 'Document')); ?>"
                                                        data-file-type="<?php echo htmlspecialchars($reqPreviewType); ?>"
                                                    >
                                                        <i class="fas fa-eye"></i> View File
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($reqStatus === 'pending' && is_array($reqDoc)): ?>
                                                    <button type="button" class="btn btn-success" onclick="verifyDocument(<?php echo (int) $reqDoc['id']; ?>, <?php echo (int) $application['user_id']; ?>)"><i class="fas fa-check-circle"></i> Verify</button>
                                                    <button type="button" class="btn btn-danger" onclick="openRejectModal(<?php echo (int) $reqDoc['id']; ?>, <?php echo (int) $application['user_id']; ?>)"><i class="fas fa-times-circle"></i> Reject</button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <aside class="review-sidebar">
                    <div class="review-panel review-panel-timeline">
                        <div class="review-panel-header"><div><h3>Review Timeline</h3><p>Current progress across the application review process</p></div></div>
                        <div class="review-timeline">
                            <div class="review-timeline-item complete">
                                <span class="review-timeline-marker"><i class="fas fa-file-arrow-up"></i></span>
                                <div class="review-timeline-copy">
                                    <strong>Application submitted</strong>
                                    <span><?php echo htmlspecialchars(appDate($application['applied_at'] ?? null)); ?></span>
                                </div>
                            </div>
                            <div class="review-timeline-item <?php echo $requirementsRemainingCount === 0 ? 'complete' : 'current'; ?>">
                                <span class="review-timeline-marker"><i class="fas fa-folder-open"></i></span>
                                <div class="review-timeline-copy">
                                    <strong>Document verification</strong>
                                    <span><?php echo htmlspecialchars($requirementsRemainingLabel); ?></span>
                                </div>
                            </div>
                            <div class="review-timeline-item <?php echo $applicationStatus === 'pending' ? 'upcoming' : 'complete'; ?>">
                                <span class="review-timeline-marker"><i class="fas fa-gavel"></i></span>
                                <div class="review-timeline-copy">
                                    <strong>Final decision</strong>
                                    <span><?php echo htmlspecialchars($studentAccepted ? 'Accepted' : ucfirst($applicationStatus)); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="review-panel review-panel-summary">
                        <div class="review-panel-header"><div><h3>Verification Snapshot</h3><p>Current standing of the application and supporting records</p></div></div>
                        <div class="sidebar-stack">
                            <div class="meta-item"><span class="label">Current Status</span><span class="value"><span class="status-pill <?php echo htmlspecialchars($applicationStatus); ?>"><i class="fas <?php echo htmlspecialchars($statusIconMap[$applicationStatus] ?? 'fa-circle-info'); ?>"></i> <?php echo htmlspecialchars(ucfirst($applicationStatus)); ?></span></span></div>
                            <div class="meta-item"><span class="label">Student Response</span><span class="value"><?php echo htmlspecialchars($studentResponseLabel); ?></span><span class="subvalue"><?php echo htmlspecialchars($studentResponseNote); ?></span></div>
                            <div class="meta-item"><span class="label">Application Score</span><span class="value"><?php echo $application['probability_score'] !== null ? htmlspecialchars(number_format((float) $application['probability_score'], 1) . '%') : 'Not calculated'; ?></span><span class="subvalue">Profile score</span></div>
                            <div class="meta-item"><span class="label">Requirements Snapshot</span><span class="value"><?php echo htmlspecialchars(((int) ($requirementsSummary['uploaded'] ?? 0)) . ' uploaded | ' . $requirementsVerifiedCount . ' verified'); ?></span><span class="subvalue"><?php echo htmlspecialchars($requirementsPendingCount . ' pending'); ?></span></div>
                            <div class="meta-item"><span class="label">Applicant Account</span><span class="value"><?php echo htmlspecialchars(ucfirst((string) ($application['user_status'] ?? 'inactive'))); ?></span><span class="subvalue">Student account standing</span></div>
                            <?php if ($requirementsTotalCount > 0 && $requirementsVerifiedCount < $requirementsTotalCount): ?><div class="review-note warning"><?php echo htmlspecialchars($requirementsRemainingCount . ' required document' . ($requirementsRemainingCount === 1 ? ' is' : 's are') . ' still awaiting completion or verification.'); ?></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="review-panel review-panel-program">
                        <div class="review-panel-header"><div><h3>Scholarship Information</h3><p>Program details attached to this application</p></div></div>
                        <div class="meta-list">
                            <div class="meta-item"><span class="label">Scholarship</span><span class="value"><?php echo htmlspecialchars($application['scholarship_name']); ?></span></div>
                            <div class="meta-item"><span class="label">Provider</span><span class="value"><?php echo htmlspecialchars($scholarshipProvider); ?></span></div>
                            <div class="meta-item"><span class="label">Deadline</span><span class="value"><?php echo htmlspecialchars(appDate($application['deadline'] ?? null, 'F j, Y')); ?></span></div>
                            <div class="meta-item"><span class="label">Benefits</span><span class="value"><?php echo nl2br(htmlspecialchars(appValue($application['benefits'] ?? '', 'No benefit summary provided.'))); ?></span></div>
                            <div class="meta-item"><span class="label">Program Notes</span><span class="value"><?php echo nl2br(htmlspecialchars(appValue($application['scholarship_description'] ?? '', 'No scholarship description available.'))); ?></span></div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </section>

    <div id="rejectModal" class="app-modal" aria-hidden="true">
        <div class="app-modal-content">
            <div class="app-modal-header">
                <h3><i class="fas fa-times-circle app-modal-heading-icon"></i> Reject Document</h3>
                <button type="button" class="app-modal-close" onclick="closeRejectModal()" aria-label="Close rejection modal">&times;</button>
            </div>
            <div class="app-modal-body">
                <p class="app-modal-intro">Provide a reason so the student knows what needs to be corrected before re-uploading the document.</p>
                <textarea id="rejectionReason" class="app-textarea" placeholder="Example: Document is blurry, signature is missing, or wrong document type."></textarea>
            </div>
            <div class="app-modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeRejectModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmReject()">Confirm Rejection</button>
            </div>
        </div>
    </div>

    <div id="filePreviewModal" class="app-modal file-preview-modal" aria-hidden="true">
        <div class="app-modal-content file-preview-content">
            <div class="app-modal-header">
                <div class="file-preview-heading">
                    <h3><i class="fas fa-file-lines"></i> Document Preview</h3>
                    <p id="filePreviewName" class="app-modal-intro">Selected document</p>
                </div>
                <button type="button" class="app-modal-close" onclick="closeFilePreviewModal()" aria-label="Close file preview modal">&times;</button>
            </div>
            <div class="app-modal-body file-preview-body">
                <div id="filePreviewFrameWrap" class="file-preview-frame-wrap">
                    <iframe id="filePreviewFrame" title="Document preview"></iframe>
                    <img id="filePreviewImage" alt="Document preview">
                </div>
                <p id="filePreviewFallback" class="file-preview-fallback" hidden>Preview is limited for this file type inside the modal.</p>
            </div>
            <div class="app-modal-footer">
                <div class="file-preview-zoom-controls" aria-label="Preview zoom controls">
                    <button type="button" class="btn btn-outline file-preview-zoom-btn" id="filePreviewZoomOut" title="Zoom out">
                        <i class="fas fa-magnifying-glass-minus"></i>
                    </button>
                    <span id="filePreviewZoomLevel" class="file-preview-zoom-level">100%</span>
                    <button type="button" class="btn btn-outline file-preview-zoom-btn" id="filePreviewZoomIn" title="Zoom in">
                        <i class="fas fa-magnifying-glass-plus"></i>
                    </button>
                    <button type="button" class="btn btn-outline file-preview-reset-btn" id="filePreviewZoomReset">Reset</button>
                </div>
                <button type="button" class="btn btn-primary" onclick="closeFilePreviewModal()">Close</button>
            </div>
        </div>
    </div>

    <?php include 'layouts/admin_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let currentDocumentId = null;
        let currentUserId = null;
        const applicationReviewCsrfToken = <?php echo json_encode(csrfGetToken('application_review')); ?>;
        const documentReviewCsrfToken = <?php echo json_encode(csrfGetToken('document_review')); ?>;
        const filePreviewModal = document.getElementById('filePreviewModal');
        const filePreviewFrame = document.getElementById('filePreviewFrame');
        const filePreviewImage = document.getElementById('filePreviewImage');
        const filePreviewName = document.getElementById('filePreviewName');
        const filePreviewFallback = document.getElementById('filePreviewFallback');
        const filePreviewZoomOut = document.getElementById('filePreviewZoomOut');
        const filePreviewZoomIn = document.getElementById('filePreviewZoomIn');
        const filePreviewZoomReset = document.getElementById('filePreviewZoomReset');
        const filePreviewZoomLevel = document.getElementById('filePreviewZoomLevel');
        let filePreviewType = 'document';
        let filePreviewBaseUrl = '';
        let filePreviewZoom = 1;

        document.querySelectorAll('.application-decision-trigger').forEach((button) => {
            button.addEventListener('click', async () => {
                const action = button.dataset.action || '';
                const actionUrl = button.dataset.actionUrl || '';
                const applicantName = button.dataset.applicantName || 'this applicant';
                const scholarshipName = button.dataset.scholarshipName || 'this scholarship';

                if (!action || !actionUrl) {
                    return;
                }

                if (action === 'approve') {
                    const result = await Swal.fire({
                        title: 'Approve application?',
                        text: `${applicantName} will be marked as approved for ${scholarshipName}.`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#10b981',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Approve'
                    });

                    if (result.isConfirmed) {
                        submitApplicationDecision(actionUrl);
                    }
                    return;
                }

                const result = await Swal.fire({
                    title: 'Reject application',
                    text: 'Provide a reason that will also be included in the email notice.',
                    icon: 'warning',
                    input: 'textarea',
                    inputLabel: 'Rejection reason',
                    inputPlaceholder: 'Example: Required documents were incomplete or eligibility requirements were not met.',
                    inputAttributes: {
                        'aria-label': 'Rejection reason'
                    },
                    inputValidator: (value) => {
                        if (!value || !value.trim()) {
                            return 'A rejection reason is required.';
                        }
                        return null;
                    },
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Reject application'
                });

                if (result.isConfirmed) {
                    submitApplicationDecision(actionUrl, result.value || '');
                }
            });
        });

        function submitApplicationDecision(actionUrl, rejectionReason = '') {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = actionUrl;
            form.style.display = 'none';

            if (rejectionReason.trim() !== '') {
                const reasonInput = document.createElement('input');
                reasonInput.type = 'hidden';
                reasonInput.name = 'rejection_reason';
                reasonInput.value = rejectionReason.trim();
                form.appendChild(reasonInput);
            }

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = applicationReviewCsrfToken;
            form.appendChild(csrfInput);

            document.body.appendChild(form);
            form.submit();
        }

        document.querySelectorAll('.open-file-modal').forEach((button) => {
            button.addEventListener('click', function() {
                openFilePreviewModal(
                    button.dataset.fileUrl || '',
                    button.dataset.fileName || 'Document',
                    button.dataset.fileType || 'document'
                );
            });
        });

        if (filePreviewZoomOut) {
            filePreviewZoomOut.addEventListener('click', () => changeFilePreviewZoom(-0.25));
        }

        if (filePreviewZoomIn) {
            filePreviewZoomIn.addEventListener('click', () => changeFilePreviewZoom(0.25));
        }

        if (filePreviewZoomReset) {
            filePreviewZoomReset.addEventListener('click', resetFilePreviewZoom);
        }

        function verifyDocument(docId, userId) {
            Swal.fire({
                title: 'Verify document?',
                text: 'This will mark the selected document as verified.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Verify'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitDocumentReview('verify', docId, userId);
                }
            });
        }

        function openRejectModal(docId, userId) {
            currentDocumentId = docId;
            currentUserId = userId;
            document.getElementById('rejectionReason').value = '';
            document.getElementById('rejectModal').classList.add('active');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
            currentDocumentId = null;
            currentUserId = null;
        }

        function openFilePreviewModal(fileUrl, fileName, fileType) {
            if (!filePreviewModal || !filePreviewFrame || !filePreviewImage || !filePreviewName || !filePreviewFallback || !fileUrl) {
                return;
            }

            filePreviewType = fileType;
            filePreviewBaseUrl = fileUrl;
            filePreviewZoom = 1;
            filePreviewName.textContent = fileName;
            filePreviewFrame.style.display = 'none';
            filePreviewImage.style.display = 'none';
            filePreviewFrame.removeAttribute('src');
            filePreviewImage.removeAttribute('src');
            filePreviewFallback.hidden = true;

            if (fileType === 'image') {
                filePreviewImage.src = fileUrl;
                filePreviewImage.style.display = 'block';
                applyFilePreviewZoom();
            } else {
                if (fileType === 'pdf') {
                    applyFilePreviewZoom();
                } else {
                    filePreviewFrame.src = fileUrl;
                }
                filePreviewFrame.style.display = 'block';
                if (fileType !== 'pdf') {
                    filePreviewFallback.hidden = false;
                }
            }

            updateFilePreviewZoomControls();
            filePreviewModal.classList.add('active');
        }

        function closeFilePreviewModal() {
            if (!filePreviewModal || !filePreviewFrame || !filePreviewImage) {
                return;
            }

            filePreviewModal.classList.remove('active');
            filePreviewFrame.removeAttribute('src');
            filePreviewImage.removeAttribute('src');
            filePreviewFrame.style.display = 'none';
            filePreviewImage.style.display = 'none';
            filePreviewImage.style.width = '';
            filePreviewImage.style.maxWidth = '';
            filePreviewImage.style.maxHeight = '';
            filePreviewType = 'document';
            filePreviewBaseUrl = '';
            filePreviewZoom = 1;
            updateFilePreviewZoomControls();
        }

        function changeFilePreviewZoom(delta) {
            if (filePreviewType !== 'image' && filePreviewType !== 'pdf') {
                return;
            }

            filePreviewZoom = Math.max(0.5, Math.min(3, Number((filePreviewZoom + delta).toFixed(2))));
            applyFilePreviewZoom();
            updateFilePreviewZoomControls();
        }

        function resetFilePreviewZoom() {
            filePreviewZoom = 1;
            applyFilePreviewZoom();
            updateFilePreviewZoomControls();
        }

        function applyFilePreviewZoom() {
            if (filePreviewType === 'image') {
                if (Math.abs(filePreviewZoom - 1) < 0.01) {
                    filePreviewImage.style.width = '';
                    filePreviewImage.style.maxWidth = '';
                    filePreviewImage.style.maxHeight = '';
                } else {
                    filePreviewImage.style.width = `${Math.round(filePreviewZoom * 100)}%`;
                    filePreviewImage.style.maxWidth = 'none';
                    filePreviewImage.style.maxHeight = 'none';
                }
                return;
            }

            if (filePreviewType === 'pdf' && filePreviewBaseUrl) {
                filePreviewFrame.src = `${filePreviewBaseUrl}#toolbar=1&navpanes=0&zoom=${Math.round(filePreviewZoom * 100)}`;
            }
        }

        function updateFilePreviewZoomControls() {
            const isZoomable = filePreviewType === 'image' || filePreviewType === 'pdf';

            if (filePreviewZoomOut) {
                filePreviewZoomOut.disabled = !isZoomable;
            }
            if (filePreviewZoomIn) {
                filePreviewZoomIn.disabled = !isZoomable;
            }
            if (filePreviewZoomReset) {
                filePreviewZoomReset.disabled = !isZoomable || Math.abs(filePreviewZoom - 1) < 0.01;
            }
            if (filePreviewZoomLevel) {
                filePreviewZoomLevel.textContent = isZoomable
                    ? `${Math.round(filePreviewZoom * 100)}%`
                    : 'N/A';
            }
        }

        function confirmReject() {
            const reason = document.getElementById('rejectionReason').value.trim();
            const docId = currentDocumentId;
            const userId = currentUserId;

            if (!reason) {
                Swal.fire({ icon: 'warning', title: 'Reason required', text: 'Please provide a rejection reason before continuing.' });
                return;
            }

            closeRejectModal();
            submitDocumentReview('reject', docId, userId, reason);
        }

        function submitDocumentReview(action, docId, userId, reason = '') {
            Swal.fire({
                title: action === 'verify' ? 'Verifying document...' : 'Rejecting document...',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });

            const params = new URLSearchParams({ action, document_id: docId, user_id: userId });
            params.append('csrf_token', documentReviewCsrfToken);
            if (reason) {
                params.append('reason', reason);
            }

            fetch('../app/AdminControllers/verify_document_process.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: params.toString()
            })
                .then((response) => response.json())
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || 'The document could not be updated.');
                    }

                    return Swal.fire({
                        icon: 'success',
                        title: action === 'verify' ? 'Document verified' : 'Document rejected',
                        text: data.message,
                        timer: 1600,
                        showConfirmButton: false
                    });
                })
                .then(() => window.location.reload())
                .catch((error) => {
                    Swal.fire({ icon: 'error', title: 'Action failed', text: error.message || 'Unable to update the document right now.' });
                });
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeRejectModal();
                closeFilePreviewModal();
            }
        });

        document.addEventListener('click', function(event) {
            const rejectModal = document.getElementById('rejectModal');
            if (event.target === rejectModal) {
                closeRejectModal();
            }
            if (event.target === filePreviewModal) {
                closeFilePreviewModal();
            }
        });

    </script>
</body>
</html>
