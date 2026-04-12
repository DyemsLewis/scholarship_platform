<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/provider_scope.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Controllers/scholarshipResultController.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to view applications.');

function queueHasValue($value): bool
{
    return trim((string) ($value ?? '')) !== '';
}

function queueTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
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

function queueOptionalColumnSelect(PDO $pdo, string $tableAlias, string $tableName, string $columnName, ?string $alias = null): string
{
    $selectAlias = $alias ?? $columnName;
    if (!queueTableHasColumn($pdo, $tableName, $columnName)) {
        return 'NULL AS ' . $selectAlias . ',';
    }

    return $tableAlias . '.' . $columnName . ' AS ' . $selectAlias . ',';
}

function queueBuildFallbackMatchGuidePayload(array $application): array
{
    $score = isset($application['probability_score']) && $application['probability_score'] !== null
        ? (int) round((float) $application['probability_score'])
        : null;

    return [
        'buttonLabel' => $score !== null ? ('Why ' . $score . '% match?') : 'How match works',
        'title' => $score !== null ? ('Why this shows as ' . $score . '% match') : 'How the match score works',
        'summary' => 'This score comes from the DSS profile matching model using the applicant information available in the system.',
        'note' => 'Open the full review workspace if you need the complete applicant profile, documents, and final decision context.',
        'factors' => [],
        'positive' => ['The DSS found enough information to produce a review score for this application.'],
        'limiting' => ['Open the full review page for deeper score context and applicant details.'],
    ];
}

