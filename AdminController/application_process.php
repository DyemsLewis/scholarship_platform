<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once '../Config/db_config.php';
require_once '../Config/access_control.php';
require_once '../Config/provider_scope.php';
require_once '../Config/url_token.php';
require_once '../Config/csrf.php';
require_once '../Config/notification_helpers.php';
require_once '../Config/SmtpMailer.php';
require_once '../Model/ActivityLog.php';
require_once '../Model/Notification.php';
require_once '../Model/UserDocument.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to manage applications.');

$mailConfig = require __DIR__ . '/../Config/mail_config.php';

function applicationTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
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

function buildApplicationDecisionEmail(string $action, array $applicationDetails, string $rejectionReason = ''): array
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
        $scholarshipName = 'your scholarship application';
    }

    $providerName = trim((string) ($applicationDetails['provider_name'] ?? ''));
    if ($providerName === '') {
        $providerName = 'the scholarship provider';
    }

    $applicationId = isset($applicationDetails['application_id']) ? (int) $applicationDetails['application_id'] : 0;
    $referenceLine = $applicationId > 0
        ? "Reference number: APP-" . str_pad((string) $applicationId, 5, '0', STR_PAD_LEFT) . "\n"
        : '';

    if ($action === 'approve') {
        $subject = 'Scholarship Finder Application Approved - Acceptance Required';
        $body = "Hello {$studentName},\n\n"
            . "Good news. Your application for {$scholarshipName} under {$providerName} has been approved by the scholarship review team.\n\n"
            . "Current status: Approved - awaiting your confirmation\n"
            . ($referenceLine !== '' ? $referenceLine : '')
            . "\n"
            . "What to do next:\n"
            . "1. Log in to your Scholarship Finder account.\n"
            . "2. Open your Profile and go to the Application Tracking section.\n"
            . "3. Click Accept Scholarship on this approved application to confirm that you will take the offer.\n"
            . "4. After you accept, wait for further instructions from {$providerName}, including confirmation, orientation, interview, or release details.\n"
            . "5. Prepare your original supporting documents and a valid school or government ID in case they are requested for final validation.\n\n"
            . "If you have questions, please contact the scholarship provider through the contact details listed in the scholarship posting.\n\n"
            . "Thank you,\nScholarship Finder";

        return [$subject, $body];
    }

    $subject = 'Scholarship Finder Application Update';
    $body = "Hello {$studentName},\n\n"
        . "Your application for {$scholarshipName} was not approved at this time.\n\n"
        . ($referenceLine !== '' ? $referenceLine . "\n" : '')
        . ($rejectionReason !== ''
            ? "Reason provided by the reviewer:\n{$rejectionReason}\n\n"
            : '')
        . "What to do next:\n"
        . "1. Log in to your Scholarship Finder account and review your current application records.\n"
        . "2. Check whether your profile details and uploaded documents need improvement for future submissions.\n"
        . "3. Continue exploring other scholarship opportunities that match your academic profile and requirements.\n\n"
        . "If the provider shares additional guidance, please follow the instructions in your account or email.\n\n"
        . "Thank you,\nScholarship Finder";

    return [$subject, $body];
}

