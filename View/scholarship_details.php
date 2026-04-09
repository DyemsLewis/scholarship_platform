<?php
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Controllers/scholarshipResultController.php';
require_once __DIR__ . '/../app/Models/Scholarship.php';
require_once __DIR__ . '/../app/Models/UserDocument.php';

$scholarshipId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_GET['scholarship_id'] ?? 0);
if ($scholarshipId <= 0) {
    $_SESSION['error'] = 'Invalid scholarship ID.';
    header('Location: scholarships.php');
    exit();
}

requireValidEntityUrlToken(
    'scholarship',
    $scholarshipId,
    $_GET['token'] ?? null,
    'view',
    'scholarships.php',
    'Invalid or expired scholarship details link.'
);

$scholarshipModel = new Scholarship($pdo);
$scholarship = $scholarshipModel->getScholarshipById($scholarshipId);

if (!$scholarship) {
    $_SESSION['error'] = 'Scholarship not found or inactive.';
    header('Location: scholarships.php');
    exit();
}

$requiredGwa = null;
if (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') {
    $requiredGwa = (float) $scholarship['min_gwa'];
} elseif (isset($scholarship['max_gwa']) && $scholarship['max_gwa'] !== null && $scholarship['max_gwa'] !== '') {
    $requiredGwa = (float) $scholarship['max_gwa'];
}

$assessmentRequirement = strtolower(trim((string) ($scholarship['assessment_requirement'] ?? 'none')));
$hasAssessment = $assessmentRequirement !== '' && $assessmentRequirement !== 'none';
$remoteExamLocations = is_array($scholarship['remote_exam_locations'] ?? null) ? $scholarship['remote_exam_locations'] : [];
$userHasLocation = !empty($userLatitude) && !empty($userLongitude);
$scholarshipService = new ScholarshipService($pdo);

$matchPercentage = null;
$matchText = 'Login to view match score';
$requiresGwa = false;
$isEligible = false;
$distance = null;
$currentInfoDecision = 'Complete profile for stronger DSS recommendations';
$currentInfoClass = 'warn';
$currentInfoChecks = [];
$currentInfoTotal = 0;
$currentInfoMet = 0;
$currentInfoPending = 0;
$currentInfoWarn = 0;
$currentInfoSummary = 'Provide complete student details to improve recommendation precision.';
$documentSummary = [
    'requirements' => [],
    'verified' => 0,
    'pending' => 0,
    'missing' => []
];