function queueBuildMatchGuidePayload(array $application, ScholarshipService $scholarshipService): array
{
    $applicantProfile = [
        'applicant_type' => $application['applicant_type'] ?? '',
        'year_level' => $application['year_level'] ?? '',
        'admission_status' => $application['admission_status'] ?? '',
        'shs_strand' => $application['shs_strand'] ?? '',
        'course' => $application['course'] ?? '',
        'target_course' => $application['target_course'] ?? '',
        'school' => $application['school'] ?? '',
        'target_college' => $application['target_college'] ?? '',
        'enrollment_status' => $application['enrollment_status'] ?? '',
        'academic_standing' => $application['academic_standing'] ?? '',
        'city' => $application['city'] ?? '',
        'province' => $application['province'] ?? '',
        'citizenship' => $application['citizenship'] ?? '',
        'household_income_bracket' => $application['household_income_bracket'] ?? '',
        'special_category' => $application['special_category'] ?? '',
    ];

    $scholarshipContext = [
        'id' => (int) ($application['scholarship_id'] ?? 0),
        'name' => (string) ($application['scholarship_name'] ?? ''),
        'description' => (string) ($application['scholarship_description'] ?? ''),
        'eligibility' => (string) ($application['scholarship_eligibility'] ?? ''),
        'provider' => (string) ($application['provider'] ?? ''),
        'deadline' => $application['deadline'] ?? null,
        'application_open_date' => $application['scholarship_application_open_date'] ?? null,
        'min_gwa' => $application['scholarship_min_gwa'] ?? null,
        'address' => (string) ($application['scholarship_address'] ?? ''),
        'city' => (string) ($application['scholarship_city'] ?? ''),
        'province' => (string) ($application['scholarship_province'] ?? ''),
        'target_applicant_type' => (string) ($application['target_applicant_type'] ?? ''),
        'target_year_level' => (string) ($application['target_year_level'] ?? ''),
        'required_admission_status' => (string) ($application['required_admission_status'] ?? ''),
        'target_strand' => (string) ($application['target_strand'] ?? ''),
        'target_citizenship' => (string) ($application['target_citizenship'] ?? ''),
        'target_income_bracket' => (string) ($application['target_income_bracket'] ?? ''),
        'target_special_category' => (string) ($application['target_special_category'] ?? ''),
    ];

    if (!empty($scholarshipContext['deadline'])) {
        try {
            $deadlineDate = new DateTime((string) $scholarshipContext['deadline']);
            $now = new DateTime();
            $interval = $now->diff($deadlineDate);
            $daysRemaining = (int) $interval->days;
            if ($deadlineDate < $now) {
                $daysRemaining *= -1;
            }
            $scholarshipContext['days_remaining'] = $daysRemaining;
        } catch (Throwable $e) {
            $scholarshipContext['days_remaining'] = null;
        }
    } else {
        $scholarshipContext['days_remaining'] = null;
    }

    $applicantGwaValue = queueHasValue($application['gwa'] ?? null) && is_numeric($application['gwa'])
        ? (float) $application['gwa']
        : null;
    $applicantCourseValue = trim((string) ($application['course'] ?? ''));
    $matchAssessment = $scholarshipService->getMatchAssessmentForScholarship(
        $scholarshipContext,
        $applicantGwaValue,
        $applicantCourseValue,
        $applicantProfile
    );

    $matchGuideScore = $application['probability_score'] !== null
        ? (int) round((float) $application['probability_score'])
        : (isset($matchAssessment['percentage']) ? (int) $matchAssessment['percentage'] : null);
    $matchProfileChecks = is_array($matchAssessment['profile_checks'] ?? null) ? $matchAssessment['profile_checks'] : [];
    $matchProfileTotal = (int) ($matchAssessment['profile_requirement_total'] ?? 0);
    $matchProfileMet = (int) ($matchAssessment['profile_requirement_met'] ?? 0);
    $matchProfilePending = (int) ($matchAssessment['profile_requirement_pending'] ?? 0);
    $matchProfileFailed = (int) ($matchAssessment['profile_requirement_failed'] ?? 0);
    $matchCurrentInfoChecks = is_array($matchAssessment['current_info_checks'] ?? null) ? $matchAssessment['current_info_checks'] : [];
    $matchCurrentInfoTotal = (int) ($matchAssessment['current_info_total'] ?? 0);
    $matchCurrentInfoMet = (int) ($matchAssessment['current_info_met'] ?? 0);
    $matchCurrentInfoPending = (int) ($matchAssessment['current_info_pending'] ?? 0);
    $matchCurrentInfoWarn = (int) ($matchAssessment['current_info_warn'] ?? 0);
    $matchRequiresGwa = !empty($matchAssessment['requires_gwa']);

    $matchRequiredGwa = null;
    if ($scholarshipContext['min_gwa'] !== null && $scholarshipContext['min_gwa'] !== '') {
        $matchRequiredGwa = (float) $scholarshipContext['min_gwa'];
    }

    $pushReason = static function (array &$reasons, string $reason, int $limit = 4): void {
        $normalized = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
        if ($normalized === '' || in_array($normalized, $reasons, true) || count($reasons) >= $limit) {
            return;
        }
        $reasons[] = $normalized;
    };

    $matchAcademicDecision = 'No GWA requirement';
    $matchAcademicClass = 'info';
    if ($matchRequiredGwa !== null) {
        if ($applicantGwaValue === null) {
            $matchAcademicDecision = 'Pending: upload grades';
            $matchAcademicClass = 'warn';
        } elseif ($applicantGwaValue <= $matchRequiredGwa) {
            $matchAcademicDecision = 'Passed';
            $matchAcademicClass = 'good';
        } else {
            $matchAcademicDecision = 'Above limit';
            $matchAcademicClass = 'bad';
        }
    }
    $matchAcademicDetail = $matchRequiredGwa !== null
        ? ('Required GWA: ' . number_format($matchRequiredGwa, 2) . ' or better.')
        : 'No fixed academic cutoff is configured for this scholarship.';

    $matchCoursePathwayCheck = null;
    foreach ($matchCurrentInfoChecks as $currentInfoCheck) {
        if (strtolower(trim((string) ($currentInfoCheck['key'] ?? ''))) === 'course_pathway') {
            $matchCoursePathwayCheck = $currentInfoCheck;
            break;
        }
    }

    $matchCourseValue = 'Course details still needed';
    $matchCourseDetail = 'Add the applicant current or target course so the DSS can compare it with the scholarship focus.';
    $matchCourseClass = 'info';
    if (is_array($matchCoursePathwayCheck)) {
        $matchCourseStatus = strtolower(trim((string) ($matchCoursePathwayCheck['status'] ?? 'pending')));
        $matchCourseDetail = trim((string) ($matchCoursePathwayCheck['detail'] ?? $matchCourseDetail));
        if ($matchCourseStatus === 'met') {
            $matchCourseValue = 'Passed course check';
            $matchCourseClass = 'good';
        } elseif ($matchCourseStatus === 'warn') {
            $matchCourseValue = 'Course check needs review';
            $matchCourseClass = 'warn';
        }
    } elseif (queueHasValue($application['course'] ?? null) || queueHasValue($application['target_course'] ?? null)) {
        $matchCourseValue = 'Course information on file';
        $matchCourseDetail = 'The DSS compares the applicant current or target course with the scholarship focus.';
    }

    $matchProfileClass = 'info';
    if ($matchProfileTotal > 0) {
        if ($matchProfileFailed > 0) {
            $matchProfileClass = 'bad';
        } elseif ($matchProfilePending > 0) {
            $matchProfileClass = 'warn';
        } else {
            $matchProfileClass = 'good';
        }
    }
    $matchProfileValue = $matchProfileTotal > 0
        ? ($matchProfileMet . '/' . $matchProfileTotal . ' audience checks passed')
        : 'No extra audience filters';
    $matchProfileDetail = $matchProfileTotal > 0
        ? ($matchProfileMet . ' of ' . $matchProfileTotal . ' scholarship audience checks are already passing.')
        : 'This scholarship does not require extra profile filters.';

    $matchStudentContextClass = 'info';
    if ($matchCurrentInfoTotal > 0) {
        if ($matchCurrentInfoPending > 0 || $matchCurrentInfoWarn > 0) {
            $matchStudentContextClass = 'warn';
        } elseif ($matchCurrentInfoMet > 0) {
            $matchStudentContextClass = 'good';
        }
    }
    $matchStudentContextValue = $matchCurrentInfoTotal > 0
        ? ($matchCurrentInfoMet . '/' . $matchCurrentInfoTotal . ' support checks passed')
        : 'No extra context checks';
    $matchStudentContextDetail = $matchCurrentInfoTotal > 0
        ? ($matchCurrentInfoMet . ' of ' . $matchCurrentInfoTotal . ' current student support checks are already passing.')
        : 'No additional current-information checks are required.';

    $matchApplicationNotYetOpen = false;
    $matchApplicationOpenDateDisplay = 'Open now';
    if (!empty($scholarshipContext['application_open_date'])) {
        try {
            $applicationOpenDate = new DateTime((string) $scholarshipContext['application_open_date']);
            $applicationOpenDate->setTime(0, 0, 0);
            $matchApplicationOpenDateDisplay = $applicationOpenDate->format('M d, Y');
            if ($applicationOpenDate > new DateTime()) {
                $matchApplicationNotYetOpen = true;
            }
        } catch (Throwable $e) {
            $matchApplicationNotYetOpen = false;
            $matchApplicationOpenDateDisplay = 'Open now';
        }
    }

    $matchDeadlineClass = 'info';
    $matchDeadlineDecision = 'Open / no deadline';
    $matchDeadlineDetail = 'This scholarship is open without a fixed closing date.';
    if (!empty($scholarshipContext['deadline'])) {
        $matchDeadlineDateDisplay = date('M d, Y', strtotime((string) $scholarshipContext['deadline']));
        try {
            $deadlineDate = new DateTime((string) $scholarshipContext['deadline']);
            $now = new DateTime();
            if ($deadlineDate < $now) {
                $matchDeadlineDecision = 'Closed';
                $matchDeadlineClass = 'bad';
            } else {
                $daysLeft = (int) $now->diff($deadlineDate)->days;
                if ($daysLeft <= 7) {
                    $matchDeadlineDecision = 'Urgent (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left)';
                    $matchDeadlineClass = 'warn';
                } else {
                    $matchDeadlineDecision = 'Open';
                    $matchDeadlineClass = 'good';
                }
            }
            $matchDeadlineDetail = 'Submission deadline: ' . $matchDeadlineDateDisplay . '.';
        } catch (Throwable $e) {
            $matchDeadlineDetail = 'This scholarship has a stored deadline, but it could not be parsed for the guide.';
        }
    }

    $matchTimingValue = $matchApplicationNotYetOpen ? ('Opens ' . $matchApplicationOpenDateDisplay) : $matchDeadlineDecision;
    $matchTimingDetail = $matchApplicationNotYetOpen
        ? ('Applications open on ' . $matchApplicationOpenDateDisplay . '.')
        : $matchDeadlineDetail;
    $matchTimingClass = $matchApplicationNotYetOpen
        ? 'warn'
        : ($matchDeadlineClass === 'bad' ? 'bad' : ($matchDeadlineClass === 'warn' ? 'warn' : 'good'));

    $matchProviderValue = 'Standard provider signal';
    $matchProviderDetail = 'Provider profile contributes a smaller general ranking signal in the DSS.';
    $matchProviderClass = 'info';
    $recognizedProviders = ['CHED', 'DOST', 'University of the Philippines', 'SM Foundation', 'Ayala Foundation'];
    $recognizedProviderMatch = false;
    foreach ($recognizedProviders as $recognizedProvider) {
        if ($scholarshipContext['provider'] !== '' && stripos($scholarshipContext['provider'], $recognizedProvider) !== false) {
            $recognizedProviderMatch = true;
            break;
        }
    }
    if ($recognizedProviderMatch) {
        $matchProviderValue = 'Established provider signal';
        $matchProviderDetail = 'Recognized providers receive a slightly stronger ranking signal in the DSS.';
        $matchProviderClass = 'good';
    } elseif (
        $scholarshipContext['provider'] !== ''
        && (
            stripos($scholarshipContext['provider'], 'university') !== false
            || stripos($scholarshipContext['provider'], 'college') !== false
        )
    ) {
        $matchProviderValue = 'Academic institution signal';
        $matchProviderDetail = 'College and university providers receive a moderate ranking signal in the DSS.';
        $matchProviderClass = 'good';
    }

    $matchGuideSummary = '';

    $matchGuideNote = 'This percentage ranks fit only. Required documents affect approval readiness, and the final decision still depends on provider review.';
    $matchPositiveReasons = [];
    $matchLimitingReasons = [];

    if ($matchRequiredGwa !== null) {
        if ($applicantGwaValue === null) {
            $pushReason($matchLimitingReasons, 'The academic record is missing, so the score is only partly estimated right now.');
        } elseif ($applicantGwaValue <= $matchRequiredGwa) {
            $pushReason(
                $matchPositiveReasons,
                'Passed academic check because the recorded GWA of ' . number_format((float) $applicantGwaValue, 2) . ' meets the scholarship limit of ' . number_format((float) $matchRequiredGwa, 2) . ' or better.'
            );
        } else {
            $pushReason(
                $matchLimitingReasons,
                'Academic check does not pass because the recorded GWA of ' . number_format((float) $applicantGwaValue, 2) . ' is above the scholarship limit of ' . number_format((float) $matchRequiredGwa, 2) . '.'
            );
        }
    } else {
        $pushReason($matchPositiveReasons, 'Passed academic check automatically because this scholarship does not use a fixed GWA cutoff.');
    }

    if (is_array($matchCoursePathwayCheck)) {
        $matchCourseReasonText = trim((string) ($matchCoursePathwayCheck['detail'] ?? ''));
        $matchCourseStatus = strtolower(trim((string) ($matchCoursePathwayCheck['status'] ?? 'pending')));
        if ($matchCourseStatus === 'met') {
            $pushReason($matchPositiveReasons, $matchCourseReasonText);
        } elseif (in_array($matchCourseStatus, ['warn', 'pending'], true)) {
            $pushReason($matchLimitingReasons, $matchCourseReasonText);
        }
    }

    if (!empty($matchProfileChecks)) {
        foreach ($matchProfileChecks as $matchProfileCheck) {
            $matchProfileCheckStatus = strtolower(trim((string) ($matchProfileCheck['status'] ?? 'pending')));
            if ($matchProfileCheckStatus === 'met') {
                $pushReason($matchPositiveReasons, scholarshipMatchGuideReasonFromCheck($matchProfileCheck));
            } elseif (in_array($matchProfileCheckStatus, ['failed', 'pending'], true)) {
                $pushReason($matchLimitingReasons, scholarshipMatchGuideReasonFromCheck($matchProfileCheck));
            }
        }
    } else {
        $pushReason($matchPositiveReasons, 'The scholarship is open to a broader set of applicants, which helps the fit score.');
    }

    if ($matchCurrentInfoTotal > 0) {
        foreach ($matchCurrentInfoChecks as $matchCurrentInfoCheck) {
            $matchCurrentInfoKey = strtolower(trim((string) ($matchCurrentInfoCheck['key'] ?? '')));
            if ($matchCurrentInfoKey === 'course_pathway') {
                continue;
            }

            $matchCurrentInfoStatus = strtolower(trim((string) ($matchCurrentInfoCheck['status'] ?? 'pending')));
            if ($matchCurrentInfoStatus === 'met') {
                $pushReason($matchPositiveReasons, scholarshipMatchGuideReasonFromCheck($matchCurrentInfoCheck));
            } elseif (in_array($matchCurrentInfoStatus, ['warn', 'pending'], true)) {
                $pushReason($matchLimitingReasons, scholarshipMatchGuideReasonFromCheck($matchCurrentInfoCheck));
            }
        }
    }

    if ($matchApplicationNotYetOpen) {
        $pushReason($matchLimitingReasons, 'Applications have not opened yet, so the timing signal is weaker right now.');
    } elseif ($matchDeadlineClass === 'bad') {
        $pushReason($matchLimitingReasons, 'The application period has already closed.');
    } elseif ($matchDeadlineClass === 'warn') {
        $pushReason($matchLimitingReasons, 'The deadline is close, so the timing signal is smaller.');
    } else {
        $pushReason($matchPositiveReasons, 'The application window timing supports this recommendation.');
    }

    if ($recognizedProviderMatch) {
        $pushReason($matchPositiveReasons, 'The provider is recognized in the DSS as an established scholarship source.');
    } elseif ($matchProviderClass === 'good') {
        $pushReason($matchPositiveReasons, 'The provider receives an academic-institution ranking signal in the DSS.');
    }

    $matchGuideSummary = scholarshipMatchGuideSummary($matchGuideScore, $matchRequiresGwa);

    return [
        'buttonLabel' => $matchGuideScore !== null ? ('Why ' . $matchGuideScore . '% match?') : 'How match works',
        'title' => $matchGuideScore !== null ? ('Why this shows as ' . $matchGuideScore . '% match') : 'How the match score works',
        'summary' => $matchGuideSummary,
        'note' => $matchGuideNote,
        'factors' => [
            ['label' => 'Academic fit', 'value' => $matchAcademicDecision, 'detail' => $matchAcademicDetail, 'class' => $matchAcademicClass, 'icon' => 'fa-chart-line'],
            ['label' => 'Course focus', 'value' => $matchCourseValue, 'detail' => $matchCourseDetail, 'class' => $matchCourseClass, 'icon' => 'fa-graduation-cap'],
            ['label' => 'Audience fit', 'value' => $matchProfileValue, 'detail' => $matchProfileDetail, 'class' => $matchProfileClass, 'icon' => 'fa-user-check'],
            ['label' => 'Student context', 'value' => $matchStudentContextValue, 'detail' => $matchStudentContextDetail, 'class' => $matchStudentContextClass, 'icon' => 'fa-id-card'],
            ['label' => 'Application timing', 'value' => $matchTimingValue, 'detail' => $matchTimingDetail, 'class' => $matchTimingClass, 'icon' => 'fa-calendar-days'],
            ['label' => 'Provider signal', 'value' => $matchProviderValue, 'detail' => $matchProviderDetail, 'class' => $matchProviderClass, 'icon' => 'fa-building-columns'],
        ],
        'positive' => !empty($matchPositiveReasons) ? array_slice($matchPositiveReasons, 0, 4) : ['The DSS has not found strong positive scoring signals yet.'],
        'limiting' => !empty($matchLimitingReasons) ? array_slice($matchLimitingReasons, 0, 4) : ['No major factor is pulling the match score down right now.'],
    ];
}

