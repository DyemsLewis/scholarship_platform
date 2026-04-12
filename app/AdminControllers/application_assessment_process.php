<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/provider_scope.php';
require_once __DIR__ . '/../Config/url_token.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Config/notification_helpers.php';
require_once __DIR__ . '/../Models/Application.php';
require_once __DIR__ . '/../Models/ActivityLog.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to manage application assessments.');

function applicationAssessmentTableExists(PDO $pdo, string $tableName): bool
{
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute([':table_name' => $tableName]);
        $cache[$tableName] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        error_log('application_assessment_process tableExists error: ' . $e->getMessage());
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function normalizeAssessmentStatusLabel(string $status): string
{
    $map = [
        'not_started' => 'Not started',
        'scheduled' => 'Scheduled',
        'ready' => 'Ready to take',
        'submitted' => 'Submitted / attended',
        'under_review' => 'Under review',
        'passed' => 'Passed',
        'failed' => 'Did not pass',
    ];

    return $map[$status] ?? 'Assessment updated';
}

function normalizeAssessmentTypeLabel(string $assessmentRequirement): string
{
    $map = [
        'online_exam' => 'Online Exam',
        'remote_examination' => 'Remote Examination',
        'assessment' => 'Online Assessment',
        'evaluation' => 'Online Evaluation',
    ];

    return $map[$assessmentRequirement] ?? 'Assessment';
}

function normalizeAssessmentRedirect(int $applicationId): string
{
    return normalizeAppUrl(buildEntityUrl('../AdminView/view_application.php', 'application', $applicationId, 'view', [
        'id' => $applicationId,
    ]) . '#reviewSupport');
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'save'));
$applicationId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($action !== 'save' || $applicationId <= 0) {
    $_SESSION['error'] = 'Invalid application assessment request.';
    header('Location: ' . normalizeAppUrl('../AdminView/manage_applications.php'));
    exit();
}

if (!isValidEntityUrlToken('application', $applicationId, $_GET['token'] ?? $_POST['token'] ?? null, 'assessment')) {
    $_SESSION['error'] = 'Invalid or expired assessment access token.';
    header('Location: ' . normalizeAppUrl('../AdminView/manage_applications.php'));
    exit();
}

$csrfValidation = csrfValidateRequest('application_assessment');
if (!$csrfValidation['valid']) {
    $_SESSION['error'] = $csrfValidation['message'];
    header('Location: ' . normalizeAssessmentRedirect($applicationId));
    exit();
}

if (!providerCanAccessApplication($pdo, $applicationId)) {
    $_SESSION['error'] = 'You can only manage assessments for applications under your scholarship programs.';
    header('Location: ' . normalizeAppUrl('../AdminView/manage_applications.php'));
    exit();
}

$applicationModel = new Application($pdo);
$applicationModel->ensureAssessmentColumns();