$requiredDocuments = [];
try {
    $stmt = $pdo->prepare("
        SELECT dr.document_type, dr.description, dt.name
        FROM document_requirements dr
        LEFT JOIN document_types dt ON dt.code = dr.document_type
        WHERE dr.scholarship_id = ? AND dr.is_required = 1
        ORDER BY dr.id ASC
    ");
    $stmt->execute([$scholarshipId]);
    $requiredDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $requiredDocuments = [];
}

if ($isLoggedIn) {
    $matchedScholarships = $scholarshipService->getMatchedScholarships(
        $userGWA,
        $userCourse,
        $userLatitude,
        $userLongitude,
        [
            'applicant_type' => $userApplicantType,
            'year_level' => $userYearLevel,
            'admission_status' => $userAdmissionStatus,
            'shs_strand' => $userShsStrand,
            'course' => $userCourse,
            'target_course' => $userTargetCourse,
            'school' => $userSchool,
            'target_college' => $userTargetCollege,
            'enrollment_status' => $userEnrollmentStatus,
            'academic_standing' => $userAcademicStanding,
            'city' => $userCity,
            'province' => $userProvince,
            'citizenship' => $userCitizenship,
            'household_income_bracket' => $userHouseholdIncomeBracket,
            'special_category' => $userSpecialCategory
        ]
    );
    foreach ($matchedScholarships as $item) {
        if ((int) ($item['id'] ?? 0) === $scholarshipId) {
            $matchPercentage = isset($item['match_percentage']) ? (int) $item['match_percentage'] : null;
            $matchText = $matchPercentage !== null ? $scholarshipService->getMatchText($matchPercentage) : 'Unavailable';
            $requiresGwa = !empty($item['requires_gwa']);
            $isEligible = !empty($item['is_eligible']);
            if (isset($item['distance']) && $item['distance'] !== null) {
                $distance = (float) $item['distance'];
            }

            $currentInfoDecision = trim((string) ($item['current_info_label'] ?? ''));
            if ($currentInfoDecision === '') {
                $currentInfoDecision = 'Current student context unavailable';
            }

            $currentInfoChecks = is_array($item['current_info_checks'] ?? null) ? $item['current_info_checks'] : [];
            $currentInfoPending = (int) ($item['current_info_pending'] ?? 0);
            $currentInfoWarn = (int) ($item['current_info_warn'] ?? 0);
            $currentInfoMet = (int) ($item['current_info_met'] ?? 0);
            $currentInfoTotal = (int) ($item['current_info_total'] ?? 0);

            if ($currentInfoTotal === 0) {
                $currentInfoClass = 'info';
                $currentInfoSummary = 'No additional student-context checks are required for this scholarship.';
            } elseif ($currentInfoPending > 0) {
                $currentInfoClass = 'warn';
                $currentInfoSummary = $currentInfoMet . '/' . $currentInfoTotal . ' aligned. Complete missing profile fields to improve the DSS result.';
            } elseif ($currentInfoWarn > 0) {
                $currentInfoClass = 'warn';
                $currentInfoSummary = $currentInfoMet . '/' . $currentInfoTotal . ' aligned. Some current-info checks may need manual review.';
            } elseif ($currentInfoMet > 0) {
                $currentInfoClass = 'good';
                $currentInfoSummary = $currentInfoMet . '/' . $currentInfoTotal . ' aligned. Current student information strongly supports this match.';
            } else {
                $currentInfoClass = 'info';
                $currentInfoSummary = 'Current student context is available but not yet aligned.';
            }
            break;
        }
    }

    $documentModel = new UserDocument($pdo);
    $documentSummary = $documentModel->checkScholarshipRequirements($_SESSION['user_id'], $scholarshipId);
}

$profileEvaluation = $scholarshipService->evaluateProfileRequirements($scholarship, [
    'applicant_type' => $userApplicantType,
    'year_level' => $userYearLevel,
    'admission_status' => $userAdmissionStatus,
    'shs_strand' => $userShsStrand,
    'course' => $userCourse,
    'target_course' => $userTargetCourse,
    'school' => $userSchool,
    'target_college' => $userTargetCollege,
    'enrollment_status' => $userEnrollmentStatus,
    'academic_standing' => $userAcademicStanding,
    'city' => $userCity,
    'province' => $userProvince,
    'citizenship' => $userCitizenship,
    'household_income_bracket' => $userHouseholdIncomeBracket,
    'special_category' => $userSpecialCategory
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
$audienceLabel = !empty($audienceParts) ? implode(' / ', $audienceParts) : 'Open to all applicants';

$requirementsCount = count($requiredDocuments);
$verifiedCount = (int) ($documentSummary['verified'] ?? 0);
$pendingCount = (int) ($documentSummary['pending'] ?? 0);
$missingCount = count($documentSummary['missing'] ?? []);
$rejectedCount = 0;
foreach (($documentSummary['requirements'] ?? []) as $requirement) {
    if (($requirement['status'] ?? '') === 'rejected') {
        $rejectedCount++;
    }
}
$hasAllRequired = $requirementsCount === 0 ? true : ($missingCount === 0 && $rejectedCount === 0);

$academicDecision = 'No GWA requirement';
$academicClass = 'info';
if ($requiredGwa !== null) {
    if (!$isLoggedIn) {
        $academicDecision = 'Login to evaluate against required GWA';
        $academicClass = 'warn';
    } elseif (empty($userGWA)) {
        $academicDecision = 'Pending: upload grades';
        $academicClass = 'warn';
    } elseif ((float) $userGWA <= (float) $requiredGwa) {
        $academicDecision = 'Passed';
        $academicClass = 'good';
    } else {
        $academicDecision = 'Above limit';
        $academicClass = 'bad';
    }
}

$documentsDecision = 'No required documents';
$documentsClass = 'info';
if ($requirementsCount > 0) {
    if (!$isLoggedIn) {
        $documentsDecision = 'Login to track your document status';
        $documentsClass = 'warn';
    } elseif ($missingCount > 0) {
        $documentsDecision = ($requirementsCount - $missingCount) . '/' . $requirementsCount . ' uploaded (' . $missingCount . ' missing)';
        $documentsClass = 'bad';
    } elseif ($rejectedCount > 0) {
        $documentsDecision = ($requirementsCount - $missingCount) . '/' . $requirementsCount . ' uploaded (' . $rejectedCount . ' rejected)';
        $documentsClass = 'bad';
    } elseif ($pendingCount > 0) {
        $documentsDecision = ($requirementsCount - $missingCount) . '/' . $requirementsCount . ' uploaded (' . $pendingCount . ' pending review)';
        $documentsClass = 'warn';
    } else {
        $documentsDecision = $verifiedCount . '/' . $requirementsCount . ' verified';
        $documentsClass = 'good';
    }
}

$profileDecision = $profileEvaluation['label'] ?? 'Open profile policy';
$profileClass = 'info';
if (($profileEvaluation['total'] ?? 0) > 0) {
    if (($profileEvaluation['failed'] ?? 0) > 0) {
        $profileClass = 'bad';
    } elseif (($profileEvaluation['pending'] ?? 0) > 0) {
        $profileClass = 'warn';
    } else {
        $profileClass = 'good';
    }
}
$profileChecks = is_array($profileEvaluation['checks'] ?? null) ? $profileEvaluation['checks'] : [];

$locationDecision = 'Set your location to enable distance checks';
$locationClass = 'warn';
if ($userHasLocation && $distance !== null) {
    $locationDecision = $distance < 1
        ? (round($distance * 1000) . 'm away')
        : (number_format($distance, 1) . 'km away');
    $locationClass = 'good';
} elseif ($userHasLocation) {
    $locationDecision = 'Scholarship pin unavailable';
    $locationClass = 'info';
}

$deadlineDecision = 'Open / no deadline';
$deadlineClass = 'info';
$deadlineDisplay = 'Open / no deadline';
if (!empty($scholarship['deadline'])) {
    $deadlineDisplay = date('M d, Y', strtotime((string) $scholarship['deadline']));
    $now = new DateTime();
    $deadlineDate = new DateTime((string) $scholarship['deadline']);
    if ($deadlineDate < $now) {
        $deadlineDecision = 'Closed';
        $deadlineClass = 'bad';
    } else {
        $daysLeft = (int) $now->diff($deadlineDate)->days;
        if ($daysLeft <= 7) {
            $deadlineDecision = 'Urgent (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left)';
            $deadlineClass = 'warn';
        } else {
            $deadlineDecision = 'Open';
            $deadlineClass = 'good';
        }
    }
}

$applicationNotYetOpen = false;
$applicationOpenDateDisplay = 'Open now';
$applicationOpenDateDetail = 'This scholarship does not list a separate opening date.';
if (!empty($scholarship['application_open_date'])) {
    try {
        $applicationOpenDate = new DateTime((string) $scholarship['application_open_date']);
        $applicationOpenDate->setTime(0, 0, 0);
        $applicationOpenDateDisplay = $applicationOpenDate->format('M d, Y');
        if ($applicationOpenDate > new DateTime()) {
            $applicationNotYetOpen = true;
            $applicationOpenDateDetail = 'Applications open on ' . $applicationOpenDateDisplay . '.';
        } else {
            $applicationOpenDateDetail = 'Applications opened on ' . $applicationOpenDateDisplay . '.';
        }
    } catch (Throwable $e) {
        $applicationNotYetOpen = false;
        $applicationOpenDateDisplay = 'Open now';
    }
}

$applicationWindowDecision = 'Open now';
$applicationWindowClass = 'good';
$applicationWindowDetail = $applicationOpenDateDetail;
if ($deadlineClass === 'bad') {
    $applicationWindowDecision = 'Closed';
    $applicationWindowClass = 'bad';
    $applicationWindowDetail = 'This scholarship is already closed.';
} elseif ($applicationNotYetOpen) {
    $applicationWindowDecision = 'Opens ' . $applicationOpenDateDisplay;
    $applicationWindowClass = 'warn';
    $applicationWindowDetail = 'You can prepare your profile and documents now, but submissions start on ' . $applicationOpenDateDisplay . '.';
} elseif (!empty($scholarship['deadline'])) {
    $applicationWindowDetail = 'Applications are open now. The current deadline is ' . $deadlineDisplay . '.';
}

$assessmentLabel = 'None';
if ($assessmentRequirement === 'online_exam') $assessmentLabel = 'Online Exam';
if ($assessmentRequirement === 'remote_examination') $assessmentLabel = 'Remote Examination';
if ($assessmentRequirement === 'assessment') $assessmentLabel = 'Online Assessment';
if ($assessmentRequirement === 'evaluation') $assessmentLabel = 'Online Evaluation';

$applicationProcessLabelDisplay = trim((string) ($scholarship['application_process_label'] ?? ''));
if ($applicationProcessLabelDisplay === '') {
    if ($hasAssessment) {
        $applicationProcessLabelDisplay = 'Documents + ' . $assessmentLabel;
    } elseif ($requirementsCount > 0) {
        $applicationProcessLabelDisplay = 'Documents + Provider Review';
    } else {
        $applicationProcessLabelDisplay = 'Provider Review';
    }
}

$postApplicationStepsText = trim((string) ($scholarship['post_application_steps'] ?? ''));
if ($postApplicationStepsText === '') {
    $postApplicationStepsText = 'After submission, the provider reviews your profile and documents, then sends the result through the system.';
}

$renewalConditionsText = trim((string) ($scholarship['renewal_conditions'] ?? ''));
if ($renewalConditionsText === '') {
    $renewalConditionsText = 'The provider did not list renewal conditions in this posting.';
}

$scholarshipRestrictionsText = trim((string) ($scholarship['scholarship_restrictions'] ?? ''));
if ($scholarshipRestrictionsText === '') {
    $scholarshipRestrictionsText = 'The provider did not list extra restrictions or service obligations in this posting.';
}

$benefitItems = [];
$benefitsRaw = trim((string) ($scholarship['benefits'] ?? ''));
if ($benefitsRaw !== '') {
    foreach (explode(',', $benefitsRaw) as $benefit) {
        $item = trim($benefit);
        if ($item !== '') {
            $benefitItems[] = $item;
        }
    }
}
if (empty($benefitItems)) {
    $benefitItems[] = 'Benefits are defined by the scholarship provider.';
}

$defaultScholarshipImage = resolvePublicUploadUrl(null, '../');
$scholarshipImage = resolvePublicUploadUrl($scholarship['image'] ?? null, '../');

$profileNeedsAttention = (($profileEvaluation['pending'] ?? 0) > 0 || ($profileEvaluation['failed'] ?? 0) > 0);
$primaryActionHref = 'documents.php';
$primaryActionLabel = 'Complete Documents';
$primaryActionIcon = 'fa-file-upload';
$readinessHeadline = 'Needs documents';
$readinessClass = 'warn';
$readinessMessage = 'Upload the remaining requirements before starting your application.';

if (!$isLoggedIn) {
    $primaryActionHref = 'login.php';
    $primaryActionLabel = 'Login to Continue';
    $primaryActionIcon = 'fa-sign-in-alt';
    $readinessHeadline = 'Login required';
    $readinessClass = 'info';
    $readinessMessage = 'Sign in to compare this scholarship against your profile and document records.';
} elseif ($requiresGwa) {
    $primaryActionHref = 'upload.php';
    $primaryActionLabel = 'Upload Grades';
    $primaryActionIcon = 'fa-upload';
    $readinessHeadline = 'Needs academic record';
    $readinessClass = 'warn';
    $readinessMessage = 'Upload your TOR or Form 138 so the system can verify your recorded GWA.';
} elseif ($profileNeedsAttention) {
    $primaryActionHref = 'profile.php';
    $primaryActionLabel = 'Update Profile';
    $primaryActionIcon = 'fa-user-pen';
    $readinessHeadline = 'Profile update needed';
    $readinessClass = ($profileEvaluation['failed'] ?? 0) > 0 ? 'bad' : 'warn';
    $readinessMessage = 'Some required profile signals are missing or do not yet align with this scholarship.';
} elseif (!$isEligible) {
    $primaryActionHref = 'profile.php';
    $primaryActionLabel = 'Review Profile';
    $primaryActionIcon = 'fa-user-pen';
    $readinessHeadline = 'Not currently eligible';
    $readinessClass = 'bad';
    $readinessMessage = 'Your current profile or academic record does not yet satisfy this scholarship policy.';
} elseif ($isEligible && $hasAllRequired) {
    $primaryActionHref = buildEntityUrl('wizard.php', 'scholarship', $scholarshipId, 'apply', ['scholarship_id' => $scholarshipId]);
    $primaryActionLabel = 'Apply in Wizard';
    $primaryActionIcon = 'fa-paper-plane';
    $readinessHeadline = 'Ready to apply';
    $readinessClass = 'good';
    $readinessMessage = 'Your profile and listed requirements are aligned for application submission.';
} elseif ($pendingCount > 0) {
    $primaryActionHref = 'documents.php';
    $primaryActionLabel = 'Review Documents';
    $primaryActionIcon = 'fa-folder-open';
    $readinessHeadline = 'Documents under review';
    $readinessClass = 'warn';
    $readinessMessage = 'Your required files are uploaded, but some are still waiting for verification.';
}

$academicDetail = $requiredGwa !== null
    ? ('Required GWA: ' . number_format($requiredGwa, 2) . ' or better.')
    : 'No fixed academic cutoff is configured for this scholarship.';
$profileDetail = ($profileEvaluation['total'] ?? 0) > 0
    ? (($profileEvaluation['met'] ?? 0) . '/' . ($profileEvaluation['total'] ?? 0) . ' configured profile filters are aligned.')
    : 'This scholarship does not require extra profile filters.';
$currentInfoDetail = $currentInfoTotal > 0
    ? ($currentInfoMet . '/' . $currentInfoTotal . ' current student signals are aligned.')
    : 'No additional current-information checks are required.';
$documentsDetail = $requirementsCount > 0
    ? ($verifiedCount . '/' . $requirementsCount . ' required documents are currently verified.')
    : 'No required documents are configured for this scholarship.';
$locationDetail = $userHasLocation
    ? (($distance !== null) ? 'Distance-based matching is active for your saved location.' : 'Your location is saved, but this scholarship has no pinned site.')
    : 'Add your location to improve distance-aware matching.';
$deadlineDetail = !empty($scholarship['deadline'])
    ? ('Submission deadline: ' . $deadlineDisplay . '.')
    : 'This scholarship is open without a fixed closing date.';

$decisionItems = [
    [
        'label' => 'Academic Fit',
        'value' => $academicDecision,
        'detail' => $academicDetail,
        'class' => $academicClass,
        'icon' => 'fa-chart-line',
    ],
    [
        'label' => 'Profile Policy',
        'value' => $profileDecision,
        'detail' => $profileDetail,
        'class' => $profileClass,
        'icon' => 'fa-id-card',
    ],
    [
        'label' => 'Student Context',
        'value' => $currentInfoDecision,
        'detail' => $currentInfoDetail,
        'class' => $currentInfoClass,
        'icon' => 'fa-user-check',
    ],
    [
        'label' => 'Documents',
        'value' => $documentsDecision,
        'detail' => $documentsDetail,
        'class' => $documentsClass,
        'icon' => 'fa-folder-open',
    ],
    [
        'label' => 'Location',
        'value' => $locationDecision,
        'detail' => $locationDetail,
        'class' => $locationClass,
        'icon' => 'fa-location-dot',
    ],
    [
        'label' => 'Deadline',
        'value' => $deadlineDecision,
        'detail' => $deadlineDetail,
        'class' => $deadlineClass,
        'icon' => 'fa-calendar-days',
    ],
];

$mappedExamSiteCount = 0;
foreach ($remoteExamLocations as $remoteExamLocation) {
    if (
        isset($remoteExamLocation['latitude'], $remoteExamLocation['longitude']) &&
        $remoteExamLocation['latitude'] !== '' &&
        $remoteExamLocation['longitude'] !== '' &&
        $remoteExamLocation['latitude'] !== null &&
        $remoteExamLocation['longitude'] !== null
    ) {
        $mappedExamSiteCount++;
    }
}

$assessmentHeadline = 'Assessment required';
$assessmentSummary = 'Review the provider assessment instructions before moving forward with your application.';
$assessmentModeLabel = 'Provider review';
$assessmentAccessLabel = 'Instructions only';

if ($assessmentRequirement === 'online_exam') {
    $assessmentHeadline = 'Online exam required';
    $assessmentSummary = 'This scholarship includes an online exam that must be completed through the provider link or instructions.';
    $assessmentModeLabel = 'Online exam';
    $assessmentAccessLabel = !empty($scholarship['assessment_link']) ? 'Direct link available' : 'Check provider instructions';
} elseif ($assessmentRequirement === 'assessment') {
    $assessmentHeadline = 'Online assessment required';
    $assessmentSummary = 'A provider assessment must be completed as part of the scholarship evaluation process.';
    $assessmentModeLabel = 'Online assessment';
    $assessmentAccessLabel = !empty($scholarship['assessment_link']) ? 'Direct link available' : 'Check provider instructions';
} elseif ($assessmentRequirement === 'evaluation') {
    $assessmentHeadline = 'Evaluation stage required';
    $assessmentSummary = 'The provider uses an evaluation step before final scholarship review.';
    $assessmentModeLabel = 'Evaluation';
    $assessmentAccessLabel = !empty($scholarship['assessment_link']) ? 'Provider link available' : 'Provider-managed evaluation';
} elseif ($assessmentRequirement === 'remote_examination') {
    $assessmentHeadline = 'Remote examination required';
    $assessmentSummary = $mappedExamSiteCount > 0
        ? 'Choose the most suitable examination site and review the provider instructions before attending.'
        : 'This scholarship uses remote examination sites. Review the provider instructions and listed site details.';
    $assessmentModeLabel = 'Remote examination';
    $assessmentAccessLabel = $mappedExamSiteCount > 0
        ? ($mappedExamSiteCount . ' mapped site' . ($mappedExamSiteCount === 1 ? '' : 's'))
        : 'Site details pending map pins';
}

$assessmentNotesLabel = trim((string) ($scholarship['assessment_details'] ?? '')) !== ''
    ? 'Provider notes available'
    : 'No extra provider notes';

$pushReason = static function (array &$reasons, string $reason, int $limit = 4): void {
    $normalized = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);
    if ($normalized === '' || in_array($normalized, $reasons, true) || count($reasons) >= $limit) {
        return;
    }

    $reasons[] = $normalized;
};

$buildScholarshipCheckReason = static function (array $check, string $status): string {
    $label = trim((string) ($check['label'] ?? 'Requirement'));
    $target = trim((string) ($check['target'] ?? ''));
    $detail = trim((string) ($check['detail'] ?? ''));

    if ($status === 'met') {
        if ($label !== '' && $target !== '') {
            return $label . ': ' . $target;
        }

        if ($detail !== '') {
            return $detail;
        }

        return $label . ' aligned';
    }

    if ($status === 'failed') {
        if ($target !== '') {
            return 'Needs ' . $label . ': ' . $target;
        }

        if ($detail !== '') {
            return $detail;
        }

        return 'Update ' . $label;
    }

    if ($status === 'pending') {
        if ($detail !== '') {
            return $detail;
        }

        return 'Set ' . strtolower($label);
    }

    if ($status === 'warn') {
        if ($detail !== '') {
            return $detail;
        }

        return $label . ' needs review';
    }

    return $detail !== '' ? $detail : $label;
};

$buildCompactScholarshipText = static function (string $text, string $fallback = 'Not specified'): string {
    $normalized = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text);
    if ($normalized === '') {
        return $fallback;
    }

    $maxLength = 62;
    $currentLength = function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized);
    if ($currentLength <= $maxLength) {
        return $normalized;
    }

    $trimmed = function_exists('mb_substr')
        ? mb_substr($normalized, 0, $maxLength - 3)
        : substr($normalized, 0, $maxLength - 3);

    return rtrim($trimmed, " \t\n\r\0\x0B.,;:") . '...';
};

