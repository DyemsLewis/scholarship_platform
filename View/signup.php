<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/signup_verification.php';

if ($isLoggedIn) {
    redirect($isProviderOrAdmin ? '../AdminView/admin_dashboard.php' : 'index.php');
}

$signupVerificationDbReady = signupVerificationTableReady($pdo);
$lastVerifiedEmail = trim((string) ($_SESSION['signup_verification_last_email'] ?? ''));
$signupOld = isset($_SESSION['signup_old']) && is_array($_SESSION['signup_old']) ? $_SESSION['signup_old'] : [];
$signupErrors = isset($_SESSION['errors']) && is_array($_SESSION['errors']) ? $_SESSION['errors'] : [];
unset($_SESSION['signup_old'], $_SESSION['errors']);

function signupOldValue(array $oldInput, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $oldInput)) {
        return $default;
    }

    return trim((string) $oldInput[$key]);
}

function signupSelected(array $oldInput, string $key, string $expectedValue, string $default = ''): string
{
    $currentValue = signupOldValue($oldInput, $key, $default);
    return $currentValue === $expectedValue ? 'selected' : '';
}

function signupChecked(array $oldInput, string $key): string
{
    return signupOldValue($oldInput, $key) !== '' ? 'checked' : '';
}

function signupLocalMobileValue(array $oldInput): string
{
    $value = signupOldValue($oldInput, 'mobile_number');
    if ($value === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '63')) {
        $digits = substr($digits, 2);
    }

    if (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    return substr($digits, 0, 10);
}

