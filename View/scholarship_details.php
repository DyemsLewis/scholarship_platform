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

$assessmentLabel = 'None';
if ($assessmentRequirement === 'online_exam') $assessmentLabel = 'Online Exam';
if ($assessmentRequirement === 'remote_examination') $assessmentLabel = 'Remote Examination';
if ($assessmentRequirement === 'assessment') $assessmentLabel = 'Online Assessment';
if ($assessmentRequirement === 'evaluation') $assessmentLabel = 'Online Evaluation';

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

$resultsReleaseValue = 'Not specified';
$resultsReleaseDetail = 'The provider did not list a result release date in this posting.';

$applicationEffortValue = 'Standard';
$applicationEffortDetail = 'This is based on the listed documents and whether an extra assessment step is required.';
if (!$hasAssessment && $requirementsCount <= 2) {
    $applicationEffortValue = 'Simple';
} elseif ($hasAssessment || $requirementsCount >= 5) {
    $applicationEffortValue = 'More steps';
}

$timelineItems = [
    [
        'label' => 'Application deadline',
        'value' => $deadlineDisplay,
        'detail' => $deadlineClass === 'bad'
            ? 'This scholarship is already closed.'
            : ($deadlineClass === 'warn'
                ? 'Apply as soon as possible because the deadline is close.'
                : 'This is the current deadline shown by the provider.'),
    ],
    [
        'label' => 'Assessment or interview',
        'value' => $hasAssessment ? $assessmentLabel : 'No extra step listed',
        'detail' => $hasAssessment
            ? $assessmentSummary
            : 'The posting does not list an exam, interview, or extra assessment step.',
    ],
    [
        'label' => 'Application effort',
        'value' => $applicationEffortValue,
        'detail' => $applicationEffortDetail,
    ],
    [
        'label' => 'Result release',
        'value' => $resultsReleaseValue,
        'detail' => $resultsReleaseDetail,
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

$providerWebsiteLabel = 'Not listed';
$providerWebsiteDetail = 'No official website is linked in this scholarship posting.';
if ($visitSiteUrl !== '') {
    $providerWebsiteHost = parse_url($visitSiteUrl, PHP_URL_HOST);
    $providerWebsiteLabel = $providerWebsiteHost ? (string) $providerWebsiteHost : 'Official website available';
    $providerWebsiteDetail = 'You can open the provider site from the action buttons above.';
}

$providerInfoItems = [
    [
        'label' => 'Scholarship provider',
        'value' => (string) ($scholarship['provider'] ?? 'Provider not specified'),
        'detail' => 'Students usually check the provider to see if the scholarship is legitimate and familiar.',
    ],
    [
        'label' => 'Official website',
        'value' => $providerWebsiteLabel,
        'detail' => $providerWebsiteDetail,
    ],
    [
        'label' => 'Slots or acceptance rate',
        'value' => 'Not specified',
        'detail' => 'The provider did not list how many students will be accepted.',
    ],
    [
        'label' => 'Renewal or return service',
        'value' => 'Check with provider',
        'detail' => 'Renewal rules, maintaining grades, or return-service obligations are not listed here.',
    ],
];

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

$readyToApplyNow = $isLoggedIn && $isEligible && $hasAllRequired && !$requiresGwa && $deadlineClass !== 'bad';
$detailCardStatusClass = 'ineligible';
$detailCardStatusLabel = 'Not Eligible';
if ($deadlineClass === 'bad') {
    $detailCardStatusClass = 'expired';
    $detailCardStatusLabel = 'Expired';
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
    $nextStepMessage = 'Upload your grades to complete the academic requirement check.';
    $primaryActionHref = 'upload.php';
    $primaryActionClass = 'btn-warning-modern';
    $primaryActionIcon = 'fa-upload';
    $primaryActionLabel = 'Upload Grades';
} elseif ($profileRequirementPending > 0) {
    $nextStepTone = 'warning';
    $nextStepIcon = 'fa-user-gear';
    $nextStepMessage = 'Complete your applicant profile so the system can finish the scholarship policy check.';
    $primaryActionHref = 'profile.php';
    $primaryActionClass = 'btn-warning-modern';
    $primaryActionIcon = 'fa-user-pen';
    $primaryActionLabel = 'Complete Profile';
} elseif ($profileRequirementFailed > 0) {
    $nextStepTone = 'warning';
    $nextStepIcon = 'fa-user-xmark';
    $nextStepMessage = 'Your current applicant profile does not yet match the target audience for this scholarship.';
    $primaryActionHref = 'profile.php';
    $primaryActionClass = 'btn-outline-modern';
    $primaryActionIcon = 'fa-user-pen';
    $primaryActionLabel = 'Review Profile';
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
    $nextStepMessage = 'Upload the remaining required documents before you submit your application.';
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
        'label' => 'Deadline',
        'value' => $deadlineDecision,
        'detail' => $deadlineDetail,
        'class' => $deadlineClass,
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
    $deadlineClass === 'bad'
        ? 'This scholarship is already closed.'
        : 'The application deadline must still be open.',
];

$cantApplyRuleItems = [
    $requiredGwa !== null
        ? 'You cannot apply yet if your GWA is missing or above ' . $requiredGwaLabel . '.'
        : 'GWA alone does not block this scholarship, but other checks can still do so.',
    'You cannot apply yet if your applicant profile is incomplete or does not match the scholarship audience.',
    $requirementsCount > 0
        ? 'You cannot apply yet if required files are missing, rejected, or still waiting for review.'
        : 'If the provider adds required documents later, those will need to be completed before submission.',
    !empty($scholarship['deadline'])
        ? 'You cannot apply after ' . $deadlineDisplay . '.'
        : 'There is no fixed closing date listed right now, but the provider can still update the posting.',
];

$rulesInfoNote = 'These rules explain system readiness only. Final approval still depends on the provider review process.';
$studentFocusItems = !empty($attentionReasons) ? $attentionReasons : $matchedReasons;
$studentFocusTone = !empty($attentionReasons) ? 'warning' : 'positive';
$studentFocusTitle = !empty($attentionReasons) ? 'What to fix first' : 'Why you can continue';
$studentFocusEmpty = !empty($attentionReasons)
    ? 'No blockers were found. You can continue with the next step above.'
    : 'The system has not confirmed strong fit signals yet.';
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
    <div class="container scholarship-detail-container">
        <div class="scholarship-detail-backbar">
            <a href="scholarships.php" class="scholarship-detail-backlink">
                <i class="fas fa-arrow-left"></i>
                Back to Scholarship Board
            </a>
        </div>

        <section class="scholarship-detail-cardview">
            <article class="detail-hero-card state-<?php echo htmlspecialchars($detailCardStatusClass); ?>">
                <div class="detail-hero-grid">
                    <div class="detail-hero-media">
                        <div class="detail-media-kicker">
                            <span class="detail-media-pill">
                                <i class="fas fa-circle-info"></i>
                                Scholarship Details
                            </span>
                            <span class="detail-match-pill <?php echo htmlspecialchars($badgeClass); ?>">
                                <?php echo htmlspecialchars($matchBadgeText); ?>
                            </span>
                        </div>

                        <div class="detail-media-stage">
                            <img src="<?php echo htmlspecialchars($scholarshipImage); ?>"
                                 alt="<?php echo htmlspecialchars((string) $scholarship['name']); ?>"
                                 class="detail-media-image"
                                 onerror="this.src='<?php echo htmlspecialchars($defaultScholarshipImage); ?>'">
                        </div>

                        <div class="detail-media-support">
                            <span class="detail-support-chip">
                                <i class="fas fa-location-dot"></i>
                                <?php echo htmlspecialchars($scholarship['location_name'] ?? 'Location not specified'); ?>
                            </span>
                            <span class="detail-support-chip">
                                <i class="fas fa-file-circle-check"></i>
                                <?php echo htmlspecialchars($requirementsBadgeText); ?>
                            </span>
                        </div>
                    </div>

                    <div class="detail-hero-content">
                        <div class="detail-hero-topline">
                            <div class="detail-provider-copy">
                                <div class="detail-provider-name"><?php echo htmlspecialchars($scholarship['provider'] ?? 'Provider not specified'); ?></div>
                                <div class="detail-provider-audience"><?php echo htmlspecialchars($audienceLabel); ?></div>
                            </div>

                            <span class="detail-status-pill <?php echo htmlspecialchars($detailCardStatusClass); ?>">
                                <i class="fas fa-<?php echo $detailCardStatusClass === 'expired' ? 'clock' : ($detailCardStatusClass === 'ready' ? 'check-circle' : ($detailCardStatusClass === 'estimated' ? 'chart-line' : ($detailCardStatusClass === 'docs' ? 'file-circle-exclamation' : ($detailCardStatusClass === 'profile' ? 'user-gear' : 'ban')))); ?>"></i>
                                <?php echo htmlspecialchars($detailCardStatusLabel); ?>
                            </span>
                        </div>

                        <h1 class="detail-page-title"><?php echo htmlspecialchars((string) $scholarship['name']); ?></h1>
                        <p class="detail-page-summary"><?php echo htmlspecialchars($descriptionPreview); ?></p>

                        <div class="detail-chip-grid">
                            <div class="detail-chip-card">
                                <span>Minimum GWA</span>
                                <strong><?php echo htmlspecialchars($requiredGwaLabel); ?></strong>
                            </div>
                            <div class="detail-chip-card">
                                <span>Deadline</span>
                                <strong><?php echo htmlspecialchars($deadlineDisplay); ?></strong>
                            </div>
                            <div class="detail-chip-card">
                                <span>Audience</span>
                                <strong><?php echo htmlspecialchars($audienceLabel); ?></strong>
                            </div>
                            <div class="detail-chip-card">
                                <span>Documents</span>
                                <strong><?php echo htmlspecialchars($requirementsSummary); ?></strong>
                            </div>
                        </div>

                        <div class="detail-hero-answer is-<?php echo htmlspecialchars($nextStepTone); ?>">
                            <div class="detail-hero-answer-copy">
                                <strong><?php echo htmlspecialchars($eligibilityStatusTitle); ?></strong>
                                <p><?php echo htmlspecialchars($eligibilityStatusSummary); ?></p>
                            </div>
                        </div>

                        <div class="detail-action-row">
                            <?php if ($primaryActionType === 'button'): ?>
                                <button class="<?php echo htmlspecialchars($primaryActionClass); ?>" type="button" disabled>
                                    <i class="fas <?php echo htmlspecialchars($primaryActionIcon); ?>"></i>
                                    <?php echo htmlspecialchars($primaryActionLabel); ?>
                                </button>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($primaryActionHref); ?>" class="<?php echo htmlspecialchars($primaryActionClass); ?>">
                                    <i class="fas <?php echo htmlspecialchars($primaryActionIcon); ?>"></i>
                                    <?php echo htmlspecialchars($primaryActionLabel); ?>
                                </a>
                            <?php endif; ?>

                            <?php if ($visitSiteUrl !== ''): ?>
                                <a href="<?php echo htmlspecialchars($visitSiteUrl); ?>" class="btn-outline-modern" target="_blank" rel="noopener noreferrer">
                                    <i class="fas fa-up-right-from-square"></i>
                                    Visit Site
                                </a>
                            <?php endif; ?>

                            <?php if ($remoteExamMapUrl !== ''): ?>
                                <a href="<?php echo htmlspecialchars($remoteExamMapUrl); ?>" class="btn-outline-modern">
                                    <i class="fas fa-map-location-dot"></i>
                                    View Exam Map
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </article>
        </section>

        <div class="detail-page-stack">
            <section class="detail-panel detail-panel-highlight">
                <div class="detail-panel-header detail-panel-header-with-action">
                    <div>
                        <span class="detail-panel-kicker">Your result</span>
                        <h2>Can you apply right now?</h2>
                        <p>This section explains the current result and the main checks the system uses before you apply.</p>
                    </div>
                    <button type="button" class="detail-info-trigger" id="detailRulesOpen" aria-haspopup="dialog" aria-controls="detailRulesModal">
                        <i class="fas fa-circle-info"></i>
                        How this is decided
                    </button>
                </div>

                <div class="detail-checklist-grid">
                    <?php foreach ($statusChecklistItems as $statusChecklistItem): ?>
                        <article class="detail-check-item is-<?php echo htmlspecialchars((string) ($statusChecklistItem['class'] ?? 'info')); ?>">
                            <div class="detail-check-icon">
                                <i class="fas <?php echo htmlspecialchars((string) ($statusChecklistItem['icon'] ?? 'fa-circle-info')); ?>"></i>
                            </div>
                            <div class="detail-check-copy">
                                <span><?php echo htmlspecialchars((string) ($statusChecklistItem['label'] ?? 'Check')); ?></span>
                                <strong><?php echo htmlspecialchars((string) ($statusChecklistItem['value'] ?? '')); ?></strong>
                                <p><?php echo htmlspecialchars((string) ($statusChecklistItem['detail'] ?? '')); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <article class="detail-reason-card is-<?php echo htmlspecialchars($studentFocusTone); ?>">
                    <h3><?php echo htmlspecialchars($studentFocusTitle); ?></h3>
                    <?php if (!empty($studentFocusItems)): ?>
                        <ul class="detail-bullet-list is-<?php echo htmlspecialchars($studentFocusTone); ?>">
                            <?php foreach ($studentFocusItems as $studentFocusItem): ?>
                                <li><?php echo htmlspecialchars($studentFocusItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="detail-empty-copy"><?php echo htmlspecialchars($studentFocusEmpty); ?></p>
                    <?php endif; ?>
                </article>
            </section>

            <details class="detail-accordion" open>
                <summary class="detail-accordion-summary">
                    <span>
                        <span class="detail-panel-kicker">Overview</span>
                        <strong>Scholarship overview and benefits</strong>
                    </span>
                    <i class="fas fa-chevron-down"></i>
                </summary>
                <div class="detail-accordion-body">
                    <div class="detail-split-grid">
                        <div class="detail-content-block">
                            <div class="detail-copy-block">
                                <p><?php echo nl2br(htmlspecialchars($descriptionFullText)); ?></p>
                            </div>
                            <?php if ($hasEligibilityNotes): ?>
                                <div class="detail-note-inline">
                                    <span>Provider notes on eligibility</span>
                                    <p><?php echo nl2br(htmlspecialchars($eligibilityNotesText)); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="detail-content-block">
                            <div class="detail-copy-block">
                                <p><?php echo nl2br(htmlspecialchars($benefitsDisplayText)); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </details>

            <details class="detail-accordion">
                <summary class="detail-accordion-summary">
                    <span>
                        <span class="detail-panel-kicker">Requirements</span>
                        <strong>Documents you need</strong>
                    </span>
                    <span class="detail-summary-pill">
                        <i class="fas fa-file-circle-check"></i>
                        <?php echo htmlspecialchars($requirementsSummary); ?>
                    </span>
                </summary>
                <div class="detail-accordion-body">
                    <?php if (empty($requiredDocuments)): ?>
                        <div class="detail-empty-state">No required documents were configured for this scholarship yet.</div>
                    <?php else: ?>
                        <div class="detail-requirements-grid">
                            <?php foreach ($requiredDocuments as $doc): ?>
                                <?php
                                $docName = $doc['name'] ?? $doc['document_type'];
                                $docStatus = 'missing';
                                $statusLabel = $isLoggedIn ? 'Missing' : 'Login to track';
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
                                $docHelper = !empty($doc['description'])
                                    ? (string) $doc['description']
                                    : 'Upload this file to complete the scholarship requirement.';
                                ?>
                                <a class="detail-requirement-card <?php echo htmlspecialchars($docStatus); ?>" href="documents.php#doc-card-<?php echo htmlspecialchars((string) $doc['document_type']); ?>">
                                    <div class="detail-requirement-icon">
                                        <i class="fas <?php echo $docStatus === 'verified' ? 'fa-check-circle' : ($docStatus === 'pending' ? 'fa-clock' : ($docStatus === 'rejected' ? 'fa-triangle-exclamation' : 'fa-file-circle-plus')); ?>"></i>
                                    </div>
                                    <div class="detail-requirement-copy">
                                        <strong><?php echo htmlspecialchars((string) $docName); ?></strong>
                                        <span><?php echo htmlspecialchars($docHelper); ?></span>
                                        <em><?php echo htmlspecialchars($statusLabel); ?></em>
                                    </div>
                                    <span class="detail-requirement-link">Open</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </details>

            <details class="detail-accordion">
                <summary class="detail-accordion-summary">
                    <span>
                        <span class="detail-panel-kicker">Process</span>
                        <strong>Timeline and provider notes</strong>
                    </span>
                    <i class="fas fa-chevron-down"></i>
                </summary>
                <div class="detail-accordion-body">
                    <div class="detail-split-grid detail-split-grid-bottom">
                        <div class="detail-content-block">
                            <div class="detail-process-list">
                                <?php foreach ($timelineItems as $timelineItem): ?>
                                    <article class="detail-process-item">
                                        <div class="detail-process-label"><?php echo htmlspecialchars((string) ($timelineItem['label'] ?? 'Step')); ?></div>
                                        <div class="detail-process-body">
                                            <strong><?php echo htmlspecialchars((string) ($timelineItem['value'] ?? '')); ?></strong>
                                            <p><?php echo htmlspecialchars((string) ($timelineItem['detail'] ?? '')); ?></p>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <?php if (!empty($scholarship['assessment_details'])): ?>
                                <div class="detail-note-inline">
                                    <span>Provider instructions</span>
                                    <p><?php echo nl2br(htmlspecialchars((string) $scholarship['assessment_details'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="detail-content-block">
                            <div class="detail-info-list">
                                <?php foreach ($providerInfoItems as $providerInfoItem): ?>
                                    <article class="detail-info-item">
                                        <span><?php echo htmlspecialchars((string) ($providerInfoItem['label'] ?? 'Info')); ?></span>
                                        <strong><?php echo htmlspecialchars((string) ($providerInfoItem['value'] ?? '')); ?></strong>
                                        <p><?php echo htmlspecialchars((string) ($providerInfoItem['detail'] ?? '')); ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="detail-note-inline">
                                <span>Helpful reminder</span>
                                <p>Competition level, renewal conditions, result release dates, and return-service obligations should still be confirmed directly with the provider because they are not fully listed in this posting.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </details>
        </div>

        <div class="detail-info-modal" id="detailRulesModal" hidden>
            <div class="detail-info-backdrop" data-close-detail-rules></div>
            <div class="detail-info-dialog" role="dialog" aria-modal="true" aria-labelledby="detailRulesTitle">
                <div class="detail-info-dialog-header">
                    <div>
                        <span class="detail-panel-kicker">System guide</span>
                        <h2 id="detailRulesTitle">How the system decides if you can apply</h2>
                    </div>
                    <button type="button" class="detail-info-close" data-close-detail-rules aria-label="Close information panel">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <p class="detail-info-copy">This explains the system checks only. Final approval still depends on the provider review process.</p>

                <div class="detail-info-current">
                    <span class="detail-status-pill <?php echo htmlspecialchars($detailCardStatusClass); ?>">
                        <i class="fas fa-<?php echo $detailCardStatusClass === 'expired' ? 'clock' : ($detailCardStatusClass === 'ready' ? 'check-circle' : ($detailCardStatusClass === 'estimated' ? 'chart-line' : ($detailCardStatusClass === 'docs' ? 'file-circle-exclamation' : ($detailCardStatusClass === 'profile' ? 'user-gear' : 'ban')))); ?>"></i>
                        <?php echo htmlspecialchars($detailCardStatusLabel); ?>
                    </span>
                    <p><?php echo htmlspecialchars($eligibilityStatusSummary); ?></p>
                </div>

                <div class="detail-info-rule-grid">
                    <section class="detail-info-rule-card is-positive">
                        <h3>You can apply when</h3>
                        <ul class="detail-bullet-list is-positive">
                            <?php foreach ($canApplyRuleItems as $canApplyRuleItem): ?>
                                <li><?php echo htmlspecialchars($canApplyRuleItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>

                    <section class="detail-info-rule-card is-warning">
                        <h3>You cannot apply yet when</h3>
                        <ul class="detail-bullet-list is-warning">
                            <?php foreach ($cantApplyRuleItems as $cantApplyRuleItem): ?>
                                <li><?php echo htmlspecialchars($cantApplyRuleItem); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                </div>

                <div class="detail-note-inline detail-note-inline-tight">
                    <span>Important note</span>
                    <p><?php echo htmlspecialchars($rulesInfoNote); ?></p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'layout/footer.php'; ?>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/script.js')); ?>"></script>
<script>
(function () {
    const modal = document.getElementById('detailRulesModal');
    const openButton = document.getElementById('detailRulesOpen');
    if (!modal || !openButton) {
        return;
    }

    const closeElements = modal.querySelectorAll('[data-close-detail-rules]');

    function openModal() {
        modal.hidden = false;
        document.body.classList.add('detail-modal-open');
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('detail-modal-open');
    }

    openButton.addEventListener('click', openModal);
    closeElements.forEach(function (element) {
        element.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
})();
</script>
</body>
</html>