$profileRequirementPending = (int) ($profileEvaluation['pending'] ?? 0);
$profileRequirementFailed = (int) ($profileEvaluation['failed'] ?? 0);
$profileRequirementMet = (int) ($profileEvaluation['met'] ?? 0);
$profileRequirementTotal = (int) ($profileEvaluation['total'] ?? 0);

$benefitsDisplayText = $benefitsRaw !== '' ? $benefitsRaw : 'Benefits are defined by the scholarship provider.';
$descriptionFullText = trim((string) ($scholarship['description'] ?? ''));
if ($descriptionFullText === '') {
    $descriptionFullText = 'The scholarship provider has not added a longer description yet. Use the eligibility, requirements, and provider details below before you apply.';
}

$eligibilityNotesText = trim((string) ($scholarship['eligibility'] ?? ''));
$hasEligibilityNotes = $eligibilityNotesText !== '';
$requiredGwaLabel = $requiredGwa !== null
    ? rtrim(rtrim(number_format($requiredGwa, 2), '0'), '.') . ' or better'
    : 'Open';

$eligibilityRequirementItems = [];
$eligibilitySeenLabels = [];
$addEligibilityRequirement = static function (string $label, string $value, string $detail = '') use (&$eligibilityRequirementItems, &$eligibilitySeenLabels): void {
    $normalizedLabel = strtolower(trim($label));
    $normalizedValue = trim($value);
    if ($normalizedLabel === '' || $normalizedValue === '' || isset($eligibilitySeenLabels[$normalizedLabel]) || count($eligibilityRequirementItems) >= 6) {
        return;
    }

    $eligibilitySeenLabels[$normalizedLabel] = true;
    $eligibilityRequirementItems[] = [
        'label' => $label,
        'value' => $normalizedValue,
        'detail' => trim($detail),
    ];
};

$addEligibilityRequirement(
    'Target applicants',
    $audienceLabel,
    'This shows who the scholarship is mainly intended for.'
);

if ($requiredGwa !== null) {
    $addEligibilityRequirement(
        'Required GWA',
        $requiredGwaLabel,
        'Students usually check this first before preparing the rest of the requirements.'
    );
}

foreach ($profileChecks as $profileCheck) {
    $targetText = trim((string) ($profileCheck['target'] ?? ''));
    $detailText = trim((string) ($profileCheck['detail'] ?? ''));
    $valueText = $targetText !== '' ? $targetText : $detailText;
    $labelText = trim((string) ($profileCheck['label'] ?? ''));

    if ($labelText === '' || $valueText === '') {
        continue;
    }

    $addEligibilityRequirement($labelText, $valueText, $detailText !== '' && $detailText !== $valueText ? $detailText : '');
}

