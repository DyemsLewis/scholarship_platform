<?php
// profile.php
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Controllers/scholarshipResultController.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/StudentData.php';

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
</body>
</html>
