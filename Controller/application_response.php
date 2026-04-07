<?php
require_once '../Config/init.php';
require_once '../Config/url_token.php';
require_once '../Config/csrf.php';
require_once '../Config/notification_helpers.php';
require_once '../Config/SmtpMailer.php';
require_once '../Model/Application.php';
require_once '../Model/ActivityLog.php';

$mailConfig = require __DIR__ . '/../Config/mail_config.php';

function buildApplicationAcceptanceEmail(array $applicationDetails): array
{
    $studentName = trim((string) ($applicationDetails['student_name'] ?? ''));
    if ($studentName === '') {
        $studentName = trim((string) ($applicationDetails['username'] ?? ''));
    }
    if ($studentName === '') {
        $studentName = 'Student';
    }

    $scholarshipName = trim((string) ($applicationDetails['scholarship_name'] ?? ''));
    if ($scholarshipName === '') {
        $scholarshipName = 'your scholarship';
    }

    $providerName = trim((string) ($applicationDetails['provider_name'] ?? ''));
    if ($providerName === '') {
        $providerName = 'the scholarship provider';
    }

    $applicationId = isset($applicationDetails['application_id']) ? (int) $applicationDetails['application_id'] : 0;
    $referenceLine = $applicationId > 0
        ? "Reference number: APP-" . str_pad((string) $applicationId, 5, '0', STR_PAD_LEFT) . "\n\n"
        : '';

    $subject = 'Scholarship Finder Acceptance Received';
    $body = "Hello {$studentName},\n\n"
        . "Your application for {$scholarshipName} has been approved, and your acceptance has been recorded successfully.\n\n"
        . $referenceLine
        . "What happens next:\n"
        . "1. Wait for further instructions from {$providerName}.\n"
        . "2. Keep your supporting documents ready in case additional confirmation is requested.\n"
        . "3. Check your Scholarship Finder account and email regularly for follow-up updates.\n\n"
        . "Thank you,\nScholarship Finder";

    return [$subject, $body];
}

function sendApplicationAcceptanceEmail(array $mailConfig, array $applicationDetails): array
{
    $recipientEmail = trim((string) ($applicationDetails['email'] ?? ''));
    if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'The student does not have a valid email address on file.'
        ];
    }

    if (empty($mailConfig['configured'])) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Email notifications are not configured on this server.'
        ];
    }

    [$subject, $body] = buildApplicationAcceptanceEmail($applicationDetails);

    $mailer = new SmtpMailer($mailConfig);
    $sendResult = $mailer->send($recipientEmail, $subject, wordwrap($body, 70));

    return [
        'success' => !empty($sendResult['success']),
        'skipped' => false,
        'error' => $sendResult['error'] ?? null
    ];
}

function applicationResponseHasColumn(PDO $pdo, string $tableName, string $columnName): bool
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
        ':column_name' => $columnName,
    ]);

    $cache[$key] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$key];
}

$redirectUrl = '../View/profile.php#applicationTracking';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid scholarship response request.';
    header('Location: ' . $redirectUrl);
    exit();
}

requireRoles(['student'], '../View/index.php', 'You need a student account to respond to scholarships.');

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$applicationId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($action !== 'accept' || $applicationId <= 0) {
    $_SESSION['error'] = 'Invalid scholarship response action.';
    header('Location: ' . $redirectUrl);
    exit();
}

if (!isValidEntityUrlToken('application', $applicationId, $_GET['token'] ?? $_POST['token'] ?? null, 'accept')) {
    $_SESSION['error'] = 'Invalid or expired scholarship confirmation link.';
    header('Location: ' . $redirectUrl);
    exit();
}

$csrfValidation = csrfValidateRequest('application_accept');
if (!$csrfValidation['valid']) {
    $_SESSION['error'] = $csrfValidation['message'];
    header('Location: ' . $redirectUrl);
    exit();
}

$hasStudentResponseStatus = applicationResponseHasColumn($pdo, 'applications', 'student_response_status');
$hasStudentRespondedAt = applicationResponseHasColumn($pdo, 'applications', 'student_responded_at');

if (!$hasStudentResponseStatus || !$hasStudentRespondedAt) {
    $_SESSION['error'] = 'Scholarship acceptance is not available until the latest database update is applied.';
    header('Location: ' . $redirectUrl);
    exit();
}

$studentResponseStatusSelect = $hasStudentResponseStatus
    ? 'a.student_response_status AS student_response_status,'
    : 'NULL AS student_response_status,';
$studentRespondedAtSelect = $hasStudentRespondedAt
    ? 'a.student_responded_at AS student_responded_at,'
    : 'NULL AS student_responded_at,';