function sendApplicationDecisionEmail(array $mailConfig, string $action, array $applicationDetails, string $rejectionReason = ''): array
{
    $recipientEmail = trim((string) ($applicationDetails['email'] ?? ''));
    if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'The applicant does not have a valid email address on file.'
        ];
    }

    if (empty($mailConfig['configured'])) {
        return [
            'success' => false,
            'skipped' => true,
            'error' => 'Email notifications are not configured on this server.'
        ];
    }

    [$subject, $body] = buildApplicationDecisionEmail($action, $applicationDetails, $rejectionReason);

    $mailer = new SmtpMailer($mailConfig);
    $sendResult = $mailer->send($recipientEmail, $subject, wordwrap($body, 70));

    return [
        'success' => !empty($sendResult['success']),
        'skipped' => false,
        'error' => $sendResult['error'] ?? null
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$applicationId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$rejectionReason = trim((string) ($_POST['rejection_reason'] ?? $_POST['reason'] ?? ''));
$statusMap = [
    'approve' => 'approved',
    'reject' => 'rejected',
];

if (!isset($statusMap[$action]) || $applicationId <= 0) {
    $_SESSION['error'] = 'Invalid application action.';
    header('Location: ../AdminView/manage_applications.php');
    exit();
}

if (!isValidEntityUrlToken('application', $applicationId, $_GET['token'] ?? $_POST['token'] ?? null, $action)) {
    $_SESSION['error'] = 'Invalid or expired access token.';
    header('Location: ../AdminView/manage_applications.php');
    exit();
}

$csrfValidation = csrfValidateRequest('application_review');
if (!$csrfValidation['valid']) {
    $_SESSION['error'] = $csrfValidation['message'];
    header('Location: ../AdminView/manage_applications.php');
    exit();
}

if (!providerCanAccessApplication($pdo, $applicationId)) {
    $_SESSION['error'] = 'You can only update applications submitted to your scholarship programs.';
    header('Location: ../AdminView/manage_applications.php');
    exit();
}

if ($action === 'reject') {
    if ($rejectionReason === '') {
        $_SESSION['error'] = 'A rejection reason is required before rejecting this application.';
        header('Location: ' . buildEntityUrl('../AdminView/view_application.php', 'application', $applicationId, 'view', ['id' => $applicationId]));
        exit();
    }

    if (function_exists('mb_strlen')) {
        $rejectionReason = mb_substr($rejectionReason, 0, 1000);
    } else {
        $rejectionReason = substr($rejectionReason, 0, 1000);
    }
}

try {
    $detailStmt = $pdo->prepare("
        SELECT
            a.id AS application_id,
            a.user_id,
            a.scholarship_id,
            s.name AS scholarship_name,
            sd2.provider AS provider_name,
            CONCAT(COALESCE(sd.firstname, ''), ' ', COALESCE(sd.lastname, '')) AS student_name,
            u.username,
            u.email
        FROM applications a
        JOIN scholarships s ON a.scholarship_id = s.id
        JOIN users u ON a.user_id = u.id
        LEFT JOIN student_data sd ON u.id = sd.student_id
        LEFT JOIN scholarship_data sd2 ON s.id = sd2.scholarship_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $detailStmt->execute([$applicationId]);
    $applicationDetails = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($action === 'approve' && $applicationDetails) {
        $userDocumentModel = new UserDocument($pdo);
        $requirementsSummary = $userDocumentModel->checkScholarshipRequirements(
            (int) ($applicationDetails['user_id'] ?? 0),
            (int) ($applicationDetails['scholarship_id'] ?? 0)
        );

        $totalRequired = (int) ($requirementsSummary['total_required'] ?? 0);
        $verifiedRequired = (int) ($requirementsSummary['verified'] ?? 0);

        if ($totalRequired > 0 && $verifiedRequired < $totalRequired) {
            $_SESSION['error'] = 'You cannot approve this application while required documents are still pending, missing, or rejected.';
            header('Location: ' . buildEntityUrl('../AdminView/view_application.php', 'application', $applicationId, 'view', ['id' => $applicationId]));
            exit();
        }
    }

    if (applicationTableHasColumn($pdo, 'applications', 'rejection_reason')) {
        $stmt = $pdo->prepare('
            UPDATE applications
            SET status = ?, rejection_reason = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([
            $statusMap[$action],
            $action === 'reject' ? $rejectionReason : null,
            $applicationId
        ]);
    } else {
        $stmt = $pdo->prepare('UPDATE applications SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$statusMap[$action], $applicationId]);
    }

    $emailNotice = null;

    if ($stmt->rowCount() > 0 && $applicationDetails) {
        $targetName = trim((string) ($applicationDetails['student_name'] ?? ''));
        if ($targetName === '') {
            $targetName = (string) ($applicationDetails['username'] ?? 'Student');
        }

        $activityLog = new ActivityLog($pdo);
        $activityLog->log($action, 'application', 'Updated scholarship application status.', [
            'entity_id' => $applicationId,
            'entity_name' => (string) ($applicationDetails['scholarship_name'] ?? 'Scholarship Application'),
            'target_user_id' => isset($applicationDetails['user_id']) ? (int) $applicationDetails['user_id'] : null,
            'target_name' => $targetName,
            'details' => [
                'application_id' => $applicationId,
                'scholarship_id' => isset($applicationDetails['scholarship_id']) ? (int) $applicationDetails['scholarship_id'] : null,
                'new_status' => $statusMap[$action],
                'rejection_reason' => $action === 'reject' ? $rejectionReason : null
            ]
        ]);

        try {
            $notificationTitle = $action === 'approve' ? 'Application approved' : 'Application update';
            $notificationMessage = $action === 'approve'
                ? 'Your application for ' . ((string) ($applicationDetails['scholarship_name'] ?? 'this scholarship')) . ' has been approved.'
                : 'Your application for ' . ((string) ($applicationDetails['scholarship_name'] ?? 'this scholarship')) . ' was not approved.'
                    . ($rejectionReason !== '' ? ' Reason: ' . $rejectionReason : '');

            $notificationModel = new Notification($pdo);
            $notificationModel->createForUser(
                (int) ($applicationDetails['user_id'] ?? 0),
                $action === 'approve' ? 'application_approved' : 'application_rejected',
                $notificationTitle,
                $notificationMessage,
                [
                    'entity_type' => 'application',
                    'entity_id' => $applicationId,
                    'link_url' => 'profile.php'
                ]
            );
        } catch (Throwable $notificationError) {
            error_log('application_process notification error: ' . $notificationError->getMessage());
        }

        $emailNotice = sendApplicationDecisionEmail($mailConfig, $action, $applicationDetails, $rejectionReason);
        if (!$emailNotice['success'] && empty($emailNotice['skipped'])) {
            error_log('Failed to send application decision email for application #' . $applicationId . ': ' . ($emailNotice['error'] ?? 'Unknown error'));
        }
    }

    $successMessage = 'Application status updated successfully.';
    if ($stmt->rowCount() > 0) {
        if (is_array($emailNotice) && !empty($emailNotice['success'])) {
            $successMessage .= ' Email notice sent to the applicant.';
        } elseif (is_array($emailNotice) && !empty($emailNotice['error'])) {
            $successMessage .= ' Email notice was not sent: ' . $emailNotice['error'];
        }
    }

    $_SESSION['success'] = $successMessage;
} catch (PDOException $e) {
    $_SESSION['error'] = 'Failed to update application status.';
}

header('Location: ' . buildEntityUrl('../AdminView/view_application.php', 'application', $applicationId, 'view', ['id' => $applicationId]));
exit();