if (!empty($scholarship['location_name'])) {
    $addEligibilityRequirement(
        'Area or location',
        (string) $scholarship['location_name'],
        'Some scholarships are tied to a school, campus, city, or region.'
    );
}

$applicationGuideItems = [
    [
        'label' => 'Application flow',
        'value' => $applicationProcessLabelDisplay,
        'detail' => 'You will go through the scholarship wizard to check your eligibility, required documents, and final review before submission.',
    ],
    [
        'label' => 'Extra step',
        'value' => $hasAssessment ? $assessmentModeLabel : 'Provider review only',
        'detail' => $hasAssessment
            ? $assessmentSummary
            : 'No extra exam or assessment is listed. The provider reviews your submitted requirements after you apply.',
    ],
    [
        'label' => 'After you submit',
        'value' => 'Provider review',
        'detail' => $postApplicationStepsText,
    ],
];

if ($requirementsCount > 0) {
    $uploadedRequirementCount = $requirementsCount - $missingCount;
    $requirementsSummary = $uploadedRequirementCount . '/' . $requirementsCount . ' uploaded';
    if ($missingCount > 0) {
        $requirementsSummary .= ' (' . $missingCount . ' missing)';
    } elseif ($rejectedCount > 0) {
        $requirementsSummary .= ' (' . $rejectedCount . ' rejected)';
    } elseif ($pendingCount > 0) {
        $requirementsSummary .= ' (' . $pendingCount . ' pending review)';
    } else {
        $requirementsSummary .= ' (all verified)';
    }
} else {
    $requirementsSummary = 'No required docs listed';
}

$descriptionPreview = trim(strip_tags((string) ($scholarship['description'] ?? '')));
if ($descriptionPreview !== '' && strlen($descriptionPreview) > 180) {
    $descriptionPreview = substr($descriptionPreview, 0, 177) . '...';
}
if ($descriptionPreview === '') {
    $descriptionPreview = 'Review the scholarship details, requirements, and next steps before you apply.';
}

$visitSiteUrl = trim((string) ($scholarship['provider_website'] ?? ''));
if ($visitSiteUrl === '' && !empty($scholarship['assessment_link'])) {
    $visitSiteUrl = trim((string) $scholarship['assessment_link']);
}
if ($visitSiteUrl !== '' && !filter_var($visitSiteUrl, FILTER_VALIDATE_URL)) {
    $visitSiteUrl = '';
}

if ($matchPercentage === null) {
    $badgeClass = 'estimated';
} elseif ($matchPercentage >= 80) {
    $badgeClass = 'high';
} elseif ($matchPercentage >= 60) {
    $badgeClass = 'medium';
} else {
    $badgeClass = 'low';
}
$matchBadgeText = $matchPercentage !== null ? ((int) $matchPercentage . '% match') : $matchText;

$remoteExamMapUrl = ($assessmentRequirement === 'remote_examination' && !empty($remoteExamLocations))
    ? buildEntityUrl('remote_exam_map.php', 'scholarship', $scholarshipId, 'view', ['id' => $scholarshipId])
    : '';

$readyToApplyNow = $isLoggedIn && $isEligible && $hasAllRequired && !$requiresGwa && !$applicationNotYetOpen && $deadlineClass !== 'bad';
$detailCardStatusClass = 'ineligible';
$detailCardStatusLabel = 'Not Eligible';
if ($deadlineClass === 'bad') {
    $detailCardStatusClass = 'expired';
    $detailCardStatusLabel = 'Expired';
} elseif ($applicationNotYetOpen) {
    $detailCardStatusClass = 'estimated';
    $detailCardStatusLabel = 'Not Open Yet';
} elseif ($requiresGwa) {
    $detailCardStatusClass = 'estimated';
    $detailCardStatusLabel = 'Needs GWA';
} elseif ($readyToApplyNow) {
    $detailCardStatusClass = 'ready';
    $detailCardStatusLabel = 'Ready to Apply';
} elseif ($isEligible) {
    $detailCardStatusClass = 'docs';
    $detailCardStatusLabel = 'Needs Documents';
} elseif ($profileRequirementFailed > 0 || $profileRequirementPending > 0) {
    $detailCardStatusClass = 'profile';
    $detailCardStatusLabel = 'Profile Needed';
}

$detailStatusIcon = 'fa-ban';
if ($detailCardStatusClass === 'expired') {
    $detailStatusIcon = 'fa-clock';
} elseif ($detailCardStatusClass === 'ready') {
    $detailStatusIcon = 'fa-check-circle';
} elseif ($applicationNotYetOpen) {
    $detailStatusIcon = 'fa-hourglass-half';
} elseif ($requiresGwa) {
    $detailStatusIcon = 'fa-chart-line';
} elseif ($detailCardStatusClass === 'docs') {
    $detailStatusIcon = 'fa-file-circle-exclamation';
} elseif ($detailCardStatusClass === 'profile') {
    $detailStatusIcon = 'fa-user-gear';
}

$requirementsBadgeText = 'Requirements checked';
if ($requirementsCount === 0) {
    $requirementsBadgeText = 'Open requirements';
} elseif ($hasAllRequired && $pendingCount === 0) {
    $requirementsBadgeText = 'Requirements ready';
} elseif ($missingCount > 0 || $rejectedCount > 0) {
    $requirementsBadgeText = 'Requirements pending';
} elseif ($pendingCount > 0) {
    $requirementsBadgeText = 'Review in progress';
}

$matchedReasons = [];
$attentionReasons = [];

if ($requiredGwa !== null) {
    if (!empty($userGWA)) {
        if ((float) $userGWA <= (float) $requiredGwa) {
            $pushReason($matchedReasons, 'GWA meets the required limit');
        } else {
            $pushReason($attentionReasons, 'Your current GWA is above the allowed limit');
        }
    } else {
        $pushReason($attentionReasons, 'Upload your academic record so the system can verify your GWA');
    }
} else {
    $pushReason($matchedReasons, 'No fixed GWA cutoff is required for this scholarship');
}

if ($deadlineClass === 'bad') {
    $pushReason($attentionReasons, 'The application period for this scholarship has already closed');
} elseif ($applicationNotYetOpen) {
    $pushReason($attentionReasons, 'Applications open on ' . $applicationOpenDateDisplay);
} else {
    $pushReason($matchedReasons, 'The scholarship is currently open for applications');
}

if ($requirementsCount > 0) {
    if ($missingCount > 0) {
        $pushReason($attentionReasons, $missingCount . ' required document' . ($missingCount === 1 ? ' is' : 's are') . ' still missing');
    } elseif ($rejectedCount > 0) {
        $pushReason($attentionReasons, 'Re-upload ' . $rejectedCount . ' rejected required document' . ($rejectedCount === 1 ? '' : 's'));
    } elseif ($pendingCount > 0) {
        $pushReason($attentionReasons, $pendingCount . ' required document' . ($pendingCount === 1 ? ' is' : 's are') . ' waiting for review');
    } else {
        $pushReason($matchedReasons, 'Required documents are complete');
    }
} else {
    $pushReason($matchedReasons, 'No additional document upload is required by this scholarship');
}

foreach ($profileChecks as $profileCheck) {
    $checkStatus = strtolower(trim((string) ($profileCheck['status'] ?? 'pending')));
    $reasonText = $buildScholarshipCheckReason($profileCheck, $checkStatus);

    if ($checkStatus === 'met') {
        $pushReason($matchedReasons, $reasonText);
    } elseif (in_array($checkStatus, ['failed', 'pending'], true)) {
        $pushReason($attentionReasons, $reasonText);
    }
}

foreach ($currentInfoChecks as $currentInfoCheck) {
    $checkStatus = strtolower(trim((string) ($currentInfoCheck['status'] ?? 'pending')));
    $reasonText = $buildScholarshipCheckReason($currentInfoCheck, $checkStatus);

    if ($checkStatus === 'met') {
        $pushReason($matchedReasons, $reasonText);
    } elseif (in_array($checkStatus, ['pending', 'warn'], true)) {
        $pushReason($attentionReasons, $reasonText);
    }
}

if ($readyToApplyNow) {
    $pushReason($matchedReasons, 'Your profile and listed requirements are ready for application submission');
}

$nextStepTone = 'info';
$nextStepIcon = 'fa-circle-info';
$nextStepMessage = 'Review the scholarship details and listed requirements before applying.';
$openingDatePrepPrefix = $applicationNotYetOpen
    ? ('Applications open on ' . $applicationOpenDateDisplay . '. ')
    : '';

$primaryActionType = 'link';
$primaryActionHref = 'scholarships.php';
$primaryActionClass = 'btn-outline-modern';
$primaryActionIcon = 'fa-arrow-left';
$primaryActionLabel = 'Back to Scholarships';