try {
    $stmt = $pdo->prepare("
        SELECT
            a.id AS application_id,
            a.user_id,
            a.status AS application_status,
            a.student_response_status,
            a.assessment_status,
            a.assessment_schedule_at,
            a.assessment_link_override,
            a.assessment_site_id,
            a.assessment_notes,
            s.id AS scholarship_id,
            s.name AS scholarship_name,
            u.email,
            u.username,
            CONCAT(COALESCE(sd.firstname, ''), ' ', COALESCE(sd.lastname, '')) AS student_name,
            sd2.provider AS provider_name,
            sd2.assessment_requirement,
            sd2.assessment_link,
            sd2.assessment_details
        FROM applications a
        JOIN scholarships s ON s.id = a.scholarship_id
        JOIN users u ON u.id = a.user_id
        LEFT JOIN student_data sd ON sd.student_id = u.id
        LEFT JOIN scholarship_data sd2 ON sd2.scholarship_id = s.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('application_assessment_process load error: ' . $e->getMessage());
    $_SESSION['error'] = 'Unable to load application assessment details right now.';
    header('Location: ' . normalizeAssessmentRedirect($applicationId));
    exit();
}

if (!$application) {
    $_SESSION['error'] = 'Application not found.';
    header('Location: ' . normalizeAppUrl('../AdminView/manage_applications.php'));
    exit();
}

$applicationStatus = strtolower(trim((string) ($application['application_status'] ?? 'pending')));
$studentResponseStatus = strtolower(trim((string) ($application['student_response_status'] ?? '')));
$assessmentRequirement = strtolower(trim((string) ($application['assessment_requirement'] ?? 'none')));

if ($assessmentRequirement === '' || $assessmentRequirement === 'none') {
    $_SESSION['error'] = 'This scholarship does not have a post-acceptance assessment configured.';
    header('Location: ' . normalizeAssessmentRedirect($applicationId));
    exit();
}

if ($applicationStatus !== 'approved' || $studentResponseStatus !== 'accepted') {
    $_SESSION['error'] = 'Assessment updates become available after the student accepts the approved scholarship.';
    header('Location: ' . normalizeAssessmentRedirect($applicationId));
    exit();
}

$assessmentStatus = $applicationModel->normalizeAssessmentStatus($_POST['assessment_status'] ?? null);
$assessmentLinkOverride = trim((string) ($_POST['assessment_link_override'] ?? ''));
$assessmentNotes = trim((string) ($_POST['assessment_notes'] ?? ''));
$assessmentSiteId = (int) ($_POST['assessment_site_id'] ?? 0);
$assessmentSiteId = $assessmentSiteId > 0 ? $assessmentSiteId : null;
$assessmentScheduleInput = trim((string) ($_POST['assessment_schedule_at'] ?? ''));
$assessmentScheduleAt = null;

if ($assessmentLinkOverride !== '' && !filter_var($assessmentLinkOverride, FILTER_VALIDATE_URL)) {
    $_SESSION['error'] = 'Please enter a valid assessment link.';
    header('Location: ' . normalizeAssessmentRedirect($applicationId));
    exit();
}

if ($assessmentScheduleInput !== '') {
    try {
        $assessmentScheduleAt = (new DateTime($assessmentScheduleInput))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $_SESSION['error'] = 'Please enter a valid assessment schedule.';
        header('Location: ' . normalizeAssessmentRedirect($applicationId));
        exit();
    }
}

if (function_exists('mb_substr')) {
    $assessmentNotes = mb_substr($assessmentNotes, 0, 2000);
} else {
    $assessmentNotes = substr($assessmentNotes, 0, 2000);
}

$selectedSiteLabel = '';
if ($assessmentRequirement === 'remote_examination') {
    if ($assessmentStatus === 'scheduled' && $assessmentSiteId === null) {
        $_SESSION['error'] = 'Select a remote examination site before marking the assessment as scheduled.';
        header('Location: ' . normalizeAssessmentRedirect($applicationId));
        exit();
    }

    if ($assessmentSiteId !== null) {
        if (!applicationAssessmentTableExists($pdo, 'scholarship_remote_exam_locations')) {
            $_SESSION['error'] = 'Remote examination sites are not available on this server.';
            header('Location: ' . normalizeAssessmentRedirect($applicationId));
            exit();
        }

        $siteStmt = $pdo->prepare("
            SELECT id, site_name, address, city, province
            FROM scholarship_remote_exam_locations
            WHERE id = ? AND scholarship_id = ?
            LIMIT 1
        ");
        $siteStmt->execute([$assessmentSiteId, (int) ($application['scholarship_id'] ?? 0)]);
        $selectedSite = $siteStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$selectedSite) {
            $_SESSION['error'] = 'The selected remote examination site is not valid for this scholarship.';
            header('Location: ' . normalizeAssessmentRedirect($applicationId));
            exit();
        }

        $selectedSiteLabel = trim((string) ($selectedSite['site_name'] ?? ''));
        if ($selectedSiteLabel === '') {
            $selectedSiteLabel = 'Remote examination site';
        }
    }
}

$effectiveLink = $assessmentLinkOverride !== ''
    ? $assessmentLinkOverride
    : trim((string) ($application['assessment_link'] ?? ''));

$payload = [
    'assessment_status' => $assessmentStatus,
    'assessment_schedule_at' => $assessmentScheduleAt,
    'assessment_link_override' => $assessmentLinkOverride !== '' ? $assessmentLinkOverride : null,
    'assessment_site_id' => $assessmentSiteId,
    'assessment_notes' => $assessmentNotes !== '' ? $assessmentNotes : null,
];

try {
    $applicationModel->updateAssessmentFields($applicationId, $payload);

    $statusLabel = normalizeAssessmentStatusLabel($assessmentStatus);
    $assessmentTypeLabel = normalizeAssessmentTypeLabel($assessmentRequirement);
    $scholarshipName = trim((string) ($application['scholarship_name'] ?? 'this scholarship'));

    $messageParts = [
        'Your ' . strtolower($assessmentTypeLabel) . ' for ' . $scholarshipName . ' is now marked as ' . strtolower($statusLabel) . '.',
    ];

    if ($assessmentScheduleAt !== null) {
        $messageParts[] = 'Schedule: ' . (new DateTime($assessmentScheduleAt))->format('M d, Y g:i A') . '.';
    }

    if ($selectedSiteLabel !== '') {
        $messageParts[] = 'Site: ' . $selectedSiteLabel . '.';
    }

    if ($effectiveLink !== '' && in_array($assessmentRequirement, ['online_exam', 'assessment', 'evaluation'], true)) {
        $messageParts[] = 'Open your application tracking to access the exam link.';
    }

    if ($assessmentNotes !== '') {
        $messageParts[] = 'Latest note: ' . $assessmentNotes;
    }

    createNotificationsForUsers(
        $pdo,
        [(int) ($application['user_id'] ?? 0)],
        'application_assessment_update',
        $assessmentTypeLabel . ' update',
        implode(' ', $messageParts),
        [
            'entity_type' => 'application',
            'entity_id' => $applicationId,
            'link_url' => 'profile.php',
        ]
    );

    try {
        $studentName = trim((string) ($application['student_name'] ?? ''));
        if ($studentName === '') {
            $studentName = trim((string) ($application['username'] ?? 'Student'));
        }

        $activityLog = new ActivityLog($pdo);
        $activityLog->log('update', 'application', 'Updated post-acceptance assessment details.', [
            'entity_id' => $applicationId,
            'entity_name' => $scholarshipName,
            'target_user_id' => (int) ($application['user_id'] ?? 0),
            'target_name' => $studentName,
            'details' => [
                'application_id' => $applicationId,
                'assessment_requirement' => $assessmentRequirement,
                'assessment_status' => $assessmentStatus,
                'assessment_schedule_at' => $assessmentScheduleAt,
                'assessment_site_id' => $assessmentSiteId,
                'assessment_link_override' => $assessmentLinkOverride !== '' ? $assessmentLinkOverride : null,
            ],
        ]);
    } catch (Throwable $activityError) {
        error_log('application_assessment_process activity error: ' . $activityError->getMessage());
    }

    $_SESSION['success'] = $assessmentTypeLabel . ' details saved successfully.';
} catch (Throwable $e) {
    error_log('application_assessment_process save error: ' . $e->getMessage());
    $_SESSION['error'] = 'Unable to save the assessment details right now.';
}

header('Location: ' . normalizeAssessmentRedirect($applicationId));
exit();