$statusFilter = $_GET['status'] ?? '';
$validStatuses = ['pending', 'approved', 'rejected'];
$activeFilter = in_array($statusFilter, $validStatuses, true) ? $statusFilter : '';
$providerScope = getCurrentProviderScope($pdo);
$providerApplicationScope = getProviderScholarshipScopeClause($pdo, 'sd2.provider');
$studentGwaSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'gwa');
$studentApplicantTypeSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'applicant_type');
$studentYearLevelSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'year_level');
$studentAdmissionStatusSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'admission_status');
$studentShsStrandSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'shs_strand');
$studentTargetCourseSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'target_course');
$studentTargetCollegeSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'target_college');
$studentEnrollmentStatusSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'enrollment_status');
$studentAcademicStandingSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'academic_standing');
$studentCitySelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'city');
$studentProvinceSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'province');
$studentCitizenshipSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'citizenship');
$studentIncomeBracketSelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'household_income_bracket');
$studentSpecialCategorySelect = queueOptionalColumnSelect($pdo, 'sd', 'student_data', 'special_category');
$scholarshipEligibilitySelect = queueOptionalColumnSelect($pdo, 's', 'scholarships', 'eligibility', 'scholarship_eligibility');
$scholarshipMinGwaSelect = queueOptionalColumnSelect($pdo, 's', 'scholarships', 'min_gwa', 'scholarship_min_gwa');
$scholarshipAddressSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'address', 'scholarship_address');
$scholarshipCitySelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'city', 'scholarship_city');
$scholarshipProvinceSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'province', 'scholarship_province');
$scholarshipApplicationOpenDateSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'application_open_date', 'scholarship_application_open_date');
$scholarshipTargetApplicantTypeSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_applicant_type');
$scholarshipTargetYearLevelSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_year_level');
$scholarshipRequiredAdmissionStatusSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'required_admission_status');
$scholarshipTargetStrandSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_strand');
$scholarshipTargetCitizenshipSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_citizenship');
$scholarshipTargetIncomeBracketSelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_income_bracket');
$scholarshipTargetSpecialCategorySelect = queueOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_special_category');

