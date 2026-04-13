<?php
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Controllers/scholarshipResultController.php';
require_once __DIR__ . '/../app/Models/Application.php';
require_once __DIR__ . '/../app/Models/UserDocument.php';

$scholarshipsPageCssVersion = @filemtime(__DIR__ . '/../public/css/scholarships-page.css') ?: time();

if (!function_exists('normalizeScannerNoticeCopy')) {
    function normalizeScannerNoticeCopy(string $value): string
    {
        return str_replace(
            ['Remote OCR API', 'OCR.space', 'OCR API', 'OCR'],
            ['remote scanner service', 'scanner service', 'scanner API', 'scanner'],
            $value
        );
    }
}

if (!$isLoggedIn) {
    $_SESSION['error'] = 'Please log in first to view the scholarship board.';
    redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Finder - Scholarship Board</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/filter.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/resultmap.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/card-pagination.css')); ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/scholarships-page.css') . '?v=' . rawurlencode((string) $scholarshipsPageCssVersion)); ?>">
</head>
<body>
    <!-- Header -->
    <?php 
    include 'layout/header.php'; 

    // Initialize services
    $scholarshipService = new ScholarshipService($pdo);
    $documentModel = new UserDocument($pdo);
    $applicationModel = $isLoggedIn ? new Application($pdo) : null;
    
    // Get user's documents for verification
    $userDocuments = [];
    $userDocStatus = [];
    $userAcademicDocumentStatus = 'missing';
    if ($isLoggedIn) {
        $userDocs = $documentModel->getUserDocuments($_SESSION['user_id']);
        foreach ($userDocs as $doc) {
            $userDocStatus[$doc['document_type']] = $doc['status'];
        }
        $userAcademicDocumentStatus = resolveApplicantAcademicDocumentStatus($userApplicantType, $userDocStatus);
    }

    $matchedScholarships = [];
    $matchedCount = 0;
    $eligibleScholarshipsCount = 0;
    $acceptedScholarshipSummary = null;
    $userAppliedScholarships = [];
    $documentVerifiedCount = count(array_filter($userDocStatus, function($status) {
        return $status === 'verified';
    }));
    $documentPendingCount = count(array_filter($userDocStatus, function($status) {
        return $status === 'pending';
    }));
    $userHasLocation = !empty($userLatitude) && !empty($userLongitude);

    $uploadNoticeType = '';
    $uploadNoticeMessage = '';
    $uploadNoticeTitle = isset($_SESSION['upload_notice_title']) && trim((string) $_SESSION['upload_notice_title']) !== ''
        ? (string) $_SESSION['upload_notice_title']
        : 'TOR Scanner Result';
    if (!empty($_SESSION['upload_success']) && !empty($_SESSION['message'])) {
        $uploadNoticeType = 'success';
        $uploadNoticeMessage = (string) $_SESSION['message'];
        unset($_SESSION['upload_success'], $_SESSION['message'], $_SESSION['upload_notice_title']);
    } elseif (!empty($_SESSION['upload_error'])) {
        $uploadNoticeType = 'error';
        $uploadNoticeMessage = (string) $_SESSION['upload_error'];
        unset($_SESSION['upload_error'], $_SESSION['upload_notice_title']);
    }

    $uploadNoticeTitle = normalizeScannerNoticeCopy($uploadNoticeTitle);
    $uploadNoticeMessage = normalizeScannerNoticeCopy($uploadNoticeMessage);
    $boardAcademicDocumentState = describeAcademicDocumentStatus($userAcademicDocumentStatus, $userAcademicDocumentLabel, $userAcademicMetricLabel);

    if ($isLoggedIn) {
        $acceptedScholarshipSummary = $applicationModel ? $applicationModel->getAcceptedScholarshipSummary((int) $_SESSION['user_id']) : null;
        if ($applicationModel) {
            foreach ($applicationModel->getByUser((int) $_SESSION['user_id']) as $applicationRow) {
                $appliedScholarshipId = (int) ($applicationRow['scholarship_id'] ?? 0);
                if ($appliedScholarshipId <= 0 || isset($userAppliedScholarships[$appliedScholarshipId])) {
                    continue;
                }

                $userAppliedScholarships[$appliedScholarshipId] = $applicationRow;
            }
        }
        $matchedScholarships = $scholarshipService->getMatchedScholarships(
            $userAcademicScore,
            $userCourse ?: $userTargetCourse,
            $userLatitude,
            $userLongitude,
            [
                'applicant_type' => $userApplicantType,
                'year_level' => $userYearLevel,
                'admission_status' => $userAdmissionStatus,
                'shs_strand' => $userShsStrand,
                'shs_average' => $userShsAverage,
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
        usort($matchedScholarships, function($a, $b) {
            $aExpired = !empty($a['is_expired']);
            $bExpired = !empty($b['is_expired']);

            if ($aExpired !== $bExpired) {
                return $aExpired <=> $bExpired;
            }

            return ($b['match_percentage'] ?? 0) <=> ($a['match_percentage'] ?? 0);
        });
        $matchedCount = count($matchedScholarships);
        $eligibleScholarshipsCount = count(array_filter($matchedScholarships, function($scholarship) use ($acceptedScholarshipSummary) {
            if (empty($scholarship['is_eligible'])) {
                return false;
            }

            $allowsAcceptedScholarshipApplicants = (int) ($scholarship['allow_if_already_accepted'] ?? 1) === 1;
            if ($allowsAcceptedScholarshipApplicants || $acceptedScholarshipSummary === null) {
                return true;
            }

            return (int) ($acceptedScholarshipSummary['scholarship_id'] ?? 0) === (int) ($scholarship['id'] ?? 0);
        }));
    }

    $pushReason = static function (array &$reasons, string $reason, int $limit = 3): void {
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

    $buildProfileMismatchSummary = static function (array $checks): string {
        $failedChecks = array_values(array_filter($checks, static function (array $check): bool {
            return strtolower(trim((string) ($check['status'] ?? 'pending'))) === 'failed';
        }));

        if (empty($failedChecks)) {
            return 'Some of your current applicant details do not match this scholarship requirement.';
        }

        usort($failedChecks, static function (array $left, array $right): int {
            $leftPriority = strtolower(trim((string) ($left['key'] ?? ''))) === 'applicant_type' ? 0 : 1;
            $rightPriority = strtolower(trim((string) ($right['key'] ?? ''))) === 'applicant_type' ? 0 : 1;
            return $leftPriority <=> $rightPriority;
        });

        $failedCheck = $failedChecks[0];
        $key = strtolower(trim((string) ($failedCheck['key'] ?? '')));
        $label = trim((string) ($failedCheck['label'] ?? 'Profile'));
        $target = trim((string) ($failedCheck['target'] ?? ''));

        if ($key === 'applicant_type' && $target !== '') {
            return 'Your current applicant type does not match this scholarship. Required applicant type: ' . $target . '.';
        }

        if ($label !== '' && $target !== '') {
            return 'Your current ' . strtolower($label) . ' does not match this scholarship requirement. Required ' . strtolower($label) . ': ' . $target . '.';
        }

        return 'Some of your current applicant details do not match this scholarship requirement.';
    };
    ?>
    
    <!-- Scholarships Section -->
    <section class="dashboard scholarships-page user-page-shell">
        <div class="container">
            <?php if ($uploadNoticeMessage !== ''): ?>
                <div style="margin-bottom: 16px; padding: 12px 14px; border-radius: 10px; background: <?php echo $uploadNoticeType === 'success' ? '#ecfdf5' : '#fef2f2'; ?>; border: 1px solid <?php echo $uploadNoticeType === 'success' ? '#86efac' : '#fecaca'; ?>; color: <?php echo $uploadNoticeType === 'success' ? '#166534' : '#b91c1c'; ?>; font-size: 0.9rem;">
                    <i class="fas <?php echo $uploadNoticeType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($uploadNoticeMessage); ?>
                </div>
            <?php endif; ?>

            <div class="scholarships-hero app-page-hero">
                <div class="app-page-hero-copy scholarships-hero-copy">
                    <h2><i class="fas fa-graduation-cap"></i> Scholarship Board</h2>
                    <p>Browse scholarship opportunities, review your readiness, and see the next steps for programs that fit your academic profile.</p>
                </div>
            </div>
            
            <?php if(!$isLoggedIn): ?>
            <div class="scholarship-panel">
                <?php include 'partials/guestViewResult.php'; ?>
            </div>
            
            <?php else: ?>
            <?php /* Profile Ready section intentionally hidden per current UI request. */ ?>
            
            <!-- No academic score banner (but still show scholarships) -->
            <?php if(!$userAcademicScore): ?>
            <div class="no-gwa-banner scholarship-panel" style="display: flex; align-items: center; gap: 16px; border-top: 5px solid #ed6c02;">
                <i class="fas fa-chart-line"></i>
                <div style="flex: 1;">
                    <strong style="color: #ed6c02;"><?php echo htmlspecialchars($boardAcademicDocumentState['headline']); ?></strong>
                    <p style="margin: 5px 0 0 0; font-size: 0.85rem;"><?php echo htmlspecialchars($boardAcademicDocumentState['summary']); ?></p>
                </div>
                <a href="upload.php" class="btn btn-primary" style="padding: 8px 20px;">
                    <i class="fas fa-upload"></i> <?php echo htmlspecialchars($boardAcademicDocumentState['action_label']); ?>
                </a>
            </div>
            <?php endif; ?>
            
            <div class="scholarship-panel scholarship-panel-compact scholarship-search-panel">
                <div class="panel-header">
                    <div>
                        <h3>Search Scholarships</h3>
                    </div>
                    <div class="board-meta-strip">
                        <span class="board-meta-pill">
                            <i class="fas fa-list"></i>
                            <?php echo $matchedCount; ?> available
                        </span>
                        <span class="board-meta-pill">
                            <i class="fas fa-circle-check"></i>
                            <?php echo $eligibleScholarshipsCount; ?> eligible
                        </span>
                        <span class="board-meta-pill">
                            <i class="fas fa-file-circle-check"></i>
                            <?php echo $documentVerifiedCount; ?> docs ready
                        </span>
                    </div>
                </div>
                <?php include 'partials/search-sort.php'; ?>
                <div class="scholarship-results-embed">
                    <!-- Scholarships Container - Compact Modern Design -->
                    <div id="scholarshipsContainer" class="scholarships-container-modern" data-pagination="cards" data-page-size="6" data-item-selector=".scholarship-card" data-pagination-label="scholarships">
                <?php if (empty($matchedScholarships)): ?>
                    <div class="scholarship-empty-state scholarship-empty-state-server">
                        <div class="scholarship-empty-icon">
                            <i class="fas fa-compass"></i>
                        </div>
                        <div class="scholarship-empty-copy">
                            <span class="scholarship-empty-kicker">Scholarship Board</span>
                            <h3>No scholarships found</h3>
                            <p>There are no active scholarships available right now. Check back later for new opportunities from providers and partner institutions.</p>
                        </div>
                        <div class="scholarship-empty-actions">
                            <a href="scholarships.php" class="scholarship-empty-action">
                                <i class="fas fa-rotate-right"></i>
                                Refresh Board
                            </a>
                        </div>
                    </div>
                <?php else: 
                    foreach ($matchedScholarships as $scholarship): 
                        $requiresGwa = !empty($scholarship['requires_gwa']);
                        $requiredGwa = $scholarship['required_gwa'] ?? null;
                        $assessmentRequirement = strtolower(trim((string)($scholarship['assessment_requirement'] ?? 'none')));
                        $hasAssessment = $assessmentRequirement !== '' && $assessmentRequirement !== 'none';
                        $remoteExamLocations = is_array($scholarship['remote_exam_locations'] ?? null) ? $scholarship['remote_exam_locations'] : [];
                        $existingApplication = $userAppliedScholarships[(int) ($scholarship['id'] ?? 0)] ?? null;
                        $hasUserAppliedToScholarship = is_array($existingApplication);

                        $requirementSummary = $documentModel->checkScholarshipRequirements($_SESSION['user_id'] ?? 0, $scholarship['id']);
                        $documentRequirements = $requirementSummary['requirements'] ?? [];
                        $docStatuses = array_map(function($req) {
                            return [
                                'name' => $req['name'],
                                'type' => $req['type'],
                                'status' => $req['status']
                            ];
                        }, $documentRequirements);
                        $verifiedCount = (int) ($requirementSummary['verified'] ?? 0);
                        $pendingCount = (int) ($requirementSummary['pending'] ?? 0);
                        $missingCount = count($requirementSummary['missing'] ?? []);
                        $documentReadyCount = 0;
                        $rejectedCount = 0;
                        foreach ($docStatuses as $docStatusItem) {
                            $docStatusValue = strtolower((string) ($docStatusItem['status'] ?? 'missing'));
                            if (in_array($docStatusValue, ['verified', 'pending'], true)) {
                                $documentReadyCount++;
                            }
                            if ($docStatusValue === 'rejected') {
                                $rejectedCount++;
                            }
                        }
                        $hasAllRequired = ($missingCount === 0 && $rejectedCount === 0);
                        $requirementsCount = count($documentRequirements);
                        $profileRequirementTotal = (int) ($scholarship['profile_requirement_total'] ?? 0);
                        $profileRequirementMet = (int) ($scholarship['profile_requirement_met'] ?? 0);
                        $profileRequirementPending = (int) ($scholarship['profile_requirement_pending'] ?? 0);
                        $profileRequirementFailed = (int) ($scholarship['profile_requirement_failed'] ?? 0);
                        $profileReadinessLabel = (string) ($scholarship['profile_readiness_label'] ?? 'Open profile policy');
                        $targetApplicantType = strtolower(trim((string) ($scholarship['target_applicant_type'] ?? 'all')));
                        $targetYearLevel = strtolower(trim((string) ($scholarship['target_year_level'] ?? 'any')));
                        $requiredAdmissionStatus = strtolower(trim((string) ($scholarship['required_admission_status'] ?? 'any')));
                        $preferredCourse = trim((string) ($scholarship['preferred_course'] ?? ''));
                        $targetStrand = trim((string) ($scholarship['target_strand'] ?? ''));
                        $academicMetricLabel = (string) ($scholarship['academic_metric_label'] ?? $userAcademicMetricLabel);
                        $academicDocumentLabel = (string) ($scholarship['academic_document_label'] ?? $userAcademicDocumentLabel);
                        $academicDocumentState = describeAcademicDocumentStatus($userAcademicDocumentStatus, $academicDocumentLabel, $academicMetricLabel);
                        $minimumAcademicLabel = $academicMetricLabel === 'GWA' ? 'Minimum GWA' : 'Minimum Academic Score';

                        $academicRequirementMet = true;
                        $academicRequirementPending = false;
                        $academicRequirementFailed = false;
                        if ($requiredGwa !== null) {
                            if (!empty($userAcademicScore)) {
                                $academicRequirementMet = ((float) $userAcademicScore <= (float) $requiredGwa);
                                $academicRequirementFailed = !$academicRequirementMet;
                            } else {
                                $academicRequirementMet = false;
                                $academicRequirementPending = true;
                            }
                        }

                        $requirementsTotalChecks = $requirementsCount + ($requiredGwa !== null ? 1 : 0) + $profileRequirementTotal;
                        $requirementsMetChecks = $documentReadyCount + (($requiredGwa !== null && $academicRequirementMet) ? 1 : 0) + $profileRequirementMet;
                        $requirementsPercentage = $requirementsTotalChecks > 0
                            ? (int) round(($requirementsMetChecks / $requirementsTotalChecks) * 100)
                            : 100;
                        $matchPercentage = isset($scholarship['match_percentage'])
                            ? max(0, min(100, (int) round((float) ($scholarship['match_percentage'] ?? 0))))
                            : null;
                        $matchBadgeText = $matchPercentage !== null ? ($matchPercentage . '%') : 'Open';
                        $matchBadgeClass = 'estimated';
                        if ($matchPercentage !== null) {
                            if ($matchPercentage >= 80) {
                                $matchBadgeClass = 'high';
                            } elseif ($matchPercentage >= 60) {
                                $matchBadgeClass = 'medium';
                            } else {
                                $matchBadgeClass = 'low';
                            }
                        }

                        $matchText = 'No listed requirements yet';
                        if ($requirementsTotalChecks > 0) {
                            if ($academicRequirementFailed || $profileRequirementFailed > 0) {
                                $matchText = $requirementsMetChecks . ' of ' . $requirementsTotalChecks . ' requirements met';
                            } elseif ($requirementsMetChecks === $requirementsTotalChecks) {
                                $matchText = 'All listed requirements complete';
                            } else {
                                $matchText = $requirementsMetChecks . ' of ' . $requirementsTotalChecks . ' requirements complete';
                            }
                        }

                        $matchBarClass = 'low';
                        if ($requirementsPercentage >= 100) {
                            $matchBarClass = 'high';
                        } elseif ($requirementsPercentage >= 60) {
                            $matchBarClass = 'medium';
                        }
                        $readyToApplyNow = !empty($scholarship['is_eligible']) && $hasAllRequired && empty($scholarship['is_expired']);

                        $distance = null;
                        if ($userHasLocation && isset($scholarship['latitude']) && isset($scholarship['longitude']) && $scholarship['latitude'] && $scholarship['longitude']) {
                            $distance = $scholarshipService->calculateDistance(
                                $userLatitude, 
                                $userLongitude, 
                                $scholarship['latitude'], 
                                $scholarship['longitude']
                            );
                        }
                        
                        $defaultScholarshipImage = resolvePublicUploadUrl(null, '../');
                        $scholarshipImage = resolvePublicUploadUrl($scholarship['image'] ?? null, '../');

                        $descriptionPreview = trim(strip_tags($scholarship['description'] ?? ''));
                        if ($descriptionPreview !== '' && strlen($descriptionPreview) > 170) {
                            $descriptionPreview = substr($descriptionPreview, 0, 167) . '...';
                        }

                        $visitSiteUrl = trim((string) ($scholarship['provider_website'] ?? ''));
                        if ($visitSiteUrl === '' && !empty($scholarship['assessment_link'])) {
                            $visitSiteUrl = trim((string) $scholarship['assessment_link']);
                        }
                        if ($visitSiteUrl !== '' && !filter_var($visitSiteUrl, FILTER_VALIDATE_URL)) {
                            $visitSiteUrl = '';
                        }

                        $scholarshipDetailsUrl = buildEntityUrl('scholarship_details.php', 'scholarship', (int) $scholarship['id'], 'view', ['id' => (int) $scholarship['id']]);
$wizardApplyUrl = buildEntityUrl('applications.php', 'scholarship', (int) $scholarship['id'], 'apply', ['scholarship_id' => (int) $scholarship['id']]);
                        $applicationNotYetOpen = false;
                        $applicationOpenDateLabel = 'Open now';
                        if (!empty($scholarship['application_open_date'])) {
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
                        $allowsAcceptedScholarshipApplicants = (int) ($scholarship['allow_if_already_accepted'] ?? 1) === 1;
                        $hasAcceptedScholarshipConflict = !$allowsAcceptedScholarshipApplicants
                            && $acceptedScholarshipSummary !== null
                            && (int) ($acceptedScholarshipSummary['scholarship_id'] ?? 0) !== (int) $scholarship['id'];
                        $acceptedScholarshipName = trim((string) ($acceptedScholarshipSummary['scholarship_name'] ?? ''));
                        if ($acceptedScholarshipName === '') {
                            $acceptedScholarshipName = 'another scholarship';
                        }
                        $readyToApplyNow = !empty($scholarship['is_eligible']) && $hasAllRequired && empty($scholarship['is_expired']) && !$applicationNotYetOpen && !$hasAcceptedScholarshipConflict;

                        if ($hasAcceptedScholarshipConflict) {
                            $cardStatusClass = 'ineligible';
                            $cardStatusLabel = 'Accepted Elsewhere';
                        } elseif ($applicationNotYetOpen) {
                            $cardStatusClass = 'estimated';
                            $cardStatusLabel = 'Opens Soon';
                        } elseif ($requiresGwa) {
                            $cardStatusClass = 'estimated';
                            $cardStatusLabel = $academicDocumentState['status_label'];
                        } elseif ($readyToApplyNow) {
                            $cardStatusClass = 'ready';
                            $cardStatusLabel = 'Ready to Apply';
                        } elseif (!empty($scholarship['is_eligible'])) {
                            $cardStatusClass = 'docs';
                            $cardStatusLabel = 'Needs Documents';
                        } elseif ($profileRequirementFailed > 0 || $profileRequirementPending > 0) {
                            $cardStatusClass = 'profile';
                            $cardStatusLabel = 'Profile Needed';
                        } else {
                            $cardStatusClass = 'ineligible';
                            $cardStatusLabel = 'Not Eligible';
                        }

                        if (!empty($scholarship['is_expired'])) {
                            $cardStatusClass = 'expired';
                            $cardStatusLabel = 'Expired';
                            $scholarship['is_eligible'] = false;
                            $requiresGwa = false;
                        }

                        $cardStatusIcon = 'fa-ban';
                        if ($cardStatusClass === 'expired') {
                            $cardStatusIcon = 'fa-clock';
                        } elseif ($hasAcceptedScholarshipConflict) {
                            $cardStatusIcon = 'fa-award';
                        } elseif ($applicationNotYetOpen) {
                            $cardStatusIcon = 'fa-hourglass-half';
                        } elseif ($requiresGwa) {
                            $cardStatusIcon = 'fa-chart-line';
                        } elseif ($readyToApplyNow) {
                            $cardStatusIcon = 'fa-check-circle';
                        } elseif ($cardStatusClass === 'docs') {
                            $cardStatusIcon = 'fa-file-circle-exclamation';
                        } elseif ($cardStatusClass === 'profile') {
                            $cardStatusIcon = 'fa-user-gear';
                        }

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

                        $academicFactorLabel = 'No ' . $academicMetricLabel . ' requirement';
                        $academicFactorClass = 'info';
                        if ($requiredGwa !== null) {
                            if (!empty($userAcademicScore)) {
                                if ((float) $userAcademicScore <= (float) $requiredGwa) {
                                    $academicFactorLabel = 'Pass (' . number_format((float) $userAcademicScore, 2) . ' <= ' . number_format((float) $requiredGwa, 2) . ')';
                                    $academicFactorClass = 'good';
                                } else {
                                    $academicFactorLabel = 'Above limit (' . number_format((float) $userAcademicScore, 2) . ' > ' . number_format((float) $requiredGwa, 2) . ')';
                                    $academicFactorClass = 'bad';
                                }
                            } else {
                                $academicFactorLabel = 'Pending: upload ' . strtolower($academicMetricLabel);
                                $academicFactorClass = 'warn';
                            }
                        }

                        $documentFactorLabel = $requirementsSummary;
                        if ($requirementsCount === 0) {
                            $documentFactorClass = 'info';
                        } elseif ($missingCount > 0 || $rejectedCount > 0) {
                            $documentFactorClass = 'bad';
                        } elseif ($pendingCount > 0) {
                            $documentFactorClass = 'warn';
                        } else {
                            $documentFactorClass = 'good';
                        }

                        $profileFactorLabel = $profileReadinessLabel;
                        if ($profileRequirementTotal === 0) {
                            $profileFactorClass = 'info';
                        } elseif ($profileRequirementFailed > 0) {
                            $profileFactorClass = 'bad';
                        } elseif ($profileRequirementPending > 0) {
                            $profileFactorClass = 'warn';
                        } else {
                            $profileFactorClass = 'good';
                        }

                        $currentInfoChecks = is_array($scholarship['current_info_checks'] ?? null) ? $scholarship['current_info_checks'] : [];
                        $currentInfoTotal = (int) ($scholarship['current_info_total'] ?? 0);
                        $currentInfoMet = (int) ($scholarship['current_info_met'] ?? 0);
                        $currentInfoPending = (int) ($scholarship['current_info_pending'] ?? 0);
                        $currentInfoWarn = (int) ($scholarship['current_info_warn'] ?? 0);
                        $currentInfoLabel = trim((string) ($scholarship['current_info_label'] ?? ''));
                        if ($currentInfoLabel === '') {
                            $currentInfoLabel = 'Current student context unavailable';
                        }

                        if ($currentInfoTotal === 0) {
                            $currentInfoSummary = 'No additional student-context checks were required for this scholarship.';
                            $currentInfoPanelBorder = '#dbe4ee';
                            $currentInfoPanelBg = '#f8fafc';
                        } elseif ($currentInfoPending > 0) {
                            $currentInfoSummary = $currentInfoMet . '/' . $currentInfoTotal . ' aligned. Complete missing profile details to improve recommendation precision.';
                            $currentInfoPanelBorder = '#fde68a';
                            $currentInfoPanelBg = '#fffbeb';
                        } elseif ($currentInfoWarn > 0) {
                            $currentInfoSummary = $currentInfoMet . '/' . $currentInfoTotal . ' aligned. Some current-info checks need manual review.';
                            $currentInfoPanelBorder = '#fed7aa';
                            $currentInfoPanelBg = '#fff7ed';
                        } else {
                            $currentInfoSummary = $currentInfoMet . '/' . $currentInfoTotal . ' aligned. Current student information supports this scholarship recommendation.';
                            $currentInfoPanelBorder = '#bbf7d0';
                            $currentInfoPanelBg = '#f0fdf4';
                        }

                        if (!$userHasLocation) {
                            $locationFactorLabel = 'Set your location';
                            $locationFactorClass = 'warn';
                        } elseif ($distance !== null) {
                            $locationFactorLabel = $distance < 1
                                ? (round($distance * 1000) . 'm away')
                                : (number_format($distance, 1) . 'km away');
                            $locationFactorClass = 'good';
                        } else {
                            $locationFactorLabel = 'Scholarship pin unavailable';
                            $locationFactorClass = 'info';
                        }

                        $deadlineFactorLabel = 'No deadline set';
                        $deadlineFactorClass = 'info';
                        if (!empty($scholarship['deadline'])) {
                            $nowDate = new DateTime();
                            $deadlineDate = new DateTime((string) $scholarship['deadline']);
                            if ($deadlineDate < $nowDate) {
                                $deadlineFactorLabel = 'Closed';
                                $deadlineFactorClass = 'bad';
                            } else {
                                $daysLeft = (int) $nowDate->diff($deadlineDate)->days;
                                if ($daysLeft <= 7) {
                                    $deadlineFactorLabel = 'Urgent (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left)';
                                    $deadlineFactorClass = 'warn';
                                } else {
                                    $deadlineFactorLabel = date('M d, Y', strtotime((string) $scholarship['deadline']));
                                    $deadlineFactorClass = 'good';
                                }
                            }
                        }

                        $cardProfileChecks = is_array($scholarship['profile_checks'] ?? null) ? $scholarship['profile_checks'] : [];
                        $cardMatchGuidePositiveReasons = [];
                        $cardMatchGuideLimitingReasons = [];
                        $cardMatchGuideTitle = $matchPercentage !== null
                            ? ('Why this shows as ' . $matchPercentage . '% match')
                            : 'How the match score works';

                        if (!$isLoggedIn) {
                            $cardMatchGuideSummary = 'This is a personalized fit score. Sign in and complete your student details to see exactly which scholarship checks you already pass.';
                        } elseif ($requiredGwa !== null && empty($userAcademicScore)) {
                            if (in_array($userAcademicDocumentStatus, ['pending', 'verified'], true)) {
                                $cardMatchGuideSummary = 'This score is still conservative because your academic document is already uploaded, but the recorded academic score is not available yet.';
                            } elseif ($userAcademicDocumentStatus === 'rejected') {
                                $cardMatchGuideSummary = 'This score is still limited because the last academic upload needs to be updated before the recorded score can be used.';
                            } else {
                                $cardMatchGuideSummary = 'This score is still estimated because the academic record needed for this scholarship has not been uploaded yet.';
                            }
                        } elseif ($matchPercentage !== null && $matchPercentage >= 80) {
                            $cardMatchGuideSummary = 'This score is high because several key scholarship checks are already passing.';
                        } elseif ($matchPercentage !== null && $matchPercentage >= 60) {
                            $cardMatchGuideSummary = 'This score passed because some major scholarship checks are already passing, even though a few other signals are still weaker or incomplete.';
                        } elseif ($matchPercentage !== null) {
                            $cardMatchGuideSummary = 'This score is lower because only some scholarship checks are passing right now, while other signals still need work.';
                        } else {
                            $cardMatchGuideSummary = 'The score is explained below by showing which scholarship checks already pass and which ones still limit the result.';
                        }

                        $cardMatchGuideNote = 'This percentage helps rank overall fit. Required documents still affect whether you can submit, and final approval still depends on provider review.';

                        if ($requiredGwa !== null) {
                            if (!$isLoggedIn) {
                                $pushReason($cardMatchGuideLimitingReasons, 'Log in so the board can compare your ' . strtolower($academicMetricLabel) . ' with the scholarship limit.');
                            } elseif (empty($userAcademicScore)) {
                                $pushReason($cardMatchGuideLimitingReasons, $academicDocumentState['reason']);
                            } elseif ((float) $userAcademicScore <= (float) $requiredGwa) {
                                $pushReason(
                                    $cardMatchGuidePositiveReasons,
                                    'Passed academic check because your ' . strtolower($academicMetricLabel) . ' of ' . number_format((float) $userAcademicScore, 2) . ' meets the scholarship limit of ' . number_format((float) $requiredGwa, 2) . ' or better.'
                                );
                            } else {
                                $pushReason(
                                    $cardMatchGuideLimitingReasons,
                                    'Academic check does not pass because your ' . strtolower($academicMetricLabel) . ' of ' . number_format((float) $userAcademicScore, 2) . ' is above the scholarship limit of ' . number_format((float) $requiredGwa, 2) . '.'
                                );
                            }
                        } else {
                            $pushReason($cardMatchGuidePositiveReasons, 'Passed academic check automatically because this scholarship does not use a fixed ' . strtolower($academicMetricLabel) . ' cutoff.');
                        }

                        if ($requirementsCount > 0) {
                            if ($missingCount > 0) {
                                $pushReason($cardMatchGuideLimitingReasons, $missingCount . ' required document' . ($missingCount === 1 ? ' is' : 's are') . ' still missing.');
                            } elseif ($rejectedCount > 0) {
                                $pushReason($cardMatchGuideLimitingReasons, 'Re-upload ' . $rejectedCount . ' rejected required document' . ($rejectedCount === 1 ? '' : 's') . ' so the board can count them again.');
                            } elseif ($pendingCount > 0) {
                                $pushReason($cardMatchGuideLimitingReasons, $pendingCount . ' required document' . ($pendingCount === 1 ? ' is' : 's are') . ' uploaded but still pending review.');
                            } else {
                                $pushReason($cardMatchGuidePositiveReasons, 'Passed document readiness because all listed required documents are already uploaded and verified.');
                            }
                        } else {
                            $pushReason($cardMatchGuidePositiveReasons, 'Passed document readiness automatically because this scholarship does not list required uploads on the board.');
                        }

                        if (!empty($cardProfileChecks)) {
                            foreach ($cardProfileChecks as $cardProfileCheck) {
                                $cardProfileStatus = strtolower(trim((string) ($cardProfileCheck['status'] ?? 'pending')));
                                $cardProfileReason = scholarshipMatchGuideReasonFromCheck($cardProfileCheck);

                                if ($cardProfileStatus === 'met') {
                                    $pushReason($cardMatchGuidePositiveReasons, $cardProfileReason);
                                } elseif (in_array($cardProfileStatus, ['failed', 'pending'], true)) {
                                    $pushReason($cardMatchGuideLimitingReasons, $cardProfileReason);
                                }
                            }
                        } else {
                            $pushReason($cardMatchGuidePositiveReasons, 'Passed applicant profile checks automatically because this scholarship is open to a broader set of applicants.');
                        }

                        foreach ($currentInfoChecks as $currentInfoCheck) {
                            $currentInfoStatus = strtolower(trim((string) ($currentInfoCheck['status'] ?? 'pending')));
                            $currentInfoReason = scholarshipMatchGuideReasonFromCheck($currentInfoCheck);

                            if ($currentInfoStatus === 'met') {
                                $pushReason($cardMatchGuidePositiveReasons, $currentInfoReason);
                            } elseif (in_array($currentInfoStatus, ['pending', 'warn'], true)) {
                                $pushReason($cardMatchGuideLimitingReasons, $currentInfoReason);
                            }
                        }

                        if ($applicationNotYetOpen) {
                            $pushReason($cardMatchGuideLimitingReasons, 'Applications have not opened yet, so the timing signal is weaker right now.');
                        } elseif (!empty($scholarship['is_expired'])) {
                            $pushReason($cardMatchGuideLimitingReasons, 'The application period has already closed.');
                        } elseif ($deadlineFactorClass === 'warn') {
                            $pushReason($cardMatchGuideLimitingReasons, 'The deadline is close, so the timing signal is smaller.');
                        } else {
                            $pushReason($cardMatchGuidePositiveReasons, 'The application window timing supports this recommendation.');
                        }

                        if ($hasUserAppliedToScholarship) {
                            $pushReason($cardMatchGuidePositiveReasons, 'You already submitted an application for this scholarship.');
                        }

                        if ($hasAcceptedScholarshipConflict) {
                            $pushReason($cardMatchGuideLimitingReasons, 'You already accepted ' . $acceptedScholarshipName . ', and this scholarship only accepts applicants who have not yet accepted another scholarship offer.');
                        }

                        $currentInfoFactorClass = 'info';
                        $currentInfoFactorValue = $currentInfoTotal > 0 ? ($currentInfoMet . '/' . $currentInfoTotal . ' aligned') : 'Open';
                        if ($currentInfoTotal > 0) {
                            if ($currentInfoPending > 0 || $currentInfoWarn > 0) {
                                $currentInfoFactorClass = 'warn';
                            } else {
                                $currentInfoFactorClass = 'good';
                            }
                        }

                        if ($profileRequirementTotal === 0) {
                            $profileFactorDetail = 'This scholarship does not restrict the applicant profile with narrow policy checks.';
                        } elseif ($profileRequirementFailed > 0) {
                            $profileFactorDetail = $buildProfileMismatchSummary($cardProfileChecks);
                        } elseif ($profileRequirementPending > 0) {
                            $profileFactorDetail = 'Complete the missing applicant details so the profile policy checks can finish.';
                        } else {
                            $profileFactorDetail = 'Your recorded applicant profile matches the scholarship policy checks on file.';
                        }

                        if ($requirementsCount === 0) {
                            $documentFactorDetail = 'This scholarship does not list required uploads on the board.';
                        } elseif ($missingCount > 0) {
                            $documentFactorDetail = 'Upload the missing required file' . ($missingCount === 1 ? '' : 's') . ' so the application can move forward.';
                        } elseif ($rejectedCount > 0) {
                            $documentFactorDetail = 'One or more listed uploads were rejected and need to be updated.';
                        } elseif ($pendingCount > 0) {
                            $documentFactorDetail = 'Your required uploads are present, but review is still ongoing.';
                        } else {
                            $documentFactorDetail = 'All listed required documents are uploaded and verified.';
                        }

                        if ($requiredGwa === null) {
                            $academicFactorDetail = 'This scholarship does not use a fixed ' . strtolower($academicMetricLabel) . ' cutoff.';
                        } elseif (empty($userAcademicScore)) {
                            $academicFactorDetail = $academicDocumentState['summary'];
                        } elseif ((float) $userAcademicScore <= (float) $requiredGwa) {
                            $academicFactorDetail = 'Your ' . strtolower($academicMetricLabel) . ' of ' . number_format((float) $userAcademicScore, 2) . ' meets the scholarship limit of ' . number_format((float) $requiredGwa, 2) . ' or better.';
                        } else {
                            $academicFactorDetail = 'Your ' . strtolower($academicMetricLabel) . ' of ' . number_format((float) $userAcademicScore, 2) . ' is above the scholarship limit of ' . number_format((float) $requiredGwa, 2) . '.';
                        }

                        if ($applicationNotYetOpen) {
                            $timingFactorValue = 'Opens ' . $applicationOpenDateLabel;
                            $timingFactorClass = 'warn';
                            $timingFactorDetail = 'You can review the scholarship now, but submissions only start on the posted opening date.';
                        } elseif (!empty($scholarship['is_expired'])) {
                            $timingFactorValue = 'Closed';
                            $timingFactorClass = 'bad';
                            $timingFactorDetail = 'The deadline has already passed for this scholarship.';
                        } elseif ($deadlineFactorClass === 'warn') {
                            $timingFactorValue = $deadlineFactorLabel;
                            $timingFactorClass = 'warn';
                            $timingFactorDetail = 'The application window is still open, but the deadline is close.';
                        } elseif ($deadlineFactorClass === 'good') {
                            $timingFactorValue = 'Open now';
                            $timingFactorClass = 'good';
                            $timingFactorDetail = 'The application window is currently open and supports this recommendation.';
                        } else {
                            $timingFactorValue = 'Open';
                            $timingFactorClass = 'info';
                            $timingFactorDetail = 'No closing date is listed on this scholarship card.';
                        }

                        $cardMatchGuideFactors = [
                            [
                                'label' => 'Academic fit',
                                'value' => $academicFactorLabel,
                                'detail' => $academicFactorDetail,
                                'class' => $academicFactorClass,
                                'icon' => 'fa-chart-line',
                            ],
                            [
                                'label' => 'Document readiness',
                                'value' => $requirementsCount > 0 ? $requirementsSummary : 'No uploads required',
                                'detail' => $documentFactorDetail,
                                'class' => $documentFactorClass,
                                'icon' => 'fa-file-lines',
                            ],
                            [
                                'label' => 'Applicant fit',
                                'value' => $profileFactorLabel,
                                'detail' => $profileFactorDetail,
                                'class' => $profileFactorClass,
                                'icon' => 'fa-user-check',
                            ],
                            [
                                'label' => 'Student context',
                                'value' => $currentInfoFactorValue,
                                'detail' => $currentInfoSummary,
                                'class' => $currentInfoFactorClass,
                                'icon' => 'fa-id-card',
                            ],
                            [
                                'label' => 'Application timing',
                                'value' => $timingFactorValue,
                                'detail' => $timingFactorDetail,
                                'class' => $timingFactorClass,
                                'icon' => 'fa-calendar-days',
                            ],
                        ];

                        $cardMatchGuidePositiveItems = !empty($cardMatchGuidePositiveReasons)
                            ? array_slice($cardMatchGuidePositiveReasons, 0, 4)
                            : ['The board has not confirmed strong positive scoring signals yet.'];
                        $cardMatchGuideLimitingItems = !empty($cardMatchGuideLimitingReasons)
                            ? array_slice($cardMatchGuideLimitingReasons, 0, 4)
                            : ['No major factor is pulling the match score down right now.'];
                ?>
                        <div class="scholarship-card scholarship-card-modern state-<?php echo $cardStatusClass; ?> <?php echo (!$scholarship['is_eligible'] && !$requiresGwa) ? 'not-eligible' : ''; ?>" 
                            data-id="<?php echo $scholarship['id']; ?>"
                            data-name="<?php echo strtolower(htmlspecialchars($scholarship['name'])); ?>"
                            data-provider="<?php echo strtolower(htmlspecialchars($scholarship['provider'])); ?>"
                            data-benefits="<?php echo strtolower(htmlspecialchars($scholarship['benefits'] ?? '')); ?>"
                            data-match="<?php echo $requirementsPercentage; ?>"
                            data-requirements="<?php echo $requirementsPercentage; ?>"
                            data-deadline="<?php echo $scholarship['deadline'] ?? '9999-12-31'; ?>"
                            data-distance="<?php echo $distance ?? 999999; ?>"
                            data-expired="<?php echo !empty($scholarship['is_expired']) ? 'true' : 'false'; ?>"
                            data-eligible="<?php echo $readyToApplyNow ? 'true' : 'false'; ?>"
                            data-lat="<?php echo $scholarship['latitude'] ?? ''; ?>"
                            data-lng="<?php echo $scholarship['longitude'] ?? ''; ?>"
                            data-location="<?php echo htmlspecialchars($scholarship['location_name'] ?? 'Location not specified'); ?>">
                            
                            <div class="scholarship-card-modern-inner">
                                <div class="scholarship-details-modern">
                                    <div class="scholarship-brand-row">
                                        <div class="scholarship-brand-main">
                                            <div class="logo-stage-modern">
                                                <img src="<?php echo htmlspecialchars($scholarshipImage); ?>" 
                                                    alt="<?php echo htmlspecialchars($scholarship['name']); ?>" 
                                                    class="scholarship-img"
                                                    onerror="this.src='<?php echo htmlspecialchars($defaultScholarshipImage); ?>'">
                                            </div>

                                            <div class="scholarship-brand-copy">
                                                <div class="logo-provider-modern"><?php echo htmlspecialchars($scholarship['provider']); ?></div>
                                                <div class="logo-caption-row-modern">
                                                    <div class="logo-caption-modern">
                                                        <?php echo !empty($scholarship['location_name']) ? htmlspecialchars($scholarship['location_name']) : 'Scholarship partner institution'; ?>
                                                    </div>
                                                    <span class="brand-tier-pill">
                                                        <i class="fas fa-circle-check"></i>
                                                        <?php echo $requirementsTotalChecks > 0 ? 'Requirements checked' : 'Open requirements'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="scholarship-brand-meta">
                                            <button
                                                type="button"
                                                class="match-badge-modern match-badge-trigger <?php echo htmlspecialchars($matchBadgeClass); ?>"
                                                data-open-board-match
                                                data-match-guide-title="<?php echo htmlspecialchars($cardMatchGuideTitle, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-match-guide-summary="<?php echo htmlspecialchars($cardMatchGuideSummary, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-match-guide-note="<?php echo htmlspecialchars($cardMatchGuideNote, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-match-guide-factors="<?php echo htmlspecialchars(json_encode($cardMatchGuideFactors, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-match-guide-positive="<?php echo htmlspecialchars(json_encode($cardMatchGuidePositiveItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-match-guide-limiting="<?php echo htmlspecialchars(json_encode($cardMatchGuideLimitingItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                aria-haspopup="dialog"
                                                aria-controls="scholarshipBoardMatchModal"
                                                aria-label="<?php echo htmlspecialchars($matchPercentage !== null ? ('Open why ' . $matchPercentage . '% match guide') : 'Open match guide', ENT_QUOTES, 'UTF-8'); ?>"
                                            >
                                                <?php echo htmlspecialchars($matchBadgeText); ?>
                                            </button>
                                        </div>
                                    </div>
                                    <?php
                                    $distanceDisplay = 'Set your location';
                                    if ($userHasLocation) {
                                        if ($distance !== null) {
                                            $distanceDisplay = $distance < 1
                                                ? (round($distance * 1000) . 'm away')
                                                : (number_format($distance, 1) . 'km away');
                                        } else {
                                            $distanceDisplay = 'Pin unavailable';
                                        }
                                    }
                                    ?>

                                    <div class="scholarship-topline">
                                        <span class="card-status-pill <?php echo $cardStatusClass; ?>">
                                            <i class="fas <?php echo htmlspecialchars($cardStatusIcon); ?>"></i>
                                            <?php echo $cardStatusLabel; ?>
                                        </span>
                                        <span class="card-status-pill distance">
                                            <i class="fas fa-location-dot"></i>
                                            <?php echo htmlspecialchars($distanceDisplay); ?>
                                        </span>
                                    </div>

                                    <div class="scholarship-header-modern">
                                        <h3 class="scholarship-name-modern"><?php echo htmlspecialchars($scholarship['name']); ?></h3>
                                    </div>
                                    
                                    <?php
                                    $locationDisplay = !empty($scholarship['location_name'])
                                        ? (string) $scholarship['location_name']
                                        : 'Location to be announced';
                                    $hasMapCoordinates = isset($scholarship['latitude'], $scholarship['longitude'])
                                        && $scholarship['latitude']
                                        && $scholarship['longitude'];
                                    $benefitsDisplay = trim((string) ($scholarship['benefits'] ?? ''));
                                    if ($benefitsDisplay === '') {
                                        $benefitsDisplay = 'Tuition support and scholarship assistance';
                                    }

                                    $gwaDisplay = $requiredGwa !== null ? $requiredGwa . ' or better' : 'Open';
                                    $deadlineDisplay = 'Open';
                                    if (!empty($scholarship['deadline'])) {
                                        $deadlineDate = new DateTime((string) $scholarship['deadline']);
                                        $todayDate = new DateTime(date('Y-m-d'));
                                        if ($deadlineDate < $todayDate) {
                                            $deadlineDisplay = 'Expired';
                                        } else {
                                            $deadlineDisplay = $deadlineDate->format('M d, Y');
                                            $daysLeft = (int) $todayDate->diff($deadlineDate)->days;
                                            if ($daysLeft <= 7) {
                                                $deadlineDisplay .= ' (' . $daysLeft . 'd left)';
                                            }
                                        }
                                    }

                                    $eligibilityFocusTags = [];
                                    if ($targetApplicantType === '' || $targetApplicantType === 'all') {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-users',
                                            'label' => 'Open to all applicants'
                                        ];
                                    } else {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-user-graduate',
                                            'label' => formatApplicantTypeLabel($targetApplicantType)
                                        ];
                                    }
                                    if ($targetYearLevel !== '' && $targetYearLevel !== 'any') {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-layer-group',
                                            'label' => formatYearLevelLabel($targetYearLevel)
                                        ];
                                    }
                                    if ($requiredAdmissionStatus !== '' && $requiredAdmissionStatus !== 'any') {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-clipboard-check',
                                            'label' => formatAdmissionStatusLabel($requiredAdmissionStatus) . '+'
                                        ];
                                    }
                                    if ($preferredCourse !== '') {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-book-open',
                                            'label' => 'Course: ' . $preferredCourse
                                        ];
                                    }
                                    if ($targetStrand !== '') {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-sitemap',
                                            'label' => strtoupper($targetStrand)
                                        ];
                                    }

                                    $targetCitizenship = strtolower(trim((string) ($scholarship['target_citizenship'] ?? 'all')));
                                    if ($targetCitizenship !== '' && $targetCitizenship !== 'all') {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-flag',
                                            'label' => formatCitizenshipLabel($targetCitizenship)
                                        ];
                                    }

                                    $targetIncomeBracket = strtolower(trim((string) ($scholarship['target_income_bracket'] ?? 'any')));
                                    if ($targetIncomeBracket !== '' && $targetIncomeBracket !== 'any') {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-wallet',
                                            'label' => formatHouseholdIncomeBracketLabel($targetIncomeBracket)
                                        ];
                                    }

                                    $targetSpecialCategory = strtolower(trim((string) ($scholarship['target_special_category'] ?? 'any')));
                                    if ($targetSpecialCategory !== '' && $targetSpecialCategory !== 'any') {
                                        $eligibilityFocusTags[] = [
                                            'icon' => 'fa-users',
                                            'label' => formatSpecialCategoryLabel($targetSpecialCategory)
                                        ];
                                    }

                                    $matchedReasons = [];
                                    $attentionReasons = [];
                                    $profileChecks = is_array($scholarship['profile_checks'] ?? null) ? $scholarship['profile_checks'] : [];

                                    if ($requiredGwa !== null) {
                                        if (!empty($userAcademicScore)) {
                                            if ((float) $userAcademicScore <= (float) $requiredGwa) {
                                                $pushReason($matchedReasons, $academicMetricLabel . ' meets the required limit');
                                            } else {
                                                $pushReason($attentionReasons, $academicMetricLabel . ' is above the required limit');
                                            }
                                        } else {
                                            $pushReason($attentionReasons, $academicDocumentState['reason']);
                                        }
                                    }

                                    if ($applicationNotYetOpen) {
                                        $pushReason($attentionReasons, 'Applications open on ' . $applicationOpenDateLabel);
                                    } else {
                                        $pushReason($matchedReasons, 'The scholarship is currently open for applications');
                                    }

                                    if ($hasUserAppliedToScholarship) {
                                        $pushReason($matchedReasons, 'You already submitted an application for this scholarship');
                                    }

                                    if ($hasAcceptedScholarshipConflict) {
                                        $pushReason($attentionReasons, 'You already accepted ' . $acceptedScholarshipName . ', and this scholarship only accepts applicants who have not yet accepted another scholarship offer');
                                    }

                                    if ($requirementsCount > 0) {
                                        if ($missingCount > 0) {
                                            $pushReason($attentionReasons, $missingCount . ' required document' . ($missingCount === 1 ? ' is' : 's are') . ' still missing');
                                        } elseif ($rejectedCount > 0) {
                                            $pushReason($attentionReasons, 'Re-upload ' . $rejectedCount . ' rejected required document' . ($rejectedCount === 1 ? '' : 's'));
                                        } elseif ($pendingCount > 0) {
                                            $pushReason($attentionReasons, $pendingCount . ' required document' . ($pendingCount === 1 ? ' is' : 's are') . ' pending review');
                                        } else {
                                            $pushReason($matchedReasons, 'Required documents are complete');
                                        }
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

                                    $nextStepTone = 'info';
                                    $nextStepIcon = 'fa-circle-info';
                                    $nextStepMessage = 'Review the scholarship details and listed requirements before applying.';

                                    $primaryActionType = 'link';
                                    $primaryActionHref = $scholarshipDetailsUrl;
                                    $primaryActionClass = 'btn-outline-modern';
                                    $primaryActionIcon = 'fa-circle-info';
                                    $primaryActionLabel = 'Review Details';

                                    $openingDatePrepPrefix = $applicationNotYetOpen
                                        ? ('Applications open on ' . $applicationOpenDateLabel . '. ')
                                        : '';

                                    if ($hasUserAppliedToScholarship) {
                                        $nextStepTone = 'success';
                                        $nextStepIcon = 'fa-circle-check';
                                        $nextStepMessage = 'You already applied to this scholarship. Check your application tracking for the latest status.';
                                        $primaryActionHref = 'applications.php';
                                        $primaryActionClass = 'btn-outline-modern';
                                        $primaryActionIcon = 'fa-circle-check';
                                        $primaryActionLabel = 'Applied';
                                    } elseif (!empty($scholarship['is_expired'])) {
                                        $nextStepTone = 'muted';
                                        $nextStepIcon = 'fa-calendar-times';
                                        $nextStepMessage = 'The application period for this scholarship has already closed.';
                                        $primaryActionType = 'button';
                                        $primaryActionClass = 'btn-disabled-modern';
                                        $primaryActionIcon = 'fa-calendar-times';
                                        $primaryActionLabel = 'Closed';
                                    } elseif ($hasAcceptedScholarshipConflict) {
                                        $nextStepTone = 'warning';
                                        $nextStepIcon = 'fa-award';
                                        $nextStepMessage = 'You already accepted ' . $acceptedScholarshipName . '. This scholarship only accepts applicants who have not yet accepted another scholarship offer.';
                                        $primaryActionHref = 'applications.php';
                                        $primaryActionClass = 'btn-outline-modern';
                                        $primaryActionIcon = 'fa-award';
                                        $primaryActionLabel = 'View My Applications';
                                    } elseif ($requiresGwa) {
                                        if ($userAcademicDocumentStatus === 'pending') {
                                            $nextStepTone = 'info';
                                            $nextStepIcon = 'fa-clock';
                                            $primaryActionClass = 'btn-outline-modern';
                                            $primaryActionIcon = 'fa-folder-open';
                                        } elseif ($userAcademicDocumentStatus === 'verified') {
                                            $nextStepTone = 'info';
                                            $nextStepIcon = 'fa-circle-info';
                                            $primaryActionClass = 'btn-outline-modern';
                                            $primaryActionIcon = 'fa-folder-open';
                                        } elseif ($userAcademicDocumentStatus === 'rejected') {
                                            $nextStepTone = 'warning';
                                            $nextStepIcon = 'fa-rotate-right';
                                            $primaryActionClass = 'btn-warning-modern';
                                            $primaryActionIcon = 'fa-upload';
                                        } else {
                                            $nextStepIcon = 'fa-chart-line';
                                            $primaryActionClass = 'btn-warning-modern';
                                            $primaryActionIcon = 'fa-upload';
                                        }
                                        $nextStepMessage = $openingDatePrepPrefix . $academicDocumentState['summary'];
                                        $primaryActionHref = 'upload.php';
                                        $primaryActionLabel = $academicDocumentState['action_label'];
                                    } elseif ($profileRequirementPending > 0) {
                                        $nextStepIcon = 'fa-user-gear';
                                        $nextStepMessage = $openingDatePrepPrefix . 'Complete your applicant profile so the system can finish the scholarship policy check.';
                                        $primaryActionHref = 'profile.php';
                                        $primaryActionClass = 'btn-warning-modern';
                                        $primaryActionIcon = 'fa-user-pen';
                                        $primaryActionLabel = 'Complete Profile';
                                    } elseif ($profileRequirementFailed > 0) {
                                        $nextStepTone = 'warning';
                                        $nextStepIcon = 'fa-user-xmark';
                                        $nextStepMessage = $openingDatePrepPrefix . $buildProfileMismatchSummary($profileChecks);
                                        $primaryActionHref = 'profile.php';
                                        $primaryActionClass = 'btn-outline-modern';
                                        $primaryActionIcon = 'fa-user-pen';
                                        $primaryActionLabel = 'Review Profile';
                                    } elseif ($applicationNotYetOpen) {
                                        $nextStepTone = 'info';
                                        $nextStepIcon = 'fa-hourglass-half';
                                        $nextStepMessage = 'Applications open on ' . $applicationOpenDateLabel . '. This scholarship is not accepting submissions yet.';
                                        $primaryActionType = 'button';
                                        $primaryActionClass = 'btn-disabled-modern';
                                        $primaryActionIcon = 'fa-hourglass-half';
                                        $primaryActionLabel = 'Opens Soon';
                                    } elseif (!empty($scholarship['is_eligible']) && $hasAllRequired) {
                                        $nextStepTone = 'success';
                                        $nextStepIcon = 'fa-circle-check';
                                        $nextStepMessage = 'Your profile and listed requirements are ready for application submission.';
                                        $primaryActionHref = $wizardApplyUrl;
                                        $primaryActionClass = 'btn-primary-modern';
                                        $primaryActionIcon = 'fa-paper-plane';
                                        $primaryActionLabel = 'Apply Now';
                                    } elseif (!empty($scholarship['is_eligible'])) {
                                        $nextStepIcon = 'fa-upload';
                                        $nextStepMessage = $openingDatePrepPrefix . 'Upload the remaining required documents before you submit your application.';
                                        $primaryActionHref = 'documents.php';
                                        $primaryActionClass = 'btn-warning-modern';
                                        $primaryActionIcon = 'fa-upload';
                                        $primaryActionLabel = 'Upload Documents';
                                    } elseif ($userAcademicScore && $requiredGwa !== null && $userAcademicScore > $requiredGwa) {
                                        $nextStepTone = 'warning';
                                        $nextStepIcon = 'fa-triangle-exclamation';
                                        $nextStepMessage = 'Your current ' . strtolower($academicMetricLabel) . ' is above the scholarship minimum requirement.';
                                        $primaryActionType = 'button';
                                        $primaryActionClass = 'btn-disabled-modern';
                                        $primaryActionIcon = 'fa-ban';
                                        $primaryActionLabel = 'Not Eligible';
                                    }
                                    ?>

                                    <p class="scholarship-support-modern"><?php echo htmlspecialchars($benefitsDisplay); ?></p>

                                    <div class="scholarship-info-grid scholarship-info-grid-essentials">
                                        <div class="info-chip-modern">
                                            <span class="label"><?php echo htmlspecialchars($minimumAcademicLabel); ?></span>
                                            <span class="value"><?php echo htmlspecialchars($gwaDisplay); ?></span>
                                        </div>

                                        <div class="info-chip-modern">
                                            <span class="label">Deadline</span>
                                            <span class="value"><?php echo htmlspecialchars($deadlineDisplay); ?></span>
                                        </div>

                                        <div class="info-chip-modern">
                                            <span class="label">Documents</span>
                                            <span class="value"><?php echo htmlspecialchars($requirementsSummary); ?></span>
                                        </div>
                                    </div>

                                    <?php if (!empty($eligibilityFocusTags)): ?>
                                    <div class="scholarship-focus-modern">
                                        <div class="scholarship-focus-label">Eligibility Focus</div>
                                        <div class="scholarship-focus-tags">
                                            <?php foreach ($eligibilityFocusTags as $focusTag): ?>
                                            <span class="scholarship-focus-tag">
                                                <i class="fas <?php echo htmlspecialchars($focusTag['icon']); ?>"></i>
                                                <?php echo htmlspecialchars($focusTag['label']); ?>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($matchedReasons) || !empty($attentionReasons)): ?>
                                    <div class="scholarship-reasons-modern">
                                        <div class="scholarship-reason-group is-match">
                                            <div class="scholarship-reason-label">
                                                <i class="fas fa-check-circle"></i>
                                                Why it fits
                                            </div>
                                            <?php if (!empty($matchedReasons)): ?>
                                            <ul class="scholarship-reason-list">
                                                <?php foreach ($matchedReasons as $matchedReason): ?>
                                                <li><?php echo htmlspecialchars($matchedReason); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <?php else: ?>
                                            <p class="scholarship-reason-empty">No confirmed match signals yet.</p>
                                            <?php endif; ?>
                                        </div>

                                        <div class="scholarship-reason-group is-attention">
                                            <div class="scholarship-reason-label">
                                                <i class="fas fa-triangle-exclamation"></i>
                                                Needs attention
                                            </div>
                                            <?php if (!empty($attentionReasons)): ?>
                                            <ul class="scholarship-reason-list">
                                                <?php foreach ($attentionReasons as $attentionReason): ?>
                                                <li><?php echo htmlspecialchars($attentionReason); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <?php else: ?>
                                            <p class="scholarship-reason-empty">No blockers identified right now.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="scholarship-next-step-modern is-<?php echo htmlspecialchars($nextStepTone); ?>">
                                        <i class="fas <?php echo htmlspecialchars($nextStepIcon); ?>"></i>
                                        <span><?php echo htmlspecialchars($nextStepMessage); ?></span>
                                    </div>
                                    
                                    <!-- Action Buttons - Compact -->
                                    <div class="scholarship-actions-modern">
                                        <?php if ($primaryActionType === 'button'): ?>
                                            <button class="<?php echo htmlspecialchars($primaryActionClass); ?>" type="button" disabled>
                                                <i class="fas <?php echo htmlspecialchars($primaryActionIcon); ?>"></i> <?php echo htmlspecialchars($primaryActionLabel); ?>
                                            </button>
                                        <?php else: ?>
                                            <a href="<?php echo htmlspecialchars($primaryActionHref); ?>" class="<?php echo htmlspecialchars($primaryActionClass); ?>">
                                                <i class="fas <?php echo htmlspecialchars($primaryActionIcon); ?>"></i> <?php echo htmlspecialchars($primaryActionLabel); ?>
                                            </a>
                                        <?php endif; ?>

                                        <a href="<?php echo htmlspecialchars($scholarshipDetailsUrl); ?>" class="btn-outline-modern">
                                            <i class="fas fa-circle-info"></i> View Details
                                        </a>

                                        <?php if ($visitSiteUrl !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($visitSiteUrl); ?>" class="btn-outline-modern" target="_blank" rel="noopener noreferrer">
                                                <i class="fas fa-up-right-from-square"></i> Visit Site
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($hasMapCoordinates): ?>
                                            <button class="btn-outline-modern map-action-modern" onclick="showOnMap(<?php echo $scholarship['id']; ?>, '<?php echo htmlspecialchars(addslashes($scholarship['name'])); ?>', <?php echo $scholarship['latitude']; ?>, <?php echo $scholarship['longitude']; ?>)">
                                                <i class="fas fa-map-marked-alt"></i> Map
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-outline-modern is-disabled map-action-modern" type="button" disabled>
                                                <i class="fas fa-map-marked-alt"></i> Map Unavailable
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                <?php 
                    endforeach; 
                endif; ?>
                    </div>
                </div>
            </div>
            
            <?php include 'partials/scholarship_map_modal.php'; ?>
            <?php include 'partials/scholarship_board_info.php'; ?>
            
            <!-- Decision Support System Info -->
            <?php include 'partials/dss.php'; ?>

            <div class="scholarship-board-match-modal" id="scholarshipBoardMatchModal" hidden>
                <div class="scholarship-board-match-backdrop" data-close-board-match-modal></div>
                <div class="scholarship-board-match-dialog" role="dialog" aria-modal="true" aria-labelledby="scholarshipBoardMatchTitle">
                    <div class="scholarship-board-match-header">
                        <div>
                            <span class="scholarship-board-match-eyebrow">Match Guide</span>
                            <h2 id="scholarshipBoardMatchTitle">Why this shows as a match</h2>
                        </div>
                        <button type="button" class="scholarship-board-match-close" data-close-board-match-modal aria-label="Close match guide">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <p class="scholarship-board-match-summary" data-board-match-summary>These checks explain which parts of the scholarship already pass and which ones still limit the score.</p>
                    <div class="scholarship-board-match-note" data-board-match-note></div>

                    <div class="scholarship-board-match-factor-grid" data-board-match-factors></div>

                    <div class="scholarship-board-match-reason-grid">
                        <section class="scholarship-board-match-reason-card positive">
                            <h3><i class="fas fa-arrow-trend-up"></i> Why it passed</h3>
                            <ul class="scholarship-board-match-reason-list" data-board-match-positive></ul>
                        </section>
                        <section class="scholarship-board-match-reason-card warning">
                            <h3><i class="fas fa-triangle-exclamation"></i> What is still limiting this score</h3>
                            <ul class="scholarship-board-match-reason-list" data-board-match-limiting></ul>
                        </section>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
    
    <!-- Footer -->
    <?php include 'layout/footer.php'; ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/scholarship-map.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/scholarship-filter.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/card-pagination.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/script.js')); ?>"></script>
    <script>
        (function () {
            const modal = document.getElementById('scholarshipBoardMatchModal');
            if (!modal) {
                return;
            }

            const title = document.getElementById('scholarshipBoardMatchTitle');
            const summary = modal.querySelector('[data-board-match-summary]');
            const note = modal.querySelector('[data-board-match-note]');
            const factorsContainer = modal.querySelector('[data-board-match-factors]');
            const positiveList = modal.querySelector('[data-board-match-positive]');
            const limitingList = modal.querySelector('[data-board-match-limiting]');
            const closeElements = modal.querySelectorAll('[data-close-board-match-modal]');

            function escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function parseJsonList(value, fallback) {
                try {
                    const parsed = JSON.parse(value || '');
                    return Array.isArray(parsed) ? parsed : fallback;
                } catch (error) {
                    return fallback;
                }
            }

            function renderReasonList(target, items) {
                if (!target) {
                    return;
                }

                target.innerHTML = items.map(function (item) {
                    return '<li>' + escapeHtml(item) + '</li>';
                }).join('');
            }

            function renderFactors(items) {
                if (!factorsContainer) {
                    return;
                }

                factorsContainer.innerHTML = items.map(function (factor) {
                    const stateClass = ['good', 'warn', 'bad', 'info'].includes(factor.class) ? factor.class : 'info';
                    const iconClass = factor.icon || 'fa-circle-info';

                    return '' +
                        '<article class="scholarship-board-match-factor state-' + escapeHtml(stateClass) + '">' +
                            '<span class="scholarship-board-match-factor-icon"><i class="fas ' + escapeHtml(iconClass) + '"></i></span>' +
                            '<div class="scholarship-board-match-factor-copy">' +
                                '<span class="scholarship-board-match-factor-label">' + escapeHtml(factor.label || 'Factor') + '</span>' +
                                '<strong>' + escapeHtml(factor.value || 'Pending') + '</strong>' +
                                '<p>' + escapeHtml(factor.detail || '') + '</p>' +
                            '</div>' +
                        '</article>';
                }).join('');
            }

            function openModal(button) {
                const positiveItems = parseJsonList(button.getAttribute('data-match-guide-positive'), []);
                const limitingItems = parseJsonList(button.getAttribute('data-match-guide-limiting'), []);
                const factorItems = parseJsonList(button.getAttribute('data-match-guide-factors'), []);

                if (title) {
                    title.textContent = button.getAttribute('data-match-guide-title') || 'How the match score works';
                }

                if (summary) {
                    summary.textContent = button.getAttribute('data-match-guide-summary') || 'These checks explain which parts of the scholarship already pass and which ones still limit the score.';
                }

                if (note) {
                    note.textContent = button.getAttribute('data-match-guide-note') || '';
                }

                renderFactors(factorItems);
                renderReasonList(positiveList, positiveItems);
                renderReasonList(limitingList, limitingItems);

                modal.hidden = false;
                document.body.classList.add('board-match-modal-open');
            }

            function closeModal() {
                modal.hidden = true;
                document.body.classList.remove('board-match-modal-open');
            }

            document.querySelectorAll('[data-open-board-match]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    openModal(button);
                });
            });

            closeElements.forEach(function (element) {
                element.addEventListener('click', function () {
                    closeModal();
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeModal();
                }
            });
        })();
    </script>
    <?php if ($uploadNoticeMessage !== ''): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: <?php echo json_encode($uploadNoticeType); ?>,
                title: <?php echo json_encode($uploadNoticeTitle); ?>,
                text: <?php echo json_encode($uploadNoticeMessage); ?>,
                confirmButtonColor: '#2c5aa0'
            });
        });
    </script>
    <?php endif; ?>
    <?php include __DIR__ . '/../public/js/location_init_location.php'; ?>
</body>
</html>

