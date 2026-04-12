<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';
require_once __DIR__ . '/../app/Config/provider_scope.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/helpers.php';
require_once __DIR__ . '/../app/Models/UserDocument.php';
require_once __DIR__ . '/../app/Controllers/scholarshipResultController.php';

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

function appDateTimeLocalValue(?string $value): string
{
    if (empty($value)) {
        return '';
    }

    try {
        return (new DateTime((string) $value))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
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

function appTableExists(PDO $pdo, string $tableName): bool
{
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([
        ':table_name' => $tableName
    ]);

    $cache[$tableName] = ((int) $stmt->fetchColumn()) > 0;
    return $cache[$tableName];
}

function appOptionalColumnSelect(PDO $pdo, string $tableAlias, string $tableName, string $columnName, ?string $alias = null): string
{
    $selectAlias = $alias ?? $columnName;

    if (!appTableHasColumn($pdo, $tableName, $columnName)) {
        return 'NULL AS ' . $selectAlias . ',';
    }

    return $tableAlias . '.' . $columnName . ' AS ' . $selectAlias . ',';
}

function appAssessmentTypeLabel(?string $value): string
{
    $normalized = strtolower(trim((string) $value));
    $map = [
        'online_exam' => 'Online Exam',
        'remote_examination' => 'Remote Examination',
        'assessment' => 'Online Assessment',
        'evaluation' => 'Online Evaluation',
    ];

    return $map[$normalized] ?? 'Assessment';
}

function appAssessmentStatusLabel(?string $value): string
{
    $normalized = strtolower(trim((string) $value));
    $map = [
        'not_started' => 'Assessment required',
        'scheduled' => 'Scheduled',
        'ready' => 'Ready to take',
        'submitted' => 'Submitted / attended',
        'under_review' => 'Under review',
        'passed' => 'Passed',
        'failed' => 'Did not pass',
    ];

    return $map[$normalized] ?? 'Assessment required';
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
$applicationAssessmentStatusSelect = appOptionalColumnSelect($pdo, 'a', 'applications', 'assessment_status');
$applicationAssessmentScheduleSelect = appOptionalColumnSelect($pdo, 'a', 'applications', 'assessment_schedule_at');
$applicationAssessmentLinkOverrideSelect = appOptionalColumnSelect($pdo, 'a', 'applications', 'assessment_link_override');
$applicationAssessmentSiteSelect = appOptionalColumnSelect($pdo, 'a', 'applications', 'assessment_site_id');
$applicationAssessmentNotesSelect = appOptionalColumnSelect($pdo, 'a', 'applications', 'assessment_notes');
$scholarshipEligibilitySelect = appOptionalColumnSelect($pdo, 's', 'scholarships', 'eligibility', 'scholarship_eligibility');
$scholarshipMinGwaSelect = appOptionalColumnSelect($pdo, 's', 'scholarships', 'min_gwa', 'scholarship_min_gwa');
$scholarshipAddressSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'address', 'scholarship_address');
$scholarshipCitySelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'city', 'scholarship_city');
$scholarshipProvinceSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'province', 'scholarship_province');
$scholarshipApplicationOpenDateSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'application_open_date', 'scholarship_application_open_date');
$scholarshipTargetApplicantTypeSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_applicant_type');
$scholarshipTargetYearLevelSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_year_level');
$scholarshipRequiredAdmissionStatusSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'required_admission_status');
$scholarshipTargetStrandSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_strand');
$scholarshipTargetCitizenshipSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_citizenship');
$scholarshipTargetIncomeBracketSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_income_bracket');
$scholarshipTargetSpecialCategorySelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'target_special_category');
$scholarshipAssessmentRequirementSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'assessment_requirement');
$scholarshipAssessmentLinkSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'assessment_link');
$scholarshipAssessmentDetailsSelect = appOptionalColumnSelect($pdo, 'sd2', 'scholarship_data', 'assessment_details');
$assessmentSiteJoin = (appTableHasColumn($pdo, 'applications', 'assessment_site_id') && appTableExists($pdo, 'scholarship_remote_exam_locations'))
    ? 'LEFT JOIN scholarship_remote_exam_locations srel ON srel.id = a.assessment_site_id'
    : '';
$assessmentSiteNameSelect = $assessmentSiteJoin !== ''
    ? 'srel.site_name AS assessment_site_name,'
    : 'NULL AS assessment_site_name,';
$assessmentSiteAddressSelect = $assessmentSiteJoin !== ''
    ? 'srel.address AS assessment_site_address,'
    : 'NULL AS assessment_site_address,';
$assessmentSiteCitySelect = $assessmentSiteJoin !== ''
    ? 'srel.city AS assessment_site_city,'
    : 'NULL AS assessment_site_city,';
$assessmentSiteProvinceSelect = $assessmentSiteJoin !== ''
    ? 'srel.province AS assessment_site_province,'
    : 'NULL AS assessment_site_province,';