try {
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.user_id,
            a.status,
            {$studentResponseStatusSelect}
            {$studentRespondedAtSelect}
            u.username,
            u.email,
            s.id AS scholarship_id,
            s.name AS scholarship_name,
            sd.provider AS provider_name
        FROM applications a
        JOIN users u ON a.user_id = u.id
        JOIN scholarships s ON a.scholarship_id = s.id
        LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('application_response load error: ' . $e->getMessage());
    $_SESSION['error'] = 'Unable to load the scholarship response right now.';
    header('Location: ' . $redirectUrl);
    exit();
}

if (!$application || (int) ($application['user_id'] ?? 0) !== $userId) {
    $_SESSION['error'] = 'That scholarship response is not available for your account.';
    header('Location: ' . $redirectUrl);
    exit();
}

$applicationStatus = strtolower(trim((string) ($application['status'] ?? 'pending')));
$studentResponseStatus = strtolower(trim((string) ($application['student_response_status'] ?? '')));
$scholarshipName = trim((string) ($application['scholarship_name'] ?? 'this scholarship'));
$providerOrganization = trim((string) ($application['provider_name'] ?? ''));
$providerName = $providerOrganization !== '' ? $providerOrganization : 'the provider';
$studentName = trim((string) ($_SESSION['user_firstname'] ?? '') . ' ' . (string) ($_SESSION['user_lastname'] ?? ''));
if ($studentName === '') {
    $studentName = trim((string) ($_SESSION['user_username'] ?? 'Student'));
}

if ($studentResponseStatus === 'accepted') {
    $_SESSION['success'] = 'You already accepted ' . $scholarshipName . '.';
    header('Location: ' . $redirectUrl);
    exit();
}

if ($applicationStatus !== 'approved') {
    $_SESSION['error'] = 'You can only accept scholarships after your application has been approved.';
    header('Location: ' . $redirectUrl);
    exit();
}

try {
    $applicationModel = new Application($pdo);
    $accepted = $applicationModel->acceptScholarship($applicationId, $userId);

    if (!$accepted) {
        $_SESSION['error'] = 'We could not record your scholarship acceptance right now.';
        header('Location: ' . $redirectUrl);
        exit();
    }

    try {
        $recipientIds = array_merge(
            getNotificationRecipientIdsByRoles($pdo, ['admin', 'super_admin']),
            getProviderNotificationRecipientIds($pdo, $providerOrganization)
        );

        createNotificationsForUsers(
            $pdo,
            $recipientIds,
            'application_accepted',
            'Scholarship accepted by student',
            $studentName . ' accepted the approved scholarship offer for ' . $scholarshipName . '.',
            [
                'entity_type' => 'application',
                'entity_id' => $applicationId,
                'link_url' => 'manage_applications.php'
            ]
        );
    } catch (Throwable $notificationError) {
        error_log('application_response notification error: ' . $notificationError->getMessage());
    }

    $emailNotice = sendApplicationAcceptanceEmail($mailConfig, [
        'application_id' => $applicationId,
        'student_name' => $studentName,
        'username' => (string) ($application['username'] ?? ''),
        'email' => (string) ($application['email'] ?? ($_SESSION['user_email'] ?? '')),
        'scholarship_name' => $scholarshipName,
        'provider_name' => $providerName,
    ]);
    if (!$emailNotice['success'] && empty($emailNotice['skipped'])) {
        error_log('application_response email error: ' . ($emailNotice['error'] ?? 'Unknown error'));
    }

    try {
        $activityLog = new ActivityLog($pdo);
        $activityLog->log('accept', 'application', 'Accepted an approved scholarship offer.', [
            'entity_id' => $applicationId,
            'entity_name' => $scholarshipName,
            'target_user_id' => $userId,
            'target_name' => $studentName,
            'details' => [
                'application_id' => $applicationId,
                'scholarship_id' => (int) ($application['scholarship_id'] ?? 0),
                'provider' => $providerName,
                'student_response_status' => 'accepted'
            ]
        ]);
    } catch (Throwable $activityError) {
        error_log('application_response activity error: ' . $activityError->getMessage());
    }

    $_SESSION['success'] = 'You accepted ' . $scholarshipName . '. Please wait for further instructions.';
    if (!empty($emailNotice['success'])) {
        $_SESSION['success'] .= ' A confirmation email has been sent to your inbox.';
    } elseif (!empty($emailNotice['error'])) {
        $_SESSION['success'] .= ' Your acceptance was saved, but the email could not be sent: ' . $emailNotice['error'];
    }
} catch (Throwable $e) {
    error_log('application_response error: ' . $e->getMessage());
    $_SESSION['error'] = 'Something went wrong while confirming your scholarship acceptance.';
}

header('Location: ' . $redirectUrl);
exit();
