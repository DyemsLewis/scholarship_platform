<?php
require_once __DIR__ . '/../Config/init.php';
require_once __DIR__ . '/../Config/SmtpMailer.php';
require_once __DIR__ . '/../Config/notification_helpers.php';
require_once __DIR__ . '/../Models/Application.php';
require_once __DIR__ . '/../Models/Notification.php';
require_once __DIR__ . '/../Models/UserDocument.php';
require_once __DIR__ . '/../Controllers/scholarshipResultController.php';

header('Content-Type: application/json; charset=UTF-8');

if (!function_exists('tableHasColumn')) {
    function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $cacheKey = $tableName . '.' . $columnName;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
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

        $cache[$cacheKey] = ((int) $stmt->fetchColumn()) > 0;
        return $cache[$cacheKey];
    }
}

function jsonResponse(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function buildApplicationSubmissionEmail(array $details): array
{
    $studentName = trim((string) ($details['student_name'] ?? ''));
    if ($studentName === '') {
        $studentName = trim((string) ($details['username'] ?? ''));
    }
    if ($studentName === '') {
        $studentName = 'Student';
    }

    $scholarshipName = trim((string) ($details['scholarship_name'] ?? ''));
    if ($scholarshipName === '') {
        $scholarshipName = 'your scholarship application';
    }

    $providerName = trim((string) ($details['provider_name'] ?? ''));
    if ($providerName === '') {
        $providerName = 'the scholarship provider';
    }

    $applicationId = isset($details['application_id']) ? (int) $details['application_id'] : 0;
    $referenceLine = $applicationId > 0
        ? "Reference number: APP-" . str_pad((string) $applicationId, 5, '0', STR_PAD_LEFT) . "\n\n"
        : '';

    $subject = 'Scholarship Finder Application Received';
    $body = "Hello {$studentName},\n\n"
        . "We have received your application for {$scholarshipName}.\n"
        . "Provider: {$providerName}\n"
        . "Current status: Pending confirmation\n\n"
        . $referenceLine
        . "What to do next:\n"
        . "1. Log in to Scholarship Finder to monitor your application status.\n"
        . "2. Keep your uploaded documents available in case the reviewer asks for re-upload or clarification.\n"
        . "3. Watch your email and account for updates from the provider or the review team.\n"
        . "4. Avoid submitting duplicate applications for the same scholarship unless you are instructed to do so.\n\n"
        . "Thank you,\nScholarship Finder";

    return [$subject, $body];
}

function sendApplicationSubmissionEmail(array $mailConfig, array $details): array
{
    $recipientEmail = trim((string) ($details['email'] ?? ''));
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

    [$subject, $body] = buildApplicationSubmissionEmail($details);

    $mailer = new SmtpMailer($mailConfig);
    $sendResult = $mailer->send($recipientEmail, $subject, wordwrap($body, 70));

    return [
        'success' => !empty($sendResult['success']),
        'skipped' => false,
        'error' => $sendResult['error'] ?? null
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, [
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
}

if (!isset($_SESSION['user_id'])) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Please login before submitting an application.'
    ]);
}

$userId = (int) $_SESSION['user_id'];
$scholarshipId = (int) ($_POST['scholarship_id'] ?? 0);

if ($scholarshipId <= 0) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Invalid scholarship selected.'
    ]);
}

$agreedTerms = isset($_POST['agree_terms']) && (string) $_POST['agree_terms'] === '1';
$confirmedInfo = isset($_POST['confirm_info']) && (string) $_POST['confirm_info'] === '1';
if (!$agreedTerms || !$confirmedInfo) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Please agree to the terms and confirm your information before submitting.'
    ]);
}

$mailConfig = require __DIR__ . '/../Config/mail_config.php';