if ($deadlineClass === 'bad') {
    $nextStepTone = 'muted';
    $nextStepIcon = 'fa-calendar-times';
    $nextStepMessage = 'The application period for this scholarship has already closed.';
    $primaryActionType = 'button';
    $primaryActionClass = 'btn-disabled-modern';
    $primaryActionIcon = 'fa-calendar-times';
    $primaryActionLabel = 'Closed';
} elseif ($requiresGwa) {
    $nextStepTone = 'warning';
    $nextStepIcon = 'fa-chart-line';
    $nextStepMessage = $openingDatePrepPrefix . 'Upload your grades to complete the academic requirement check.';
    $primaryActionHref = 'upload.php';
    $primaryActionClass = 'btn-warning-modern';
    $primaryActionIcon = 'fa-upload';
    $primaryActionLabel = 'Upload Grades';
} elseif ($profileRequirementPending > 0) {
    $nextStepTone = 'warning';
    $nextStepIcon = 'fa-user-gear';
    $nextStepMessage = $openingDatePrepPrefix . 'Complete your applicant profile so the system can finish the scholarship policy check.';
    $primaryActionHref = 'profile.php';
    $primaryActionClass = 'btn-warning-modern';
    $primaryActionIcon = 'fa-user-pen';
    $primaryActionLabel = 'Complete Profile';
} elseif ($profileRequirementFailed > 0) {
    $nextStepTone = 'warning';
    $nextStepIcon = 'fa-user-xmark';
    $nextStepMessage = $openingDatePrepPrefix . 'Your current applicant profile does not yet match the target audience for this scholarship.';
    $primaryActionHref = 'profile.php';
    $primaryActionClass = 'btn-outline-modern';
    $primaryActionIcon = 'fa-user-pen';
    $primaryActionLabel = 'Review Profile';
} elseif ($applicationNotYetOpen) {
    $nextStepTone = 'info';
    $nextStepIcon = 'fa-hourglass-half';
    $nextStepMessage = 'Applications open on ' . $applicationOpenDateDisplay . '. This scholarship is not accepting submissions yet.';
    $primaryActionType = 'button';
    $primaryActionClass = 'btn-disabled-modern';
    $primaryActionIcon = 'fa-hourglass-half';
    $primaryActionLabel = 'Opens Soon';
} elseif ($readyToApplyNow) {
    $nextStepTone = 'success';
    $nextStepIcon = 'fa-circle-check';
    $nextStepMessage = 'Your profile and listed requirements are ready for application submission.';
    $primaryActionHref = buildEntityUrl('wizard.php', 'scholarship', $scholarshipId, 'apply', ['scholarship_id' => $scholarshipId]);
    $primaryActionClass = 'btn-primary-modern';
    $primaryActionIcon = 'fa-paper-plane';
    $primaryActionLabel = 'Apply Now';
} elseif ($isEligible) {
    $nextStepTone = 'warning';
    $nextStepIcon = 'fa-upload';
    $nextStepMessage = $openingDatePrepPrefix . 'Upload the remaining required documents before you submit your application.';
    $primaryActionHref = 'documents.php';
    $primaryActionClass = 'btn-warning-modern';
    $primaryActionIcon = 'fa-upload';
    $primaryActionLabel = 'Upload Documents';
} elseif ($requiredGwa !== null && !empty($userGWA) && (float) $userGWA > (float) $requiredGwa) {
    $nextStepTone = 'warning';
    $nextStepIcon = 'fa-triangle-exclamation';
    $nextStepMessage = 'Your current GWA is above the scholarship minimum requirement.';
    $primaryActionType = 'button';
    $primaryActionClass = 'btn-disabled-modern';
    $primaryActionIcon = 'fa-ban';
    $primaryActionLabel = 'Not Eligible';
}

$eligibilityStatusTitle = 'Review the details below before deciding.';
$eligibilityStatusSummary = $nextStepMessage;

if ($detailCardStatusClass === 'ready') {
    $eligibilityStatusTitle = 'Yes, you can apply now.';
    $eligibilityStatusSummary = 'Your profile, academic record, and listed requirements currently line up with this scholarship.';
} elseif ($applicationNotYetOpen) {
    $eligibilityStatusTitle = 'Not yet. Applications open on ' . $applicationOpenDateDisplay . '.';
    $eligibilityStatusSummary = 'Use this time to prepare your profile and required documents so you are ready once the application window starts.';
} elseif ($detailCardStatusClass === 'docs') {
    $eligibilityStatusTitle = 'Almost there. You still need some documents.';
    $eligibilityStatusSummary = 'Your profile fits the scholarship, but the required files are not fully complete or verified yet.';
} elseif ($detailCardStatusClass === 'profile') {
    $eligibilityStatusTitle = 'Not yet. Your profile still needs attention.';
    $eligibilityStatusSummary = $profileRequirementFailed > 0
        ? 'Some of your current applicant details do not match the target audience for this scholarship.'
        : 'Complete the missing profile fields first so the system can finish checking your eligibility.';
} elseif ($detailCardStatusClass === 'estimated') {
    $eligibilityStatusTitle = 'Not yet. The system still needs your academic record.';
    $eligibilityStatusSummary = 'Upload your grade document first so the system can verify your GWA and finish the scholarship check.';
} elseif ($detailCardStatusClass === 'expired') {
    $eligibilityStatusTitle = 'This scholarship is already closed.';
    $eligibilityStatusSummary = 'You can still review the details, but new applications are no longer accepted.';
} elseif ($detailCardStatusClass === 'ineligible') {
    $eligibilityStatusTitle = 'Not yet. Some requirements are not aligned.';
    $eligibilityStatusSummary = 'Check the missing items below to see what needs to be completed or corrected first.';
}

$statusChecklistItems = [
    [
        'label' => 'Academic requirement',
        'value' => $academicDecision,
        'detail' => $academicDetail,
        'class' => $academicClass,
        'icon' => 'fa-chart-line',
    ],
    [
        'label' => 'Profile requirements',
        'value' => $profileDecision,
        'detail' => $profileDetail,
        'class' => $profileClass,
        'icon' => 'fa-user-check',
    ],
    [
        'label' => 'Required documents',
        'value' => $documentsDecision,
        'detail' => $documentsDetail,
        'class' => $documentsClass,
        'icon' => 'fa-folder-open',
    ],
    [
        'label' => 'Application window',
        'value' => $applicationWindowDecision,
        'detail' => $applicationWindowDetail,
        'class' => $applicationWindowClass,
        'icon' => 'fa-calendar-days',
    ],
];

$canApplyRuleItems = [
    $requiredGwa !== null
        ? 'Your recorded GWA must be ' . $requiredGwaLabel . '.'
        : 'This scholarship does not use a fixed GWA cutoff.',
    'Your profile should match the target audience: ' . $audienceLabel . '.',
    $requirementsCount > 0
        ? 'All listed required documents should be uploaded and not rejected.'
        : 'No extra required documents are listed for this scholarship right now.',
    $applicationNotYetOpen
        ? 'The application window starts on ' . $applicationOpenDateDisplay . '.'
        : 'The application window must already be open and not past the deadline.',
];

$cantApplyRuleItems = [
    $requiredGwa !== null
        ? 'You cannot apply yet if your GWA is missing or above ' . $requiredGwaLabel . '.'
        : 'GWA alone does not block this scholarship, but other checks can still do so.',
    'You cannot apply yet if your applicant profile is incomplete or does not match the scholarship audience.',
    $requirementsCount > 0
        ? 'You cannot apply yet if required files are missing, rejected, or still waiting for review.'
        : 'If the provider adds required documents later, those will need to be completed before submission.',
    $applicationNotYetOpen
        ? 'You cannot apply before ' . $applicationOpenDateDisplay . '.'
        : (!empty($scholarship['deadline'])
            ? 'You cannot apply after ' . $deadlineDisplay . '.'
            : 'There is no fixed closing date listed right now, but the provider can still update the posting.'),
];

$rulesInfoNote = 'These rules explain system readiness only. Final approval still depends on the provider review process.';

$coursePathwayCheck = null;
foreach ($currentInfoChecks as $currentInfoCheck) {
    if (strtolower(trim((string) ($currentInfoCheck['key'] ?? ''))) === 'course_pathway') {
        $coursePathwayCheck = $currentInfoCheck;
        break;
    }
}

$courseMatchValue = 'Course details still needed';
$courseMatchDetail = 'Add your current or target course so the system can compare it with the scholarship focus.';
$courseMatchClass = 'info';
if (is_array($coursePathwayCheck)) {
    $courseMatchStatus = strtolower(trim((string) ($coursePathwayCheck['status'] ?? 'pending')));
    $courseMatchDetail = trim((string) ($coursePathwayCheck['detail'] ?? $courseMatchDetail));

    if ($courseMatchStatus === 'met') {
        $courseMatchValue = 'Strong course alignment';
        $courseMatchClass = 'good';
    } elseif ($courseMatchStatus === 'warn') {
        $courseMatchValue = 'Partial course alignment';
        $courseMatchClass = 'warn';
    } else {
        $courseMatchValue = 'Course details still needed';
        $courseMatchClass = 'info';
    }
} elseif (!empty($userCourse) || !empty($userTargetCourse)) {
    $courseMatchValue = 'Course information on file';
    $courseMatchDetail = 'The DSS compares your current or target course with the scholarship focus.';
}