$signupEmailValue = signupOldValue($signupOld, 'email', $lastVerifiedEmail);
$signupApplicantTypeValue = signupOldValue($signupOld, 'applicant_type');
$verifiedEmailStateValue = ($signupEmailValue !== '' && isSignupEmailVerified($pdo, $signupEmailValue))
    ? normalizeSignupVerificationEmail($signupEmailValue)
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship - Create Account</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/signup.css')); ?>">
</head>
<body>
    <!-- Header -->
    <?php include 'layout/header.php'; ?>

    <!-- Signup Section -->
    <section class="signup-page">
        <div class="container">
            <div class="signup-container">
                <div class="signup-card">
                    <div class="signup-header">
                        <i class="fas fa-graduation-cap signup-header-icon"></i>
                        <h1>Create Your Account</h1>
                        <p>Join Scholarship Finder to discover opportunities</p>
                    </div>

                    <div class="signup-note">
                        <strong>Student registration:</strong> verify your email first, complete the student information that matches your academic path, and use a complete address so location-based scholarship matching works properly.
                    </div>

                    <?php if (!$signupVerificationDbReady): ?>
                        <div class="signup-errors">
                            <strong>Email verification setup is incomplete.</strong>
                            <div>Run the signup verification migration first, then refresh this page.</div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($signupErrors)): ?>
                        <div class="signup-errors">
                            <strong>Please fix the following issues:</strong>
                            <ul>
                                <?php foreach ($signupErrors as $signupError): ?>
                                    <li><?php echo htmlspecialchars((string) $signupError); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Signup Form -->
                    <form id="signupForm" method="POST" action="../app/Controllers/registerController.php" enctype="multipart/form-data" novalidate>
                        <?php echo csrfInputField('student_signup'); ?>
                        <!-- Personal Information Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-user-circle"></i>
                                Personal Information
                            </h3>
                            
                            <div class="signup-grid">
                                <div class="form-group">
                                    <label for="signupFirstname">First Name *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user"></i>
                                        <input type="text" id="signupFirstname" name="firstname" placeholder="Enter your first name" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'firstname')); ?>" required>
                                    </div>
                                    <span class="field-error-message" id="firstnameError"></span>
                                </div>

                                <div class="form-group">
                                    <label for="signupLastname">Last Name *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user"></i>
                                        <input type="text" id="signupLastname" name="lastname" placeholder="Enter your last name" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'lastname')); ?>" required>
                                    </div>
                                    <span class="field-error-message" id="lastnameError"></span>
                                </div>

                                <div class="form-group">
                                    <label for="signupMiddlename">Middle Initial</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user-tag"></i>
                                        <input type="text" id="signupMiddlename" name="middleinitial" placeholder="e.g., D" maxlength="1" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'middleinitial')); ?>">
                                    </div>
                                    <small class="hint">Only one letter, no period or numbers</small>
                                    <span class="field-error-message" id="middlenameError"></span>
                                </div>

                                <div class="form-group">
                                    <label for="signupSuffix">Suffix</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-id-badge"></i>
                                        <select id="signupSuffix" name="suffix">
                                            <option value="" <?php echo signupSelected($signupOld, 'suffix', '', ''); ?>>None</option>
                                            <option value="Jr." <?php echo signupSelected($signupOld, 'suffix', 'Jr.'); ?>>Jr.</option>
                                            <option value="Sr." <?php echo signupSelected($signupOld, 'suffix', 'Sr.'); ?>>Sr.</option>
                                            <option value="II" <?php echo signupSelected($signupOld, 'suffix', 'II'); ?>>II</option>
                                            <option value="III" <?php echo signupSelected($signupOld, 'suffix', 'III'); ?>>III</option>
                                            <option value="IV" <?php echo signupSelected($signupOld, 'suffix', 'IV'); ?>>IV</option>
                                            <option value="V" <?php echo signupSelected($signupOld, 'suffix', 'V'); ?>>V</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupGender">Gender *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-venus-mars"></i>
                                        <select id="signupGender" name="gender" required>
                                            <option value="" <?php echo signupSelected($signupOld, 'gender', '', ''); ?>>Select gender</option>
                                            <option value="male" <?php echo signupSelected($signupOld, 'gender', 'male'); ?>>Male</option>
                                            <option value="female" <?php echo signupSelected($signupOld, 'gender', 'female'); ?>>Female</option>
                                            <option value="other" <?php echo signupSelected($signupOld, 'gender', 'other'); ?>>Other</option>
                                            <option value="prefer_not_to_say" <?php echo signupSelected($signupOld, 'gender', 'prefer_not_to_say'); ?>>Prefer not to say</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupBirthdate">Birthdate *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-calendar-alt"></i>
                                        <input type="date" id="signupBirthdate" name="birthdate" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'birthdate')); ?>" required
                                               max="<?php echo date('Y-m-d', strtotime('-15 years')); ?>"
                                               min="<?php echo date('Y-m-d', strtotime('-100 years')); ?>">
                                    </div>
                                    <small class="hint">You must be at least 15 years old</small>
                                </div>

                                <div class="form-group">
                                    <label for="signupAge">Age *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-calendar-check"></i>
                                        <input type="number" id="signupAge" name="age" class="readonly-input" placeholder="Auto-calculated from birthdate" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'age')); ?>" readonly>
                                    </div>
                                    <small class="hint">Automatically calculated from birthdate</small>
                                </div>
                            </div>
                            
                            <!-- Age calculation hint -->
                            <div class="age-hint">
                                <i class="fas fa-info-circle"></i>
                                <span>Your age will be automatically calculated from your birthdate.</span>
                            </div>
                        </div>
                        
                        <!-- Account Information Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-id-card"></i>
                                Account Information
                            </h3>
                            
                            <div class="signup-grid">
                                <div class="form-group">
                                    <label for="signupName">Username *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-at"></i>
                                        <input type="text" id="signupName" name="name" placeholder="Choose a username" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'name')); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-group signup-full-width">
                                    <label for="signupEmail">Email Address *</label>
                                    <div class="email-verification-group">
                                        <div class="input-with-icon">
                                            <i class="fas fa-envelope"></i>
                                            <input
                                                type="email"
                                                id="signupEmail"
                                                name="email"
                                                placeholder="Enter your email"
                                                value="<?php echo htmlspecialchars($signupEmailValue); ?>"
                                                required
                                            >
                                        </div>
                                        <button type="button" class="btn btn-outline btn-inline" id="sendVerificationCodeBtn" <?php echo $signupVerificationDbReady ? '' : 'disabled'; ?>>
                                            Send Code
                                        </button>
                                    </div>
                                    <small class="hint">We will send a 6-digit verification code to this email before signup can continue.</small>
                                    <div class="verification-code-row">
                                        <div class="input-with-icon">
                                            <i class="fas fa-key"></i>
                                            <input
                                                type="text"
                                                id="signupVerificationCode"
                                                inputmode="numeric"
                                                maxlength="6"
                                                placeholder="Enter 6-digit verification code"
                                            >
                                        </div>
                                        <button type="button" class="btn btn-primary btn-inline" id="verifyEmailCodeBtn" <?php echo $signupVerificationDbReady ? '' : 'disabled'; ?>>
                                            Verify Email
                                        </button>
                                    </div>
                                    <div class="verification-status" id="emailVerificationStatus"></div>
                                    <input type="hidden" id="verifiedEmailState" value="<?php echo htmlspecialchars($verifiedEmailStateValue); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Educational Information Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-graduation-cap"></i>
                                Educational Information
                            </h3>

                            <div class="academic-path-note">
                                <i class="fas fa-circle-info"></i>
                                <div>
                                    Use the path that matches you best. Incoming freshmen can provide senior high school and admission details, while current college students can provide year level and enrollment details.
                                </div>
                            </div>
                            
                            <div class="signup-grid">
                                <div class="form-group signup-full-width">
                                    <label for="signupApplicantType">Applicant Type *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user-graduate"></i>
                                        <select id="signupApplicantType" name="applicant_type" required>
                                            <option value="" <?php echo signupSelected($signupOld, 'applicant_type', '', ''); ?>>Select applicant type</option>
                                            <option value="incoming_freshman" <?php echo signupSelected($signupOld, 'applicant_type', 'incoming_freshman'); ?>>Incoming Freshman</option>
                                            <option value="current_college" <?php echo signupSelected($signupOld, 'applicant_type', 'current_college'); ?>>Current College Student</option>
                                            <option value="transferee" <?php echo signupSelected($signupOld, 'applicant_type', 'transferee'); ?>>Transferee</option>
                                            <option value="continuing_student" <?php echo signupSelected($signupOld, 'applicant_type', 'continuing_student'); ?>>Continuing Student</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div
                                    class="form-group signup-full-width"
                                    id="signupSchoolGroup"
                                    <?php echo $signupApplicantTypeValue === 'incoming_freshman' ? 'style="display: none;"' : ''; ?>
                                >
                                    <label for="signupSchool" id="signupSchoolLabel">School/University *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-university"></i>
                                        <input type="text" id="signupSchool" name="school" placeholder="Enter your school or university name" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'school')); ?>" <?php echo $signupApplicantTypeValue === 'incoming_freshman' ? '' : 'required'; ?>>
                                    </div>
                                </div>

                                <div class="form-group signup-full-width">
                                    <label for="signupCourse" id="signupCourseLabel"><?php echo $signupApplicantTypeValue === 'incoming_freshman' ? 'Planned Course/Program *' : 'Course/Program *'; ?></label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-book-open"></i>
                                        <select id="signupCourse" name="course" required>
                                            <option value="" <?php echo signupSelected($signupOld, 'course', '', ''); ?>>Select your course/program</option>
                                            <option value="Bachelor of Science in Information Technology" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Science in Information Technology'); ?>>Bachelor of Science in Information Technology</option>
                                            <option value="Bachelor of Science in Computer Science" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Science in Computer Science'); ?>>Bachelor of Science in Computer Science</option>
                                            <option value="Bachelor of Science in Information Systems" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Science in Information Systems'); ?>>Bachelor of Science in Information Systems</option>
                                            <option value="Bachelor of Science in Accountancy" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Science in Accountancy'); ?>>Bachelor of Science in Accountancy</option>
                                            <option value="Bachelor of Science in Business Administration" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Science in Business Administration'); ?>>Bachelor of Science in Business Administration</option>
                                            <option value="Bachelor of Science in Nursing" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Science in Nursing'); ?>>Bachelor of Science in Nursing</option>
                                            <option value="Bachelor of Science in Education" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Science in Education'); ?>>Bachelor of Science in Education</option>
                                            <option value="Bachelor of Science in Engineering" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Science in Engineering'); ?>>Bachelor of Science in Engineering</option>
                                            <option value="Bachelor of Arts in Communication" <?php echo signupSelected($signupOld, 'course', 'Bachelor of Arts in Communication'); ?>>Bachelor of Arts in Communication</option>
                                            <option value="other" <?php echo signupSelected($signupOld, 'course', 'other'); ?>>Other (Specify)</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group signup-full-width course-other-group" id="courseOtherGroup">
                                    <label for="signupCourseOther">Specify Course/Program *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-pen"></i>
                                        <input type="text" id="signupCourseOther" name="course_other" placeholder="Enter your course/program" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'course_other')); ?>">
                                    </div>
                                </div>

                                <div class="form-group incoming-student-field">
                                    <label for="signupAdmissionStatus" id="signupAdmissionStatusLabel"><?php echo $signupApplicantTypeValue === 'incoming_freshman' ? 'Admission Status *' : 'Admission / Enrollment Status'; ?></label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-clipboard-check"></i>
                                        <select id="signupAdmissionStatus" name="admission_status">
                                            <option value="" <?php echo signupSelected($signupOld, 'admission_status', '', ''); ?>>Select status</option>
                                            <option value="not_yet_applied" <?php echo signupSelected($signupOld, 'admission_status', 'not_yet_applied'); ?>>Not Yet Applied</option>
                                            <option value="applied" <?php echo signupSelected($signupOld, 'admission_status', 'applied'); ?>>Applied</option>
                                            <option value="admitted" <?php echo signupSelected($signupOld, 'admission_status', 'admitted'); ?>>Admitted</option>
                                            <option value="enrolled" <?php echo signupSelected($signupOld, 'admission_status', 'enrolled'); ?>>Enrolled</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupTargetCourse">Target Course</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-compass-drafting"></i>
                                        <input type="text" id="signupTargetCourse" name="target_course" placeholder="Planned or preferred course" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'target_course', signupOldValue($signupOld, 'course_other'))); ?>">
                                    </div>
                                </div>

                                <div class="form-group current-student-field">
                                    <label for="signupYearLevel">Year Level *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-layer-group"></i>
                                        <select id="signupYearLevel" name="year_level">
                                            <option value="" <?php echo signupSelected($signupOld, 'year_level', '', ''); ?>>Select year level</option>
                                            <option value="1st_year" <?php echo signupSelected($signupOld, 'year_level', '1st_year'); ?>>1st Year</option>
                                            <option value="2nd_year" <?php echo signupSelected($signupOld, 'year_level', '2nd_year'); ?>>2nd Year</option>
                                            <option value="3rd_year" <?php echo signupSelected($signupOld, 'year_level', '3rd_year'); ?>>3rd Year</option>
                                            <option value="4th_year" <?php echo signupSelected($signupOld, 'year_level', '4th_year'); ?>>4th Year</option>
                                            <option value="5th_year_plus" <?php echo signupSelected($signupOld, 'year_level', '5th_year_plus'); ?>>5th Year+</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group current-student-field">
                                    <label for="signupEnrollmentStatus">Enrollment Status *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user-check"></i>
                                        <select id="signupEnrollmentStatus" name="enrollment_status">
                                            <option value="" <?php echo signupSelected($signupOld, 'enrollment_status', '', ''); ?>>Select enrollment status</option>
                                            <option value="currently_enrolled" <?php echo signupSelected($signupOld, 'enrollment_status', 'currently_enrolled'); ?>>Currently Enrolled</option>
                                            <option value="regular" <?php echo signupSelected($signupOld, 'enrollment_status', 'regular'); ?>>Regular</option>
                                            <option value="irregular" <?php echo signupSelected($signupOld, 'enrollment_status', 'irregular'); ?>>Irregular</option>
                                            <option value="leave_of_absence" <?php echo signupSelected($signupOld, 'enrollment_status', 'leave_of_absence'); ?>>Leave of Absence</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group current-student-field">
                                    <label for="signupAcademicStanding">Academic Standing</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-award"></i>
                                        <select id="signupAcademicStanding" name="academic_standing">
                                            <option value="" <?php echo signupSelected($signupOld, 'academic_standing', '', ''); ?>>Select academic standing</option>
                                            <option value="good_standing" <?php echo signupSelected($signupOld, 'academic_standing', 'good_standing'); ?>>Good Standing</option>
                                            <option value="deans_list" <?php echo signupSelected($signupOld, 'academic_standing', 'deans_list'); ?>>Dean's List</option>
                                            <option value="probationary" <?php echo signupSelected($signupOld, 'academic_standing', 'probationary'); ?>>Probationary</option>
                                            <option value="graduating" <?php echo signupSelected($signupOld, 'academic_standing', 'graduating'); ?>>Graduating</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group incoming-student-field">
                                    <label for="signupShsSchool">Senior High School *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-school"></i>
                                        <input type="text" id="signupShsSchool" name="shs_school" placeholder="Enter your senior high school" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'shs_school')); ?>">
                                    </div>
                                </div>

                                <div class="form-group incoming-student-field">
                                    <label for="signupShsStrand">SHS Strand *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-sitemap"></i>
                                        <input type="text" id="signupShsStrand" name="shs_strand" placeholder="e.g., STEM, ABM, HUMSS" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'shs_strand')); ?>">
                                    </div>
                                </div>

                                <div class="form-group incoming-student-field">
                                    <label for="signupShsGraduationYear">SHS Graduation Year *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-calendar-day"></i>
                                        <input type="number" id="signupShsGraduationYear" name="shs_graduation_year" min="2000" max="<?php echo date('Y') + 6; ?>" placeholder="e.g., 2026" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'shs_graduation_year')); ?>">
                                    </div>
                                </div>

                                <div class="form-group signup-full-width incoming-student-field">
                                    <label for="signupTargetCollege">Preferred College / University *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-building-columns"></i>
                                        <input type="text" id="signupTargetCollege" name="target_college" placeholder="Enter the college you plan to attend" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'target_college')); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Family and Address Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-house-user"></i>
                                Family and Address
                            </h3>

                            <div class="signup-grid">
                                <div class="form-group">
                                    <label for="signupMobileNumber">Mobile Number</label>
                                    <div class="phone-input-shell">
                                        <span class="phone-input-icon"><i class="fas fa-phone"></i></span>
                                        <span class="phone-prefix">+63</span>
                                        <input type="text" id="signupMobileNumber" inputmode="numeric" maxlength="10" placeholder="9123456789" value="<?php echo htmlspecialchars(signupLocalMobileValue($signupOld)); ?>">
                                        <input type="hidden" id="signupMobileNumberHidden" name="mobile_number" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'mobile_number')); ?>">
                                    </div>
                                    <small class="hint">Optional, but helpful for scholarship contact and review. Enter the 10-digit number after the country code.</small>
                                </div>

                                <div class="form-group">
                                    <label for="signupCitizenship">Citizenship</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-flag"></i>
                                        <select id="signupCitizenship" name="citizenship">
                                            <option value="" <?php echo signupSelected($signupOld, 'citizenship', '', ''); ?>>Select citizenship</option>
                                            <option value="filipino" <?php echo signupSelected($signupOld, 'citizenship', 'filipino'); ?>>Filipino</option>
                                            <option value="dual_citizen" <?php echo signupSelected($signupOld, 'citizenship', 'dual_citizen'); ?>>Dual Citizen</option>
                                            <option value="permanent_resident" <?php echo signupSelected($signupOld, 'citizenship', 'permanent_resident'); ?>>Permanent Resident</option>
                                            <option value="other" <?php echo signupSelected($signupOld, 'citizenship', 'other'); ?>>Other</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupHouseholdIncomeBracket">Household Income Bracket</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-wallet"></i>
                                        <select id="signupHouseholdIncomeBracket" name="household_income_bracket">
                                            <option value="" <?php echo signupSelected($signupOld, 'household_income_bracket', '', ''); ?>>Select income bracket</option>
                                            <option value="below_10000" <?php echo signupSelected($signupOld, 'household_income_bracket', 'below_10000'); ?>>Below PHP 10,000 / month</option>
                                            <option value="10000_20000" <?php echo signupSelected($signupOld, 'household_income_bracket', '10000_20000'); ?>>PHP 10,000 - 20,000 / month</option>
                                            <option value="20001_40000" <?php echo signupSelected($signupOld, 'household_income_bracket', '20001_40000'); ?>>PHP 20,001 - 40,000 / month</option>
                                            <option value="40001_80000" <?php echo signupSelected($signupOld, 'household_income_bracket', '40001_80000'); ?>>PHP 40,001 - 80,000 / month</option>
                                            <option value="above_80000" <?php echo signupSelected($signupOld, 'household_income_bracket', 'above_80000'); ?>>Above PHP 80,000 / month</option>
                                            <option value="prefer_not_to_say" <?php echo signupSelected($signupOld, 'household_income_bracket', 'prefer_not_to_say'); ?>>Prefer not to say</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupSpecialCategory">Special Scholarship Category</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-users-rectangle"></i>
                                        <select id="signupSpecialCategory" name="special_category">
                                            <option value="" <?php echo signupSelected($signupOld, 'special_category', '', ''); ?>>Select category</option>
                                            <option value="none" <?php echo signupSelected($signupOld, 'special_category', 'none'); ?>>None</option>
                                            <option value="pwd" <?php echo signupSelected($signupOld, 'special_category', 'pwd'); ?>>Person with Disability (PWD)</option>
                                            <option value="indigenous_peoples" <?php echo signupSelected($signupOld, 'special_category', 'indigenous_peoples'); ?>>Indigenous Peoples</option>
                                            <option value="solo_parent_dependent" <?php echo signupSelected($signupOld, 'special_category', 'solo_parent_dependent'); ?>>Dependent of Solo Parent</option>
                                            <option value="working_student" <?php echo signupSelected($signupOld, 'special_category', 'working_student'); ?>>Working Student</option>
                                            <option value="child_of_ofw" <?php echo signupSelected($signupOld, 'special_category', 'child_of_ofw'); ?>>Child of OFW</option>
                                            <option value="four_ps_beneficiary" <?php echo signupSelected($signupOld, 'special_category', 'four_ps_beneficiary'); ?>>4Ps Beneficiary</option>
                                            <option value="orphan" <?php echo signupSelected($signupOld, 'special_category', 'orphan'); ?>>Orphan / Ward</option>
                                        </select>
                                        <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupHouseNo">House # *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-house"></i>
                                        <input type="text" id="signupHouseNo" name="house_no" placeholder="e.g., BLK 1 LOT 2" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'house_no')); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupStreet">Street *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-road"></i>
                                        <input type="text" id="signupStreet" name="street" placeholder="Street name" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'street')); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupBarangay">Barangay *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-map-signs"></i>
                                        <input type="text" id="signupBarangay" name="barangay" placeholder="Barangay" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'barangay')); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="signupCity">City/Municipality *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-city"></i>
                                        <input type="text" id="signupCity" name="city" placeholder="City or municipality" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'city')); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group signup-full-width">
                                    <label for="signupProvince">Province *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-map"></i>
                                        <input type="text" id="signupProvince" name="province" placeholder="Province" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'province')); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group signup-full-width">
                                    <div class="pin-helper">
                                        <small id="signupPinStatusText">No map pin selected yet. We can geocode your address automatically.</small>
                                        <button type="button" class="btn btn-outline open-signup-location-modal">
                                            <i class="fas fa-map-marker-alt"></i> Set Pin on Map
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Security Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-shield-alt"></i>
                                Security
                            </h3>
                            
                            <div class="signup-grid">
                                <div class="form-group">
                                    <label for="signupPassword">Password *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-lock"></i>
                                        <input type="password" id="signupPassword" name="password" placeholder="Create a password" minlength="<?php echo passwordPolicyMinLength(); ?>" required>
                                    </div>
                                    <small class="hint"><?php echo htmlspecialchars(passwordPolicyHint()); ?></small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="signupConfirmPassword">Confirm Password *</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-lock"></i>
                                        <input type="password" id="signupConfirmPassword" name="confirm_password" placeholder="Confirm your password" minlength="<?php echo passwordPolicyMinLength(); ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address Geolocation -->
                        <div class="location-note">
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <strong>Address Geolocation:</strong> We convert your full address into coordinates in the background.
                                <small class="location-note-subtext">Use complete and accurate address details for better scholarship distance matching.</small>
                            </div>
                        </div>

                        <!-- Hidden Location Fields -->
                        <input type="hidden" name="latitude" id="latitude" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'latitude')); ?>">
                        <input type="hidden" name="longitude" id="longitude" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'longitude')); ?>">
                        <input type="hidden" name="location_name" id="location_name" value="<?php echo htmlspecialchars(signupOldValue($signupOld, 'location_name')); ?>">

                        <!-- Supporting Documents Section -->
                        <div class="form-section">
                            <h3>
                                <i class="fas fa-file-arrow-up"></i>
                                Supporting Documents
                            </h3>

                            <div class="academic-path-note compact-doc-note">
                                <i class="fas fa-circle-info"></i>
                                <div id="signupSupportingDocsNoteText">
                                    These uploads are optional during signup. TOR is helpful for current college students, Form 138 is helpful for incoming freshmen, and the support files below can back up citizenship, income, or scholarship-category details. You can still upload clearer copies later in Documents.
                                </div>
                            </div>

                            <div class="signup-grid compact-upload-grid">
                                <div class="compact-upload-card" id="signupTorUploadCard">
                                    <div class="compact-upload-header">
                                        <div>
                                            <h4>TOR / Grade Report</h4>
                                            <p>PDF, JPG, or PNG up to 5MB. We will try to scan your GWA after signup.</p>
                                        </div>
                                        <span class="compact-upload-badge">Optional</span>
                                    </div>
                                    <label for="signupTorFile" class="compact-upload-label">Upload TOR</label>
                                    <input
                                        type="file"
                                        id="signupTorFile"
                                        name="signup_tor_file"
                                        class="compact-file-input"
                                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    >
                                    <small class="compact-upload-status" id="signupTorFileStatus">No file selected</small>
                                </div>

                                <div class="compact-upload-card" id="signupForm138UploadCard">
                                    <div class="compact-upload-header">
                                        <div>
                                            <h4>Form 138</h4>
                                            <p>Senior high school report card in PDF, JPG, or PNG up to 5MB.</p>
                                        </div>
                                        <span class="compact-upload-badge">Optional</span>
                                    </div>
                                    <label for="signupForm138File" class="compact-upload-label">Upload Form 138</label>
                                    <input
                                        type="file"
                                        id="signupForm138File"
                                        name="signup_form138_file"
                                        class="compact-file-input"
                                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    >
                                    <small class="compact-upload-status" id="signupForm138FileStatus">No file selected</small>
                                </div>

                                <div class="compact-upload-card" id="signupCitizenshipUploadCard">
                                    <div class="compact-upload-header">
                                        <div>
                                            <h4>Citizenship / Residency Proof</h4>
                                            <p>Birth certificate, passport, or residency document in PDF, JPG, or PNG up to 5MB.</p>
                                        </div>
                                        <span class="compact-upload-badge" id="signupCitizenshipUploadBadge">Optional</span>
                                    </div>
                                    <label for="signupCitizenshipFile" class="compact-upload-label" id="signupCitizenshipUploadLabel">Upload proof</label>
                                    <input
                                        type="file"
                                        id="signupCitizenshipFile"
                                        name="signup_citizenship_file"
                                        class="compact-file-input"
                                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    >
                                    <small class="compact-upload-status" id="signupCitizenshipFileStatus">No file selected</small>
                                </div>

                                <div class="compact-upload-card" id="signupIncomeUploadCard">
                                    <div class="compact-upload-header">
                                        <div>
                                            <h4>Household Income Proof</h4>
                                            <p>Certificate of indigency, payslip, ITR, or income certification in PDF, JPG, or PNG up to 5MB.</p>
                                        </div>
                                        <span class="compact-upload-badge" id="signupIncomeUploadBadge">Optional</span>
                                    </div>
                                    <label for="signupIncomeFile" class="compact-upload-label" id="signupIncomeUploadLabel">Upload proof</label>
                                    <input
                                        type="file"
                                        id="signupIncomeFile"
                                        name="signup_income_file"
                                        class="compact-file-input"
                                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    >
                                    <small class="compact-upload-status" id="signupIncomeFileStatus">No file selected</small>
                                </div>

                                <div class="compact-upload-card" id="signupSpecialCategoryUploadCard">
                                    <div class="compact-upload-header">
                                        <div>
                                            <h4>Special Category Proof</h4>
                                            <p>PWD ID, 4Ps record, solo parent proof, IP certification, or similar file up to 5MB.</p>
                                        </div>
                                        <span class="compact-upload-badge" id="signupSpecialCategoryUploadBadge">Optional</span>
                                    </div>
                                    <label for="signupSpecialCategoryFile" class="compact-upload-label" id="signupSpecialCategoryUploadLabel">Upload proof</label>
                                    <input
                                        type="file"
                                        id="signupSpecialCategoryFile"
                                        name="signup_special_category_file"
                                        class="compact-file-input"
                                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    >
                                    <small class="compact-upload-status" id="signupSpecialCategoryFileStatus">No file selected</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Terms and Conditions -->
                        <div class="terms-checkbox">
                            <input type="checkbox" id="agreeTerms" name="agree_terms" value="1" <?php echo signupChecked($signupOld, 'agree_terms'); ?> required>
                            <label for="agreeTerms">
                                I agree to the <a href="#" onclick="showTerms()">Terms and Conditions</a> and <a href="#" onclick="showPrivacy()">Privacy Policy</a>
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn1 btn-primary" id="signupSubmitBtn">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>
                        
                        <div class="form-footer">
                            <p>Already have an account? <a href="login.php">Login here</a></p>
                            <p>Registering as a scholarship provider? <a href="provider_signup.php">Open provider registration</a></p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'layout/footer.php'; ?>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <?php include 'partials/signup_location_modal.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/sweetalert.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(assetUrl('public/js/password-policy.js')); ?>"></script>

    <script>
    // Helper function to check if string contains numbers
    function containsNumbers(str) {
        return /\d/.test(str);
    }
    
    // Helper function to check if string contains only letters and allowed characters (spaces, hyphens, apostrophes)
    function isValidName(str) {
        // Allow letters, spaces, hyphens, apostrophes, and dots (for names like O'Connor, Smith-Jones, Jr.)
        const nameRegex = /^[A-Za-z\s\-'\.]+$/;
        return nameRegex.test(str) && !containsNumbers(str);
    }
    
    // Helper function to validate middle initial (single letter, no numbers)
    function isValidMiddleInitial(str) {
        if (str === "") return true; // Empty is allowed since it's optional
        // Must be exactly one letter, no numbers or special characters
        const initialRegex = /^[A-Za-z]$/;
        return initialRegex.test(str);
    }

    function extractLocalMobileDigits(value) {
        let digits = value.replace(/\D/g, '');
        if (digits.startsWith('63')) {
            digits = digits.slice(2);
        }
        if (digits.startsWith('0')) {
            digits = digits.slice(1);
        }
        return digits.slice(0, 10);
    }

    function syncSignupMobileNumber() {
        const visibleInput = document.getElementById('signupMobileNumber');
        const hiddenInput = document.getElementById('signupMobileNumberHidden');
        if (!visibleInput || !hiddenInput) {
            return '';
        }

        const localDigits = extractLocalMobileDigits(visibleInput.value);
        visibleInput.value = localDigits;
        hiddenInput.value = localDigits ? `+63${localDigits}` : '';
        return localDigits;
    }
    
    // Clear error styling and message for a field
    function clearFieldError(inputId, errorId) {
        const input = document.getElementById(inputId);
        const errorSpan = document.getElementById(errorId);
        if (input) input.classList.remove('invalid-input');
        if (errorSpan) errorSpan.textContent = '';
    }
    
    // Set error styling and message for a field
    function setFieldError(inputId, errorId, message) {
        const input = document.getElementById(inputId);
        const errorSpan = document.getElementById(errorId);
        if (input) input.classList.add('invalid-input');
        if (errorSpan) errorSpan.textContent = message;
    }

    function markInvalidField(inputId) {
        const input = document.getElementById(inputId);
        if (input) {
            input.classList.add('invalid-input');
        }
    }

    function clearInvalidField(inputId) {
        const input = document.getElementById(inputId);
        if (input) {
            input.classList.remove('invalid-input');
        }
    }

    function focusField(inputId) {
        const input = document.getElementById(inputId);
        if (!input) {
            return;
        }

        input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => {
            input.focus();
        }, 120);
    }
    
    // Validate first name in real-time
    const firstNameInput = document.getElementById('signupFirstname');
    if (firstNameInput) {
        firstNameInput.addEventListener('input', function(e) {
            const value = this.value;
            if (value.length > 0 && !isValidName(value)) {
                setFieldError('signupFirstname', 'firstnameError', 'First name should contain only letters, spaces, hyphens, or apostrophes (no numbers)');
            } else {
                clearFieldError('signupFirstname', 'firstnameError');
            }
        });
        
        firstNameInput.addEventListener('blur', function(e) {
            const value = this.value.trim();
            if (value.length > 0 && !isValidName(value)) {
                setFieldError('signupFirstname', 'firstnameError', 'First name should contain only letters, spaces, hyphens, or apostrophes (no numbers)');
            } else if (value.length === 0) {
                // Required field, but we don't show error on blur if empty - form submission will handle
                clearFieldError('signupFirstname', 'firstnameError');
            } else {
                clearFieldError('signupFirstname', 'firstnameError');
            }
        });
    }
    
    // Validate last name in real-time
    const lastNameInput = document.getElementById('signupLastname');
    if (lastNameInput) {
        lastNameInput.addEventListener('input', function(e) {
            const value = this.value;
            if (value.length > 0 && !isValidName(value)) {
                setFieldError('signupLastname', 'lastnameError', 'Last name should contain only letters, spaces, hyphens, or apostrophes (no numbers)');
            } else {
                clearFieldError('signupLastname', 'lastnameError');
            }
        });
        
        lastNameInput.addEventListener('blur', function(e) {
            const value = this.value.trim();
            if (value.length > 0 && !isValidName(value)) {
                setFieldError('signupLastname', 'lastnameError', 'Last name should contain only letters, spaces, hyphens, or apostrophes (no numbers)');
            } else if (value.length === 0) {
                clearFieldError('signupLastname', 'lastnameError');
            } else {
                clearFieldError('signupLastname', 'lastnameError');
            }
        });
    }
    
    // Validate middle initial - only 1 character, no numbers, only letters
    const middleInitialInput = document.getElementById('signupMiddlename');
    if (middleInitialInput) {
        // Restrict input to only letters and enforce max length of 1
        middleInitialInput.addEventListener('input', function(e) {
            let value = this.value;
            // Remove any non-letter characters and numbers
            value = value.replace(/[^A-Za-z]/g, '');
            // Limit to 1 character
            if (value.length > 1) {
                value = value.charAt(0);
            }
            this.value = value;
            
            if (value.length > 0 && !isValidMiddleInitial(value)) {
                setFieldError('signupMiddlename', 'middlenameError', 'Middle initial must be a single letter (A-Z)');
            } else {
                clearFieldError('signupMiddlename', 'middlenameError');
            }
        });
        
        middleInitialInput.addEventListener('blur', function(e) {
            const value = this.value.trim();
            if (value.length > 0 && !isValidMiddleInitial(value)) {
                setFieldError('signupMiddlename', 'middlenameError', 'Middle initial must be a single letter (A-Z)');
            } else if (value.length > 1) {
                // Auto-correct if somehow more than 1 character
                this.value = value.charAt(0);
                if (!isValidMiddleInitial(this.value)) {
                    setFieldError('signupMiddlename', 'middlenameError', 'Middle initial must be a single letter (A-Z)');
                } else {
                    clearFieldError('signupMiddlename', 'middlenameError');
                }
            } else {
                clearFieldError('signupMiddlename', 'middlenameError');
            }
        });
        
        // Prevent paste that contains numbers or multiple characters
        middleInitialInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const cleanedText = pastedText.replace(/[^A-Za-z]/g, '');
            if (cleanedText.length > 0) {
                this.value = cleanedText.charAt(0);
            } else {
                this.value = '';
            }
            // Trigger input event to validate
            const inputEvent = new Event('input', { bubbles: true });
            this.dispatchEvent(inputEvent);
        });
    }
    
    // Function to calculate age from birthdate
    function calculateAge(birthdate) {
        const today = new Date();
        const birthDate = new Date(birthdate);
        let age = today.getFullYear() - birthDate.getFullYear();
        const monthDiff = today.getMonth() - birthDate.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        
        return age;
    }

    // Validate birthdate and update age field
    const birthdateInput = document.getElementById('signupBirthdate');
    if (birthdateInput) {
        birthdateInput.addEventListener('change', function() {
            const birthdate = this.value;
            if (birthdate) {
                const age = calculateAge(birthdate);
                document.getElementById('signupAge').value = age;
                
                // Check minimum age requirement
                if (age < 15) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Age Requirement',
                        text: 'You must be at least 15 years old to register.',
                        confirmButtonColor: '#3085d6'
                    });
                    this.value = '';
                    document.getElementById('signupAge').value = '';
                } else if (age > 100) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid Age',
                        text: 'Please enter a valid birthdate.',
                        confirmButtonColor: '#3085d6'
                    });
                    this.value = '';
                    document.getElementById('signupAge').value = '';
                }
            }
        });

        if (birthdateInput.value && !document.getElementById('signupAge').value) {
            birthdateInput.dispatchEvent(new Event('change'));
        }
    }

    const signupForm = document.getElementById('signupForm');
    const signupSubmitBtn = document.getElementById('signupSubmitBtn');
    const emailInput = document.getElementById('signupEmail');
    const mobileNumberInput = document.getElementById('signupMobileNumber');
    const mobileNumberHiddenInput = document.getElementById('signupMobileNumberHidden');
    const genderInput = document.getElementById('signupGender');
    const verificationCodeInput = document.getElementById('signupVerificationCode');
    const sendVerificationCodeBtn = document.getElementById('sendVerificationCodeBtn');
    const verifyEmailCodeBtn = document.getElementById('verifyEmailCodeBtn');
    const emailVerificationStatus = document.getElementById('emailVerificationStatus');
    const verifiedEmailStateInput = document.getElementById('verifiedEmailState');
    const applicantTypeSelect = document.getElementById('signupApplicantType');
    const schoolGroup = document.getElementById('signupSchoolGroup');
    const schoolInput = document.getElementById('signupSchool');
    const schoolLabel = document.getElementById('signupSchoolLabel');
    const courseLabel = document.getElementById('signupCourseLabel');
    const admissionStatusLabel = document.getElementById('signupAdmissionStatusLabel');
    const courseSelect = document.getElementById('signupCourse');
    const courseOtherGroup = document.getElementById('courseOtherGroup');
    const courseOtherInput = document.getElementById('signupCourseOther');
    const incomingStudentFields = Array.from(document.querySelectorAll('.incoming-student-field'));
    const currentStudentFields = Array.from(document.querySelectorAll('.current-student-field'));
    const targetCourseInput = document.getElementById('signupTargetCourse');
    const shsSchoolInput = document.getElementById('signupShsSchool');
    const shsStrandInput = document.getElementById('signupShsStrand');
    const shsGraduationYearInput = document.getElementById('signupShsGraduationYear');
    const targetCollegeInput = document.getElementById('signupTargetCollege');
    const admissionStatusInput = document.getElementById('signupAdmissionStatus');
    const yearLevelInput = document.getElementById('signupYearLevel');
    const enrollmentStatusInput = document.getElementById('signupEnrollmentStatus');
    const citizenshipSelect = document.getElementById('signupCitizenship');
    const householdIncomeBracketSelect = document.getElementById('signupHouseholdIncomeBracket');
    const specialCategorySelect = document.getElementById('signupSpecialCategory');
    const houseNoInput = document.getElementById('signupHouseNo');
    const streetInput = document.getElementById('signupStreet');
    const barangayInput = document.getElementById('signupBarangay');
    const cityInput = document.getElementById('signupCity');
    const provinceInput = document.getElementById('signupProvince');
    const torFileInput = document.getElementById('signupTorFile');
    const form138FileInput = document.getElementById('signupForm138File');
    const torFileStatus = document.getElementById('signupTorFileStatus');
    const form138FileStatus = document.getElementById('signupForm138FileStatus');
    const torUploadCard = document.getElementById('signupTorUploadCard');
    const form138UploadCard = document.getElementById('signupForm138UploadCard');
    const supportingDocsNoteText = document.getElementById('signupSupportingDocsNoteText');
    const citizenshipFileInput = document.getElementById('signupCitizenshipFile');
    const incomeFileInput = document.getElementById('signupIncomeFile');
    const specialCategoryFileInput = document.getElementById('signupSpecialCategoryFile');
    const citizenshipFileStatus = document.getElementById('signupCitizenshipFileStatus');
    const incomeFileStatus = document.getElementById('signupIncomeFileStatus');
    const specialCategoryFileStatus = document.getElementById('signupSpecialCategoryFileStatus');
    const citizenshipUploadCard = document.getElementById('signupCitizenshipUploadCard');
    const incomeUploadCard = document.getElementById('signupIncomeUploadCard');
    const specialCategoryUploadCard = document.getElementById('signupSpecialCategoryUploadCard');
    const citizenshipUploadBadge = document.getElementById('signupCitizenshipUploadBadge');
    const incomeUploadBadge = document.getElementById('signupIncomeUploadBadge');
    const specialCategoryUploadBadge = document.getElementById('signupSpecialCategoryUploadBadge');
    const citizenshipUploadLabel = document.getElementById('signupCitizenshipUploadLabel');
    const incomeUploadLabel = document.getElementById('signupIncomeUploadLabel');
    const specialCategoryUploadLabel = document.getElementById('signupSpecialCategoryUploadLabel');
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const locationNameInput = document.getElementById('location_name');
    const signupPinStatusText = document.getElementById('signupPinStatusText');
    const signupVerificationEnabled = <?php echo $signupVerificationDbReady ? 'true' : 'false'; ?>;

    let verifiedEmail = verifiedEmailStateInput ? verifiedEmailStateInput.value.trim().toLowerCase() : '';
    let resendCountdown = 0;
    let resendInterval = null;
    let isSubmitting = false;
    const optionalUploadMaxSize = 5 * 1024 * 1024;
    const optionalUploadAllowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    const compactUploadFields = [
        { input: torFileInput, status: torFileStatus, label: 'TOR / grade report' },
        { input: form138FileInput, status: form138FileStatus, label: 'Form 138' },
        { input: citizenshipFileInput, status: citizenshipFileStatus, label: 'Citizenship / residency proof' },
        { input: incomeFileInput, status: incomeFileStatus, label: 'Household income proof' },
        { input: specialCategoryFileInput, status: specialCategoryFileStatus, label: 'Special category proof' }
    ];

    if (signupPinStatusText && latitudeInput && longitudeInput && latitudeInput.value.trim() && longitudeInput.value.trim()) {
        signupPinStatusText.textContent = 'Pin already selected. Your saved map location will be used for registration.';
    }

    function normalizeEmail(email) {
        return email.trim().toLowerCase();
    }

    function setSignupSubmittingState(submitting) {
        isSubmitting = submitting;

        if (!signupSubmitBtn) {
            return;
        }

        signupSubmitBtn.disabled = submitting;
        signupSubmitBtn.innerHTML = submitting
            ? '<i class="fas fa-spinner fa-spin"></i> Creating Account...'
            : '<i class="fas fa-user-plus"></i> Create Account';
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function updateCompactUploadStatus(input, statusElement, emptyText) {
        if (!statusElement) {
            return;
        }

        const file = input && input.files && input.files[0] ? input.files[0] : null;
        if (!file) {
            statusElement.textContent = emptyText;
            statusElement.classList.remove('has-file');
            return;
        }

        const sizeInMb = file.size / (1024 * 1024);
        statusElement.textContent = `${file.name} (${sizeInMb.toFixed(2)} MB)`;
        statusElement.classList.add('has-file');
    }

    function resetCompactUploadField(input, statusElement) {
        if (!input) {
            return;
        }

        input.value = '';
        updateCompactUploadStatus(input, statusElement, 'No file selected');
    }

    function validateOptionalSignupFile(input, label) {
        if (!input || !input.files || !input.files[0]) {
            return true;
        }

        const file = input.files[0];
        const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';

        if (!optionalUploadAllowedExtensions.includes(extension)) {
            Swal.fire({
                icon: 'error',
                title: 'Unsupported File Type',
                text: `${label} must be a PDF, JPG, or PNG file.`,
                confirmButtonColor: '#3085d6'
            });
            input.focus();
            return false;
        }

        if (file.size > optionalUploadMaxSize) {
            Swal.fire({
                icon: 'error',
                title: 'File Too Large',
                text: `${label} must be 5MB or smaller.`,
                confirmButtonColor: '#3085d6'
            });
            input.focus();
            return false;
        }

        return true;
    }

    function updateConditionalProofState(config) {
        if (!config || !config.input || !config.status) {
            return false;
        }

        const skipValues = Array.isArray(config.skipValues) ? config.skipValues : [];
        const selectedValue = config.select ? config.select.value.trim() : '';
        const isRequired = selectedValue !== '' && !skipValues.includes(selectedValue);
        const hasFile = !!(config.input.files && config.input.files[0]);

        config.input.required = isRequired;

        if (config.card) {
            config.card.classList.toggle('is-required', isRequired);
        }

        if (config.badge) {
            config.badge.textContent = isRequired ? 'Required' : 'Optional';
            config.badge.classList.toggle('is-required', isRequired);
        }

        if (config.label) {
            config.label.textContent = isRequired ? 'Upload proof *' : 'Upload proof';
        }

        if (!hasFile) {
            config.status.textContent = isRequired ? 'Required when selected' : 'No file selected';
            config.status.classList.remove('has-file');
            config.status.classList.toggle('is-required', isRequired);
        } else {
            config.status.classList.remove('is-required');
        }

        return isRequired;
    }

    function syncConditionalSupportUploads() {
        updateConditionalProofState({
            select: citizenshipSelect,
            input: citizenshipFileInput,
            status: citizenshipFileStatus,
            card: citizenshipUploadCard,
            badge: citizenshipUploadBadge,
            label: citizenshipUploadLabel
        });

        updateConditionalProofState({
            select: householdIncomeBracketSelect,
            input: incomeFileInput,
            status: incomeFileStatus,
            card: incomeUploadCard,
            badge: incomeUploadBadge,
            label: incomeUploadLabel,
            skipValues: ['prefer_not_to_say']
        });

        updateConditionalProofState({
            select: specialCategorySelect,
            input: specialCategoryFileInput,
            status: specialCategoryFileStatus,
            card: specialCategoryUploadCard,
            badge: specialCategoryUploadBadge,
            label: specialCategoryUploadLabel,
            skipValues: ['none']
        });
    }

    function validateConditionalProof(config) {
        if (!config || !config.input) {
            return true;
        }

        const skipValues = Array.isArray(config.skipValues) ? config.skipValues : [];
        const selectedValue = config.select ? config.select.value.trim() : '';
        const isRequired = selectedValue !== '' && !skipValues.includes(selectedValue);
        const hasFile = !!(config.input.files && config.input.files[0]);

        if (!isRequired || hasFile) {
            return true;
        }

        Swal.fire({
            icon: 'error',
            title: 'Supporting Document Required',
            text: config.message,
            confirmButtonColor: '#3085d6'
        });
        config.input.focus();
        return false;
    }

    function toggleCourseOtherField() {
        if (!courseSelect || !courseOtherGroup || !courseOtherInput) {
            return;
        }

        const showOther = courseSelect.value === 'other';
        courseOtherGroup.style.display = showOther ? 'block' : 'none';
        courseOtherInput.required = showOther;
        if (!showOther) {
            courseOtherInput.value = '';
        }
    }

    function toggleApplicantTypeFields() {
        if (!applicantTypeSelect) {
            return;
        }

        const applicantType = applicantTypeSelect.value;
        const isIncoming = applicantType === 'incoming_freshman';
        const isCurrentStudent = applicantType === 'current_college' || applicantType === 'transferee' || applicantType === 'continuing_student';

        if (schoolGroup) {
            schoolGroup.style.display = isIncoming ? 'none' : 'block';
        }
        if (schoolLabel) {
            schoolLabel.textContent = 'School/University *';
        }
        if (courseLabel) {
            courseLabel.textContent = isIncoming ? 'Planned Course/Program *' : 'Course/Program *';
        }
        if (admissionStatusLabel) {
            admissionStatusLabel.textContent = isIncoming ? 'Admission Status *' : 'Admission / Enrollment Status';
        }

        incomingStudentFields.forEach((field) => {
            field.style.display = isIncoming ? 'block' : 'none';
        });

        currentStudentFields.forEach((field) => {
            field.style.display = isCurrentStudent ? 'block' : 'none';
        });

        if (schoolInput) {
            schoolInput.required = !isIncoming;
        }
        if (shsSchoolInput) shsSchoolInput.required = isIncoming;
        if (shsStrandInput) shsStrandInput.required = isIncoming;
        if (shsGraduationYearInput) shsGraduationYearInput.required = isIncoming;
        if (targetCollegeInput) targetCollegeInput.required = isIncoming;
        if (yearLevelInput) yearLevelInput.required = isCurrentStudent;
        if (enrollmentStatusInput) enrollmentStatusInput.required = isCurrentStudent;
        if (admissionStatusInput) {
            admissionStatusInput.required = isIncoming;
            if (isCurrentStudent && !admissionStatusInput.value) {
                admissionStatusInput.value = 'enrolled';
            }
        }

        if (torUploadCard) {
            torUploadCard.classList.toggle('is-hidden', !isCurrentStudent);
            if (!isCurrentStudent) {
                resetCompactUploadField(torFileInput, torFileStatus);
            }
        }

        if (form138UploadCard) {
            form138UploadCard.classList.toggle('is-hidden', !isIncoming);
            if (!isIncoming) {
                resetCompactUploadField(form138FileInput, form138FileStatus);
            }
        }

        if (supportingDocsNoteText) {
            if (isIncoming) {
                supportingDocsNoteText.textContent = 'Form 138 is shown for incoming freshmen. You can also attach support files for citizenship, income, or scholarship-category details during signup.';
            } else if (isCurrentStudent) {
                supportingDocsNoteText.textContent = 'TOR / Grade Report is shown for current college applicants. You can also attach support files for citizenship, income, or scholarship-category details during signup.';
            } else {
                supportingDocsNoteText.textContent = 'Select your applicant type first. The academic upload will adjust between TOR and Form 138, while the support files below remain optional for both paths.';
            }
        }

        syncConditionalSupportUploads();
    }

    function buildAddressText() {
        const parts = [
            houseNoInput ? houseNoInput.value.trim() : '',
            streetInput ? streetInput.value.trim() : '',
            barangayInput ? barangayInput.value.trim() : '',
            cityInput ? cityInput.value.trim() : '',
            provinceInput ? provinceInput.value.trim() : ''
        ].filter(Boolean);

        return parts.join(', ');
    }

    async function geocodeAddress(addressText) {
        if (!addressText) {
            return null;
        }

        const endpoint = 'https://nominatim.openstreetmap.org/search?' + new URLSearchParams({
            q: addressText,
            format: 'json',
            limit: '1',
            addressdetails: '1',
            countrycodes: 'ph'
        }).toString();

        const response = await fetch(endpoint, {
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('Geocoding service is unavailable right now.');
        }

        const data = await response.json();
        if (!Array.isArray(data) || data.length === 0) {
            return null;
        }

        const first = data[0];
        if (!first.lat || !first.lon) {
            return null;
        }

        return {
            lat: Number(first.lat),
            lng: Number(first.lon),
            label: first.display_name || addressText
        };
    }

    function setVerificationStatus(message, type = 'pending') {
        if (!emailVerificationStatus) {
            return;
        }

        emailVerificationStatus.className = `verification-status ${type}`;
        emailVerificationStatus.textContent = message;
    }

    function clearVerificationStatus() {
        if (!emailVerificationStatus) {
            return;
        }

        emailVerificationStatus.className = 'verification-status';
        emailVerificationStatus.textContent = '';
    }

    function updateSendButtonState() {
        if (!sendVerificationCodeBtn) {
            return;
        }

        if (resendCountdown > 0) {
            sendVerificationCodeBtn.disabled = true;
            sendVerificationCodeBtn.textContent = `Resend in ${resendCountdown}s`;
            return;
        }

        sendVerificationCodeBtn.disabled = false;
        sendVerificationCodeBtn.textContent = 'Send Code';
    }

    function startResendCountdown(seconds) {
        resendCountdown = Number(seconds) || 0;
        updateSendButtonState();

        if (resendInterval) {
            clearInterval(resendInterval);
        }

        if (resendCountdown <= 0) {
            return;
        }

        resendInterval = setInterval(() => {
            resendCountdown -= 1;
            updateSendButtonState();

            if (resendCountdown <= 0) {
                clearInterval(resendInterval);
                resendInterval = null;
            }
        }, 1000);
    }

    function setVerifiedEmail(email) {
        verifiedEmail = normalizeEmail(email);

        if (verifiedEmailStateInput) {
            verifiedEmailStateInput.value = verifiedEmail;
        }

        if (verificationCodeInput) {
            verificationCodeInput.value = '';
        }

        setVerificationStatus('Email verified. You can now create your account.', 'verified');
    }

    function resetVerifiedEmailState(showMessage = true) {
        verifiedEmail = '';

        if (verifiedEmailStateInput) {
            verifiedEmailStateInput.value = '';
        }

        if (verificationCodeInput) {
            verificationCodeInput.value = '';
        }

        if (showMessage) {
            setVerificationStatus('Please verify your email address before creating an account.', 'pending');
        } else {
            clearVerificationStatus();
        }
    }

    async function sendVerificationCode() {
        if (!signupVerificationEnabled) {
            setVerificationStatus('Email verification storage is not set up yet. Please run the verification migration SQL first.', 'error');
            Swal.fire({
                icon: 'error',
                title: 'Verification Not Ready',
                text: 'Email verification storage is not set up yet. Please run the verification migration SQL first.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        const email = normalizeEmail(emailInput.value);

        if (!email) {
            Swal.fire({
                icon: 'error',
                title: 'Email Required',
                text: 'Please enter your email address first.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        if (!isValidEmail(email)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Email',
                text: 'Please enter a valid email address.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        sendVerificationCodeBtn.disabled = true;

        try {
            const response = await fetch('../app/Controllers/send_signup_verification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: new URLSearchParams({ email })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                if (data.cooldown_seconds) {
                    startResendCountdown(data.cooldown_seconds);
                }
                throw new Error(data.message || 'Unable to send verification code.');
            }

            if (data.already_verified) {
                setVerifiedEmail(email);
                updateSendButtonState();
                return;
            }

            resetVerifiedEmailState(false);
            setVerificationStatus(data.message || 'Verification code sent. Please check your email inbox.', 'pending');
            startResendCountdown(data.cooldown_seconds || 60);

            Swal.fire({
                icon: 'success',
                title: 'Code Sent',
                text: data.message || 'Verification code sent. Please check your email inbox.',
                confirmButtonColor: '#3085d6'
            });
        } catch (error) {
            setVerificationStatus(error.message, 'error');
            sendVerificationCodeBtn.disabled = false;
            updateSendButtonState();

            Swal.fire({
                icon: 'error',
                title: 'Verification Failed',
                text: error.message,
                confirmButtonColor: '#3085d6'
            });
        }
    }

    async function verifyEmailCode() {
        if (!signupVerificationEnabled) {
            setVerificationStatus('Email verification storage is not set up yet. Please run the verification migration SQL first.', 'error');
            Swal.fire({
                icon: 'error',
                title: 'Verification Not Ready',
                text: 'Email verification storage is not set up yet. Please run the verification migration SQL first.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        const email = normalizeEmail(emailInput.value);
        const code = verificationCodeInput.value.trim();

        if (!email || !isValidEmail(email)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Email',
                text: 'Enter a valid email address before verifying.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        if (!/^\d{6}$/.test(code)) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Code',
                text: 'Enter the 6-digit verification code sent to your email.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        verifyEmailCodeBtn.disabled = true;

        try {
            const response = await fetch('../app/Controllers/verify_signup_code.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: new URLSearchParams({ email, code })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to verify email.');
            }

            setVerifiedEmail(email);

            Swal.fire({
                icon: 'success',
                title: 'Email Verified',
                text: data.message || 'Email verified successfully.',
                confirmButtonColor: '#3085d6'
            });
        } catch (error) {
            setVerificationStatus(error.message, 'error');

            Swal.fire({
                icon: 'error',
                title: 'Verification Failed',
                text: error.message,
                confirmButtonColor: '#3085d6'
            });
        } finally {
            verifyEmailCodeBtn.disabled = false;
        }
    }

    if (sendVerificationCodeBtn) {
        sendVerificationCodeBtn.addEventListener('click', sendVerificationCode);
    }

    if (verifyEmailCodeBtn) {
        verifyEmailCodeBtn.addEventListener('click', verifyEmailCode);
    }

    if (emailInput) {
        emailInput.addEventListener('input', function() {
            const currentEmail = normalizeEmail(this.value);

            if (!currentEmail) {
                resetVerifiedEmailState(false);
                return;
            }

            if (verifiedEmail && currentEmail !== verifiedEmail) {
                resetVerifiedEmailState(true);
                return;
            }

            if (!verifiedEmail) {
                setVerificationStatus('Please verify your email address before creating an account.', 'pending');
            }
        });
    }

    if (verificationCodeInput) {
        verificationCodeInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    }

    if (mobileNumberInput) {
        mobileNumberInput.addEventListener('input', function() {
            syncSignupMobileNumber();
        });
        syncSignupMobileNumber();
    }

    if (courseSelect) {
        courseSelect.addEventListener('change', toggleCourseOtherField);
        toggleCourseOtherField();
    }

    if (applicantTypeSelect) {
        applicantTypeSelect.addEventListener('change', function() {
            clearInvalidField('signupApplicantType');
        });
        applicantTypeSelect.addEventListener('change', toggleApplicantTypeFields);
        toggleApplicantTypeFields();
    }

    if (yearLevelInput) {
        yearLevelInput.addEventListener('change', function() {
            clearInvalidField('signupYearLevel');
        });
    }

    if (enrollmentStatusInput) {
        enrollmentStatusInput.addEventListener('change', function() {
            clearInvalidField('signupEnrollmentStatus');
        });
    }

    if (courseSelect && targetCourseInput) {
        courseSelect.addEventListener('change', function () {
            if (this.value !== 'other' && targetCourseInput.value.trim() === '') {
                targetCourseInput.value = this.value;
            }
        });
    }

    compactUploadFields.forEach(({ input, status }) => {
        if (!input || !status) {
            return;
        }

        input.addEventListener('change', function() {
            updateCompactUploadStatus(input, status, 'No file selected');
            syncConditionalSupportUploads();
        });
        updateCompactUploadStatus(input, status, 'No file selected');
    });

    [citizenshipSelect, householdIncomeBracketSelect, specialCategorySelect].forEach((selectElement) => {
        if (selectElement) {
            selectElement.addEventListener('change', syncConditionalSupportUploads);
        }
    });

    syncConditionalSupportUploads();

    if (!signupVerificationEnabled && !verifiedEmail) {
        setVerificationStatus('Email verification storage is not set up yet. Please run the verification migration SQL first.', 'error');
    } else if (verifiedEmail && emailInput && normalizeEmail(emailInput.value) === verifiedEmail) {
        setVerifiedEmail(verifiedEmail);
    } else {
        clearVerificationStatus();
    }

    // Comprehensive form validation before submission
    signupForm.addEventListener('submit', function(e) {
        if (isSubmitting) {
            return;
        }
        e.preventDefault();
        try {
            syncSignupMobileNumber();
            const firstname = document.getElementById('signupFirstname').value.trim();
            const lastname = document.getElementById('signupLastname').value.trim();
            const middleinitial = document.getElementById('signupMiddlename').value.trim();
            const username = document.getElementById('signupName').value.trim();
            const email = normalizeEmail(emailInput.value);
            const gender = genderInput ? genderInput.value : '';
            const password = document.getElementById('signupPassword').value;
            const confirm = document.getElementById('signupConfirmPassword').value;
            const birthdate = document.getElementById('signupBirthdate').value;
            const age = Number(document.getElementById('signupAge').value || 0);
            const agreeTerms = document.getElementById('agreeTerms').checked;
            const houseNo = houseNoInput ? houseNoInput.value.trim() : '';
            const street = streetInput ? streetInput.value.trim() : '';
            const barangay = barangayInput ? barangayInput.value.trim() : '';
            const city = cityInput ? cityInput.value.trim() : '';
            const province = provinceInput ? provinceInput.value.trim() : '';
            const mobileNumber = mobileNumberHiddenInput ? mobileNumberHiddenInput.value.trim() : '';
            const applicantType = applicantTypeSelect ? applicantTypeSelect.value : '';
            const admissionStatus = admissionStatusInput ? admissionStatusInput.value : '';
            const yearLevel = yearLevelInput ? yearLevelInput.value : '';
            const enrollmentStatus = enrollmentStatusInput ? enrollmentStatusInput.value : '';
            const shsSchool = shsSchoolInput ? shsSchoolInput.value.trim() : '';
            const shsStrand = shsStrandInput ? shsStrandInput.value.trim() : '';
            const shsGraduationYear = shsGraduationYearInput ? shsGraduationYearInput.value.trim() : '';
            const targetCollege = targetCollegeInput ? targetCollegeInput.value.trim() : '';
            const selectedCourse = courseSelect ? courseSelect.value : '';
            const otherCourse = courseOtherInput ? courseOtherInput.value.trim() : '';
            const isIncomingApplicant = applicantType === 'incoming_freshman';
            const isCurrentCollegeApplicant = applicantType === 'current_college' || applicantType === 'transferee' || applicantType === 'continuing_student';

            clearInvalidField('signupApplicantType');
            clearInvalidField('signupYearLevel');
            clearInvalidField('signupEnrollmentStatus');

            // Validate first name
            if (!firstname) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please enter your first name.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!isValidName(firstname)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid First Name',
                    text: 'First name should contain only letters, spaces, hyphens, or apostrophes. Numbers are not allowed.',
                    confirmButtonColor: '#3085d6'
                });
                setFieldError('signupFirstname', 'firstnameError', 'First name should contain only letters, spaces, hyphens, or apostrophes (no numbers)');
                return;
            }

            // Validate last name
            if (!lastname) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please enter your last name.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!isValidName(lastname)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Last Name',
                    text: 'Last name should contain only letters, spaces, hyphens, or apostrophes. Numbers are not allowed.',
                    confirmButtonColor: '#3085d6'
                });
                setFieldError('signupLastname', 'lastnameError', 'Last name should contain only letters, spaces, hyphens, or apostrophes (no numbers)');
                return;
            }

            // Validate middle initial (if provided)
            if (middleinitial && !isValidMiddleInitial(middleinitial)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Middle Initial',
                    text: 'Middle initial must be a single letter (A-Z). Numbers are not allowed.',
                    confirmButtonColor: '#3085d6'
                });
                setFieldError('signupMiddlename', 'middlenameError', 'Middle initial must be a single letter (A-Z)');
                return;
            }

            if (mobileNumber && !/^\+639\d{9}$/.test(mobileNumber)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Mobile Number',
                    text: 'Enter a valid 10-digit mobile number after +63.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!birthdate) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please enter your birthdate.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!gender) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please select your gender.',
                    confirmButtonColor: '#3085d6'
                });
                focusField('signupGender');
                return;
            }

            if (age < 15) {
                Swal.fire({
                    icon: 'error',
                    title: 'Age Restriction',
                    text: 'You must be at least 15 years old to register.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!applicantType) {
                markInvalidField('signupApplicantType');
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please select your applicant type.',
                    confirmButtonColor: '#3085d6'
                });
                focusField('signupApplicantType');
                return;
            }

            if (!selectedCourse) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please select your course/program.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (selectedCourse === 'other' && !otherCourse) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please specify your course/program.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (isIncomingApplicant) {
                if (!shsSchool || !shsStrand || !shsGraduationYear || !admissionStatus || !targetCollege) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Incoming Student Details Required',
                        text: 'Please complete your senior high school, admission status, and target college details.',
                        confirmButtonColor: '#3085d6'
                    });
                    return;
                }

                const graduationYearNumber = Number(shsGraduationYear);
                if (!Number.isInteger(graduationYearNumber) || graduationYearNumber < 2000 || graduationYearNumber > (new Date().getFullYear() + 6)) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid Graduation Year',
                        text: 'Please enter a valid senior high school graduation year.',
                        confirmButtonColor: '#3085d6'
                    });
                    return;
                }
            }

            if (isCurrentCollegeApplicant && (!yearLevel || !enrollmentStatus)) {
                if (!yearLevel) {
                    markInvalidField('signupYearLevel');
                }
                if (!enrollmentStatus) {
                    markInvalidField('signupEnrollmentStatus');
                }
                Swal.fire({
                    icon: 'error',
                    title: 'College Details Required',
                    text: 'Please select your year level and enrollment status. If you are not yet in college, change Applicant Type to Incoming Freshman.',
                    confirmButtonColor: '#3085d6'
                });
                focusField(!yearLevel ? 'signupYearLevel' : 'signupEnrollmentStatus');
                return;
            }

            for (const uploadField of compactUploadFields) {
                if (!validateOptionalSignupFile(uploadField.input, uploadField.label)) {
                    return;
                }
            }

            if (!validateConditionalProof({
                select: citizenshipSelect,
                input: citizenshipFileInput,
                message: 'Please upload citizenship or residency proof when citizenship is selected.'
            })) {
                return;
            }

            if (!validateConditionalProof({
                select: householdIncomeBracketSelect,
                input: incomeFileInput,
                skipValues: ['prefer_not_to_say'],
                message: 'Please upload household income proof when an income bracket is selected.'
            })) {
                return;
            }

            if (!validateConditionalProof({
                select: specialCategorySelect,
                input: specialCategoryFileInput,
                skipValues: ['none'],
                message: 'Please upload supporting proof when a special scholarship category is selected.'
            })) {
                return;
            }

            if (!houseNo || !street || !barangay || !city || !province) {
                Swal.fire({
                    icon: 'error',
                    title: 'Address Required',
                    text: 'Please complete House #, Street, Barangay, City, and Province.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (password !== confirm) {
                Swal.fire({
                    icon: 'error',
                    title: 'Password Mismatch',
                    text: 'Passwords do not match.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            const passwordValidation = window.PasswordPolicy
                ? window.PasswordPolicy.validate(password, {
                    username: username,
                    email: email,
                    firstname: firstname,
                    lastname: lastname
                })
                : { valid: password.length >= <?php echo passwordPolicyMinLength(); ?>, errors: [<?php echo json_encode(passwordPolicyHint()); ?>] };

            if (!passwordValidation.valid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Weak Password',
                    text: passwordValidation.errors[0] || <?php echo json_encode(passwordPolicyHint()); ?>,
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!agreeTerms) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Terms Required',
                    text: 'Please agree to the Terms and Conditions and Privacy Policy to continue.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!email) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please enter your email address.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!isValidEmail(email)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Email',
                    text: 'Please enter a valid email address.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (!signupVerificationEnabled && !verifiedEmail) {
                setVerificationStatus('Email verification storage is not set up yet. Please run the verification migration SQL first.', 'error');
                Swal.fire({
                    icon: 'error',
                    title: 'Verification Not Ready',
                    text: 'Email verification storage is not set up yet. Please run the verification migration SQL first.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            if (email !== verifiedEmail) {
                setVerificationStatus('Please verify your email address before creating an account.', 'pending');
                Swal.fire({
                    icon: 'error',
                    title: 'Email Not Verified',
                    text: 'Please verify your email address before creating your account.',
                    confirmButtonColor: '#3085d6'
                });
                return;
            }

            const addressText = buildAddressText();
            const existingLat = latitudeInput ? latitudeInput.value.trim() : '';
            const existingLng = longitudeInput ? longitudeInput.value.trim() : '';

            if (locationNameInput && !locationNameInput.value.trim()) {
                locationNameInput.value = addressText;
            }

            if (signupPinStatusText && existingLat && existingLng) {
                signupPinStatusText.textContent = 'Pin selected. The map location will be used for registration.';
            } else if (signupPinStatusText) {
                signupPinStatusText.textContent = 'Address will be geocoded automatically during registration.';
            }

            clearFieldError('signupFirstname', 'firstnameError');
            clearFieldError('signupLastname', 'lastnameError');
            clearFieldError('signupMiddlename', 'middlenameError');

            setSignupSubmittingState(true);
            HTMLFormElement.prototype.submit.call(signupForm);
        } catch (error) {
            console.error('Signup submit failed:', error);
            setSignupSubmittingState(false);

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Signup Error',
                    text: 'Something went wrong while preparing your signup. Please try again.',
                    confirmButtonColor: '#3085d6'
                });
            }
        }
    });

    // Terms and conditions modal
    function showTerms() {
        Swal.fire({
            title: 'Terms and Conditions',
            html: `
                <div style="text-align: left; max-height: 400px; overflow-y: auto; padding: 10px;">
                    <h4>1. Application Process</h4>
                    <p>By submitting this scholarship application, you confirm that all information provided is accurate and complete.</p>
                    
                    <h4>2. Document Requirements</h4>
                    <p>All uploaded documents must be clear, legible, and authentic.</p>
                    
                    <h4>3. Privacy and Data Protection</h4>
                    <p>Your personal data will be used solely for scholarship processing in compliance with the Data Privacy Act of 2012.</p>
                    
                    <h4>4. Scholarship Awards</h4>
                    <p>Scholarship decisions are final and at the discretion of the awarding bodies.</p>
                </div>
            `,
            confirmButtonColor: '#3085d6'
        });
    }

    function showPrivacy() {
        Swal.fire({
            title: 'Privacy Policy',
            html: `
                <div style="text-align: left; max-height: 400px; overflow-y: auto; padding: 10px;">
                    <h4>Data Collection</h4>
                    <p>We collect personal information including name, email, school, course, birthdate, and location data.</p>
                    
                    <h4>Data Usage</h4>
                    <p>Your information is used solely for scholarship matching and application processing.</p>
                    
                    <h4>Data Protection</h4>
                    <p>We implement security measures to protect your personal information.</p>
                    
                    <h4>Your Rights</h4>
                    <p>You may request access, correction, or deletion of your personal data.</p>
                </div>
            `,
            confirmButtonColor: '#3085d6'
        });
    }
    </script>

    <?php if(isset($_SESSION['success'])): ?>
        <div data-swal-success data-swal-title="Success!" style="display: none;">
            <?php echo $_SESSION['success']; ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <div data-swal-error data-swal-title="Error!" style="display: none;">
            <?php echo $_SESSION['error']; ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['errors']) && is_array($_SESSION['errors'])): ?>
        <div data-swal-errors data-swal-title="Registration Error" style="display: none;">
            <?php echo json_encode($_SESSION['errors']); ?>
        </div>
        <?php unset($_SESSION['errors']); ?>
    <?php endif; ?>
</body>
</html>


