<?php
// Config/init.php - NO WHITESPACE BEFORE THIS LINE
require_once __DIR__ . '/session_bootstrap.php';

require_once 'db_config.php';
require_once 'helpers.php';
require_once 'access_control.php';

spl_autoload_register(function ($class) {
    $paths = [
        __DIR__ . '/../Models/' . $class . '.php',
        __DIR__ . '/../Controllers/' . $class . '.php'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return true;
        }
    }
    return false;
});

// Initialize user data if logged in
$isLoggedIn = false;
$currentUser = null;
$userName = 'Guest';
$userDisplayName = 'Guest';
$userEmail = '';
$userSchool = '';
$userCourse = '';
$userGWA = null;
$userApplicantType = '';
$userShsSchool = '';
$userShsStrand = '';
$userShsGraduationYear = '';
$userShsAverage = '';
$userAdmissionStatus = '';
$userTargetCollege = '';
$userTargetCourse = '';
$userYearLevel = '';
$userEnrollmentStatus = '';
$userAcademicStanding = '';
$userLatitude = null;
$userLongitude = null;
$userRole = 'guest';
$userFirstName = '';
$userLastName = '';
$userMiddleInitial = '';
$userSuffix = '';
$userAge = '';
$userBirthdate = '';
$userGender = '';
$userAddress = '';
$userHouseNo = '';
$userStreet = '';
$userBarangay = '';
$userCity = '';
$userProvince = '';
$userMobileNumber = '';
$userCitizenship = '';
$userHouseholdIncomeBracket = '';
$userSpecialCategory = '';
$userLocationName = '';
$userProfileImagePath = '';
$userCreatedAt = null;
$userAcademicScore = null;
$userAcademicMetricLabel = 'GWA';
$userAcademicSourceLabel = 'GWA';
$userAcademicRequirementLabel = 'minimum GWA';
$userAcademicDocumentLabel = 'TOR/grades';

