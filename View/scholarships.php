<?php
require_once '../Config/init.php';
require_once '../Config/db_config.php';
require_once '../Config/url_token.php';
require_once '../Controller/scholarshipResultController.php';
require_once '../Model/UserDocument.php';
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
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/scholarships-page.css')); ?>">
</head>
<body>
    <!-- Header -->
    <?php 
    include 'layout/header.php'; 

    // Initialize services
    $scholarshipService = new ScholarshipService($pdo);
    $documentModel = new UserDocument($pdo);
    
    // Get user's documents for verification
    $userDocuments = [];
    $userDocStatus = [];
    if ($isLoggedIn) {
        $userDocs = $documentModel->getUserDocuments($_SESSION['user_id']);
        foreach ($userDocs as $doc) {
            $userDocStatus[$doc['document_type']] = $doc['status'];
        }
    }

    $matchedScholarships = [];
    $matchedCount = 0;
    $eligibleScholarshipsCount = 0;
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
        usort($matchedScholarships, function($a, $b) {
            $aExpired = !empty($a['is_expired']);
            $bExpired = !empty($b['is_expired']);

            if ($aExpired !== $bExpired) {
                return $aExpired <=> $bExpired;
            }

            return ($b['match_percentage'] ?? 0) <=> ($a['match_percentage'] ?? 0);
        });
        $matchedCount = count($matchedScholarships);
        $eligibleScholarshipsCount = count(array_filter($matchedScholarships, function($scholarship) {
            return !empty($scholarship['is_eligible']);
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
            
            <!-- No GWA Banner (but still show scholarships) -->
            <?php if(!$userGWA): ?>
            <div class="no-gwa-banner scholarship-panel" style="display: flex; align-items: center; gap: 16px; border-top: 5px solid #ed6c02;">
                <i class="fas fa-chart-line"></i>
                <div style="flex: 1;">
                    <strong style="color: #ed6c02;">GWA Not Set</strong>
                    <p style="margin: 5px 0 0 0; font-size: 0.85rem;">Your GWA has not been uploaded yet. Scholarships are still shown, but the requirements check will stay incomplete until you upload your grades.</p>
                </div>
                <a href="upload.php" class="btn btn-primary" style="padding: 8px 20px;">
                    <i class="fas fa-upload"></i> Upload Grades
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
                    <div class="guest-warning" style="margin: 6px 0 0;">
                        <i class="fas fa-exclamation-circle"></i>
                        <p><strong>No scholarships found.</strong> There are currently no active scholarships available. Check back later for new opportunities.</p>
                    </div>
                <?php else: 
                    foreach ($matchedScholarships as $scholarship): 
                        $requiresGwa = !empty($scholarship['requires_gwa']);
                        $requiredGwa = $scholarship['required_gwa'] ?? null;
                        if ($requiredGwa === null && isset($scholarship['max_gwa']) && $scholarship['max_gwa'] !== null && $scholarship['max_gwa'] !== '') {
                            $requiredGwa = (float) $scholarship['max_gwa'];
                        }
                        $assessmentRequirement = strtolower(trim((string)($scholarship['assessment_requirement'] ?? 'none')));
                        $hasAssessment = $assessmentRequirement !== '' && $assessmentRequirement !== 'none';
                        $remoteExamLocations = is_array($scholarship['remote_exam_locations'] ?? null) ? $scholarship['remote_exam_locations'] : [];

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
                        $targetStrand = trim((string) ($scholarship['target_strand'] ?? ''));

                        $academicRequirementMet = true;
                        $academicRequirementPending = false;
                        $academicRequirementFailed = false;
                        if ($requiredGwa !== null) {
                            if (!empty($userGWA)) {
                                $academicRequirementMet = ((float) $userGWA <= (float) $requiredGwa);
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
                        $requirementsBadgeText = $requirementsTotalChecks > 0
                            ? ($requirementsMetChecks . '/' . $requirementsTotalChecks)
                            : 'Open';

                        $badgeClass = 'low';
                        if ($requirementsPercentage >= 100) {
                            $badgeClass = 'high';
                        } elseif ($requirementsPercentage >= 60) {
                            $badgeClass = 'medium';
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

                        $audienceParts = [];
                        if ($targetApplicantType !== '' && $targetApplicantType !== 'all') {
                            $audienceParts[] = formatApplicantTypeLabel($targetApplicantType);
                        }
                        if ($targetYearLevel !== '' && $targetYearLevel !== 'any') {
                            $audienceParts[] = formatYearLevelLabel($targetYearLevel);
                        }
                        if ($requiredAdmissionStatus !== '' && $requiredAdmissionStatus !== 'any') {
                            $audienceParts[] = formatAdmissionStatusLabel($requiredAdmissionStatus) . '+';
                        }
                        if ($targetStrand !== '') {
                            $audienceParts[] = strtoupper($targetStrand);
                        }
                        $audienceLabel = !empty($audienceParts) ? implode(' / ', $audienceParts) : 'Open to all applicants';

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
                        $wizardApplyUrl = buildEntityUrl('wizard.php', 'scholarship', (int) $scholarship['id'], 'apply', ['scholarship_id' => (int) $scholarship['id']]);

                        if ($requiresGwa) {
                            $cardStatusClass = 'estimated';
                            $cardStatusLabel = 'Needs GWA';
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

                        $academicFactorLabel = 'No GWA requirement';
                        $academicFactorClass = 'info';
                        if ($requiredGwa !== null) {
                            if (!empty($userGWA)) {
                                if ((float) $userGWA <= (float) $requiredGwa) {
                                    $academicFactorLabel = 'Pass (' . number_format((float) $userGWA, 2) . ' <= ' . number_format((float) $requiredGwa, 2) . ')';
                                    $academicFactorClass = 'good';
                                } else {
                                    $academicFactorLabel = 'Above limit (' . number_format((float) $userGWA, 2) . ' > ' . number_format((float) $requiredGwa, 2) . ')';
                                    $academicFactorClass = 'bad';
                                }
                            } else {
                                $academicFactorLabel = 'Pending: upload GWA';
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
                                            <div class="match-badge-modern <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($requirementsBadgeText); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="scholarship-topline">
                                        <span class="card-status-pill <?php echo $cardStatusClass; ?>">
                                            <i class="fas fa-<?php echo !empty($scholarship['is_expired']) ? 'clock' : ($requiresGwa ? 'chart-line' : ($scholarship['is_eligible'] ? 'check-circle' : 'ban')); ?>"></i>
                                            <?php echo $cardStatusLabel; ?>
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
                                    if ($targetApplicantType !== '' && $targetApplicantType !== 'all') {
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
                                        if (!empty($userGWA)) {
                                            if ((float) $userGWA <= (float) $requiredGwa) {
                                                $pushReason($matchedReasons, 'GWA meets the required limit');
                                            } else {
                                                $pushReason($attentionReasons, 'GWA is above the required limit');
                                            }
                                        } else {
                                            $pushReason($attentionReasons, 'Upload TOR or Form 138 to verify GWA');
                                        }
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

                                    if (!empty($scholarship['is_expired'])) {
                                        $nextStepTone = 'muted';
                                        $nextStepIcon = 'fa-calendar-times';
                                        $nextStepMessage = 'The application period for this scholarship has already closed.';
                                        $primaryActionType = 'button';
                                        $primaryActionClass = 'btn-disabled-modern';
                                        $primaryActionIcon = 'fa-calendar-times';
                                        $primaryActionLabel = 'Closed';
                                    } elseif ($requiresGwa) {
                                        $nextStepIcon = 'fa-chart-line';
                                        $nextStepMessage = 'Upload your grades to complete the academic requirement check.';
                                        $primaryActionHref = 'upload.php';
                                        $primaryActionClass = 'btn-warning-modern';
                                        $primaryActionIcon = 'fa-upload';
                                        $primaryActionLabel = 'Upload Grades';
                                    } elseif ($profileRequirementPending > 0) {
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
                                        $nextStepMessage = 'Upload the remaining required documents before you submit your application.';
                                        $primaryActionHref = 'documents.php';
                                        $primaryActionClass = 'btn-warning-modern';
                                        $primaryActionIcon = 'fa-upload';
                                        $primaryActionLabel = 'Upload Documents';
                                    } elseif ($userGWA && $requiredGwa !== null && $userGWA > $requiredGwa) {
                                        $nextStepTone = 'warning';
                                        $nextStepIcon = 'fa-triangle-exclamation';
                                        $nextStepMessage = 'Your current GWA is above the scholarship minimum requirement.';
                                        $primaryActionType = 'button';
                                        $primaryActionClass = 'btn-disabled-modern';
                                        $primaryActionIcon = 'fa-ban';
                                        $primaryActionLabel = 'Not Eligible';
                                    }
                                    ?>

                                    <p class="scholarship-support-modern"><?php echo htmlspecialchars($benefitsDisplay); ?></p>

                                    <div class="scholarship-info-grid scholarship-info-grid-essentials">
                                        <div class="info-chip-modern">
                                            <span class="label">Minimum GWA</span>
                                            <span class="value"><?php echo htmlspecialchars($gwaDisplay); ?></span>
                                        </div>

                                        <div class="info-chip-modern">
                                            <span class="label">Deadline</span>
                                            <span class="value"><?php echo htmlspecialchars($deadlineDisplay); ?></span>
                                        </div>

                                        <div class="info-chip-modern">
                                            <span class="label">Audience</span>
                                            <span class="value"><?php echo htmlspecialchars($audienceLabel); ?></span>
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
    <?php include '../public/js/location_init_location.php'; ?>
</body>
</html>