$profileMatchValue = ($profileEvaluation['total'] ?? 0) > 0
    ? (($profileEvaluation['met'] ?? 0) . '/' . ($profileEvaluation['total'] ?? 0) . ' audience rules aligned')
    : 'No extra audience filters';
$profileMatchClass = ($profileEvaluation['total'] ?? 0) > 0
    ? ($profileClass === 'bad' ? 'bad' : ($profileClass === 'warn' ? 'warn' : 'good'))
    : 'info';

$studentContextValue = $currentInfoTotal > 0
    ? ($currentInfoMet . '/' . $currentInfoTotal . ' support signals aligned')
    : 'No extra context checks';
$studentContextClass = $currentInfoClass === 'good'
    ? 'good'
    : ($currentInfoClass === 'warn' ? 'warn' : 'info');

$deadlineSignalValue = $applicationWindowDecision;
$deadlineSignalDetail = $applicationWindowDetail;
$deadlineSignalClass = $applicationWindowClass === 'bad'
    ? 'bad'
    : ($applicationWindowClass === 'warn' ? 'warn' : 'good');

$providerSignalValue = 'Standard provider signal';
$providerSignalDetail = 'Provider profile contributes a smaller general ranking signal in the DSS.';
$providerSignalClass = 'info';
$providerNameForScore = (string) ($scholarship['provider'] ?? '');
$recognizedProviders = ['CHED', 'DOST', 'University of the Philippines', 'SM Foundation', 'Ayala Foundation'];
$recognizedProviderMatch = false;
foreach ($recognizedProviders as $recognizedProvider) {
    if ($providerNameForScore !== '' && stripos($providerNameForScore, $recognizedProvider) !== false) {
        $recognizedProviderMatch = true;
        break;
    }
}
if ($recognizedProviderMatch) {
    $providerSignalValue = 'Established provider signal';
    $providerSignalDetail = 'Recognized providers receive a slightly stronger ranking signal in the DSS.';
    $providerSignalClass = 'good';
} elseif ($providerNameForScore !== '' && (stripos($providerNameForScore, 'university') !== false || stripos($providerNameForScore, 'college') !== false)) {
    $providerSignalValue = 'Academic institution signal';
    $providerSignalDetail = 'College and university providers receive a moderate ranking signal in the DSS.';
    $providerSignalClass = 'good';
}

$matchGuideButtonLabel = $matchPercentage !== null
    ? ('Why ' . (int) $matchPercentage . '% match?')
    : 'How match works';
$matchGuideTitle = $matchPercentage !== null
    ? ('Why this shows as ' . (int) $matchPercentage . '% match')
    : 'How the match score works';

if (!$isLoggedIn) {
    $matchGuideSummary = 'This is a personalized fit score. Log in and complete your student details to see how well this scholarship matches your profile.';
} elseif ($requiresGwa) {
    $matchGuideSummary = 'This score is still partly estimated because your academic record is missing. The DSS is using your other profile signals for now.';
} elseif ($matchPercentage !== null && $matchPercentage >= 80) {
    $matchGuideSummary = 'This is a strong fit score because several major DSS signals line up well with this scholarship.';
} elseif ($matchPercentage !== null && $matchPercentage >= 60) {
    $matchGuideSummary = 'This is a moderate fit score. Some major DSS signals line up, but a few areas are weaker or still incomplete.';
} elseif ($matchPercentage !== null) {
    $matchGuideSummary = 'This is a lower fit score right now because the scholarship and your current record are not aligning strongly yet.';
} else {
    $matchGuideSummary = 'The DSS uses your current academic and profile details to build a fit score for each scholarship.';
}

$matchGuideNote = 'This percentage ranks fit only. Required documents affect whether you can submit, and final approval still depends on provider review.';

$matchScorePositiveReasons = [];
$matchScoreLimitingReasons = [];

if ($requiredGwa !== null) {
    if (!$isLoggedIn) {
        $pushReason($matchScoreLimitingReasons, 'Log in so the system can compare your GWA with the scholarship requirement.');
    } elseif (empty($userGWA)) {
        $pushReason($matchScoreLimitingReasons, 'Your academic record is missing, so the score is only partly estimated right now.');
    } elseif ((float) $userGWA <= (float) $requiredGwa) {
        $pushReason($matchScorePositiveReasons, 'Your GWA is within the required range for this scholarship.');
    } else {
        $pushReason($matchScoreLimitingReasons, 'Your current GWA is above the scholarship limit.');
    }
} else {
    $pushReason($matchScorePositiveReasons, 'This scholarship does not require a fixed GWA cutoff.');
}

if (is_array($coursePathwayCheck)) {
    $courseScoreReasonText = trim((string) ($coursePathwayCheck['detail'] ?? ''));
    $courseScoreStatus = strtolower(trim((string) ($coursePathwayCheck['status'] ?? 'pending')));
    if ($courseScoreStatus === 'met') {
        $pushReason($matchScorePositiveReasons, $courseScoreReasonText);
    } elseif (in_array($courseScoreStatus, ['warn', 'pending'], true)) {
        $pushReason($matchScoreLimitingReasons, $courseScoreReasonText);
    }
}

if (($profileEvaluation['total'] ?? 0) > 0) {
    if (($profileEvaluation['failed'] ?? 0) > 0) {
        $pushReason($matchScoreLimitingReasons, 'Some applicant profile rules do not match this scholarship audience yet.');
    } elseif (($profileEvaluation['pending'] ?? 0) > 0) {
        $pushReason($matchScoreLimitingReasons, 'Some audience-fit details are still missing from your profile.');
    } else {
        $pushReason($matchScorePositiveReasons, 'Your applicant profile matches the target audience rules.');
    }
} else {
    $pushReason($matchScorePositiveReasons, 'The scholarship is open to a broader set of applicants, which helps your fit score.');
}

if ($currentInfoTotal > 0) {
    if ($currentInfoPending > 0) {
        $pushReason($matchScoreLimitingReasons, 'Some current student details are still missing, so the DSS cannot use the full context yet.');
    } elseif ($currentInfoWarn > 0) {
        $pushReason($matchScoreLimitingReasons, 'Some current student signals only partially support this scholarship focus.');
    } elseif ($currentInfoMet > 0) {
        $pushReason($matchScorePositiveReasons, 'Your current student details support this scholarship focus.');
    }
}

if ($applicationNotYetOpen) {
    $pushReason($matchScoreLimitingReasons, 'Applications have not opened yet, so the timing signal is weaker right now.');
} elseif ($deadlineClass === 'bad') {
    $pushReason($matchScoreLimitingReasons, 'The application period has already closed.');
} elseif ($deadlineClass === 'warn') {
    $pushReason($matchScoreLimitingReasons, 'The deadline is close, so the timing signal is smaller.');
} else {
    $pushReason($matchScorePositiveReasons, 'The application window timing supports this recommendation.');
}

if ($recognizedProviderMatch) {
    $pushReason($matchScorePositiveReasons, 'The provider is recognized in the DSS as an established scholarship source.');
} elseif ($providerSignalClass === 'good') {
    $pushReason($matchScorePositiveReasons, 'The provider receives an academic-institution ranking signal in the DSS.');
}

$matchScorePositiveItems = !empty($matchScorePositiveReasons)
    ? array_slice($matchScorePositiveReasons, 0, 4)
    : ['The DSS has not found strong positive scoring signals yet.'];
$matchScoreLimitingItems = !empty($matchScoreLimitingReasons)
    ? array_slice($matchScoreLimitingReasons, 0, 4)
    : ['No major factor is pulling the match score down right now.'];

$matchScoreFactors = [
    [
        'label' => 'Academic fit',
        'value' => $academicDecision,
        'detail' => $academicDetail,
        'class' => $academicClass,
        'icon' => 'fa-chart-line',
    ],
    [
        'label' => 'Course focus',
        'value' => $courseMatchValue,
        'detail' => $courseMatchDetail,
        'class' => $courseMatchClass,
        'icon' => 'fa-graduation-cap',
    ],
    [
        'label' => 'Audience fit',
        'value' => $profileMatchValue,
        'detail' => $profileDetail,
        'class' => $profileMatchClass,
        'icon' => 'fa-user-check',
    ],
    [
        'label' => 'Student context',
        'value' => $studentContextValue,
        'detail' => $currentInfoDetail,
        'class' => $studentContextClass,
        'icon' => 'fa-id-card',
    ],
    [
        'label' => 'Application timing',
        'value' => $deadlineSignalValue,
        'detail' => $deadlineSignalDetail,
        'class' => $deadlineSignalClass,
        'icon' => 'fa-calendar-days',
    ],
    [
        'label' => 'Provider signal',
        'value' => $providerSignalValue,
        'detail' => $providerSignalDetail,
        'class' => $providerSignalClass,
        'icon' => 'fa-building-columns',
    ],
];

