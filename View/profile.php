<?php
// profile.php
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Controllers/scholarshipResultController.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/StudentData.php';
require_once __DIR__ . '/../app/Models/Application.php';

function profileFormatTimelineDate(?string $value, string $fallback = 'Not yet available'): string
{
    if (!$value) {
        return $fallback;
    }

    try {
        return (new DateTime($value))->format('M d, Y g:i A');
    } catch (Throwable $e) {
        return $fallback;
    }
}

function profileBuildApplicationTimelineData(array $application, array $requirementsSummary): array
{
    $applicationStatus = strtolower(trim((string) ($application['status'] ?? 'pending')));
    $studentResponseStatus = strtolower(trim((string) ($application['student_response_status'] ?? '')));
    $studentRespondedAt = !empty($application['student_responded_at'])
        ? (string) $application['student_responded_at']
        : '';
    $studentAccepted = $applicationStatus === 'approved' && $studentResponseStatus === 'accepted';
    $totalRequired = (int) ($requirementsSummary['total_required'] ?? 0);
    $verifiedCount = (int) ($requirementsSummary['verified'] ?? 0);
    $pendingCount = (int) ($requirementsSummary['pending'] ?? 0);
    $missingCount = count($requirementsSummary['missing'] ?? []);
    $rejectedCount = 0;

    foreach (($requirementsSummary['requirements'] ?? []) as $requirement) {
        if (strtolower(trim((string) ($requirement['status'] ?? ''))) === 'rejected') {
            $rejectedCount++;
        }
    }

    $documentsNeedAttention = ($missingCount + $rejectedCount) > 0;
    $documentsCleared = $totalRequired === 0 || (!$documentsNeedAttention && $verifiedCount >= $totalRequired);
    $updatedAt = !empty($application['updated_at']) ? (string) $application['updated_at'] : (string) ($application['applied_at'] ?? '');
    $rejectionReason = trim((string) ($application['rejection_reason'] ?? ''));

    if ($studentAccepted) {
        $currentStageTitle = 'Scholarship accepted';
        $currentStageNote = 'You confirmed your acceptance on ' . profileFormatTimelineDate($studentRespondedAt, 'the latest update') . '.';
    } elseif ($applicationStatus === 'approved') {
        $currentStageTitle = 'Approved';
        $currentStageNote = 'Your application passed review. Confirm your acceptance to complete your scholarship response.';
    } elseif ($applicationStatus === 'rejected') {
        $currentStageTitle = 'Not approved';
        $currentStageNote = $rejectionReason !== ''
            ? $rejectionReason
            : 'Your application was reviewed but was not approved this time.';
    } elseif ($documentsNeedAttention) {
        $issueParts = [];
        if ($rejectedCount > 0) {
            $issueParts[] = $rejectedCount . ' rejected';
        }
        if ($missingCount > 0) {
            $issueParts[] = $missingCount . ' missing';
        }
        $currentStageTitle = 'Action needed';
        $currentStageNote = 'Please update your required documents. ' . ucfirst(implode(' and ', $issueParts)) . ' requirement' . (($rejectedCount + $missingCount) === 1 ? '' : 's') . '.';
    } elseif (!$documentsCleared) {
        $currentStageTitle = 'Documents under review';
        $currentStageNote = $pendingCount > 0
            ? $pendingCount . ' required document' . ($pendingCount === 1 ? ' is' : 's are') . ' still being verified.'
            : 'Your required documents are being checked.';
    } else {
        $currentStageTitle = 'Under scholarship review';
        $currentStageNote = "All required documents are on file. Waiting for the reviewer's final decision.";
    }

    $documentsDetail = 'No required documents configured.';
    if ($totalRequired > 0) {
        if ($documentsNeedAttention) {
            $documentsDetail = $verifiedCount . '/' . $totalRequired . ' verified with ' . ($missingCount + $rejectedCount) . ' item' . (($missingCount + $rejectedCount) === 1 ? '' : 's') . ' needing attention.';
        } elseif ($documentsCleared) {
            $documentsDetail = $verifiedCount . '/' . $totalRequired . ' verified and ready for reviewer evaluation.';
        } else {
            $documentsDetail = $verifiedCount . '/' . $totalRequired . ' verified with ' . $pendingCount . ' pending verification.';
        }
    }

    $reviewDetail = 'Waiting for document checks to finish.';
    if ($studentAccepted) {
        $reviewDetail = 'Scholarship review completed and your acceptance has been recorded.';
    } elseif ($applicationStatus === 'approved' || $applicationStatus === 'rejected') {
        $reviewDetail = 'Scholarship review completed.';
    } elseif ($documentsNeedAttention) {
        $reviewDetail = 'Reviewer is waiting for corrected or complete requirements.';
    } elseif ($documentsCleared) {
        $reviewDetail = 'Application is currently being evaluated by the scholarship reviewer.';
    }

    $decisionDetail = 'Final decision is still pending.';
    if ($studentAccepted) {
        $decisionDetail = 'Accepted on ' . profileFormatTimelineDate($studentRespondedAt, 'the latest response date') . '.';
    } elseif ($applicationStatus === 'approved') {
        $decisionDetail = 'Approved on ' . profileFormatTimelineDate($updatedAt, 'the latest review date') . '.';
    } elseif ($applicationStatus === 'rejected') {
        $decisionDetail = $rejectionReason !== ''
            ? $rejectionReason
            : 'Rejected on ' . profileFormatTimelineDate($updatedAt, 'the latest review date') . '.';
    }

    return [
        'current_stage_title' => $currentStageTitle,
        'current_stage_note' => $currentStageNote,
        'documents_summary' => $documentsDetail,
        'review_summary' => $reviewDetail,
        'decision_summary' => $decisionDetail,
        'documents_verified_count' => $verifiedCount,
        'documents_total_count' => $totalRequired,
        'documents_pending_count' => $pendingCount,
        'documents_missing_count' => $missingCount,
        'documents_rejected_count' => $rejectedCount,
        'student_response_status' => $studentResponseStatus,
        'student_responded_at' => $studentRespondedAt,
        'can_accept' => $applicationStatus === 'approved' && !$studentAccepted,
        'student_response_note' => $studentAccepted
            ? 'Accepted on ' . profileFormatTimelineDate($studentRespondedAt, 'the latest response date') . '.'
            : ($applicationStatus === 'approved'
                ? 'Waiting for your confirmation.'
                : 'No response required yet.'),
        'timeline_steps' => [
            [
                'label' => 'Submitted',
                'detail' => 'Application sent on ' . profileFormatTimelineDate($application['applied_at'] ?? null),
                'state' => 'complete',
                'icon' => 'fa-paper-plane',
            ],
            [
                'label' => 'Documents Screening',
                'detail' => $documentsDetail,
                'state' => $documentsNeedAttention ? 'attention' : ($documentsCleared ? 'complete' : 'current'),
                'icon' => 'fa-folder-open',
            ],
            [
                'label' => 'Scholarship Review',
                'detail' => $reviewDetail,
                'state' => ($applicationStatus === 'approved' || $applicationStatus === 'rejected')
                    ? 'complete'
                    : (($documentsNeedAttention || !$documentsCleared) ? 'upcoming' : 'current'),
                'icon' => 'fa-clipboard-check',
            ],
            [
                'label' => 'Final Decision',
                'detail' => $decisionDetail,
                'state' => ($applicationStatus === 'approved' || $studentAccepted)
                    ? 'success'
                    : ($applicationStatus === 'rejected' ? 'rejected' : 'upcoming'),
                'icon' => 'fa-flag-checkered',
            ],
        ],
    ];
}