if (isset($_SESSION['user_id'])) {
    $isLoggedIn = true;
    
    // Set display name from session
    $userDisplayName = $_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? 'User';
    $userName = $userDisplayName;
    
    // Set other user data from session
    $userEmail = $_SESSION['user_email'] ?? '';
    $userRole = normalizeUserRole($_SESSION['user_role'] ?? ($_SESSION['admin_role'] ?? 'student'));
    syncRoleSessionMeta($userRole);
    
    // Student data
    $user_id = $_SESSION['user_id'];
    $userFirstName = $_SESSION['user_firstname'] ?? '';
    $userLastName = $_SESSION['user_lastname'] ?? '';
    $userMiddleInitial = $_SESSION['user_middleinitial'] ?? '';
    $userSuffix = $_SESSION['user_suffix'] ?? '';
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
    $userMobileNumber = formatPhilippineMobileNumber($_SESSION['user_mobile_number'] ?? '');
    $userCitizenship = $_SESSION['user_citizenship'] ?? '';
    $userHouseholdIncomeBracket = $_SESSION['user_household_income_bracket'] ?? '';
    $userSpecialCategory = $_SESSION['user_special_category'] ?? '';
    $userProfileImagePath = $_SESSION['user_profile_image_path'] ?? '';
    // Location data - from student_location table
    $userLatitude = $_SESSION['user_latitude'] ?? null;
    $userLongitude = $_SESSION['user_longitude'] ?? null;
    $userLocationName = $_SESSION['user_location_name'] ?? '';
    
    // Account creation date
    $userCreatedAt = $_SESSION['user_created_at'] ?? null;
    $userAcademicScore = resolveApplicantAcademicScore($userApplicantType, $userGWA, $userShsAverage);
    $userAcademicMetricLabel = getApplicantAcademicMetricLabel($userApplicantType);
    $userAcademicSourceLabel = getApplicantAcademicSourceLabel($userApplicantType);
    $userAcademicRequirementLabel = getApplicantAcademicRequirementLabel($userApplicantType);
    $userAcademicDocumentLabel = getApplicantAcademicDocumentLabel($userApplicantType);

    if ($userRole === 'student') {
        try {
            $studentDataModel = new StudentData($pdo);
            $latestStudentData = $studentDataModel->getByUserId((int) $user_id);

            if (is_array($latestStudentData) && !empty($latestStudentData)) {
                $sessionFieldMap = [
                    'firstname' => 'user_firstname',
                    'lastname' => 'user_lastname',
                    'middleinitial' => 'user_middleinitial',
                    'suffix' => 'user_suffix',
                    'school' => 'user_school',
                    'course' => 'user_course',
                    'gwa' => 'user_gwa',
                    'applicant_type' => 'user_applicant_type',
                    'shs_school' => 'user_shs_school',
                    'shs_strand' => 'user_shs_strand',
                    'shs_graduation_year' => 'user_shs_graduation_year',
                    'shs_average' => 'user_shs_average',
                    'admission_status' => 'user_admission_status',
                    'target_college' => 'user_target_college',
                    'target_course' => 'user_target_course',
                    'year_level' => 'user_year_level',
                    'enrollment_status' => 'user_enrollment_status',
                    'academic_standing' => 'user_academic_standing',
                    'age' => 'user_age',
                    'birthdate' => 'user_birthdate',
                    'gender' => 'user_gender',
                    'address' => 'user_address',
                    'house_no' => 'user_house_no',
                    'street' => 'user_street',
                    'barangay' => 'user_barangay',
                    'city' => 'user_city',
                    'province' => 'user_province',
                    'mobile_number' => 'user_mobile_number',
                    'citizenship' => 'user_citizenship',
                    'household_income_bracket' => 'user_household_income_bracket',
                    'special_category' => 'user_special_category',
                    'profile_image_path' => 'user_profile_image_path',
                ];

                foreach ($sessionFieldMap as $dataKey => $sessionKey) {
                    if (array_key_exists($dataKey, $latestStudentData)) {
                        $_SESSION[$sessionKey] = $dataKey === 'mobile_number'
                            ? formatPhilippineMobileNumber($latestStudentData[$dataKey] ?? '')
                            : $latestStudentData[$dataKey];
                    }
                }
            }

            $locationModel = new Location($pdo);
            $latestLocation = $locationModel->getByStudentId((int) $user_id);
            if (is_array($latestLocation) && !empty($latestLocation)) {
                $_SESSION['user_latitude'] = $latestLocation['latitude'] ?? null;
                $_SESSION['user_longitude'] = $latestLocation['longitude'] ?? null;
                $_SESSION['user_location_name'] = $latestLocation['location_name'] ?? '';
            }

            $userFirstName = $_SESSION['user_firstname'] ?? '';
            $userLastName = $_SESSION['user_lastname'] ?? '';
            $userMiddleInitial = $_SESSION['user_middleinitial'] ?? '';
            $userSuffix = $_SESSION['user_suffix'] ?? '';
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
            $userMobileNumber = formatPhilippineMobileNumber($_SESSION['user_mobile_number'] ?? '');
            $userCitizenship = $_SESSION['user_citizenship'] ?? '';
            $userHouseholdIncomeBracket = $_SESSION['user_household_income_bracket'] ?? '';
            $userSpecialCategory = $_SESSION['user_special_category'] ?? '';
            $userProfileImagePath = $_SESSION['user_profile_image_path'] ?? '';
            $userLatitude = $_SESSION['user_latitude'] ?? null;
            $userLongitude = $_SESSION['user_longitude'] ?? null;
            $userLocationName = $_SESSION['user_location_name'] ?? '';
            $userAcademicScore = resolveApplicantAcademicScore($userApplicantType, $userGWA, $userShsAverage);
            $userAcademicMetricLabel = getApplicantAcademicMetricLabel($userApplicantType);
            $userAcademicSourceLabel = getApplicantAcademicSourceLabel($userApplicantType);
            $userAcademicRequirementLabel = getApplicantAcademicRequirementLabel($userApplicantType);
            $userAcademicDocumentLabel = getApplicantAcademicDocumentLabel($userApplicantType);

            $resolvedDisplayName = trim($userFirstName . ' ' . $userLastName);
            if ($resolvedDisplayName !== '') {
                $_SESSION['user_display_name'] = $resolvedDisplayName;
                $userDisplayName = $resolvedDisplayName;
                $userName = $resolvedDisplayName;
            }
        } catch (Throwable $e) {
            error_log('init student profile sync error: ' . $e->getMessage());
        }
    }
}

// Check current access levels
$isProvider = ($userRole === 'provider');
$isAdmin = ($userRole === 'admin' || $userRole === 'super_admin');
$isProviderOrAdmin = ($isProvider || $isAdmin);

// Common functions
function redirect($url) {
    header('Location: ' . normalizeAppUrl($url));
    exit();
}

function getCurrentPage() {
    return basename($_SERVER['PHP_SELF']);
}

function isActivePage($page) {
    return getCurrentPage() == $page ? 'active' : '';
}
?>