$studentFocusItems = !empty($attentionReasons) ? $attentionReasons : $matchedReasons;
$studentFocusTone = !empty($attentionReasons) ? 'warning' : 'positive';
$studentFocusTitle = !empty($attentionReasons) ? 'Why you cannot apply yet' : 'Why you can apply right now';
$studentFocusEmpty = !empty($attentionReasons)
    ? 'No blockers were found. You can continue with the next step above.'
    : 'The system has not confirmed strong fit signals yet.';
$positiveReasonItems = !empty($matchedReasons)
    ? array_slice($matchedReasons, 0, 4)
    : ['The system has not found strong qualifying signals yet.'];
$attentionReasonItems = !empty($attentionReasons)
    ? array_slice($attentionReasons, 0, 4)
    : ['No blocker is stopping you right now.'];
$attentionReasonTone = !empty($attentionReasons) ? 'warning' : 'neutral';
$attentionReasonTitle = !empty($attentionReasons) ? 'These are the blockers right now' : 'Nothing is blocking you right now';
$attentionReasonListClass = !empty($attentionReasons) ? 'warning' : 'positive';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Details - <?php echo htmlspecialchars($scholarship['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/scholarship-details.css')); ?>">
</head>
<body>
<?php include 'layout/header.php'; ?>

<section class="dashboard scholarship-detail-page user-page-shell">
    <div class="container scholarship-detail-shell">
        <div class="scholarship-detail-pagebar">
            <div class="scholarship-detail-pagebar-copy">
                <span class="scholarship-detail-pagebar-kicker">Scholarships</span>
                <h2>Scholarship Details</h2>
                <p>Review the scholarship, your current status, and the next step before you apply.</p>
            </div>
            <a href="scholarships.php" class="scholarship-detail-backlink">
                <i class="fas fa-arrow-left"></i>
                Back to Scholarships
            </a>
        </div>

        <article class="scholarship-hero-card state-<?php echo htmlspecialchars($detailCardStatusClass); ?>">
            <div class="scholarship-hero-media">
                <div class="scholarship-hero-image-wrap">
                    <img
                        src="<?php echo htmlspecialchars($scholarshipImage); ?>"
                        alt="<?php echo htmlspecialchars((string) $scholarship['name']); ?>"
                        class="scholarship-hero-image"
                        onerror="this.src='<?php echo htmlspecialchars($defaultScholarshipImage); ?>'"
                    >
                </div>

                <div class="scholarship-provider-block">
                    <span>Provider</span>
                    <strong><?php echo htmlspecialchars((string) ($scholarship['provider'] ?? 'Provider not specified')); ?></strong>
                    <?php if (!empty($scholarship['location_name'])): ?>
                        <small><?php echo htmlspecialchars((string) $scholarship['location_name']); ?></small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="scholarship-hero-content">
                <div class="scholarship-hero-top">
                    <div class="scholarship-hero-badges">
                        <span class="scholarship-status-badge state-<?php echo htmlspecialchars($detailCardStatusClass); ?>">
                            <i class="fas <?php echo htmlspecialchars($detailStatusIcon); ?>"></i>
                            <?php echo htmlspecialchars($detailCardStatusLabel); ?>
                        </span>
                        <span class="scholarship-match-badge <?php echo htmlspecialchars($badgeClass); ?>">
                            <?php echo htmlspecialchars($matchBadgeText); ?>
                        </span>
                    </div>

                    <div class="scholarship-hero-tools">
                        <button type="button" class="scholarship-rules-trigger" id="detailMatchOpen" aria-haspopup="dialog" aria-controls="detailMatchModal">
                            <i class="fas fa-percent"></i>
                            <?php echo htmlspecialchars($matchGuideButtonLabel); ?>
                        </button>

                        <button type="button" class="scholarship-rules-trigger" id="detailRulesOpen" aria-haspopup="dialog" aria-controls="detailRulesModal">
                            <i class="fas fa-circle-info"></i>
                            Rules Guide
                        </button>
                    </div>
                </div>

                <div class="scholarship-hero-copy">
                    <h1><?php echo htmlspecialchars((string) $scholarship['name']); ?></h1>
                    <p><?php echo htmlspecialchars($descriptionPreview); ?></p>
                </div>

                <div class="scholarship-provider-inline">
                    <span>Application Process</span>
                    <strong><?php echo htmlspecialchars($applicationProcessLabelDisplay); ?></strong>
                    <?php if ($hasAssessment): ?>
                        <em><?php echo htmlspecialchars($assessmentHeadline); ?></em>
                    <?php endif; ?>
                </div>

                <div class="scholarship-fact-grid">
                    <article class="scholarship-fact-card">
                        <span>Minimum GWA</span>
                        <strong><?php echo htmlspecialchars($requiredGwaLabel); ?></strong>
                    </article>
                    <article class="scholarship-fact-card">
                        <span>Deadline</span>
                        <strong><?php echo htmlspecialchars($deadlineDisplay); ?></strong>
                    </article>
                    <article class="scholarship-fact-card">
                        <span>Application Opens</span>
                        <strong><?php echo htmlspecialchars($applicationOpenDateDisplay); ?></strong>
                    </article>
                </div>

                <div class="scholarship-action-note tone-<?php echo htmlspecialchars($nextStepTone); ?>">
                    <strong><?php echo htmlspecialchars($eligibilityStatusTitle); ?></strong>
                    <p><?php echo htmlspecialchars($eligibilityStatusSummary); ?></p>
                </div>

                <div class="scholarship-action-row">
                    <?php if ($primaryActionType === 'button'): ?>
                        <button class="scholarship-btn scholarship-btn-disabled" type="button" disabled>
                            <i class="fas <?php echo htmlspecialchars($primaryActionIcon); ?>"></i>
                            <?php echo htmlspecialchars($primaryActionLabel); ?>
                        </button>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($primaryActionHref); ?>" class="scholarship-btn scholarship-btn-primary">
                            <i class="fas <?php echo htmlspecialchars($primaryActionIcon); ?>"></i>
                            <?php echo htmlspecialchars($primaryActionLabel); ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($visitSiteUrl !== ''): ?>
                        <a href="<?php echo htmlspecialchars($visitSiteUrl); ?>" class="scholarship-btn scholarship-btn-secondary" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-up-right-from-square"></i>
                            Visit Site
                        </a>
                    <?php endif; ?>

                    <?php if ($remoteExamMapUrl !== ''): ?>
                        <a href="<?php echo htmlspecialchars($remoteExamMapUrl); ?>" class="scholarship-btn scholarship-btn-secondary">
                            <i class="fas fa-map-location-dot"></i>
                            View Exam Map
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </article>

        <div class="scholarship-detail-grid">
            <main class="scholarship-detail-main">
                <section class="scholarship-detail-panel">
                    <div class="scholarship-detail-panel-head">
                        <div>
                            <span class="scholarship-detail-eyebrow">Eligibility</span>
                            <h2>Can you apply now?</h2>
                        </div>
                    </div>

                    <div class="scholarship-eligibility-banner tone-<?php echo htmlspecialchars($nextStepTone); ?>">
                        <strong><?php echo htmlspecialchars($eligibilityStatusTitle); ?></strong>
                        <p><?php echo htmlspecialchars($eligibilityStatusSummary); ?></p>
                    </div>

                    <div class="scholarship-check-grid">
                        <?php foreach ($statusChecklistItems as $statusChecklistItem): ?>
                            <article class="scholarship-check-item state-<?php echo htmlspecialchars((string) ($statusChecklistItem['class'] ?? 'info')); ?>">
                                <div class="scholarship-check-icon">
                                    <i class="fas <?php echo htmlspecialchars((string) ($statusChecklistItem['icon'] ?? 'fa-circle-info')); ?>"></i>
                                </div>
                                <div class="scholarship-check-copy">
                                    <span><?php echo htmlspecialchars((string) ($statusChecklistItem['label'] ?? 'Check')); ?></span>
                                    <strong><?php echo htmlspecialchars((string) ($statusChecklistItem['value'] ?? '')); ?></strong>
                                    <p><?php echo htmlspecialchars((string) ($statusChecklistItem['detail'] ?? '')); ?></p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="scholarship-detail-panel">
                    <div class="scholarship-detail-panel-head">
                        <div>
                            <span class="scholarship-detail-eyebrow">Benefits</span>
                            <h2>What you get</h2>
                        </div>
                    </div>

                    <div class="scholarship-text-block">
                        <p><?php echo nl2br(htmlspecialchars($benefitsDisplayText)); ?></p>
                    </div>
                </section>

                <section class="scholarship-detail-panel">
                    <div class="scholarship-detail-panel-head scholarship-detail-panel-head-inline">
                        <div>
                            <span class="scholarship-detail-eyebrow">Documents</span>
                            <h2>What to prepare</h2>
                        </div>
                        <span class="scholarship-summary-badge"><?php echo htmlspecialchars($requirementsSummary); ?></span>
                    </div>

                    <?php if (empty($requiredDocuments)): ?>
                        <div class="scholarship-empty-note">No required documents are listed for this scholarship right now.</div>
                    <?php else: ?>
                        <div class="scholarship-doc-list">
                            <?php foreach ($requiredDocuments as $doc): ?>
                                <?php
                                $docName = $doc['name'] ?? $doc['document_type'];
                                $docStatus = 'missing';
                                $statusLabel = $isLoggedIn ? 'Missing' : 'Login to track';
                                $docDetail = trim((string) ($doc['description'] ?? ''));
                                if ($isLoggedIn) {
                                    foreach (($documentSummary['requirements'] ?? []) as $req) {
                                        if (($req['type'] ?? '') === ($doc['document_type'] ?? '')) {
                                            $rawStatus = strtolower((string) ($req['status'] ?? 'missing'));
                                            $statusLabel = ucfirst($rawStatus);
                                            if (in_array($rawStatus, ['verified', 'pending', 'missing', 'rejected'], true)) {
                                                $docStatus = $rawStatus;
                                            }
                                            break;
                                        }
                                    }
                                }
                                if ($docDetail === '') {
                                    $docDetail = 'Check or manage this file in your documents page.';
                                }
                                ?>
                                <a class="scholarship-doc-item state-<?php echo htmlspecialchars($docStatus); ?>" href="documents.php#doc-card-<?php echo htmlspecialchars((string) $doc['document_type']); ?>">
                                    <div class="scholarship-doc-main">
                                        <strong><?php echo htmlspecialchars((string) $docName); ?></strong>
                                        <p><?php echo htmlspecialchars($docDetail); ?></p>
                                    </div>
                                    <span class="scholarship-doc-status"><?php echo htmlspecialchars($statusLabel); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </main>

            <aside class="scholarship-detail-side">
                <section class="scholarship-detail-panel">
                    <div class="scholarship-detail-panel-head">
                        <div>
                            <span class="scholarship-detail-eyebrow">Your Result</span>
                            <h2>Why it fits and what needs attention</h2>
                        </div>
                    </div>

                    <div class="scholarship-reason-stack">
                        <div class="scholarship-reason-block">
                            <h3>Why it fits</h3>
                            <ul class="scholarship-bullet-list tone-positive">
                                <?php foreach ($positiveReasonItems as $positiveReasonItem): ?>
                                    <li><?php echo htmlspecialchars($positiveReasonItem); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="scholarship-reason-block scholarship-reason-block-warning">
                            <h3>What needs attention</h3>
                            <ul class="scholarship-bullet-list tone-warning">
                                <?php foreach ($attentionReasonItems as $attentionReasonItem): ?>
                                    <li><?php echo htmlspecialchars($attentionReasonItem); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </section>

                <section class="scholarship-detail-panel">
                    <div class="scholarship-detail-panel-head">
                        <div>
                            <span class="scholarship-detail-eyebrow">Process</span>
                            <h2>How the application works</h2>
                        </div>
                    </div>

                    <div class="scholarship-info-stack">
                        <?php foreach ($applicationGuideItems as $applicationGuideItem): ?>
                            <article class="scholarship-info-item">
                                <span><?php echo htmlspecialchars((string) ($applicationGuideItem['label'] ?? '')); ?></span>
                                <strong><?php echo htmlspecialchars((string) ($applicationGuideItem['value'] ?? '')); ?></strong>
                                <p><?php echo htmlspecialchars((string) ($applicationGuideItem['detail'] ?? '')); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

            </aside>
        </div>

        <div class="scholarship-rules-modal" id="detailMatchModal" hidden>
            <div class="scholarship-rules-backdrop" data-close-detail-match></div>
            <div class="scholarship-rules-dialog" role="dialog" aria-modal="true" aria-labelledby="detailMatchTitle">
                <div class="scholarship-rules-header">
                    <div>
                        <span class="scholarship-detail-eyebrow">Match Guide</span>
                        <h2 id="detailMatchTitle"><?php echo htmlspecialchars($matchGuideTitle); ?></h2>
                    </div>
                    <button type="button" class="scholarship-rules-close" data-close-detail-match aria-label="Close match guide">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <p class="scholarship-rules-copy"><?php echo htmlspecialchars($matchGuideSummary); ?></p>

                <div class="scholarship-text-block scholarship-match-note">
                    <p><?php echo htmlspecialchars($matchGuideNote); ?></p>
                </div>

                <div class="scholarship-check-grid scholarship-match-factor-grid">
                    <?php foreach ($matchScoreFactors as $matchScoreFactor): ?>
                        <article class="scholarship-check-item state-<?php echo htmlspecialchars((string) ($matchScoreFactor['class'] ?? 'info')); ?>">
                            <div class="scholarship-check-icon">
                                <i class="fas <?php echo htmlspecialchars((string) ($matchScoreFactor['icon'] ?? 'fa-circle-info')); ?>"></i>
                            </div>
                            <div class="scholarship-check-copy">
                                <span><?php echo htmlspecialchars((string) ($matchScoreFactor['label'] ?? 'Factor')); ?></span>
                                <strong><?php echo htmlspecialchars((string) ($matchScoreFactor['value'] ?? '')); ?></strong>
                                <p><?php echo htmlspecialchars((string) ($matchScoreFactor['detail'] ?? '')); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="scholarship-rules-grid">
                    <section class="scholarship-rules-card">
                        <h3>Main reasons helping this score</h3>
                        <ul class="scholarship-bullet-list tone-positive">
                            <?php foreach ($matchScorePositiveItems as $matchScorePositiveItem): ?>
                                <li><?php echo htmlspecialchars($matchScorePositiveItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <section class="scholarship-rules-card scholarship-rules-card-warning">
                        <h3>What is limiting the score right now</h3>
                        <ul class="scholarship-bullet-list tone-warning">
                            <?php foreach ($matchScoreLimitingItems as $matchScoreLimitingItem): ?>
                                <li><?php echo htmlspecialchars($matchScoreLimitingItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                </div>
            </div>
        </div>

        <div class="scholarship-rules-modal" id="detailRulesModal" hidden>
            <div class="scholarship-rules-backdrop" data-close-detail-rules></div>
            <div class="scholarship-rules-dialog" role="dialog" aria-modal="true" aria-labelledby="detailRulesTitle">
                <div class="scholarship-rules-header">
                    <div>
                        <span class="scholarship-detail-eyebrow">Rules Guide</span>
                        <h2 id="detailRulesTitle">How the system decides if you can apply</h2>
                    </div>
                    <button type="button" class="scholarship-rules-close" data-close-detail-rules aria-label="Close rules guide">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <p class="scholarship-rules-copy"><?php echo htmlspecialchars($rulesInfoNote); ?></p>

                <div class="scholarship-rules-grid">
                    <section class="scholarship-rules-card">
                        <h3>You can apply when</h3>
                        <ul class="scholarship-bullet-list tone-positive">
                            <?php foreach ($canApplyRuleItems as $canApplyRuleItem): ?>
                                <li><?php echo htmlspecialchars($canApplyRuleItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <section class="scholarship-rules-card scholarship-rules-card-warning">
                        <h3>You cannot apply yet when</h3>
                        <ul class="scholarship-bullet-list tone-warning">
                            <?php foreach ($cantApplyRuleItems as $cantApplyRuleItem): ?>
                                <li><?php echo htmlspecialchars($cantApplyRuleItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'layout/footer.php'; ?>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/script.js')); ?>"></script>
<script>
(function () {
    const modalConfigs = [
        {
            modalId: 'detailMatchModal',
            openId: 'detailMatchOpen',
            closeSelector: '[data-close-detail-match]'
        },
        {
            modalId: 'detailRulesModal',
            openId: 'detailRulesOpen',
            closeSelector: '[data-close-detail-rules]'
        }
    ];

    const allModals = modalConfigs
        .map(function (config) {
            return document.getElementById(config.modalId);
        })
        .filter(Boolean);

    function syncBodyState() {
        const hasOpenModal = allModals.some(function (modal) {
            return !modal.hidden;
        });

        document.body.classList.toggle('scholarship-detail-modal-open', hasOpenModal);
    }

    function closeModal(modal) {
        if (!modal) {
            return;
        }
        modal.hidden = true;
        syncBodyState();
    }

    function openModal(targetModal) {
        allModals.forEach(function (modal) {
            modal.hidden = modal !== targetModal;
        });
        syncBodyState();
    }

    modalConfigs.forEach(function (config) {
        const modal = document.getElementById(config.modalId);
        const openButton = document.getElementById(config.openId);
        if (!modal || !openButton) {
            return;
        }

        const closeElements = modal.querySelectorAll(config.closeSelector);

        openButton.addEventListener('click', function () {
            openModal(modal);
        });

        closeElements.forEach(function (element) {
            element.addEventListener('click', function () {
                closeModal(modal);
            });
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            allModals.forEach(function (modal) {
                modal.hidden = true;
            });
            syncBodyState();
        }
    });
})();
</script>
</body>
</html>