// Get matches count
$matchesCount = 0;
if ($isLoggedIn && $userGWA && $userCourse) {
    try {
        $scholarshipService = new ScholarshipService($pdo);
        $matches = $scholarshipService->getMatchedScholarships(
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
        $matchesCount = count($matches);
    } catch (Exception $e) {
        error_log("Error getting matches: " . $e->getMessage());
    }
}

// Load complete user data from database to ensure we have all information
if ($isLoggedIn) {
    try {
        $userModel = new User($pdo);
        $studentDataModel = new StudentData($pdo);
        
        $completeUserData = $userModel->find($_SESSION['user_id']);
        $studentData = $studentDataModel->getByUserId($_SESSION['user_id']);
        
        if ($completeUserData) {
            // Update session with user table data
            $_SESSION['user_id'] = $completeUserData['id'];
            $_SESSION['user_username'] = $completeUserData['username'] ?? '';
            $_SESSION['user_email'] = $completeUserData['email'] ?? $_SESSION['user_email'] ?? '';
            $_SESSION['user_role'] = $completeUserData['role'] ?? 'student';
            $_SESSION['user_created_at'] = $completeUserData['created_at'] ?? null;
            
            // Use username as the display name
            $_SESSION['user_display_name'] = $completeUserData['username'] ?? 'User';
            
            // If student data exists, load it
            if ($studentData) {
                $_SESSION['user_firstname'] = $studentData['firstname'] ?? '';
                $_SESSION['user_lastname'] = $studentData['lastname'] ?? '';
                $_SESSION['user_middleinitial'] = $studentData['middleinitial'] ?? '';
                $_SESSION['user_suffix'] = $studentData['suffix'] ?? '';
                $_SESSION['user_school'] = $studentData['school'] ?? $_SESSION['user_school'] ?? '';
                $_SESSION['user_course'] = $studentData['course'] ?? $_SESSION['user_course'] ?? '';
                $_SESSION['user_gwa'] = $studentData['gwa'] ?? $_SESSION['user_gwa'] ?? null;
                $_SESSION['user_applicant_type'] = $studentData['applicant_type'] ?? $_SESSION['user_applicant_type'] ?? '';
                $_SESSION['user_shs_school'] = $studentData['shs_school'] ?? $_SESSION['user_shs_school'] ?? '';
                $_SESSION['user_shs_strand'] = $studentData['shs_strand'] ?? $_SESSION['user_shs_strand'] ?? '';
                $_SESSION['user_shs_graduation_year'] = $studentData['shs_graduation_year'] ?? $_SESSION['user_shs_graduation_year'] ?? '';
                $_SESSION['user_shs_average'] = $studentData['shs_average'] ?? $_SESSION['user_shs_average'] ?? '';
                $_SESSION['user_admission_status'] = $studentData['admission_status'] ?? $_SESSION['user_admission_status'] ?? '';
                $_SESSION['user_target_college'] = $studentData['target_college'] ?? $_SESSION['user_target_college'] ?? '';
                $_SESSION['user_target_course'] = $studentData['target_course'] ?? $_SESSION['user_target_course'] ?? '';
                $_SESSION['user_year_level'] = $studentData['year_level'] ?? $_SESSION['user_year_level'] ?? '';
                $_SESSION['user_enrollment_status'] = $studentData['enrollment_status'] ?? $_SESSION['user_enrollment_status'] ?? '';
                $_SESSION['user_academic_standing'] = $studentData['academic_standing'] ?? $_SESSION['user_academic_standing'] ?? '';
                $_SESSION['user_age'] = $studentData['age'] ?? null;
                $_SESSION['user_birthdate'] = $studentData['birthdate'] ?? $_SESSION['user_birthdate'] ?? null;
                $_SESSION['user_gender'] = $studentData['gender'] ?? $_SESSION['user_gender'] ?? '';
                $_SESSION['user_address'] = $studentData['address'] ?? $_SESSION['user_address'] ?? '';
                $_SESSION['user_house_no'] = $studentData['house_no'] ?? $_SESSION['user_house_no'] ?? '';
                $_SESSION['user_street'] = $studentData['street'] ?? $_SESSION['user_street'] ?? '';
                $_SESSION['user_barangay'] = $studentData['barangay'] ?? $_SESSION['user_barangay'] ?? '';
                $_SESSION['user_city'] = $studentData['city'] ?? $_SESSION['user_city'] ?? '';
                $_SESSION['user_province'] = $studentData['province'] ?? $_SESSION['user_province'] ?? '';
                $_SESSION['user_mobile_number'] = $studentData['mobile_number'] ?? $_SESSION['user_mobile_number'] ?? '';
                $_SESSION['user_citizenship'] = $studentData['citizenship'] ?? $_SESSION['user_citizenship'] ?? '';
                $_SESSION['user_household_income_bracket'] = $studentData['household_income_bracket'] ?? $_SESSION['user_household_income_bracket'] ?? '';
                $_SESSION['user_special_category'] = $studentData['special_category'] ?? $_SESSION['user_special_category'] ?? '';
                $_SESSION['user_profile_image_path'] = $studentData['profile_image_path'] ?? $_SESSION['user_profile_image_path'] ?? '';
            }
        }
    } catch (Exception $e) {
        error_log("Error loading user data: " . $e->getMessage());
    }
}

// Set variables for template use
$userDisplayName = $_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? 'User';
$userFirstName = $_SESSION['user_firstname'] ?? '';
$userLastName = $_SESSION['user_lastname'] ?? '';
$userMiddleInitial = $_SESSION['user_middleinitial'] ?? '';
$userSchool = $_SESSION['user_school'] ?? '';
$userCourse = $_SESSION['user_course'] ?? '';
$userGWA = $_SESSION['user_gwa'] ?? null;
$userApplicantType = $_SESSION['user_applicant_type'] ?? '';
$userShsSchool = $_SESSION['user_shs_school'] ?? '';
$userShsStrand = $_SESSION['user_shs_strand'] ?? '';
$userShsGraduationYear = $_SESSION['user_shs_graduation_year'] ?? '';
$userShsAverage = $_SESSION['user_shs_average'] ?? '';
$userAdmissionStatus = $_SESSION['user_admission_status'] ?? '';
$userTargetCollege = $_SESSION['user_target_college'] ?? '';
$userTargetCourse = $_SESSION['user_target_course'] ?? '';
$userYearLevel = $_SESSION['user_year_level'] ?? '';
$userEnrollmentStatus = $_SESSION['user_enrollment_status'] ?? '';
$userAcademicStanding = $_SESSION['user_academic_standing'] ?? '';
$userAge = $_SESSION['user_age'] ?? '';
$userBirthdate = $_SESSION['user_birthdate'] ?? '';
$userGender = $_SESSION['user_gender'] ?? '';
$userAddress = $_SESSION['user_address'] ?? '';
$userHouseNo = $_SESSION['user_house_no'] ?? '';
$userStreet = $_SESSION['user_street'] ?? '';
$userBarangay = $_SESSION['user_barangay'] ?? '';
$userCity = $_SESSION['user_city'] ?? '';
$userProvince = $_SESSION['user_province'] ?? '';
$userMobileNumber = $_SESSION['user_mobile_number'] ?? '';
$userCitizenship = $_SESSION['user_citizenship'] ?? '';
$userHouseholdIncomeBracket = $_SESSION['user_household_income_bracket'] ?? '';
$userSpecialCategory = $_SESSION['user_special_category'] ?? '';
$userProfileImagePath = $_SESSION['user_profile_image_path'] ?? '';
$userLatitude = $_SESSION['user_latitude'] ?? null;
$userLongitude = $_SESSION['user_longitude'] ?? null;
$userLocationName = $_SESSION['user_location_name'] ?? '';
$userCreatedAt = $_SESSION['user_created_at'] ?? null;
$userEmail = $_SESSION['user_email'] ?? '';
$userProfileImageUrl = $userProfileImagePath !== '' ? resolveStoredFileUrl($userProfileImagePath, '../') : null;

// Get document stats
$documentStats = ['verified' => 0, 'pending' => 0, 'total' => 0];
$applicationStats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$applicationTimeline = [];
if ($isLoggedIn) {
    try {
        require_once __DIR__ . '/../app/Models/UserDocument.php';
        $docModel = new UserDocument($pdo);
        $docs = $docModel->getUserDocuments($_SESSION['user_id']);
        $verified = 0;
        foreach ($docs as $doc) {
            if ($doc['status'] === 'verified') $verified++;
        }
        $documentStats = [
            'total' => count($docs),
            'verified' => $verified,
            'pending' => count($docs) - $verified
        ];
    } catch (Exception $e) {
        error_log("Error loading document stats: " . $e->getMessage());
    }

    try {
        $applicationModel = new Application($pdo);
        $applicationStatsRaw = $applicationModel->getStats((int) $_SESSION['user_id']) ?: [];
        $applicationStats = [
            'total' => (int) ($applicationStatsRaw['total'] ?? 0),
            'pending' => (int) ($applicationStatsRaw['pending'] ?? 0),
            'approved' => (int) ($applicationStatsRaw['approved'] ?? 0),
            'rejected' => (int) ($applicationStatsRaw['rejected'] ?? 0),
        ];

        if (!isset($docModel)) {
            require_once __DIR__ . '/../app/Models/UserDocument.php';
            $docModel = new UserDocument($pdo);
        }

        foreach ($applicationModel->getTimelineByUser((int) $_SESSION['user_id'], 5) as $applicationItem) {
            $requirementsSummary = $docModel->checkScholarshipRequirements((int) $_SESSION['user_id'], (int) ($applicationItem['scholarship_id'] ?? 0));
            $applicationTimeline[] = array_merge(
                $applicationItem,
                profileBuildApplicationTimelineData($applicationItem, $requirementsSummary)
            );
        }
    } catch (Throwable $e) {
        error_log("Error loading application timeline: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship - Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/dashboard.css')); ?>">
</head>
<body>
    <!-- Header -->
    <?php include 'layout/header.php'; ?>
    
    <!-- Profile Section -->
    <section class="dashboard user-page-shell">
        <div class="container">
            <div class="profile-page-header app-page-hero">
                <div class="profile-header-copy app-page-hero-copy">
                    <h2><i class="fas fa-user-gear"></i> Profile</h2>
                    <p>View, update, and manage your student profile information in one place.</p>
                </div>
            </div>
            
            <?php if(!$isLoggedIn): ?>
            <!-- Guest View -->
            <div class="module-card" id="userProfileCard" style="margin-bottom: 20px;">
                <div class="module-header">
                    <div class="module-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <h3 class="module-title">Your Profile</h3>
                </div>
                <div id="guestProfile">
                    <p>Please login to view your profile and access personalized features.</p>
                    <a href="login.php" class="btn btn-primary">Login Now</a>
                </div>
            </div>
            
            <?php else: ?>
            <!-- User View with Profile Tabs -->
            <div class="module-card" style="margin-bottom: 20px; padding: 0; overflow: hidden;">
                <!-- Tab Headers -->
                <div style="display: flex; border-bottom: 2px solid var(--light-gray); background: #f8f9fa; flex-wrap: wrap;">
                    <button class="profile-tab active" id="tabView" onclick="switchProfileTab('view')" 
                            style="flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-weight: 600; color: var(--primary); border-bottom: 3px solid var(--primary); transition: all 0.3s; min-width: 120px;">
                        <i class="fas fa-user"></i> View Profile
                    </button>
                    <button class="profile-tab" id="tabEdit" onclick="switchProfileTab('edit')" 
                            style="flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-weight: 600; color: var(--gray); border-bottom: 3px solid transparent; transition: all 0.3s; min-width: 120px;">
                        <i class="fas fa-edit"></i> Edit Profile
                    </button>
                    <button class="profile-tab" id="tabPassword" onclick="switchProfileTab('password')" 
                            style="flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-weight: 600; color: var(--gray); border-bottom: 3px solid transparent; transition: all 0.3s; min-width: 120px;">
                        <i class="fas fa-lock"></i> Security
                    </button>
                </div>
                <!-- Tab Content -->
                <?php include 'layout/tab-content.php' ?>
            </div>

            <div class="module-card application-tracking-card" id="applicationTracking" style="margin-bottom: 20px;">
                <div class="module-header application-tracking-header">
                    <div class="module-icon">
                        <i class="fas fa-route"></i>
                    </div>
                    <div class="application-tracking-copy">
                        <h3 class="module-title">Application Tracking</h3>
                        <p>Follow the latest progress of your scholarship submissions from review to final decision.</p>
                    </div>
                </div>

                <div class="application-overview-grid">
                    <div class="application-overview-item">
                        <span class="application-overview-label">Total</span>
                        <strong><?php echo (int) $applicationStats['total']; ?></strong>
                    </div>
                    <div class="application-overview-item">
                        <span class="application-overview-label">Pending</span>
                        <strong><?php echo (int) $applicationStats['pending']; ?></strong>
                    </div>
                    <div class="application-overview-item">
                        <span class="application-overview-label">Approved</span>
                        <strong><?php echo (int) $applicationStats['approved']; ?></strong>
                    </div>
                    <div class="application-overview-item">
                        <span class="application-overview-label">Rejected</span>
                        <strong><?php echo (int) $applicationStats['rejected']; ?></strong>
                    </div>
                </div>

                <?php if (empty($applicationTimeline)): ?>
                <div class="application-tracking-empty">
                    <i class="fas fa-folder-open"></i>
                    <h4>No applications yet</h4>
                    <p>Your submitted scholarship applications will appear here once you start applying.</p>
                    <a href="scholarships.php" class="btn btn-primary">Browse Scholarships</a>
                </div>
                <?php else: ?>
                <div class="application-tracking-list">
                    <?php foreach ($applicationTimeline as $applicationItem): ?>
                    <?php
                        $applicationStatus = strtolower(trim((string) ($applicationItem['status'] ?? 'pending')));
                        $studentResponseStatus = strtolower(trim((string) ($applicationItem['student_response_status'] ?? '')));
                        $studentAccepted = $applicationStatus === 'approved' && $studentResponseStatus === 'accepted';
                        $statusPillLabel = $studentAccepted ? 'Accepted' : ucfirst($applicationStatus);
                        $statusPillClass = $studentAccepted ? 'accepted' : $applicationStatus;
                        $referenceNumber = 'APP-' . str_pad((string) ((int) ($applicationItem['id'] ?? 0)), 5, '0', STR_PAD_LEFT);
                        $providerName = trim((string) ($applicationItem['provider'] ?? ''));
                        $providerName = $providerName !== '' ? $providerName : 'Scholarship provider';
                        $deadlineValue = !empty($applicationItem['deadline'])
                            ? profileFormatTimelineDate((string) $applicationItem['deadline'], 'Not set')
                            : 'Not set';
                        $scoreValue = isset($applicationItem['probability_score']) && $applicationItem['probability_score'] !== null
                            ? number_format((float) $applicationItem['probability_score'], 1) . '%'
                            : 'Not calculated';
                        $acceptUrl = buildEntityUrl(
                            '../app/Controllers/application_response.php',
                            'application',
                            (int) ($applicationItem['id'] ?? 0),
                            'accept',
                            [
                                'action' => 'accept',
                                'id' => (int) ($applicationItem['id'] ?? 0)
                            ]
                        );
                    ?>
                    <article class="application-timeline-item status-<?php echo htmlspecialchars($applicationStatus); ?>">
                        <div class="application-timeline-top">
                            <div class="application-timeline-main">
                                <div class="application-timeline-head">
                                    <span class="application-reference"><?php echo htmlspecialchars($referenceNumber); ?></span>
                                    <span class="application-status-pill <?php echo htmlspecialchars($statusPillClass); ?>"><?php echo htmlspecialchars($statusPillLabel); ?></span>
                                </div>
                                <h4><?php echo htmlspecialchars((string) ($applicationItem['scholarship_name'] ?? 'Scholarship Application')); ?></h4>
                                <p><?php echo htmlspecialchars($providerName); ?></p>
                            </div>
                            <div class="application-timeline-summary">
                                <strong><?php echo htmlspecialchars((string) ($applicationItem['current_stage_title'] ?? 'Pending review')); ?></strong>
                                <span><?php echo htmlspecialchars((string) ($applicationItem['current_stage_note'] ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="application-meta-strip">
                            <div class="application-meta-pill">
                                <span class="label">Submitted</span>
                                <span class="value"><?php echo htmlspecialchars(profileFormatTimelineDate($applicationItem['applied_at'] ?? null)); ?></span>
                            </div>
                            <div class="application-meta-pill">
                                <span class="label">Deadline</span>
                                <span class="value"><?php echo htmlspecialchars($deadlineValue); ?></span>
                            </div>
                            <div class="application-meta-pill">
                                <span class="label">Score</span>
                                <span class="value"><?php echo htmlspecialchars($scoreValue); ?></span>
                            </div>
                            <div class="application-meta-pill">
                                <span class="label">Requirements</span>
                                <span class="value">
                                    <?php
                                    $totalRequirements = (int) ($applicationItem['documents_total_count'] ?? 0);
                                    $verifiedRequirements = (int) ($applicationItem['documents_verified_count'] ?? 0);
                                    echo htmlspecialchars($totalRequirements > 0 ? ($verifiedRequirements . '/' . $totalRequirements . ' verified') : 'No requirements');
                                    ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($applicationItem['can_accept'])): ?>
                        <div class="application-response-row">
                            <div class="application-response-copy">
                                <strong>Ready for your confirmation</strong>
                                <p>This application is already approved. Accept the scholarship to record your confirmation and notify the provider.</p>
                            </div>
                            <form
                                method="POST"
                                action="<?php echo htmlspecialchars($acceptUrl); ?>"
                                class="application-response-form"
                                data-scholarship-name="<?php echo htmlspecialchars((string) ($applicationItem['scholarship_name'] ?? 'this scholarship')); ?>">
                                <input type="hidden" name="action" value="accept">
                                <?php echo csrfInputField('application_accept'); ?>
                                <input type="hidden" name="id" value="<?php echo (int) ($applicationItem['id'] ?? 0); ?>">
                                <button type="submit" class="btn btn-primary application-response-btn">
                                    <i class="fas fa-circle-check"></i> Accept Scholarship
                                </button>
                            </form>
                        </div>
                        <?php elseif ($studentAccepted): ?>
                        <div class="application-response-row accepted">
                            <div class="application-response-copy">
                                <strong>Acceptance recorded</strong>
                                <p><?php echo htmlspecialchars((string) ($applicationItem['student_response_note'] ?? 'Your confirmation has been saved.')); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="application-stage-grid">
                            <?php foreach (($applicationItem['timeline_steps'] ?? []) as $step): ?>
                            <div class="application-stage-item state-<?php echo htmlspecialchars((string) ($step['state'] ?? 'upcoming')); ?>">
                                <div class="application-stage-marker">
                                    <i class="fas <?php echo htmlspecialchars((string) ($step['icon'] ?? 'fa-circle')); ?>"></i>
                                </div>
                                <div class="application-stage-copy">
                                    <strong><?php echo htmlspecialchars((string) ($step['label'] ?? 'Stage')); ?></strong>
                                    <p><?php echo htmlspecialchars((string) ($step['detail'] ?? '')); ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <?php include 'layout/quick-action.php' ?>
        </div>
    </section>

    <?php include 'layout/footer.php' ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <?php if ($isLoggedIn): ?>
        <?php include 'partials/profile_location_modal.php'; ?>
    <?php endif; ?>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/script.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/sweetalert.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/password-policy.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/dashboard.js')); ?>"></script>
    <script>
    document.querySelectorAll('.application-response-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();

            const scholarshipName = form.dataset.scholarshipName || 'this scholarship';
            if (!window.Swal || typeof window.Swal.fire !== 'function') {
                form.dataset.confirmed = '1';
                form.submit();
                return;
            }

            const result = await window.Swal.fire({
                title: 'Accept scholarship?',
                text: `You are about to confirm your acceptance for ${scholarshipName}.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Accept Scholarship',
                confirmButtonColor: '#2c5aa0',
                cancelButtonColor: '#64748b'
            });

            if (result.isConfirmed) {
                form.dataset.confirmed = '1';
                form.submit();
            }
        });
    });
    </script>
</body>
</html>