try {
    $applicationOpenDateSelect = tableHasColumn($pdo, 'scholarship_data', 'application_open_date')
        ? 'sd.application_open_date'
        : 'NULL AS application_open_date';

    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.status, sd.provider, sd.deadline, {$applicationOpenDateSelect}
        FROM scholarships s
        LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$scholarshipId]);
    $scholarship = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scholarship || strtolower((string) ($scholarship['status'] ?? '')) !== 'active') {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Scholarship not found or inactive.'
        ]);
    }

    if (!empty($scholarship['application_open_date'])) {
        try {
            $applicationOpenDate = new DateTime((string) $scholarship['application_open_date']);
            $applicationOpenDate->setTime(0, 0, 0);
            if ($applicationOpenDate > new DateTime()) {
                jsonResponse(422, [
                    'success' => false,
                    'message' => 'This scholarship opens on ' . $applicationOpenDate->format('M d, Y') . '.'
                ]);
            }
        } catch (Throwable $openDateError) {
            // Ignore malformed open-date values and continue with the remaining checks.
        }
    }

    if (!empty($scholarship['deadline'])) {
        $deadline = new DateTime((string) $scholarship['deadline']);
        $deadline->setTime(23, 59, 59);
        if ($deadline < new DateTime()) {
            jsonResponse(422, [
                'success' => false,
                'message' => 'This scholarship is already closed.'
            ]);
        }
    }

    // Required documents must be complete and fully verified before application submission.
    $documentModel = new UserDocument($pdo);
    $requirementSummary = $documentModel->checkScholarshipRequirements($userId, $scholarshipId);
    $missingDocuments = $requirementSummary['missing'] ?? [];
    $rejectedDocuments = [];
    $pendingDocuments = [];
    foreach (($requirementSummary['requirements'] ?? []) as $requirement) {
        if (($requirement['status'] ?? '') === 'rejected' && !empty($requirement['name'])) {
            $rejectedDocuments[] = (string) $requirement['name'];
        }
        if (($requirement['status'] ?? '') === 'pending' && !empty($requirement['name'])) {
            $pendingDocuments[] = (string) $requirement['name'];
        }
    }

    if (!empty($missingDocuments)) {
        $preview = implode(', ', array_slice($missingDocuments, 0, 3));
        if (count($missingDocuments) > 3) {
            $preview .= ' and ' . (count($missingDocuments) - 3) . ' more';
        }

        jsonResponse(422, [
            'success' => false,
            'message' => 'Cannot submit yet. Missing required documents: ' . $preview . '.'
        ]);
    }

    if (!empty($rejectedDocuments)) {
        $preview = implode(', ', array_slice($rejectedDocuments, 0, 3));
        if (count($rejectedDocuments) > 3) {
            $preview .= ' and ' . (count($rejectedDocuments) - 3) . ' more';
        }

        jsonResponse(422, [
            'success' => false,
            'message' => 'Cannot submit yet. Please re-upload rejected required documents: ' . $preview . '.'
        ]);
    }

    if (!empty($pendingDocuments)) {
        $preview = implode(', ', array_slice($pendingDocuments, 0, 3));
        if (count($pendingDocuments) > 3) {
            $preview .= ' and ' . (count($pendingDocuments) - 3) . ' more';
        }

        jsonResponse(422, [
            'success' => false,
            'message' => 'Cannot submit yet. These required documents are still pending review: ' . $preview . '.'
        ]);
    }

    $probabilityScore = null;
    try {
        $applicantType = (string) ($_SESSION['user_applicant_type'] ?? '');
        $rawUserGwa = isset($_SESSION['user_gwa']) && $_SESSION['user_gwa'] !== '' ? (float) $_SESSION['user_gwa'] : null;
        $userShsAverage = $_SESSION['user_shs_average'] ?? null;
        $userGwa = resolveApplicantAcademicScore($applicantType, $rawUserGwa, $userShsAverage);
        $academicMetricLabel = getApplicantAcademicMetricLabel($applicantType);
        $academicDocumentLabel = getApplicantAcademicDocumentLabel($applicantType);
        $userCourse = (string) ($_SESSION['user_course'] ?? '');
        $matchCourse = $userCourse !== '' ? $userCourse : (string) ($_SESSION['user_target_course'] ?? '');
        $userLat = isset($_SESSION['user_latitude']) && $_SESSION['user_latitude'] !== '' ? (float) $_SESSION['user_latitude'] : null;
        $userLng = isset($_SESSION['user_longitude']) && $_SESSION['user_longitude'] !== '' ? (float) $_SESSION['user_longitude'] : null;
        $userProfile = [
            'applicant_type' => $applicantType,
            'year_level' => (string) ($_SESSION['user_year_level'] ?? ''),
            'admission_status' => (string) ($_SESSION['user_admission_status'] ?? ''),
            'shs_strand' => (string) ($_SESSION['user_shs_strand'] ?? ''),
            'shs_average' => (string) ($_SESSION['user_shs_average'] ?? ''),
            'course' => $userCourse,
            'target_course' => (string) ($_SESSION['user_target_course'] ?? ''),
            'school' => (string) ($_SESSION['user_school'] ?? ''),
            'target_college' => (string) ($_SESSION['user_target_college'] ?? ''),
            'enrollment_status' => (string) ($_SESSION['user_enrollment_status'] ?? ''),
            'academic_standing' => (string) ($_SESSION['user_academic_standing'] ?? ''),
            'city' => (string) ($_SESSION['user_city'] ?? ''),
            'province' => (string) ($_SESSION['user_province'] ?? ''),
            'citizenship' => (string) ($_SESSION['user_citizenship'] ?? ''),
            'household_income_bracket' => (string) ($_SESSION['user_household_income_bracket'] ?? ''),
            'special_category' => (string) ($_SESSION['user_special_category'] ?? '')
        ];

        $scholarshipService = new ScholarshipService($pdo);
        $matched = $scholarshipService->getMatchedScholarships($userGwa, $matchCourse, $userLat, $userLng, $userProfile);
        foreach ($matched as $item) {
            if ((int) ($item['id'] ?? 0) === $scholarshipId) {
                if (empty($item['is_eligible'])) {
                    $profileRuleLabel = trim((string) ($item['profile_readiness_label'] ?? ''));
                    $requiredGwaValue = isset($item['required_gwa']) && $item['required_gwa'] !== null ? (float) $item['required_gwa'] : null;
                    $message = 'Your current profile does not meet this scholarship policy yet.';
                    if (!empty($item['requires_gwa'])) {
                        $message = 'Upload your ' . $academicDocumentLabel . ' first to confirm your ' . strtolower($academicMetricLabel) . '.';
                    } elseif ($requiredGwaValue !== null && $userGwa !== null && $userGwa > $requiredGwaValue) {
                        $message = 'Your ' . strtolower($academicMetricLabel) . ' is above the required limit for this scholarship.';
                    } elseif ($profileRuleLabel !== '') {
                        $message = $profileRuleLabel . '.';
                    }
                    jsonResponse(422, [
                        'success' => false,
                        'message' => $message
                    ]);
                }
                if (isset($item['match_percentage']) && $item['match_percentage'] !== null) {
                    $probabilityScore = (float) $item['match_percentage'];
                }
                break;
            }
        }
    } catch (Throwable $scoreError) {
        $probabilityScore = null;
    }

    $applicationModel = new Application($pdo);
    $result = $applicationModel->createApplication($userId, $scholarshipId, $probabilityScore);

    if (!$result['success']) {
        $message = (string) ($result['message'] ?? 'Application could not be submitted.');
        $alreadyApplied = stripos($message, 'already applied') !== false;
        jsonResponse($alreadyApplied ? 409 : 422, [
            'success' => false,
            'message' => $message
        ]);
    }

    $emailNotice = sendApplicationSubmissionEmail($mailConfig, [
        'application_id' => (int) $result['application_id'],
        'student_name' => (string) ($_SESSION['user_display_name'] ?? ''),
        'username' => (string) ($_SESSION['user_username'] ?? ''),
        'email' => (string) ($_SESSION['user_email'] ?? ''),
        'scholarship_name' => (string) ($scholarship['name'] ?? ''),
        'provider_name' => (string) ($scholarship['provider'] ?? ''),
    ]);

    try {
        $notificationModel = new Notification($pdo);
        $notificationModel->createForUser(
            $userId,
            'application_submitted',
            'Application received',
            'Your application for ' . ((string) ($scholarship['name'] ?? 'this scholarship')) . ' has been submitted and is now pending confirmation.',
            [
                'entity_type' => 'application',
                'entity_id' => (int) $result['application_id'],
                'link_url' => 'profile.php'
            ]
        );
    } catch (Throwable $notificationError) {
        error_log('submit_application notification error: ' . $notificationError->getMessage());
    }

    try {
        $recipientIds = array_merge(
            getNotificationRecipientIdsByRoles($pdo, ['admin', 'super_admin']),
            getProviderNotificationRecipientIds($pdo, (string) ($scholarship['provider'] ?? ''))
        );

        createNotificationsForUsers(
            $pdo,
            $recipientIds,
            'application_pending_review',
            'New application submitted',
            'A new application was submitted for ' . ((string) ($scholarship['name'] ?? 'a scholarship')) . '.',
            [
                'entity_type' => 'application',
                'entity_id' => (int) $result['application_id'],
                'link_url' => 'manage_applications.php'
            ]
        );
    } catch (Throwable $notificationError) {
        error_log('submit_application admin/provider notification error: ' . $notificationError->getMessage());
    }

    $successMessage = 'Application submitted successfully. It is now pending confirmation.';
    if (!empty($emailNotice['success'])) {
        $successMessage .= ' A confirmation email has been sent to your inbox.';
    } elseif (!empty($emailNotice['error'])) {
        $successMessage .= ' Your application was saved, but the confirmation email could not be sent: ' . $emailNotice['error'];
        error_log('Failed to send application submission email for application #' . (int) $result['application_id'] . ': ' . ($emailNotice['error'] ?? 'Unknown error'));
    }

    $_SESSION['success'] = $successMessage;

    try {
        $activityLog = new ActivityLog($pdo);
        $activityLog->log('submit', 'application', 'Submitted a scholarship application.', [
            'entity_id' => (int) $result['application_id'],
            'entity_name' => (string) ($scholarship['name'] ?? 'Scholarship Application'),
            'target_user_id' => $userId,
            'target_name' => $_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? 'Student',
            'details' => [
                'scholarship_id' => $scholarshipId,
                'scholarship_name' => (string) ($scholarship['name'] ?? ''),
                'provider' => (string) ($scholarship['provider'] ?? ''),
                'status' => 'pending'
            ]
        ]);
    } catch (Throwable $activityError) {
        error_log('submit_application activity log error: ' . $activityError->getMessage());
    }

    jsonResponse(200, [
        'success' => true,
        'message' => $successMessage,
        'application_id' => (int) $result['application_id']
    ]);
} catch (Throwable $e) {
    error_log('submit_application error: ' . $e->getMessage());
    jsonResponse(500, [
        'success' => false,
        'message' => 'Something went wrong while submitting your application.'
    ]);
}