try {
    $stmt = $pdo->prepare("
        SELECT
            a.id AS application_id,
            a.status AS application_status,
            a.probability_score,
            {$applicationRejectionReasonSelect}
            {$applicationStudentResponseStatusSelect}
            {$applicationStudentRespondedAtSelect}
            {$applicationAssessmentStatusSelect}
            {$applicationAssessmentScheduleSelect}
            {$applicationAssessmentLinkOverrideSelect}
            {$applicationAssessmentSiteSelect}
            {$applicationAssessmentNotesSelect}
            a.applied_at,
            a.updated_at,
            u.id AS user_id,
            u.username AS user_name,
            u.email,
            u.status AS user_status,
            sd.*,
            s.id AS scholarship_id,
            s.name AS scholarship_name,
            {$scholarshipEligibilitySelect}
            {$scholarshipMinGwaSelect}
            sd2.provider,
            sd2.deadline,
            sd2.benefits,
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
            {$scholarshipAssessmentRequirementSelect}
            {$scholarshipAssessmentLinkSelect}
            {$scholarshipAssessmentDetailsSelect}
            s.description AS scholarship_description,
            {$assessmentSiteNameSelect}
            {$assessmentSiteAddressSelect}
            {$assessmentSiteCitySelect}
            {$assessmentSiteProvinceSelect}
            sl.location_name AS student_location_name
        FROM applications a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN student_data sd ON u.id = sd.student_id
        JOIN scholarships s ON a.scholarship_id = s.id
        LEFT JOIN scholarship_data sd2 ON s.id = sd2.scholarship_id
        {$assessmentSiteJoin}
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
$isIncomingFreshmanApplicant = strtolower(trim((string) ($application['applicant_type'] ?? ''))) === 'incoming_freshman';
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
$identityFields = [
    ['label' => 'Applicant Name', 'value' => $fullName],
    ['label' => 'Email Address', 'value' => appValue($application['email'] ?? '')],
    ['label' => 'Mobile Number', 'value' => appValue(formatPhilippineMobileNumber($application['mobile_number'] ?? ''))],
    ['label' => 'Gender', 'value' => $formattedGenderLabel],
    ['label' => 'Birthdate / Age', 'value' => $birthAndAgeLabel],
    ['label' => 'Citizenship', 'value' => $citizenshipLabel],
    ['label' => 'Applicant Type', 'value' => $applicantProgramLabel],
    ['label' => 'Applicant Account', 'value' => ucfirst((string) ($application['user_status'] ?? 'inactive'))],
];
$academicFields = [
    ['label' => 'Current GWA', 'value' => $currentGwaLabel],
    ['label' => 'Course / Program', 'value' => $courseLabel],
    ['label' => 'Year Level', 'value' => $yearLevelLabel],
    ['label' => 'Enrollment Status', 'value' => $enrollmentStatusLabel],
    ['label' => 'Academic Standing', 'value' => $academicStandingLabel],
];
if (!$isIncomingFreshmanApplicant) {
    array_splice($academicFields, 1, 0, [[
        'label' => 'School / Institution',
        'value' => $schoolLabel,
    ]]);
}
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
$assessmentRequirement = strtolower(trim((string) ($application['assessment_requirement'] ?? 'none')));
$assessmentEnabled = in_array($assessmentRequirement, ['online_exam', 'remote_examination', 'assessment', 'evaluation'], true);
$assessmentTypeLabel = $assessmentEnabled ? appAssessmentTypeLabel($assessmentRequirement) : 'Assessment';
$assessmentStatusChoices = [
    'not_started' => 'Assessment required',
    'scheduled' => 'Scheduled',
    'ready' => 'Ready to take',
    'submitted' => 'Submitted / attended',
    'under_review' => 'Under review',
    'passed' => 'Passed',
    'failed' => 'Did not pass',
];
$assessmentStatus = strtolower(trim((string) ($application['assessment_status'] ?? '')));
if ($assessmentEnabled && $assessmentStatus === '') {
    $assessmentStatus = 'not_started';
}
$assessmentStatusLabel = $assessmentEnabled ? appAssessmentStatusLabel($assessmentStatus) : 'Not required';
$assessmentStatusClass = match ($assessmentStatus) {
    'passed' => 'approved',
    'failed' => 'rejected',
    'scheduled', 'under_review' => 'pending',
    'ready', 'submitted' => 'info',
    default => 'active',
};
$assessmentConfiguredLink = trim((string) ($application['assessment_link'] ?? ''));
$assessmentOverrideLink = trim((string) ($application['assessment_link_override'] ?? ''));
$assessmentEffectiveLink = $assessmentOverrideLink !== '' ? $assessmentOverrideLink : $assessmentConfiguredLink;
if ($assessmentEffectiveLink !== '' && !filter_var($assessmentEffectiveLink, FILTER_VALIDATE_URL)) {
    $assessmentEffectiveLink = '';
}
$assessmentScheduleAt = !empty($application['assessment_schedule_at']) ? (string) $application['assessment_schedule_at'] : null;
$assessmentScheduleLabel = appDate($assessmentScheduleAt, 'F j, Y g:i A', 'Not scheduled yet');
$assessmentScheduleInputValue = appDateTimeLocalValue($assessmentScheduleAt);
$assessmentSiteParts = array_filter([
    trim((string) ($application['assessment_site_name'] ?? '')),
    trim((string) ($application['assessment_site_address'] ?? '')),
    trim((string) ($application['assessment_site_city'] ?? '')),
    trim((string) ($application['assessment_site_province'] ?? '')),
]);
$assessmentSiteLabel = !empty($assessmentSiteParts) ? implode(', ', $assessmentSiteParts) : 'No site selected yet';
$assessmentNotes = trim((string) ($application['assessment_notes'] ?? ''));
$assessmentDetails = trim((string) ($application['assessment_details'] ?? ''));
$assessmentSiteId = (int) ($application['assessment_site_id'] ?? 0);
$assessmentRemoteSites = [];
if (
    $assessmentEnabled
    && $assessmentRequirement === 'remote_examination'
    && appTableExists($pdo, 'scholarship_remote_exam_locations')
) {
    try {
        $assessmentSiteStmt = $pdo->prepare("
            SELECT id, site_name, address, city, province
            FROM scholarship_remote_exam_locations
            WHERE scholarship_id = ?
            ORDER BY site_name ASC, id ASC
        ");
        $assessmentSiteStmt->execute([(int) ($application['scholarship_id'] ?? 0)]);
        $assessmentRemoteSites = $assessmentSiteStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('view_application remote sites load failed: ' . $e->getMessage());
    }
}
$assessmentManagementUnlocked = strtolower($applicationStatus) === 'approved' && $studentAccepted;
$assessmentStatusNote = 'This scholarship does not have a post-acceptance assessment configured.';
$assessmentTimelineState = 'upcoming';
if ($assessmentEnabled) {
    if (!$assessmentManagementUnlocked) {
        $assessmentStatusNote = strtolower($applicationStatus) === 'approved'
            ? 'This stage opens after the student accepts the approved scholarship offer.'
            : 'Assessment updates become available after approval and student acceptance.';
        $assessmentTimelineState = 'upcoming';
    } else {
        switch ($assessmentStatus) {
            case 'scheduled':
                $assessmentStatusNote = $assessmentTypeLabel . ' scheduled for ' . $assessmentScheduleLabel . ($assessmentRequirement === 'remote_examination' && $assessmentSiteLabel !== 'No site selected yet' ? ' at ' . $assessmentSiteLabel . '.' : '.');
                $assessmentTimelineState = 'current';
                break;
            case 'ready':
                $assessmentStatusNote = $assessmentRequirement === 'remote_examination'
                    ? ($assessmentSiteLabel !== 'No site selected yet' ? 'Assigned site: ' . $assessmentSiteLabel . '.' : 'The student can now view the assigned exam sites.')
                    : ($assessmentEffectiveLink !== '' ? 'The exam portal is ready for the student.' : 'The provider marked the exam stage as ready.');
                $assessmentTimelineState = 'current';
                break;
            case 'submitted':
                $assessmentStatusNote = $assessmentRequirement === 'remote_examination'
                    ? 'Attendance or site completion was recorded. Wait for the result update.'
                    : 'The exam submission was recorded. Wait for the result update.';
                $assessmentTimelineState = 'current';
                break;
            case 'under_review':
                $assessmentStatusNote = 'The assessment result is currently under review.';
                $assessmentTimelineState = 'current';
                break;
            case 'passed':
                $assessmentStatusNote = 'The student passed the post-acceptance assessment.';
                $assessmentTimelineState = 'complete';
                break;
            case 'failed':
                $assessmentStatusNote = 'The student did not pass the post-acceptance assessment.';
                $assessmentTimelineState = 'rejected';
                break;
            default:
                $assessmentStatusNote = 'Acceptance is recorded. Post the schedule, site, or portal details for the student here.';
                $assessmentTimelineState = 'current';
                break;
        }
    }

    if ($assessmentNotes !== '') {
        $assessmentStatusNote .= ' Note: ' . $assessmentNotes;
    }
}
$assessmentStudentActionUrl = '';
$assessmentStudentActionLabel = '';
$assessmentStudentActionExternal = false;
if ($assessmentEnabled) {
    if ($assessmentRequirement === 'remote_examination') {
        $assessmentStudentActionUrl = buildEntityUrl(
            '../View/remote_exam_map.php',
            'scholarship',
            (int) ($application['scholarship_id'] ?? 0),
            'view',
            [
                'id' => (int) ($application['scholarship_id'] ?? 0)
            ]
        );
        $assessmentStudentActionLabel = 'Open Exam Map';
    } elseif ($assessmentEffectiveLink !== '') {
        $assessmentStudentActionUrl = $assessmentEffectiveLink;
        $assessmentStudentActionLabel = 'Open Exam Portal';
        $assessmentStudentActionExternal = true;
    }
}
$assessmentSaveUrl = buildEntityUrl(
    '../app/AdminControllers/application_assessment_process.php',
    'application',
    (int) ($application['application_id'] ?? 0),
    'assessment',
    [
        'action' => 'save',
        'id' => (int) ($application['application_id'] ?? 0)
    ]
);
$studentConfirmationTimelineState = 'upcoming';
if (strtolower($applicationStatus) === 'approved' && $studentAccepted) {
    $studentConfirmationTimelineState = 'complete';
} elseif (strtolower($applicationStatus) === 'approved') {
    $studentConfirmationTimelineState = 'current';
}
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
    if ($assessmentEnabled) {
        $decisionReadinessLabel = $assessmentStatus === 'passed'
            ? $assessmentTypeLabel . ' passed'
            : ($assessmentStatus === 'failed' ? $assessmentTypeLabel . ' not passed' : $assessmentTypeLabel . ' in progress');
        $decisionReadinessNote = $assessmentStatusNote;
        if ($assessmentStatus === 'failed') {
            $decisionReadinessTone = 'attention';
        }
    }
} elseif ($applicationStatus === 'approved') {
    $decisionReadinessLabel = 'Approved and waiting for student confirmation';
    $decisionReadinessNote = 'The application is approved. Waiting for the student to accept the scholarship.';
} elseif ($applicationStatus === 'rejected') {
    $decisionReadinessTone = 'attention';
    $decisionReadinessLabel = 'Decision completed';
    $decisionReadinessNote = 'The application has already been rejected.';
}

$applicantScoreProfile = [
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

$scoreScholarshipContext = [
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

if (!empty($scoreScholarshipContext['deadline'])) {
    try {
        $matchDeadlineDate = new DateTime((string) $scoreScholarshipContext['deadline']);
        $matchNow = new DateTime();
        $matchInterval = $matchNow->diff($matchDeadlineDate);
        $matchDaysRemaining = (int) $matchInterval->days;
        if ($matchDeadlineDate < $matchNow) {
            $matchDaysRemaining *= -1;
        }
        $scoreScholarshipContext['days_remaining'] = $matchDaysRemaining;
    } catch (Throwable $e) {
        $scoreScholarshipContext['days_remaining'] = null;
    }
} else {
    $scoreScholarshipContext['days_remaining'] = null;
}

$applicantGwaValue = appHasValue($application['gwa'] ?? null) ? (float) $application['gwa'] : null;
$applicantCourseValue = trim((string) ($application['course'] ?? ''));
$scholarshipService = new ScholarshipService($pdo);
$matchAssessment = $scholarshipService->getMatchAssessmentForScholarship(
    $scoreScholarshipContext,
    $applicantGwaValue,
    $applicantCourseValue,
    $applicantScoreProfile
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
if ($scoreScholarshipContext['min_gwa'] !== null && $scoreScholarshipContext['min_gwa'] !== '') {
    $matchRequiredGwa = (float) $scoreScholarshipContext['min_gwa'];
}

$matchPushReason = static function (array &$reasons, string $reason, int $limit = 4): void {
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
$matchCourseDetail = 'Add the applicant\'s current or target course so the DSS can compare it with the scholarship focus.';
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
} elseif (appHasValue($application['course'] ?? null) || appHasValue($application['target_course'] ?? null)) {
    $matchCourseValue = 'Course information on file';
    $matchCourseDetail = 'The DSS compares the applicant\'s current or target course with the scholarship focus.';
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
if (!empty($scoreScholarshipContext['application_open_date'])) {
    try {
        $matchApplicationOpenDate = new DateTime((string) $scoreScholarshipContext['application_open_date']);
        $matchApplicationOpenDate->setTime(0, 0, 0);
        $matchApplicationOpenDateDisplay = $matchApplicationOpenDate->format('M d, Y');
        if ($matchApplicationOpenDate > new DateTime()) {
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
if (!empty($scoreScholarshipContext['deadline'])) {
    $matchDeadlineDateDisplay = appDate((string) $scoreScholarshipContext['deadline'], 'M d, Y', 'Not set');
    try {
        $matchDeadlineDate = new DateTime((string) $scoreScholarshipContext['deadline']);
        $matchNow = new DateTime();
        if ($matchDeadlineDate < $matchNow) {
            $matchDeadlineDecision = 'Closed';
            $matchDeadlineClass = 'bad';
        } else {
            $matchDeadlineDaysLeft = (int) $matchNow->diff($matchDeadlineDate)->days;
            if ($matchDeadlineDaysLeft <= 7) {
                $matchDeadlineDecision = 'Urgent (' . $matchDeadlineDaysLeft . ' day' . ($matchDeadlineDaysLeft === 1 ? '' : 's') . ' left)';
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
    if ($scoreScholarshipContext['provider'] !== '' && stripos($scoreScholarshipContext['provider'], $recognizedProvider) !== false) {
        $recognizedProviderMatch = true;
        break;
    }
}
if ($recognizedProviderMatch) {
    $matchProviderValue = 'Established provider signal';
    $matchProviderDetail = 'Recognized providers receive a slightly stronger ranking signal in the DSS.';
    $matchProviderClass = 'good';
} elseif (
    $scoreScholarshipContext['provider'] !== ''
    && (
        stripos($scoreScholarshipContext['provider'], 'university') !== false
        || stripos($scoreScholarshipContext['provider'], 'college') !== false
    )
) {
    $matchProviderValue = 'Academic institution signal';
    $matchProviderDetail = 'College and university providers receive a moderate ranking signal in the DSS.';
    $matchProviderClass = 'good';
}

$matchGuideButtonLabel = $matchGuideScore !== null
    ? ('Why ' . $matchGuideScore . '% match?')
    : 'How match works';
$matchGuideTitle = $matchGuideScore !== null
    ? ('Why this shows as ' . $matchGuideScore . '% match')
    : 'How the match score works';

$matchGuideSummary = '';

$matchGuideNote = 'This percentage ranks fit only. Required documents affect approval readiness, and the final decision still depends on provider review.';
$matchPositiveReasons = [];
$matchLimitingReasons = [];

if ($matchRequiredGwa !== null) {
    if ($applicantGwaValue === null) {
        $matchPushReason($matchLimitingReasons, 'The academic record is missing, so the score is only partly estimated right now.');
    } elseif ($applicantGwaValue <= $matchRequiredGwa) {
        $matchPushReason(
            $matchPositiveReasons,
            'Passed academic check because the recorded GWA of ' . number_format((float) $applicantGwaValue, 2) . ' meets the scholarship limit of ' . number_format((float) $matchRequiredGwa, 2) . ' or better.'
        );
    } else {
        $matchPushReason(
            $matchLimitingReasons,
            'Academic check does not pass because the recorded GWA of ' . number_format((float) $applicantGwaValue, 2) . ' is above the scholarship limit of ' . number_format((float) $matchRequiredGwa, 2) . '.'
        );
    }
} else {
    $matchPushReason($matchPositiveReasons, 'Passed academic check automatically because this scholarship does not use a fixed GWA cutoff.');
}

if (is_array($matchCoursePathwayCheck)) {
    $matchCourseReasonText = trim((string) ($matchCoursePathwayCheck['detail'] ?? ''));
    $matchCourseStatus = strtolower(trim((string) ($matchCoursePathwayCheck['status'] ?? 'pending')));
    if ($matchCourseStatus === 'met') {
        $matchPushReason($matchPositiveReasons, $matchCourseReasonText);
    } elseif (in_array($matchCourseStatus, ['warn', 'pending'], true)) {
        $matchPushReason($matchLimitingReasons, $matchCourseReasonText);
    }
}

if (!empty($matchProfileChecks)) {
    foreach ($matchProfileChecks as $matchProfileCheck) {
        $matchProfileCheckStatus = strtolower(trim((string) ($matchProfileCheck['status'] ?? 'pending')));
        if ($matchProfileCheckStatus === 'met') {
            $matchPushReason($matchPositiveReasons, scholarshipMatchGuideReasonFromCheck($matchProfileCheck));
        } elseif (in_array($matchProfileCheckStatus, ['failed', 'pending'], true)) {
            $matchPushReason($matchLimitingReasons, scholarshipMatchGuideReasonFromCheck($matchProfileCheck));
        }
    }
} else {
    $matchPushReason($matchPositiveReasons, 'The scholarship is open to a broader set of applicants, which helps the fit score.');
}

if ($matchCurrentInfoTotal > 0) {
    foreach ($matchCurrentInfoChecks as $matchCurrentInfoCheck) {
        $matchCurrentInfoKey = strtolower(trim((string) ($matchCurrentInfoCheck['key'] ?? '')));
        if ($matchCurrentInfoKey === 'course_pathway') {
            continue;
        }

        $matchCurrentInfoStatus = strtolower(trim((string) ($matchCurrentInfoCheck['status'] ?? 'pending')));
        if ($matchCurrentInfoStatus === 'met') {
            $matchPushReason($matchPositiveReasons, scholarshipMatchGuideReasonFromCheck($matchCurrentInfoCheck));
        } elseif (in_array($matchCurrentInfoStatus, ['warn', 'pending'], true)) {
            $matchPushReason($matchLimitingReasons, scholarshipMatchGuideReasonFromCheck($matchCurrentInfoCheck));
        }
    }
}

if ($matchApplicationNotYetOpen) {
    $matchPushReason($matchLimitingReasons, 'Applications have not opened yet, so the timing signal is weaker right now.');
} elseif ($matchDeadlineClass === 'bad') {
    $matchPushReason($matchLimitingReasons, 'The application period has already closed.');
} elseif ($matchDeadlineClass === 'warn') {
    $matchPushReason($matchLimitingReasons, 'The deadline is close, so the timing signal is smaller.');
} else {
    $matchPushReason($matchPositiveReasons, 'The application window timing supports this recommendation.');
}

if ($recognizedProviderMatch) {
    $matchPushReason($matchPositiveReasons, 'The provider is recognized in the DSS as an established scholarship source.');
} elseif ($matchProviderClass === 'good') {
    $matchPushReason($matchPositiveReasons, 'The provider receives an academic-institution ranking signal in the DSS.');
}

$matchPositiveItems = !empty($matchPositiveReasons)
    ? array_slice($matchPositiveReasons, 0, 4)
    : ['The DSS has not found strong positive scoring signals yet.'];
$matchLimitingItems = !empty($matchLimitingReasons)
    ? array_slice($matchLimitingReasons, 0, 4)
    : ['No major factor is pulling the match score down right now.'];

$matchGuideSummary = scholarshipMatchGuideSummary($matchGuideScore, $matchRequiresGwa);

$matchScoreFactors = [
    [
        'label' => 'Academic fit',
        'value' => $matchAcademicDecision,
        'detail' => $matchAcademicDetail,
        'class' => $matchAcademicClass,
        'icon' => 'fa-chart-line',
    ],
    [
        'label' => 'Course focus',
        'value' => $matchCourseValue,
        'detail' => $matchCourseDetail,
        'class' => $matchCourseClass,
        'icon' => 'fa-graduation-cap',
    ],
    [
        'label' => 'Audience fit',
        'value' => $matchProfileValue,
        'detail' => $matchProfileDetail,
        'class' => $matchProfileClass,
        'icon' => 'fa-user-check',
    ],
    [
        'label' => 'Student context',
        'value' => $matchStudentContextValue,
        'detail' => $matchStudentContextDetail,
        'class' => $matchStudentContextClass,
        'icon' => 'fa-id-card',
    ],
    [
        'label' => 'Application timing',
        'value' => $matchTimingValue,
        'detail' => $matchTimingDetail,
        'class' => $matchTimingClass,
        'icon' => 'fa-calendar-days',
    ],
    [
        'label' => 'Provider signal',
        'value' => $matchProviderValue,
        'detail' => $matchProviderDetail,
        'class' => $matchProviderClass,
        'icon' => 'fa-building-columns',
    ],
];

$canApproveApplication = $applicationStatus === 'pending' && ($requirementsTotalCount === 0 || $requirementsVerifiedCount >= $requirementsTotalCount);
$approvalBlockMessage = 'Approval is unavailable while required documents are pending, missing, or rejected.';
$applicantInitials = '';
foreach (preg_split('/\s+/', trim($fullName)) ?: [] as $namePiece) {
    if ($namePiece === '') {
        continue;
    }
    $applicantInitials .= strtoupper(substr($namePiece, 0, 1));
    if (strlen($applicantInitials) >= 2) {
        break;
    }
}
if ($applicantInitials === '') {
    $applicantInitials = 'AP';
}
$applicantProfileImageUrl = getDefaultProfileImageUrl('../');
$applicantProfileImagePath = trim((string) ($application['profile_image_path'] ?? ''));
if ($applicantProfileImagePath !== '') {
    $resolvedApplicantProfileImageUrl = resolveStoredFileUrl($applicantProfileImagePath, '../');
    if ($resolvedApplicantProfileImageUrl) {
        $applicantProfileImageUrl = $resolvedApplicantProfileImageUrl;
    }
}
$applicationScoreDisplay = $application['probability_score'] !== null
    ? number_format((float) $application['probability_score'], 1) . '%'
    : 'Not calculated';
$canModerateDocuments = isAdminRole();
$accountStatusLabel = ucfirst((string) ($application['user_status'] ?? 'inactive'));
$profileSectionGroups = [
    ['title' => 'Identity and Contact', 'icon' => 'fa-id-card', 'fields' => $identityFields],
    ['title' => 'Current Academic Record', 'icon' => 'fa-graduation-cap', 'fields' => $academicFields],
];
if (!empty($admissionsFields)) {
    $profileSectionGroups[] = ['title' => 'Admissions and Transition', 'icon' => 'fa-route', 'fields' => $admissionsFields];
}
if (!empty($backgroundFields)) {
    $profileSectionGroups[] = ['title' => 'Prior Academic Background', 'icon' => 'fa-school', 'fields' => $backgroundFields];
}
$profileSectionGroups[] = ['title' => 'Residence and Household Context', 'icon' => 'fa-house-user', 'fields' => $contextFields, 'wide' => true];
$reviewHeaderDescription = 'Review the applicant profile, supporting documents, and decision status for ' . $application['scholarship_name'] . '.';
$reviewSpotlightTags = [
    ['icon' => 'fa-user-graduate', 'label' => $applicantProgramLabel],
    ['icon' => 'fa-book-open', 'label' => $courseLabel],
    ['icon' => 'fa-layer-group', 'label' => formatYearLevelLabel($application['year_level'] ?? '')],
    ['icon' => 'fa-user-check', 'label' => formatEnrollmentStatusLabel($application['enrollment_status'] ?? '')],
];
if (!$isIncomingFreshmanApplicant) {
    array_splice($reviewSpotlightTags, 1, 0, [[
        'icon' => 'fa-school',
        'label' => $schoolLabel,
    ]]);
}
$adminStyleVersion = @filemtime(__DIR__ . '/../public/css/admin_style.css') ?: time();
$reviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/reviews.css') ?: time();
$viewApplicationStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/view-application.css') ?: time();
$applicationActionParams = ['id' => (int) $application['application_id']];
$approveUrl = buildEntityUrl('../app/AdminControllers/application_process.php', 'application', (int) $application['application_id'], 'approve', array_merge(['action' => 'approve'], $applicationActionParams));
$rejectUrl = buildEntityUrl('../app/AdminControllers/application_process.php', 'application', (int) $application['application_id'], 'reject', array_merge(['action' => 'reject'], $applicationActionParams));
$applicationFlashSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : '';
$applicationFlashError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);
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
            <?php if ($applicationFlashSuccess !== ''): ?>
                <noscript>
                <div class="alert alert-success application-review-alert">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($applicationFlashSuccess); ?>
                </div>
                </noscript>
            <?php endif; ?>

            <?php if ($applicationFlashError !== ''): ?>
                <noscript>
                <div class="alert alert-error application-review-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($applicationFlashError); ?>
                </div>
                </noscript>
            <?php endif; ?>

            <div class="page-header app-review-page-header">
                <div>
                    <h1>
                        <i class="fas fa-clipboard-check"></i> Application Review
                    </h1>
                    <p><?php echo htmlspecialchars($reviewHeaderDescription); ?></p>
                </div>
                <a href="manage_applications.php" class="app-review-header-back app-review-page-back">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Applications</span>
                </a>
            </div>

            <?php $reviewsCurrentView = 'applications'; include 'layouts/reviews_nav.php'; ?>

            <section class="app-review-shell state-<?php echo htmlspecialchars($decisionReadinessTone); ?>">
                <div class="app-review-shell-inner">
                    <div class="app-review-shell-brand">
                        <div class="app-review-shell-kicker-group">
                            <span class="app-review-brand-pill">
                                <i class="fas fa-clipboard-check"></i>
                                Review Workspace
                            </span>
                            <span class="app-review-shell-status state-<?php echo htmlspecialchars($decisionReadinessTone); ?>">
                                <i class="fas <?php echo htmlspecialchars($decisionReadinessTone === 'ready' ? 'fa-circle-check' : 'fa-triangle-exclamation'); ?>"></i>
                                <?php echo htmlspecialchars($decisionReadinessLabel); ?>
                            </span>
                        </div>

                        <div class="app-review-avatar-stage">
                            <span class="app-review-avatar-mark">
                                <img src="<?php echo htmlspecialchars($applicantProfileImageUrl); ?>" alt="<?php echo htmlspecialchars($fullName); ?> profile picture">
                            </span>
                        </div>
                    </div>

                    <div class="app-review-shell-content">
                        <div class="app-review-shell-head">
                            <div class="app-review-shell-title-wrap">
                                <h1><?php echo htmlspecialchars($fullName); ?></h1>
                                <p><?php echo htmlspecialchars($application['scholarship_name']); ?> under <?php echo htmlspecialchars($scholarshipProvider); ?>.</p>
                            </div>
                            <div class="app-review-shell-head-actions">
                                <button type="button" class="app-review-match-guide-trigger is-summary-action" data-open-match-guide>
                                    <i class="fas fa-circle-question"></i>
                                    <?php echo htmlspecialchars($matchGuideButtonLabel); ?>
                                </button>
                            </div>
                        </div>

                        <div class="app-review-provider-summary">
                            <div class="app-review-provider-line">
                                <span class="app-review-provider-label">Applicant Type</span>
                                <strong class="app-review-provider-inline-name"><?php echo htmlspecialchars($applicantProgramLabel); ?></strong>
                            </div>
                            <p class="app-review-provider-inline-note">Use the same review flow every application needs: profile, documents, and final decision.</p>
                        </div>

                        <div class="app-review-summary-strip">
                            <article class="app-review-summary-tile">
                                <span>Status</span>
                                <strong><?php echo htmlspecialchars(ucfirst($applicationStatus)); ?></strong>
                            </article>
                            <article class="app-review-summary-tile">
                                <span>Score</span>
                                <strong><?php echo htmlspecialchars($applicationScoreDisplay); ?></strong>
                            </article>
                            <article class="app-review-summary-tile">
                                <span>Requirements</span>
                                <strong><?php echo htmlspecialchars($reviewReadinessLabel); ?></strong>
                            </article>
                            <article class="app-review-summary-tile">
                                <span>Student Response</span>
                                <strong><?php echo htmlspecialchars($studentResponseLabel); ?></strong>
                            </article>
                        </div>

                        <div class="app-review-next-step-banner is-<?php echo htmlspecialchars($decisionReadinessTone === 'ready' ? 'success' : 'warning'); ?>">
                            <i class="fas <?php echo htmlspecialchars($decisionReadinessTone === 'ready' ? 'fa-circle-check' : 'fa-hourglass-half'); ?>"></i>
                            <span><?php echo htmlspecialchars($decisionReadinessNote); ?></span>
                        </div>

                    </div>
                </div>
                <div class="app-review-shell-stepper">
                    <nav class="app-review-stepper" aria-label="Review sections">
                        <button type="button" class="app-review-step-pill is-active" data-review-target="profile" aria-pressed="true">
                            <span class="app-review-step-index">1</span>
                            <span class="app-review-step-copy">
                                <strong>Applicant Profile</strong>
                                <small>Identity and academic details</small>
                            </span>
                        </button>
                        <button type="button" class="app-review-step-pill" data-review-target="documents" aria-pressed="false">
                            <span class="app-review-step-index">2</span>
                            <span class="app-review-step-copy">
                                <strong>Required Documents</strong>
                                <small>Uploads and verification status</small>
                            </span>
                        </button>
                        <button type="button" class="app-review-step-pill" data-review-target="support" aria-pressed="false">
                            <span class="app-review-step-index">3</span>
                            <span class="app-review-step-copy">
                                <strong>Review Support</strong>
                                <small>Timeline, notes, and context</small>
                            </span>
                        </button>
                        <button type="button" class="app-review-step-pill" data-review-target="decision" aria-pressed="false">
                            <span class="app-review-step-index">4</span>
                            <span class="app-review-step-copy">
                                <strong>Decision</strong>
                                <small>Final approval or rejection</small>
                            </span>
                        </button>
                    </nav>
                </div>
            </section>

            <div class="app-review-stage-panels">
                <section class="app-review-stage-panel" data-review-panel="decision" id="decisionSummary">
                    <div class="review-panel app-review-panel app-review-stage-surface">
                        <div class="review-panel-header">
                            <div>
                                <h2>Decision Summary</h2>
                                <p>Use this last after reviewing the applicant profile, uploaded documents, and support notes.</p>
                            </div>
                        </div>
                        <div class="app-review-stage-body">
                            <section class="app-review-decision-banner tone-<?php echo htmlspecialchars($decisionReadinessTone); ?>">
                                <div class="app-review-decision-banner-main">
                                    <span class="app-review-header-kicker">Decision Summary</span>
                                    <h2><?php echo htmlspecialchars($decisionReadinessLabel); ?></h2>
                                    <p><?php echo htmlspecialchars($decisionReadinessNote); ?></p>
                                    <?php if ($applicationStatus === 'approved'): ?>
                                        <div class="review-note ready"><strong>Student Response:</strong> <?php echo htmlspecialchars($studentResponseNote); ?></div>
                                    <?php endif; ?>
                                    <?php if ($applicationStatus === 'rejected' && $applicationRejectionReason !== ''): ?>
                                        <div class="review-note rejection"><strong>Rejection reason:</strong> <?php echo nl2br(htmlspecialchars($applicationRejectionReason)); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="app-review-decision-banner-side">
                                    <div class="app-review-progress-card">
                                        <div class="app-review-progress-top">
                                            <span>Requirement progress</span>
                                            <strong><?php echo $requirementsCompletionPercent; ?>%</strong>
                                        </div>
                                        <div class="app-review-progress-bar" aria-hidden="true">
                                            <span style="width: <?php echo $requirementsCompletionPercent; ?>%;"></span>
                                        </div>
                                        <small><?php echo htmlspecialchars($requirementsRemainingLabel); ?></small>
                                    </div>

                                    <div class="app-review-mini-grid">
                                        <div class="app-review-mini-card">
                                            <span>Verified</span>
                                            <strong><?php echo $requirementsVerifiedCount; ?>/<?php echo $requirementsTotalCount; ?></strong>
                                        </div>
                                        <div class="app-review-mini-card">
                                            <span>Pending</span>
                                            <strong><?php echo $requirementsPendingCount; ?></strong>
                                        </div>
                                        <div class="app-review-mini-card">
                                            <span>Applicant Account</span>
                                            <strong><?php echo htmlspecialchars($accountStatusLabel); ?></strong>
                                        </div>
                                        <div class="app-review-mini-card">
                                            <span>Current GWA</span>
                                            <strong><?php echo htmlspecialchars($currentGwaLabel); ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($applicationStatus === 'pending'): ?>
                                    <div class="app-review-decision-actions">
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
                            </section>
                            <div class="app-review-stage-actions">
                                <button type="button" class="btn btn-outline" data-review-prev-target="support">
                                    <i class="fas fa-arrow-left"></i> Back to Review Support
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="app-review-stage-panel is-active" data-review-panel="profile" id="applicantProfile">
                    <div class="review-panel review-panel-profile app-review-panel">
                        <div class="review-panel-header">
                            <div>
                                <h2>Applicant Profile</h2>
                                <p>Identity, academic standing, education pathway, and household details for application review.</p>
                            </div>
                        </div>
                        <div class="applicant-profile-shell">
                            <div class="applicant-profile-block-grid">
                                <?php foreach ($profileSectionGroups as $group): ?>
                                    <section class="applicant-profile-block<?php echo !empty($group['wide']) ? ' is-wide' : ''; ?>">
                                        <div class="applicant-profile-block-head">
                                            <h3>
                                                <i class="fas <?php echo htmlspecialchars($group['icon']); ?>"></i>
                                                <?php echo htmlspecialchars($group['title']); ?>
                                            </h3>
                                            <span><?php echo count($group['fields']); ?> item<?php echo count($group['fields']) === 1 ? '' : 's'; ?></span>
                                        </div>

                                        <div class="applicant-profile-row-list">
                                            <?php foreach ($group['fields'] as $field): ?>
                                                <div class="applicant-profile-row-item<?php echo !empty($field['full']) ? ' is-full' : ''; ?>">
                                                    <span class="applicant-profile-row-label"><?php echo htmlspecialchars($field['label']); ?></span>
                                                    <div class="applicant-profile-row-value<?php echo !empty($field['muted']) ? ' is-muted' : ''; ?>">
                                                        <?php echo nl2br(htmlspecialchars($field['value'])); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>

                            <div class="app-review-stage-actions">
                                <button type="button" class="btn btn-primary" data-review-next-target="documents">
                                    Continue to Required Documents <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="app-review-stage-panel" data-review-panel="documents" id="requiredDocuments">
                    <div class="review-panel app-review-panel">
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
                            <?php if (!$canModerateDocuments): ?>
                                <div class="review-note info">Providers can review uploaded files here, but only admins can verify or reject documents.</div>
                            <?php endif; ?>

                            <div class="requirements-review-list">
                                <?php foreach (($requirementsSummary['requirements'] ?? []) as $index => $requirement): ?>
                                    <?php
                                    $reqDoc = $requirement['document'] ?? null;
                                    $reqStatus = (string) ($requirement['status'] ?? 'missing');
                                    $reqHref = is_array($reqDoc) ? resolveStoredFileUrl($reqDoc['file_path'] ?? null, '../') : null;
                                    $reqNumber = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                                    $reqFileName = is_array($reqDoc) ? (string) ($reqDoc['file_name'] ?? 'Uploaded file') : '';
                                    $reqReviewerNote = is_array($reqDoc) ? extractReviewerDocumentNote($reqDoc['admin_notes'] ?? null) : '';
                                    $reqRejectionReason = is_array($reqDoc)
                                        ? trim((string) ($reqDoc['rejection_reason'] ?? ''))
                                        : '';
                                    $hasReqActions = is_array($reqDoc) || $reqHref !== '';
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
                                                <?php if ($reqStatus === 'rejected' && $reqRejectionReason !== ''): ?>
                                                    <div class="review-note rejection"><strong>Rejection reason:</strong> <?php echo htmlspecialchars($reqRejectionReason); ?></div>
                                                <?php endif; ?>
                                                <?php if ($reqReviewerNote !== ''): ?>
                                                    <div class="review-note info"><strong>Reviewer note:</strong> <?php echo nl2br(htmlspecialchars($reqReviewerNote)); ?></div>
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
                                                        data-document-id="<?php echo (int) ($reqDoc['id'] ?? 0); ?>"
                                                        data-user-id="<?php echo (int) $application['user_id']; ?>"
                                                        data-document-status="<?php echo htmlspecialchars($reqStatus); ?>"
                                                    >
                                                        <i class="fas fa-eye"></i> View File
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (is_array($reqDoc)): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline"
                                                        onclick="openDocumentNotePrompt(<?php echo (int) $reqDoc['id']; ?>, <?php echo (int) $application['user_id']; ?>, <?php echo htmlspecialchars(json_encode($reqReviewerNote), ENT_QUOTES, 'UTF-8'); ?>)"
                                                    >
                                                        <i class="fas fa-note-sticky"></i> <?php echo $reqReviewerNote !== '' ? 'Update Note' : 'Add Note'; ?>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($canModerateDocuments && $reqStatus === 'pending' && is_array($reqDoc)): ?>
                                                    <button type="button" class="btn btn-success" onclick="verifyDocument(<?php echo (int) $reqDoc['id']; ?>, <?php echo (int) $application['user_id']; ?>)"><i class="fas fa-check-circle"></i> Verify</button>
                                                    <button type="button" class="btn btn-danger" onclick="openRejectModal(<?php echo (int) $reqDoc['id']; ?>, <?php echo (int) $application['user_id']; ?>)"><i class="fas fa-times-circle"></i> Reject</button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="app-review-stage-actions">
                            <button type="button" class="btn btn-outline" data-review-prev-target="profile">
                                <i class="fas fa-arrow-left"></i> Back to Applicant Profile
                            </button>
                            <button type="button" class="btn btn-primary" data-review-next-target="support">
                                Continue to Review Support <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </section>

                <section class="app-review-stage-panel" data-review-panel="support" id="reviewSupport">
                    <div class="app-review-support-grid">
                    <div class="review-panel review-panel-timeline app-review-panel">
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
                            <div class="review-timeline-item <?php echo $applicationStatus === 'pending' ? 'current' : 'complete'; ?>">
                                <span class="review-timeline-marker"><i class="fas fa-gavel"></i></span>
                                <div class="review-timeline-copy">
                                    <strong>Final decision</strong>
                                    <span><?php echo htmlspecialchars(ucfirst($applicationStatus)); ?></span>
                                </div>
                            </div>
                            <?php if ($assessmentEnabled): ?>
                                <div class="review-timeline-item <?php echo htmlspecialchars($studentConfirmationTimelineState); ?>">
                                    <span class="review-timeline-marker"><i class="fas fa-handshake"></i></span>
                                    <div class="review-timeline-copy">
                                        <strong>Student confirmation</strong>
                                        <span><?php echo htmlspecialchars($studentResponseNote); ?></span>
                                    </div>
                                </div>
                                <div class="review-timeline-item <?php echo htmlspecialchars($assessmentTimelineState); ?>">
                                    <span class="review-timeline-marker"><i class="fas <?php echo htmlspecialchars($assessmentRequirement === 'remote_examination' ? 'fa-map-location-dot' : 'fa-laptop-file'); ?>"></i></span>
                                    <div class="review-timeline-copy">
                                        <strong><?php echo htmlspecialchars($assessmentTypeLabel); ?></strong>
                                        <span><?php echo htmlspecialchars($assessmentStatusNote); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="review-panel review-panel-summary app-review-panel">
                        <div class="review-panel-header"><div><h3>Verification Snapshot</h3><p>Current standing of the application and supporting records</p></div></div>
                        <div class="sidebar-stack">
                            <div class="meta-item"><span class="label">Current Status</span><span class="value"><span class="status-pill <?php echo htmlspecialchars($applicationStatus); ?>"><i class="fas <?php echo htmlspecialchars($statusIconMap[$applicationStatus] ?? 'fa-circle-info'); ?>"></i> <?php echo htmlspecialchars(ucfirst($applicationStatus)); ?></span></span></div>
                            <div class="meta-item"><span class="label">Student Response</span><span class="value"><?php echo htmlspecialchars($studentResponseLabel); ?></span><span class="subvalue"><?php echo htmlspecialchars($studentResponseNote); ?></span></div>
                            <div class="meta-item">
                                <span class="label">Application Score</span>
                                <span class="value"><?php echo $application['probability_score'] !== null ? htmlspecialchars(number_format((float) $application['probability_score'], 1) . '%') : 'Not calculated'; ?></span>
                                <span class="subvalue">Profile score</span>
                                <button type="button" class="app-review-match-guide-trigger is-inline" data-open-match-guide>
                                    <i class="fas fa-circle-question"></i>
                                    <?php echo htmlspecialchars($matchGuideButtonLabel); ?>
                                </button>
                            </div>
                            <div class="meta-item"><span class="label">Requirements Snapshot</span><span class="value"><?php echo htmlspecialchars(((int) ($requirementsSummary['uploaded'] ?? 0)) . ' uploaded | ' . $requirementsVerifiedCount . ' verified'); ?></span><span class="subvalue"><?php echo htmlspecialchars($requirementsPendingCount . ' pending'); ?></span></div>
                            <div class="meta-item"><span class="label">Applicant Account</span><span class="value"><?php echo htmlspecialchars(ucfirst((string) ($application['user_status'] ?? 'inactive'))); ?></span><span class="subvalue">Student account standing</span></div>
                            <?php if ($requirementsTotalCount > 0 && $requirementsVerifiedCount < $requirementsTotalCount): ?><div class="review-note warning"><?php echo htmlspecialchars($requirementsRemainingCount . ' required document' . ($requirementsRemainingCount === 1 ? ' is' : 's are') . ' still awaiting completion or verification.'); ?></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="review-panel review-panel-program app-review-panel">
                        <div class="review-panel-header"><div><h3>Scholarship Information</h3><p>Program details attached to this application</p></div></div>
                        <div class="meta-list">
                            <div class="meta-item"><span class="label">Scholarship</span><span class="value"><?php echo htmlspecialchars($application['scholarship_name']); ?></span></div>
                            <div class="meta-item"><span class="label">Provider</span><span class="value"><?php echo htmlspecialchars($scholarshipProvider); ?></span></div>
                            <div class="meta-item"><span class="label">Deadline</span><span class="value"><?php echo htmlspecialchars(appDate($application['deadline'] ?? null, 'F j, Y')); ?></span></div>
                            <div class="meta-item"><span class="label">Benefits</span><span class="value"><?php echo nl2br(htmlspecialchars(appValue($application['benefits'] ?? '', 'No benefit summary provided.'))); ?></span></div>
                            <div class="meta-item"><span class="label">Program Notes</span><span class="value"><?php echo nl2br(htmlspecialchars(appValue($application['scholarship_description'] ?? '', 'No scholarship description available.'))); ?></span></div>
                        </div>
                    </div>
                    </div>
                    <?php if ($assessmentEnabled): ?>
                    <div class="review-panel review-panel-assessment app-review-panel">
                        <div class="review-panel-header">
                            <div>
                                <h3><?php echo htmlspecialchars($assessmentTypeLabel); ?> Workspace</h3>
                                <p>Manage the post-acceptance exam details that appear in the student's application tracking.</p>
                            </div>
                            <span class="status-pill <?php echo htmlspecialchars($assessmentStatusClass); ?>">
                                <i class="fas <?php echo htmlspecialchars($assessmentRequirement === 'remote_examination' ? 'fa-map-location-dot' : 'fa-laptop-file'); ?>"></i>
                                <?php echo htmlspecialchars($assessmentStatusLabel); ?>
                            </span>
                        </div>

                        <div class="assessment-review-overview">
                            <div class="assessment-review-overview-card">
                                <span class="label">Assessment Type</span>
                                <strong><?php echo htmlspecialchars($assessmentTypeLabel); ?></strong>
                                <p><?php echo htmlspecialchars($assessmentRequirement === 'remote_examination' ? 'Use the site selector below to assign the exam location for this application.' : 'Use the schedule and link fields below to publish the exam access details for the student.'); ?></p>
                            </div>
                            <div class="assessment-review-overview-card">
                                <span class="label">Current Student View</span>
                                <strong><?php echo htmlspecialchars($assessmentStatusLabel); ?></strong>
                                <p><?php echo htmlspecialchars($assessmentStatusNote); ?></p>
                            </div>
                            <div class="assessment-review-overview-card">
                                <span class="label">Current Schedule</span>
                                <strong><?php echo htmlspecialchars($assessmentScheduleLabel); ?></strong>
                                <p>
                                    <?php
                                    if ($assessmentRequirement === 'remote_examination') {
                                        echo htmlspecialchars(
                                            $assessmentManagementUnlocked
                                                ? $assessmentSiteLabel
                                                : (!empty($assessmentRemoteSites)
                                                    ? count($assessmentRemoteSites) . ' saved site' . (count($assessmentRemoteSites) === 1 ? '' : 's') . ' can be assigned after acceptance.'
                                                    : 'No remote exam site has been saved yet.')
                                        );
                                    } else {
                                        echo htmlspecialchars(
                                            $assessmentEffectiveLink !== ''
                                                ? ($assessmentManagementUnlocked
                                                    ? 'The exam portal is ready for the student.'
                                                    : 'The exam portal link is prepared and ready to publish after acceptance.')
                                                : 'No student exam link is set yet.'
                                        );
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>

                        <?php if ($assessmentDetails !== ''): ?>
                            <div class="review-note info"><?php echo nl2br(htmlspecialchars($assessmentDetails)); ?></div>
                        <?php endif; ?>

                        <?php if (!$assessmentManagementUnlocked): ?>
                            <div class="review-note warning">
                                <?php echo htmlspecialchars($studentAccepted
                                    ? 'This assessment workspace will unlock once the application reaches the approved and accepted stage.'
                                    : 'Wait for the student to accept the approved scholarship before posting exam instructions or assigning a remote exam site.'); ?>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="<?php echo htmlspecialchars($assessmentSaveUrl); ?>" class="assessment-review-form">
                                <?php echo csrfInputField('application_assessment'); ?>
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="id" value="<?php echo (int) ($application['application_id'] ?? 0); ?>">

                                <div class="assessment-review-form-grid">
                                    <label class="assessment-review-field">
                                        <span>Status</span>
                                        <select name="assessment_status" class="app-review-input">
                                            <?php foreach ($assessmentStatusChoices as $assessmentStatusValue => $assessmentStatusChoiceLabel): ?>
                                                <option value="<?php echo htmlspecialchars($assessmentStatusValue); ?>" <?php echo $assessmentStatus === $assessmentStatusValue ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($assessmentStatusChoiceLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label class="assessment-review-field">
                                        <span>Schedule</span>
                                        <input
                                            type="datetime-local"
                                            name="assessment_schedule_at"
                                            class="app-review-input"
                                            value="<?php echo htmlspecialchars($assessmentScheduleInputValue); ?>">
                                    </label>

                                    <?php if ($assessmentRequirement === 'remote_examination'): ?>
                                        <label class="assessment-review-field">
                                            <span>Remote exam site</span>
                                            <select name="assessment_site_id" class="app-review-input">
                                                <option value="">Choose a site</option>
                                                <?php foreach ($assessmentRemoteSites as $assessmentRemoteSite): ?>
                                                    <?php
                                                    $assessmentRemoteSiteLabel = implode(', ', array_filter([
                                                        trim((string) ($assessmentRemoteSite['site_name'] ?? '')),
                                                        trim((string) ($assessmentRemoteSite['city'] ?? '')),
                                                        trim((string) ($assessmentRemoteSite['province'] ?? '')),
                                                    ]));
                                                    ?>
                                                    <option value="<?php echo (int) ($assessmentRemoteSite['id'] ?? 0); ?>" <?php echo $assessmentSiteId === (int) ($assessmentRemoteSite['id'] ?? 0) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($assessmentRemoteSiteLabel !== '' ? $assessmentRemoteSiteLabel : ('Site #' . (int) ($assessmentRemoteSite['id'] ?? 0))); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php else: ?>
                                        <label class="assessment-review-field">
                                            <span>Student exam link</span>
                                            <input
                                                type="url"
                                                name="assessment_link_override"
                                                class="app-review-input"
                                                placeholder="https://example.com/exam-link"
                                                value="<?php echo htmlspecialchars($assessmentOverrideLink !== '' ? $assessmentOverrideLink : $assessmentEffectiveLink); ?>">
                                        </label>
                                    <?php endif; ?>
                                </div>

                                <label class="assessment-review-field is-full">
                                    <span>Latest note for the student</span>
                                    <textarea name="assessment_notes" class="app-textarea" placeholder="Add reminders, room instructions, or review updates that should appear in application tracking."><?php echo htmlspecialchars($assessmentNotes); ?></textarea>
                                </label>

                                <div class="assessment-review-actions">
                                    <?php if ($assessmentStudentActionUrl !== '' && $assessmentStudentActionLabel !== ''): ?>
                                        <a
                                            href="<?php echo htmlspecialchars($assessmentStudentActionUrl); ?>"
                                            class="btn btn-outline"
                                            <?php echo $assessmentStudentActionExternal ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
                                        >
                                            <i class="fas <?php echo htmlspecialchars($assessmentStudentActionExternal ? 'fa-arrow-up-right-from-square' : 'fa-location-dot'); ?>"></i>
                                            <?php echo htmlspecialchars($assessmentStudentActionLabel); ?>
                                        </a>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-floppy-disk"></i> Save <?php echo htmlspecialchars($assessmentTypeLabel); ?> Details
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="app-review-stage-actions">
                        <button type="button" class="btn btn-outline" data-review-prev-target="documents">
                            <i class="fas fa-arrow-left"></i> Back to Required Documents
                        </button>
                        <button type="button" class="btn btn-primary" data-review-next-target="decision">
                            Continue to Decision <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </section>

    <div id="matchGuideModal" class="app-modal app-modal-wide" aria-hidden="true">
        <div class="app-modal-content match-guide-modal-content">
            <div class="app-modal-header">
                <div class="match-guide-modal-heading">
                    <h3><i class="fas fa-chart-pie app-modal-heading-icon info"></i> <?php echo htmlspecialchars($matchGuideTitle); ?></h3>
                    <p class="app-modal-intro"><?php echo htmlspecialchars($matchGuideSummary); ?></p>
                </div>
                <button type="button" class="app-modal-close" onclick="closeMatchGuideModal()" aria-label="Close match guide modal">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="review-note info match-guide-note">
                    <strong>Note:</strong> <?php echo htmlspecialchars($matchGuideNote); ?>
                </div>

                <div class="match-guide-factor-grid">
                    <?php foreach ($matchScoreFactors as $matchScoreFactor): ?>
                        <article class="match-guide-factor-card state-<?php echo htmlspecialchars((string) ($matchScoreFactor['class'] ?? 'info')); ?>">
                            <span class="match-guide-factor-icon">
                                <i class="fas <?php echo htmlspecialchars((string) ($matchScoreFactor['icon'] ?? 'fa-circle-info')); ?>"></i>
                            </span>
                            <div class="match-guide-factor-copy">
                                <span class="match-guide-factor-label"><?php echo htmlspecialchars((string) ($matchScoreFactor['label'] ?? 'Factor')); ?></span>
                                <strong><?php echo htmlspecialchars((string) ($matchScoreFactor['value'] ?? 'Not available')); ?></strong>
                                <p><?php echo htmlspecialchars((string) ($matchScoreFactor['detail'] ?? '')); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="match-guide-reason-grid">
                    <section class="match-guide-reason-card positive">
                        <div class="match-guide-reason-head">
                        <h4><i class="fas fa-arrow-trend-up"></i> Why it passed</h4>
                        </div>
                        <ul class="match-guide-reason-list">
                            <?php foreach ($matchPositiveItems as $matchPositiveItem): ?>
                                <li><?php echo htmlspecialchars($matchPositiveItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <section class="match-guide-reason-card warning">
                        <div class="match-guide-reason-head">
                        <h4><i class="fas fa-triangle-exclamation"></i> What is still limiting this score</h4>
                        </div>
                        <ul class="match-guide-reason-list">
                            <?php foreach ($matchLimitingItems as $matchLimitingItem): ?>
                                <li><?php echo htmlspecialchars($matchLimitingItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                </div>
            </div>
            <div class="app-modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeMatchGuideModal()">Close</button>
            </div>
        </div>
    </div>

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
                <?php if ($canModerateDocuments): ?>
                    <div class="file-preview-primary-actions" id="filePreviewPrimaryActions" hidden>
                        <button type="button" class="btn btn-success" id="filePreviewVerifyButton" onclick="verifyPreviewDocument()">
                            <i class="fas fa-check-circle"></i> Verify
                        </button>
                        <button type="button" class="btn btn-danger" id="filePreviewRejectButton" onclick="rejectPreviewDocument()">
                            <i class="fas fa-times-circle"></i> Reject
                        </button>
                    </div>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" onclick="closeFilePreviewModal()">Close</button>
            </div>
        </div>
    </div>

    <?php include 'layouts/admin_footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let currentDocumentId = null;
        let currentUserId = null;
        const applicationFlashQueue = [];
        const applicationFlashSuccess = <?php echo json_encode($applicationFlashSuccess); ?>;
        const applicationFlashError = <?php echo json_encode($applicationFlashError); ?>;
        const applicationReviewCsrfToken = <?php echo json_encode(csrfGetToken('application_review')); ?>;
        const documentReviewCsrfToken = <?php echo json_encode(csrfGetToken('document_review')); ?>;
        const matchGuideModal = document.getElementById('matchGuideModal');
        const filePreviewModal = document.getElementById('filePreviewModal');
        const filePreviewFrame = document.getElementById('filePreviewFrame');
        const filePreviewImage = document.getElementById('filePreviewImage');
        const filePreviewName = document.getElementById('filePreviewName');
        const filePreviewFallback = document.getElementById('filePreviewFallback');
        const filePreviewZoomOut = document.getElementById('filePreviewZoomOut');
        const filePreviewZoomIn = document.getElementById('filePreviewZoomIn');
        const filePreviewZoomReset = document.getElementById('filePreviewZoomReset');
        const filePreviewZoomLevel = document.getElementById('filePreviewZoomLevel');
        const filePreviewFrameWrap = document.getElementById('filePreviewFrameWrap');
        const filePreviewPrimaryActions = document.getElementById('filePreviewPrimaryActions');
        const filePreviewVerifyButton = document.getElementById('filePreviewVerifyButton');
        const filePreviewRejectButton = document.getElementById('filePreviewRejectButton');
        const reviewStepButtons = Array.from(document.querySelectorAll('[data-review-target]'));
        const reviewStagePanels = Array.from(document.querySelectorAll('[data-review-panel]'));
        let filePreviewType = 'document';
        let filePreviewBaseUrl = '';
        let filePreviewZoom = 1;
        let filePreviewDocumentId = 0;
        let filePreviewUserId = 0;
        let filePreviewDocumentStatus = '';

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

        function setActiveReviewStage(target) {
            if (!target) {
                return;
            }

            const reviewStageOrder = ['profile', 'documents', 'support', 'decision'];
            const activeIndex = reviewStageOrder.indexOf(target);

            reviewStepButtons.forEach((button) => {
                const buttonTarget = button.dataset.reviewTarget || '';
                const buttonIndex = reviewStageOrder.indexOf(buttonTarget);
                const isActive = buttonTarget === target;
                const isComplete = activeIndex > -1 && buttonIndex > -1 && buttonIndex < activeIndex;
                button.classList.toggle('is-active', isActive);
                button.classList.toggle('is-complete', isComplete);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                button.setAttribute('aria-current', isActive ? 'step' : 'false');
            });

            reviewStagePanels.forEach((panel) => {
                const isActive = panel.dataset.reviewPanel === target;
                panel.classList.toggle('is-active', isActive);
            });
        }

        reviewStepButtons.forEach((button) => {
            button.addEventListener('click', () => {
                setActiveReviewStage(button.dataset.reviewTarget || 'profile');
            });
        });

        document.querySelectorAll('[data-review-next-target], [data-review-prev-target]').forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.getAttribute('data-review-next-target')
                    || button.getAttribute('data-review-prev-target')
                    || 'profile';
                setActiveReviewStage(target);
            });
        });

        const reviewStageFromHashMap = {
            '#decisionSummary': 'decision',
            '#applicantProfile': 'profile',
            '#requiredDocuments': 'documents',
            '#reviewSupport': 'support'
        };
        const initialReviewStage = reviewStageFromHashMap[window.location.hash] || 'profile';
        setActiveReviewStage(initialReviewStage);

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
                openFilePreviewModal(button);
            });
        });

        document.querySelectorAll('[data-open-match-guide]').forEach((button) => {
            button.addEventListener('click', openMatchGuideModal);
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

        function openDocumentNotePrompt(docId, userId, currentNote = '') {
            Swal.fire({
                title: currentNote ? 'Update reviewer note' : 'Add reviewer note',
                text: 'This note will be visible to the student in My Documents.',
                input: 'textarea',
                inputLabel: 'Reviewer note',
                inputValue: currentNote,
                inputPlaceholder: 'Example: Please reupload a clearer copy of this document.',
                inputAttributes: {
                    'aria-label': 'Reviewer note'
                },
                showCancelButton: true,
                showDenyButton: currentNote.trim() !== '',
                denyButtonText: 'Clear Note',
                confirmButtonText: currentNote ? 'Update Note' : 'Save Note',
                confirmButtonColor: '#2c5aa0',
                cancelButtonColor: '#64748b',
                denyButtonColor: '#94a3b8',
                preConfirm: (value) => {
                    const trimmedValue = (value || '').trim();
                    if (!trimmedValue) {
                        Swal.showValidationMessage('Enter a note or use Clear Note to remove the existing one.');
                        return false;
                    }
                    return trimmedValue;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    submitDocumentReview('save_note', docId, userId, '', { reviewer_note: result.value }, {
                        loadingTitle: 'Saving note...',
                        successTitle: 'Reviewer note saved'
                    });
                    return;
                }

                if (result.isDenied) {
                    submitDocumentReview('save_note', docId, userId, '', { reviewer_note: '' }, {
                        loadingTitle: 'Clearing note...',
                        successTitle: 'Reviewer note cleared'
                    });
                }
            });
        }

        function openRejectModal(docId, userId) {
            currentDocumentId = docId;
            currentUserId = userId;
            const rejectionReasonInput = document.getElementById('rejectionReason');
            const rejectModal = document.getElementById('rejectModal');

            if (window.Swal && typeof Swal.isVisible === 'function' && Swal.isVisible()) {
                Swal.close();
            }

            if (rejectionReasonInput) {
                rejectionReasonInput.value = '';
            }
            if (rejectModal) {
                rejectModal.classList.add('active');
            }

            if (rejectionReasonInput && typeof rejectionReasonInput.focus === 'function') {
                rejectionReasonInput.focus();
            }
        }

        function openMatchGuideModal() {
            if (!matchGuideModal) {
                return;
            }

            matchGuideModal.classList.add('active');
            matchGuideModal.setAttribute('aria-hidden', 'false');
        }

        function closeMatchGuideModal() {
            if (!matchGuideModal) {
                return;
            }

            matchGuideModal.classList.remove('active');
            matchGuideModal.setAttribute('aria-hidden', 'true');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
            currentDocumentId = null;
            currentUserId = null;
        }

        function syncFilePreviewActions() {
            if (!filePreviewPrimaryActions || !filePreviewVerifyButton || !filePreviewRejectButton) {
                return;
            }

            const canModeratePendingDocument = filePreviewDocumentId > 0
                && filePreviewUserId > 0
                && filePreviewDocumentStatus === 'pending';

            filePreviewPrimaryActions.hidden = !canModeratePendingDocument;
        }

        function openFilePreviewModal(sourceButton) {
            const sourceData = sourceButton && sourceButton.dataset ? sourceButton.dataset : {};
            const fileUrl = sourceData.fileUrl || '';
            const fileName = sourceData.fileName || 'Document';
            const fileType = sourceData.fileType || 'document';

            if (!filePreviewModal || !filePreviewFrame || !filePreviewImage || !filePreviewName || !filePreviewFallback || !fileUrl) {
                return;
            }

            filePreviewType = fileType;
            filePreviewBaseUrl = fileUrl;
            filePreviewZoom = 1;
            filePreviewDocumentId = parseInt(sourceData.documentId || '0', 10) || 0;
            filePreviewUserId = parseInt(sourceData.userId || '0', 10) || 0;
            filePreviewDocumentStatus = (sourceData.documentStatus || '').trim().toLowerCase();
            filePreviewName.textContent = fileName;
            filePreviewFrame.style.display = 'none';
            filePreviewImage.style.display = 'none';
            filePreviewFrame.removeAttribute('src');
            filePreviewImage.removeAttribute('src');
            filePreviewFrame.style.zoom = '1';
            filePreviewFrame.style.transform = '';
            filePreviewFrame.style.transformOrigin = '';
            filePreviewImage.style.transform = '';
            filePreviewImage.style.transformOrigin = '';
            filePreviewFallback.hidden = true;
            syncFilePreviewActions();

            if (fileType === 'image') {
                filePreviewImage.src = fileUrl;
                filePreviewImage.style.display = 'block';
                applyFilePreviewZoom();
            } else {
                if (fileType === 'pdf') {
                    filePreviewFrame.src = `${fileUrl}#toolbar=1&navpanes=0`;
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
            filePreviewImage.style.transform = '';
            filePreviewImage.style.transformOrigin = '';
            filePreviewFrame.style.zoom = '1';
            filePreviewFrame.style.transform = '';
            filePreviewFrame.style.transformOrigin = '';
            if (filePreviewFrameWrap) {
                filePreviewFrameWrap.classList.remove('is-zoomed');
            }
            filePreviewType = 'document';
            filePreviewBaseUrl = '';
            filePreviewZoom = 1;
            filePreviewDocumentId = 0;
            filePreviewUserId = 0;
            filePreviewDocumentStatus = '';
            syncFilePreviewActions();
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
            if (filePreviewFrameWrap) {
                filePreviewFrameWrap.classList.toggle('is-zoomed', filePreviewZoom > 1.01);
            }

            if (filePreviewType === 'image') {
                filePreviewImage.style.transform = `scale(${filePreviewZoom})`;
                filePreviewImage.style.transformOrigin = 'top left';
                return;
            }

            if (filePreviewType === 'pdf' && filePreviewBaseUrl) {
                filePreviewFrame.style.zoom = String(filePreviewZoom);
                if (filePreviewFrame.style.zoom !== String(filePreviewZoom)) {
                    filePreviewFrame.style.transform = `scale(${filePreviewZoom})`;
                    filePreviewFrame.style.transformOrigin = 'top left';
                } else {
                    filePreviewFrame.style.transform = '';
                    filePreviewFrame.style.transformOrigin = '';
                }
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

        function verifyPreviewDocument() {
            if (filePreviewDocumentId <= 0 || filePreviewUserId <= 0) {
                return;
            }

            verifyDocument(filePreviewDocumentId, filePreviewUserId);
        }

        function rejectPreviewDocument() {
            if (filePreviewDocumentId <= 0 || filePreviewUserId <= 0) {
                return;
            }

            openRejectModal(filePreviewDocumentId, filePreviewUserId);
        }

        function submitDocumentReview(action, docId, userId, reason = '', extraParams = {}, uiOptions = {}) {
            const loadingTitle = uiOptions.loadingTitle || (action === 'verify' ? 'Verifying document...' : 'Rejecting document...');
            const successTitle = uiOptions.successTitle || (action === 'verify' ? 'Document verified' : 'Document rejected');

            Swal.fire({
                title: loadingTitle,
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });

            const params = new URLSearchParams({ action, document_id: docId, user_id: userId });
            params.append('csrf_token', documentReviewCsrfToken);
            if (reason) {
                params.append('reason', reason);
            }
            Object.entries(extraParams).forEach(([key, value]) => {
                params.append(key, value);
            });

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
                        title: successTitle,
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
                closeMatchGuideModal();
                closeRejectModal();
                closeFilePreviewModal();
            }
        });

        document.addEventListener('click', function(event) {
            if (event.target === matchGuideModal) {
                closeMatchGuideModal();
            }
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