$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];

try {
    $statsBaseSql = "
        FROM applications a
        JOIN scholarships s ON a.scholarship_id = s.id
        LEFT JOIN scholarship_data sd2 ON s.id = sd2.scholarship_id
        WHERE 1=1
    ";
    $statsBaseSql .= $providerApplicationScope['sql'];
    $statsBaseParams = $providerApplicationScope['params'];

    $statsStmt = $pdo->prepare("SELECT COUNT(*) " . $statsBaseSql);
    $statsStmt->execute($statsBaseParams);
    $stats['total'] = (int) $statsStmt->fetchColumn();

    foreach (['pending', 'approved', 'rejected'] as $statusName) {
        $statusStmt = $pdo->prepare("SELECT COUNT(*) " . $statsBaseSql . " AND a.status = ?");
        $statusParams = array_merge($statsBaseParams, [$statusName]);
        $statusStmt->execute($statusParams);
        $stats[$statusName] = (int) $statusStmt->fetchColumn();
    }

    $sql = "
        SELECT
            a.id,
            a.status,
            a.applied_at,
            a.updated_at,
            a.probability_score,
            u.username,
            u.email,
            sd.firstname,
            sd.lastname,
            {$studentGwaSelect}
            {$studentApplicantTypeSelect}
            {$studentYearLevelSelect}
            {$studentAdmissionStatusSelect}
            {$studentShsStrandSelect}
            sd.school,
            sd.course,
            {$studentTargetCourseSelect}
            {$studentTargetCollegeSelect}
            {$studentEnrollmentStatusSelect}
            {$studentAcademicStandingSelect}
            {$studentCitySelect}
            {$studentProvinceSelect}
            {$studentCitizenshipSelect}
            {$studentIncomeBracketSelect}
            {$studentSpecialCategorySelect}
            s.id AS scholarship_id,
            s.name AS scholarship_name,
            s.description AS scholarship_description,
            {$scholarshipEligibilitySelect}
            {$scholarshipMinGwaSelect}
            COALESCE(sd2.provider, 'Not specified') AS provider,
            sd2.deadline,
            {$scholarshipAddressSelect}
            {$scholarshipCitySelect}
            {$scholarshipProvinceSelect}
            {$scholarshipApplicationOpenDateSelect}
            {$scholarshipTargetApplicantTypeSelect}
            {$scholarshipTargetYearLevelSelect}
            {$scholarshipRequiredAdmissionStatusSelect}
            {$scholarshipTargetStrandSelect}
            {$scholarshipTargetCitizenshipSelect}
            {$scholarshipTargetIncomeBracketSelect}
            {$scholarshipTargetSpecialCategorySelect}
            NULL AS queue_match_guide_ready
        FROM applications a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN student_data sd ON u.id = sd.student_id
        JOIN scholarships s ON a.scholarship_id = s.id
        LEFT JOIN scholarship_data sd2 ON s.id = sd2.scholarship_id
        WHERE 1=1
    ";

    $params = [];
    $sql .= $providerApplicationScope['sql'];
    $params = array_merge($params, $providerApplicationScope['params']);
    if ($activeFilter !== '') {
        $sql .= ' AND a.status = ?';
        $params[] = $activeFilter;
    }

    $sql .= ' ORDER BY a.applied_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $applications = [];
    $_SESSION['error'] = 'Unable to load applications.';
}

$applicationMatchGuides = [];
if (!empty($applications)) {
    try {
        $scholarshipService = new ScholarshipService($pdo);
        foreach ($applications as $applicationRow) {
            $applicationMatchGuides[(string) ((int) ($applicationRow['id'] ?? 0))] = queueBuildMatchGuidePayload($applicationRow, $scholarshipService);
        }
    } catch (Throwable $matchGuideError) {
        error_log('manage_applications match guide error: ' . $matchGuideError->getMessage());
        foreach ($applications as $applicationRow) {
            $applicationMatchGuides[(string) ((int) ($applicationRow['id'] ?? 0))] = queueBuildFallbackMatchGuidePayload($applicationRow);
        }
    }
}

$filterOptions = [
    '' => ['label' => 'All', 'count' => $stats['total'], 'icon' => 'fa-layer-group'],
    'pending' => ['label' => 'Pending', 'count' => $stats['pending'], 'icon' => 'fa-clock'],
    'approved' => ['label' => 'Approved', 'count' => $stats['approved'], 'icon' => 'fa-check-circle'],
    'rejected' => ['label' => 'Rejected', 'count' => $stats['rejected'], 'icon' => 'fa-times-circle'],
];

$filterLabel = $activeFilter === '' ? 'All applications' : ucfirst($activeFilter) . ' applications';
$adminStyleVersion = @filemtime(__DIR__ . '/../public/css/admin_style.css') ?: time();
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();
$manageApplicationsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/manage-applications.css') ?: time();
$applicationFlashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : '';
$applicationFlashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Applications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css?v=<?php echo urlencode((string) $adminStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/manage-applications.css?v=<?php echo urlencode((string) $manageApplicationsStyleVersion); ?>">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css?v=<?php echo urlencode((string) $reviewsStyleVersion); ?>">
</head>
<body class="admin-applications-page">
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard">
        <div class="container">
            <?php
            $applicationsVisibleCount = count($applications);
            $applicationsHeaderCopy = !empty($providerScope['is_provider']) && !empty($providerScope['organization_name'])
                ? 'Applications submitted to scholarships under ' . $providerScope['organization_name'] . '.'
                : 'Review scholarship applications, applicant records, and final decisions.';
            ?>
            <div class="page-header applications-page-header">
                <div class="applications-page-header-copy">
                    <h1>
                        <i class="fas fa-file-signature"></i>Manage Applications
                    </h1>
                    <p><?php echo htmlspecialchars($applicationsHeaderCopy); ?></p>
                </div>
            </div>

            <?php $reviewsCurrentView = 'applications'; include 'layouts/reviews_nav.php'; ?>

            <?php if ($applicationFlashSuccess !== ''): ?>
                <noscript>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($applicationFlashSuccess); ?>
                </div>
                </noscript>
            <?php endif; ?>

            <?php if ($applicationFlashError !== ''): ?>
                <noscript>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($applicationFlashError); ?>
                </div>
                </noscript>
            <?php endif; ?>

            <div class="applications-summary-grid">
                <article class="application-summary-card">
                    <div class="application-summary-copy">
                        <span class="application-summary-label">Total Applications</span>
                        <strong class="application-summary-value"><?php echo number_format($stats['total']); ?></strong>
                        <span class="application-summary-meta">All submitted records</span>
                    </div>
                </article>
                <article class="application-summary-card">
                    <div class="application-summary-copy">
                        <span class="application-summary-label">Pending</span>
                        <strong class="application-summary-value"><?php echo number_format($stats['pending']); ?></strong>
                        <span class="application-summary-meta">Awaiting review</span>
                    </div>
                </article>
                <article class="application-summary-card">
                    <div class="application-summary-copy">
                        <span class="application-summary-label">Approved</span>
                        <strong class="application-summary-value"><?php echo number_format($stats['approved']); ?></strong>
                        <span class="application-summary-meta">Completed decisions</span>
                    </div>
                </article>
                <article class="application-summary-card">
                    <div class="application-summary-copy">
                        <span class="application-summary-label">Rejected</span>
                        <strong class="application-summary-value"><?php echo number_format($stats['rejected']); ?></strong>
                        <span class="application-summary-meta">Closed records</span>
                    </div>
                </article>
            </div>

            <div class="applications-toolbar-card">
                <div class="applications-toolbar-copy">
                    <h2>Application Queue</h2>
                    <p><?php echo htmlspecialchars($filterLabel); ?> | <?php echo number_format($applicationsVisibleCount); ?> visible record<?php echo $applicationsVisibleCount === 1 ? '' : 's'; ?></p>
                </div>
                <div class="applications-filter-pills">
                    <?php foreach ($filterOptions as $filterValue => $option): ?>
                        <?php
                        $filterHref = $filterValue === '' ? 'manage_applications.php' : 'manage_applications.php?status=' . urlencode($filterValue);
                        $isActiveFilter = $activeFilter === $filterValue;
                        ?>
                        <a href="<?php echo htmlspecialchars($filterHref); ?>" class="applications-filter-pill <?php echo $isActiveFilter ? 'active' : ''; ?>">
                            <i class="fas <?php echo htmlspecialchars($option['icon']); ?>" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars($option['label']); ?></span>
                            <strong><?php echo number_format($option['count']); ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (empty($applications)): ?>
                <div class="applications-empty-state">
                    <div class="applications-empty-icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <h3>No applications found</h3>
                    <p>No records match the selected filter.</p>
                </div>
            <?php else: ?>
                <div class="applications-board">
                    <?php foreach ($applications as $application): ?>
                        <?php
                        $applicationId = (int) $application['id'];
                        $matchGuidePayload = $applicationMatchGuides[(string) $applicationId] ?? queueBuildFallbackMatchGuidePayload($application);
                        $fullName = trim(($application['firstname'] ?? '') . ' ' . ($application['lastname'] ?? ''));
                        if ($fullName === '') {
                            $fullName = $application['username'];
                        }
                        $initialsSource = preg_split('/\s+/', trim($fullName)) ?: [];
                        $initials = '';
                        foreach ($initialsSource as $namePart) {
                            if ($namePart === '') {
                                continue;
                            }
                            $initials .= strtoupper(substr($namePart, 0, 1));
                            if (strlen($initials) >= 2) {
                                break;
                            }
                        }
                        $formattedProbability = $application['probability_score'] !== null
                            ? round((float) $application['probability_score'], 1) . '%'
                            : 'Not scored';
                        $scoreLabel = $application['probability_score'] !== null ? 'Profile score' : 'Profile score unavailable';
                        $statusValue = (string) ($application['status'] ?? 'pending');
                        $isIncomingFreshmanApplicant = strtolower(trim((string) ($application['applicant_type'] ?? ''))) === 'incoming_freshman';
                        $courseLabel = trim((string) ($application['course'] ?? '')) ?: 'Course not provided';
                        $schoolLabel = trim((string) ($application['school'] ?? '')) ?: 'School not provided';
                        $viewApplicationUrl = buildEntityUrl('view_application.php', 'application', $applicationId, 'view', ['id' => $applicationId]);
                        ?>
                        <article class="application-record-card status-<?php echo htmlspecialchars($statusValue); ?>">
                            <div class="application-record-head">
                                <div class="application-record-applicant">
                                    <div class="application-record-avatar"><?php echo htmlspecialchars($initials ?: 'A'); ?></div>
                                    <div class="application-record-identity">
                                        <h3><?php echo htmlspecialchars($fullName); ?></h3>
                                        <p><?php echo htmlspecialchars($application['email']); ?></p>
                                        <div class="application-record-submeta">
                                            <span><i class="fas fa-graduation-cap"></i><?php echo htmlspecialchars($courseLabel); ?></span>
                                            <?php if (!$isIncomingFreshmanApplicant): ?>
                                                <span><i class="fas fa-school"></i><?php echo htmlspecialchars($schoolLabel); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="application-record-badges">
                                    <span class="application-status-pill status-<?php echo htmlspecialchars($statusValue); ?>">
                                        <i class="fas <?php echo $statusValue === 'approved' ? 'fa-circle-check' : ($statusValue === 'rejected' ? 'fa-circle-xmark' : 'fa-clock'); ?>"></i>
                                        <?php echo htmlspecialchars(ucfirst($statusValue)); ?>
                                    </span>
                                    <span class="application-score-pill<?php echo $application['probability_score'] !== null ? '' : ' muted'; ?>">
                                        <span class="application-score-label"><?php echo htmlspecialchars($scoreLabel); ?></span>
                                        <strong><?php echo htmlspecialchars($formattedProbability); ?></strong>
                                    </span>
                                </div>
                            </div>

                            <div class="application-record-grid">
                                <div class="application-record-meta">
                                    <span class="application-record-label">Scholarship</span>
                                    <strong><?php echo htmlspecialchars($application['scholarship_name']); ?></strong>
                                </div>
                                <div class="application-record-meta">
                                    <span class="application-record-label">Provider</span>
                                    <strong><?php echo htmlspecialchars($application['provider']); ?></strong>
                                </div>
                                <div class="application-record-meta">
                                    <span class="application-record-label">Submitted</span>
                                    <strong><?php echo htmlspecialchars(date('M d, Y', strtotime($application['applied_at']))); ?></strong>
                                    <span class="application-record-note"><?php echo htmlspecialchars(date('h:i A', strtotime($application['applied_at']))); ?></span>
                                </div>
                                <div class="application-record-meta">
                                    <span class="application-record-label">Last Updated</span>
                                    <strong><?php echo htmlspecialchars(date('M d, Y', strtotime($application['updated_at'] ?: $application['applied_at']))); ?></strong>
                                    <span class="application-record-note"><?php echo htmlspecialchars(date('h:i A', strtotime($application['updated_at'] ?: $application['applied_at']))); ?></span>
                                </div>
                            </div>

                            <div class="application-record-actions">
                                <button
                                    type="button"
                                    class="btn btn-outline application-match-trigger"
                                    data-open-application-match-guide="<?php echo (int) $applicationId; ?>"
                                    aria-haspopup="dialog"
                                    aria-controls="applicationMatchModal"
                                >
                                    <i class="fas fa-circle-question"></i> <?php echo htmlspecialchars((string) ($matchGuidePayload['buttonLabel'] ?? 'How match works')); ?>
                                </button>
                                <a href="<?php echo htmlspecialchars($viewApplicationUrl); ?>" class="btn btn-primary">
                                    <i class="fas fa-eye"></i> Open Review
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="queue-match-modal" id="applicationMatchModal" hidden>
        <div class="queue-match-backdrop" data-close-application-match-guide></div>
        <div class="queue-match-dialog" role="dialog" aria-modal="true" aria-labelledby="applicationMatchModalTitle">
            <div class="queue-match-header">
                <div>
                    <span class="queue-match-eyebrow">Match Guide</span>
                    <h2 id="applicationMatchModalTitle">How the match score works</h2>
                </div>
                <button
                    type="button"
                    class="queue-match-close"
                    data-close-application-match-guide
                    aria-label="Close match guide"
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <p id="applicationMatchModalSummary" class="queue-match-copy"></p>

            <div class="queue-match-note">
                <p id="applicationMatchModalNote"></p>
            </div>

            <div id="applicationMatchModalFactors" class="queue-match-factor-grid"></div>

            <div class="queue-match-reason-grid">
                <section class="queue-match-reason-card">
                        <h3>Why it passed</h3>
                    <ul id="applicationMatchModalPositive" class="queue-match-list tone-positive"></ul>
                </section>

                <section class="queue-match-reason-card queue-match-reason-card-warning">
                        <h3>What is still limiting this score</h3>
                    <ul id="applicationMatchModalLimiting" class="queue-match-list tone-warning"></ul>
                </section>
            </div>
        </div>
    </div>

    <?php include 'layouts/admin_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const applicationFlashQueue = [];
        const applicationFlashSuccess = <?php echo json_encode($applicationFlashSuccess); ?>;
        const applicationFlashError = <?php echo json_encode($applicationFlashError); ?>;
        const applicationMatchGuides = <?php echo json_encode($applicationMatchGuides, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        if (applicationFlashSuccess) {
            applicationFlashQueue.push({
                icon: 'success',
                title: 'Success',
                text: applicationFlashSuccess,
                confirmButtonColor: '#2c5aa0'
            });
        }

        if (applicationFlashError) {
            applicationFlashQueue.push({
                icon: 'error',
                title: 'Action failed',
                text: applicationFlashError,
                confirmButtonColor: '#dc2626'
            });
        }

        async function showApplicationFlashQueue() {
            if (!window.Swal || !applicationFlashQueue.length) {
                return;
            }

            for (const flashConfig of applicationFlashQueue) {
                await Swal.fire(flashConfig);
            }
        }

        showApplicationFlashQueue();

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        const applicationMatchModal = document.getElementById('applicationMatchModal');
        const applicationMatchModalTitle = document.getElementById('applicationMatchModalTitle');
        const applicationMatchModalSummary = document.getElementById('applicationMatchModalSummary');
        const applicationMatchModalNote = document.getElementById('applicationMatchModalNote');
        const applicationMatchModalFactors = document.getElementById('applicationMatchModalFactors');
        const applicationMatchModalPositive = document.getElementById('applicationMatchModalPositive');
        const applicationMatchModalLimiting = document.getElementById('applicationMatchModalLimiting');
        const applicationMatchModalCloseElements = applicationMatchModal
            ? applicationMatchModal.querySelectorAll('[data-close-application-match-guide]')
            : [];
        let lastApplicationMatchTrigger = null;

        function renderMatchFactors(factors) {
            if (!applicationMatchModalFactors) {
                return;
            }

            if (!Array.isArray(factors) || !factors.length) {
                applicationMatchModalFactors.innerHTML = '';
                return;
            }

            applicationMatchModalFactors.innerHTML = factors.map((factor) => {
                const tone = escapeHtml(String(factor.class || 'info').toLowerCase());
                const icon = escapeHtml(String(factor.icon || 'fa-circle-info'));
                return `
                    <article class="queue-match-factor-card state-${tone}">
                        <div class="queue-match-factor-icon">
                            <i class="fas ${icon}"></i>
                        </div>
                        <div class="queue-match-factor-copy">
                            <span>${escapeHtml(factor.label || 'Factor')}</span>
                            <strong>${escapeHtml(factor.value || 'Not available')}</strong>
                            <p>${escapeHtml(factor.detail || '')}</p>
                        </div>
                    </article>
                `;
            }).join('');
        }

        function renderMatchReasonList(targetElement, items) {
            if (!targetElement) {
                return;
            }

            targetElement.innerHTML = (Array.isArray(items) ? items : []).map((item) => `
                <li>${escapeHtml(item)}</li>
            `).join('');
        }

        function closeApplicationMatchGuide() {
            if (!applicationMatchModal) {
                return;
            }

            applicationMatchModal.hidden = true;
            document.body.classList.remove('queue-match-modal-open');

            if (lastApplicationMatchTrigger && typeof lastApplicationMatchTrigger.focus === 'function') {
                lastApplicationMatchTrigger.focus();
            }
        }

        function openApplicationMatchGuide(applicationId, triggerElement) {
            const payload = applicationMatchGuides[String(applicationId)] || applicationMatchGuides[applicationId];
            if (!payload || !applicationMatchModal) {
                return;
            }

            lastApplicationMatchTrigger = triggerElement || null;
            applicationMatchModalTitle.textContent = payload.title || 'How the match score works';
            applicationMatchModalSummary.textContent = payload.summary || 'This score comes from the DSS profile matching model.';
            applicationMatchModalNote.textContent = payload.note || '';
            renderMatchFactors(payload.factors);
            renderMatchReasonList(applicationMatchModalPositive, payload.positive);
            renderMatchReasonList(applicationMatchModalLimiting, payload.limiting);

            applicationMatchModal.hidden = false;
            document.body.classList.add('queue-match-modal-open');
        }

        document.querySelectorAll('[data-open-application-match-guide]').forEach((button) => {
            button.addEventListener('click', function () {
                openApplicationMatchGuide(
                    this.getAttribute('data-open-application-match-guide') || '',
                    this
                );
            });
        });

        applicationMatchModalCloseElements.forEach((element) => {
            element.addEventListener('click', closeApplicationMatchGuide);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && applicationMatchModal && !applicationMatchModal.hidden) {
                closeApplicationMatchGuide();
            }
        });
    </script>
</body>
</html>




