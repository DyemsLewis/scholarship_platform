<?php
// Controller/user_profile_controller.php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/csrf.php';
require_once __DIR__ . '/../Config/access_control.php';
require_once __DIR__ . '/../Config/helpers.php';
require_once __DIR__ . '/../Config/password_policy.php';
require_once __DIR__ . '/../Models/User.php';
require_once __DIR__ . '/../Models/StudentData.php';
require_once __DIR__ . '/../Models/Location.php';

class UserProfileController {
    private $userModel;
    private $studentDataModel;
    private $locationModel;

    public function __construct($pdo) {
        $this->userModel = new User($pdo);
        $this->studentDataModel = new StudentData($pdo);
        $this->locationModel = new Location($pdo);
    }

    private function normalizeNullable($value): ?string {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeChoice($value, array $allowedValues, ?string $default = null): ?string {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, $allowedValues, true) ? $normalized : null;
    }

    private function isValidNameValue(string $value): bool {
        return $value !== '' && (bool) preg_match("/^[A-Za-z\s\-'\.]+$/", $value);
    }

    private function isValidMiddleInitialValue(string $value): bool {
        if ($value === '') {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]$/', $value);
    }

    private function isCollegeApplicantType(?string $applicantType): bool {
        return in_array((string) $applicantType, ['current_college', 'transferee', 'continuing_student'], true);
    }

    private function buildAddress(?string $houseNo, ?string $street, ?string $barangay, ?string $city, ?string $province): ?string {
        $parts = array_values(array_filter([
            $houseNo,
            $street,
            $barangay,
            $city,
            $province
        ], function ($value) {
            return $value !== null && trim((string) $value) !== '';
        }));

        if (empty($parts)) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function buildGeocodingAddress(?string $street, ?string $barangay, ?string $city, ?string $province): ?string {
        $parts = array_values(array_filter([
            $street,
            $barangay,
            $city,
            $province
        ], function ($value) {
            return $value !== null && trim((string) $value) !== '';
        }));

        if (empty($parts)) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function parseCoordinate($value): ?float {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        if (!is_numeric($trimmed)) {
            return null;
        }
        $float = (float) $trimmed;
        return is_finite($float) ? $float : null;
    }

    private function normalizeComparable($value): ?string {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function scalarValuesMatch($left, $right): bool {
        return $this->normalizeComparable($left) === $this->normalizeComparable($right);
    }

    private function coordinatesMatch(?float $incoming, $existing): bool {
        if ($incoming === null) {
            return $existing === null || trim((string) $existing) === '';
        }

        if ($existing === null || trim((string) $existing) === '') {
            return false;
        }

        return abs($incoming - (float) $existing) < 0.000001;
    }

    private function hasStudentDataChanges(array $incoming, ?array $existing): bool {
        if (!$existing) {
            return true;
        }

        foreach ($incoming as $key => $value) {
            $existingValue = $existing[$key] ?? null;
            if (!$this->scalarValuesMatch($value, $existingValue)) {
                return true;
            }
        }

        return false;
    }

    private function hasLocationChanges(?float $latitude, ?float $longitude, ?string $locationName, ?array $existing): bool {
        if (!$existing) {
            return $latitude !== null || $longitude !== null || $this->normalizeComparable($locationName) !== null;
        }

        if (!$this->coordinatesMatch($latitude, $existing['latitude'] ?? null)) {
            return true;
        }

        if (!$this->coordinatesMatch($longitude, $existing['longitude'] ?? null)) {
            return true;
        }

        if (!$this->scalarValuesMatch($locationName, $existing['location_name'] ?? null)) {
            return true;
        }

        return false;
    }

    private function deleteStoredProfileImage(?string $storedPath): void {
        $normalizedPath = ltrim(str_replace('\\', '/', trim((string) $storedPath)), '/');
        if ($normalizedPath === '' || strpos($normalizedPath, '..') !== false) {
            return;
        }

        if (strpos($normalizedPath, 'public/uploads/profile-images/') !== 0) {
            return;
        }

        $absolutePath = dirname(__DIR__, 2) . '/' . $normalizedPath;
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function processProfileImageUpload(int $userId, ?array $file, ?string $existingPath): array {
        if (!$file || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return [
                'success' => true,
                'changed' => false,
                'path' => $existingPath
            ];
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'Profile picture upload failed. Please try again.'
            ];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [
                'success' => false,
                'message' => 'Invalid profile picture upload.'
            ];
        }

        $maxSizeBytes = 3 * 1024 * 1024;
        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize <= 0 || $fileSize > $maxSizeBytes) {
            return [
                'success' => false,
                'message' => 'Profile picture must be 3MB or smaller.'
            ];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = strtolower((string) $finfo->file($tmpName));
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($allowedTypes[$mimeType])) {
            return [
                'success' => false,
                'message' => 'Profile picture must be a JPG, PNG, or WEBP image.'
            ];
        }

        $uploadDirectory = dirname(__DIR__, 2) . '/public/uploads/profile-images';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            return [
                'success' => false,
                'message' => 'Unable to prepare the profile picture upload folder.'
            ];
        }

        try {
            $fileName = 'profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
        } catch (Throwable $e) {
            $fileName = 'profile_' . $userId . '_' . uniqid('', true) . '.' . $allowedTypes[$mimeType];
        }

        $absolutePath = $uploadDirectory . '/' . $fileName;
        if (!move_uploaded_file($tmpName, $absolutePath)) {
            return [
                'success' => false,
                'message' => 'Unable to save the profile picture.'
            ];
        }

        $storedPath = 'public/uploads/profile-images/' . $fileName;

        return [
            'success' => true,
            'changed' => true,
            'path' => $storedPath
        ];
    }

    private function geocodeAddress(string $address): ?array {
        $query = http_build_query([
            'q' => $address,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 1,
            'countrycodes' => 'ph'
        ]);
        $url = 'https://nominatim.openstreetmap.org/search?' . $query;

        $rawResponse = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['User-Agent: ScholarshipFinder/1.0 (profile geocoding)']
            ]);
            $rawResponse = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'header' => "User-Agent: ScholarshipFinder/1.0 (profile geocoding)\r\n"
                ]
            ]);
            $rawResponse = @file_get_contents($url, false, $context);
        }

        if (!$rawResponse) {
            return null;
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded) || empty($decoded[0]['lat']) || empty($decoded[0]['lon'])) {
            return null;
        }

        return [
            'latitude' => (float) $decoded[0]['lat'],
            'longitude' => (float) $decoded[0]['lon'],
            'display_name' => (string) ($decoded[0]['display_name'] ?? $address)
        ];
    }

    public function updateProfile($userId, $data) {
        $result = ['success' => false, 'message' => ''];

        try {
            $existingData = $this->studentDataModel->getByUserId($userId);
            $existingLocation = $this->locationModel->getByStudentId($userId);
            $existingProfileImagePath = trim((string) ($existingData['profile_image_path'] ?? ''));

            $houseNo = $this->normalizeNullable($data['house_no'] ?? null);
            $street = $this->normalizeNullable($data['street'] ?? null);
            $barangay = $this->normalizeNullable($data['barangay'] ?? null);
            $city = $this->normalizeNullable($data['city'] ?? null);
            $province = $this->normalizeNullable($data['province'] ?? null);

            $address = $this->buildAddress($houseNo, $street, $barangay, $city, $province);
            $geocodingAddress = $this->buildGeocodingAddress($street, $barangay, $city, $province);

            $allowedApplicantTypes = ['incoming_freshman', 'current_college', 'transferee', 'continuing_student'];
            $allowedAdmissionStatuses = ['not_yet_applied', 'applied', 'admitted', 'enrolled'];
            $allowedYearLevels = ['1st_year', '2nd_year', '3rd_year', '4th_year', '5th_year_plus'];
            $allowedEnrollmentStatuses = ['currently_enrolled', 'regular', 'irregular', 'leave_of_absence'];
            $allowedAcademicStandings = ['good_standing', 'deans_list', 'probationary', 'graduating'];
            $allowedGenders = ['male', 'female', 'other', 'prefer_not_to_say'];
            $allowedCitizenships = ['filipino', 'dual_citizen', 'permanent_resident', 'other'];
            $allowedIncomeBrackets = ['below_10000', '10000_20000', '20001_40000', '40001_80000', 'above_80000', 'prefer_not_to_say'];
            $allowedSpecialCategories = ['none', 'pwd', 'indigenous_peoples', 'solo_parent_dependent', 'working_student', 'child_of_ofw', 'four_ps_beneficiary', 'orphan'];

            $lockedApplicantType = $this->normalizeChoice(
                $existingData['applicant_type'] ?? ($_SESSION['user_applicant_type'] ?? null),
                $allowedApplicantTypes
            );
            $applicantType = $lockedApplicantType ?? $this->normalizeChoice($data['applicant_type'] ?? null, $allowedApplicantTypes);
            $isIncomingApplicant = $applicantType === 'incoming_freshman';
            $isCollegeApplicant = $this->isCollegeApplicantType($applicantType);
            $admissionStatus = $this->normalizeChoice($data['admission_status'] ?? null, $allowedAdmissionStatuses);
            $yearLevel = $this->normalizeChoice($data['year_level'] ?? null, $allowedYearLevels);
            $enrollmentStatus = $this->normalizeChoice($data['enrollment_status'] ?? null, $allowedEnrollmentStatuses);
            $academicStanding = $this->normalizeChoice($data['academic_standing'] ?? null, $allowedAcademicStandings);
            $gender = $this->normalizeChoice($data['gender'] ?? null, $allowedGenders);
            $citizenship = $this->normalizeChoice($data['citizenship'] ?? null, $allowedCitizenships);
            $householdIncomeBracket = $this->normalizeChoice($data['household_income_bracket'] ?? null, $allowedIncomeBrackets);
            $specialCategory = $this->normalizeChoice($data['special_category'] ?? null, $allowedSpecialCategories);
            $mobileNumber = normalizePhilippineMobileNumber($data['mobile_number'] ?? null);

            if ($admissionStatus === null && $isCollegeApplicant) {
                $admissionStatus = $this->normalizeChoice($existingData['admission_status'] ?? null, $allowedAdmissionStatuses, 'enrolled');
            }

            // Preserve existing address if no address components were submitted.
            if ($address === null && $existingData && !empty($existingData['address'])) {
                $address = trim((string) $existingData['address']);
            }

            $studentData = [
                'firstname' => trim((string) ($data['firstname'] ?? '')),
                'lastname' => trim((string) ($data['lastname'] ?? '')),
                'middleinitial' => trim((string) ($data['middleinitial'] ?? '')),
                'gender' => $gender,
                'applicant_type' => $applicantType,
                'school' => $isCollegeApplicant
                    ? trim((string) ($data['school'] ?? ($existingData['school'] ?? '')))
                    : null,
                'course' => trim((string) ($data['course'] ?? '')),
                'shs_school' => $isIncomingApplicant
                    ? $this->normalizeNullable($data['shs_school'] ?? ($existingData['shs_school'] ?? null))
                    : ($existingData['shs_school'] ?? null),
                'shs_strand' => $isIncomingApplicant
                    ? $this->normalizeNullable($data['shs_strand'] ?? ($existingData['shs_strand'] ?? null))
                    : ($existingData['shs_strand'] ?? null),
                'shs_graduation_year' => $isIncomingApplicant
                    ? $this->normalizeNullable($data['shs_graduation_year'] ?? ($existingData['shs_graduation_year'] ?? null))
                    : ($existingData['shs_graduation_year'] ?? null),
                'shs_average' => array_key_exists('shs_average', $data)
                    ? $this->normalizeNullable($data['shs_average'] ?? null)
                    : ($existingData['shs_average'] ?? null),
                'admission_status' => $isIncomingApplicant
                    ? $admissionStatus
                    : ($existingData['admission_status'] ?? $admissionStatus),
                'target_college' => $isIncomingApplicant
                    ? $this->normalizeNullable($data['target_college'] ?? ($existingData['target_college'] ?? null))
                    : ($existingData['target_college'] ?? null),
                'target_course' => $isIncomingApplicant
                    ? $this->normalizeNullable($data['target_course'] ?? ($existingData['target_course'] ?? null))
                    : ($existingData['target_course'] ?? null),
                'year_level' => $isCollegeApplicant
                    ? $yearLevel
                    : ($existingData['year_level'] ?? null),
                'enrollment_status' => $isCollegeApplicant
                    ? $enrollmentStatus
                    : ($existingData['enrollment_status'] ?? null),
                'academic_standing' => $isCollegeApplicant
                    ? $academicStanding
                    : ($existingData['academic_standing'] ?? null),
                'mobile_number' => $mobileNumber,
                'citizenship' => $citizenship,
                'household_income_bracket' => $householdIncomeBracket,
                'special_category' => $specialCategory,
                'house_no' => $houseNo,
                'street' => $street,
                'barangay' => $barangay,
                'city' => $city,
                'province' => $province,
                'address' => $address
            ];

            $profileImageUpload = $this->processProfileImageUpload(
                $userId,
                $_FILES['profile_image'] ?? null,
                $existingProfileImagePath
            );
            if (!$profileImageUpload['success']) {
                $result['message'] = $profileImageUpload['message'];
                return $result;
            }
            $profileImageChanged = (bool) ($profileImageUpload['changed'] ?? false);
            if ($profileImageChanged) {
                $studentData['profile_image_path'] = $profileImageUpload['path'] ?? null;
            }

            $latitude = $this->parseCoordinate($data['latitude'] ?? null);
            $longitude = $this->parseCoordinate($data['longitude'] ?? null);
            $locationName = $this->normalizeNullable($data['location_name'] ?? null);

            // If pin coordinates are missing, try geocoding from address as fallback.
            if (($latitude === null || $longitude === null) && $geocodingAddress !== null && $geocodingAddress !== '') {
                $geocoded = $this->geocodeAddress($geocodingAddress);
                if ($geocoded) {
                    $latitude = $geocoded['latitude'];
                    $longitude = $geocoded['longitude'];
                    if ($locationName === null || $locationName === '') {
                        $locationName = $geocoded['display_name'];
                    }
                }
            }

            // Preserve existing coordinates if no new pin/geocode is available.
            if (($latitude === null || $longitude === null) && $existingLocation) {
                $latitude = isset($existingLocation['latitude']) ? (float) $existingLocation['latitude'] : $latitude;
                $longitude = isset($existingLocation['longitude']) ? (float) $existingLocation['longitude'] : $longitude;
                if (($locationName === null || $locationName === '') && !empty($existingLocation['location_name'])) {
                    $locationName = trim((string) $existingLocation['location_name']);
                }
            }

            if ($locationName === null || $locationName === '') {
                $locationName = $address;
            }

            if ($latitude !== null && $longitude !== null && ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180)) {
                $result['message'] = 'Coordinates are out of range';
                return $result;
            }

            $studentDataChanged = $this->hasStudentDataChanges($studentData, $existingData ?: null);
            $locationChanged = $this->hasLocationChanges($latitude, $longitude, $locationName, $existingLocation ?: null);

            if (!$studentDataChanged && !$locationChanged) {
                return [
                    'success' => true,
                    'no_changes' => true,
                    'message' => 'No changes detected in your profile'
                ];
            }

            if ($studentDataChanged) {
                $studentUpdate = $this->studentDataModel->saveStudentData($userId, $studentData);
                if (!$studentUpdate) {
                    if ($profileImageChanged) {
                        $this->deleteStoredProfileImage($profileImageUpload['path'] ?? null);
                    }
                    $result['message'] = 'Failed to update profile information';
                    return $result;
                }

                if ($profileImageChanged && $existingProfileImagePath !== '' && $existingProfileImagePath !== ($profileImageUpload['path'] ?? '')) {
                    $this->deleteStoredProfileImage($existingProfileImagePath);
                }
            }

            if ($locationChanged && $latitude !== null && $longitude !== null) {
                $locationSaved = $this->locationModel->saveLocation($userId, $latitude, $longitude, $locationName);
                if (!$locationSaved) {
                    error_log('Warning: Failed to save updated profile location for user ' . $userId);
                }
            }

            $_SESSION['user_firstname'] = $studentData['firstname'];
            $_SESSION['user_lastname'] = $studentData['lastname'];
            $_SESSION['user_middleinitial'] = $studentData['middleinitial'];
            $_SESSION['user_gender'] = $studentData['gender'] ?? '';
            $_SESSION['user_applicant_type'] = $studentData['applicant_type'] ?? '';
            $_SESSION['user_school'] = $studentData['school'];
            $_SESSION['user_course'] = $studentData['course'];
            $_SESSION['user_shs_school'] = $studentData['shs_school'] ?? '';
            $_SESSION['user_shs_strand'] = $studentData['shs_strand'] ?? '';
            $_SESSION['user_shs_graduation_year'] = $studentData['shs_graduation_year'] ?? '';
            $_SESSION['user_shs_average'] = $studentData['shs_average'] ?? '';
            $_SESSION['user_admission_status'] = $studentData['admission_status'] ?? '';
            $_SESSION['user_target_college'] = $studentData['target_college'] ?? '';
            $_SESSION['user_target_course'] = $studentData['target_course'] ?? '';
            $_SESSION['user_year_level'] = $studentData['year_level'] ?? '';
            $_SESSION['user_enrollment_status'] = $studentData['enrollment_status'] ?? '';
            $_SESSION['user_academic_standing'] = $studentData['academic_standing'] ?? '';
            $_SESSION['user_mobile_number'] = formatPhilippineMobileNumber($studentData['mobile_number'] ?? '');
            $_SESSION['user_citizenship'] = $studentData['citizenship'] ?? '';
            $_SESSION['user_household_income_bracket'] = $studentData['household_income_bracket'] ?? '';
            $_SESSION['user_special_category'] = $studentData['special_category'] ?? '';
            $_SESSION['user_profile_image_path'] = $profileImageChanged
                ? ($profileImageUpload['path'] ?? '')
                : ($existingProfileImagePath !== '' ? $existingProfileImagePath : ($_SESSION['user_profile_image_path'] ?? ''));
            $_SESSION['user_house_no'] = $houseNo ?? '';
            $_SESSION['user_street'] = $street ?? '';
            $_SESSION['user_barangay'] = $barangay ?? '';
            $_SESSION['user_city'] = $city ?? '';
            $_SESSION['user_province'] = $province ?? '';
            $_SESSION['user_address'] = $address ?? '';

            if ($latitude !== null && $longitude !== null) {
                $_SESSION['user_latitude'] = $latitude;
                $_SESSION['user_longitude'] = $longitude;
                $_SESSION['user_location_name'] = $locationName ?? '';
            }

            $result['success'] = true;
            $result['message'] = 'Profile updated successfully';
        } catch (Exception $e) {
            error_log('Error in updateProfile: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $result['message'] = 'An error occurred while updating your profile. Please try again.';
        }

        return $result;
    }

    public function changePassword($userId, $currentPass, $newPass) {
        return $this->userModel->changePassword($userId, $currentPass, $newPass);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }

    if (!isRoleIn(['student'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only student accounts can update this profile.']);
        exit();
    }

    $csrfValidation = csrfValidateRequest('profile_self_service');
    if (!$csrfValidation['valid']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $csrfValidation['message']]);
        exit();
    }

    $controller = new UserProfileController($pdo);
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_profile':
            $errors = [];
            $isValidNameValue = function (string $value): bool {
                return $value !== '' && (bool) preg_match("/^[A-Za-z\s\-'\.]+$/", $value);
            };
            $isValidMiddleInitialValue = function (string $value): bool {
                if ($value === '') {
                    return true;
                }

                return (bool) preg_match('/^[A-Za-z]$/', $value);
            };
            $normalizeChoice = function ($value, array $allowedValues, ?string $default = null): ?string {
                $normalized = strtolower(trim((string) $value));
                if ($normalized === '') {
                    return $default;
                }
                return in_array($normalized, $allowedValues, true) ? $normalized : null;
            };
            $isCollegeApplicantType = function (?string $applicantType): bool {
                return in_array((string) $applicantType, ['current_college', 'transferee', 'continuing_student'], true);
            };

            if (empty(trim($_POST['firstname'] ?? ''))) {
                $errors[] = 'First name is required';
            } elseif (!$isValidNameValue(trim((string) ($_POST['firstname'] ?? '')))) {
                $errors[] = 'First name should contain only letters, spaces, hyphens, or apostrophes';
            }

            if (empty(trim($_POST['lastname'] ?? ''))) {
                $errors[] = 'Last name is required';
            } elseif (!$isValidNameValue(trim((string) ($_POST['lastname'] ?? '')))) {
                $errors[] = 'Last name should contain only letters, spaces, hyphens, or apostrophes';
            }

            if (!$isValidMiddleInitialValue(trim((string) ($_POST['middleinitial'] ?? '')))) {
                $errors[] = 'Middle initial must be a single letter';
            }

            $allowedApplicantTypes = ['incoming_freshman', 'current_college', 'transferee', 'continuing_student'];
            $allowedAdmissionStatuses = ['not_yet_applied', 'applied', 'admitted', 'enrolled'];
            $allowedYearLevels = ['1st_year', '2nd_year', '3rd_year', '4th_year', '5th_year_plus'];
            $allowedEnrollmentStatuses = ['currently_enrolled', 'regular', 'irregular', 'leave_of_absence'];
            $allowedAcademicStandings = ['good_standing', 'deans_list', 'probationary', 'graduating'];
            $allowedGenders = ['male', 'female', 'other', 'prefer_not_to_say'];
            $allowedCitizenships = ['filipino', 'dual_citizen', 'permanent_resident', 'other'];
            $allowedIncomeBrackets = ['below_10000', '10000_20000', '20001_40000', '40001_80000', 'above_80000', 'prefer_not_to_say'];
            $allowedSpecialCategories = ['none', 'pwd', 'indigenous_peoples', 'solo_parent_dependent', 'working_student', 'child_of_ofw', 'four_ps_beneficiary', 'orphan'];

            $existingStudentDataModel = new StudentData($pdo);
            $existingStudentData = $existingStudentDataModel->getByUserId((int) $_SESSION['user_id']);
            $lockedApplicantType = $normalizeChoice(
                $existingStudentData['applicant_type'] ?? ($_SESSION['user_applicant_type'] ?? ''),
                $allowedApplicantTypes
            );
            $applicantType = $lockedApplicantType ?? $normalizeChoice($_POST['applicant_type'] ?? '', $allowedApplicantTypes);
            $isIncomingApplicant = $applicantType === 'incoming_freshman';
            $isCollegeApplicant = $isCollegeApplicantType($applicantType);
            $admissionStatus = $normalizeChoice($_POST['admission_status'] ?? '', $allowedAdmissionStatuses);
            $yearLevel = $normalizeChoice($_POST['year_level'] ?? '', $allowedYearLevels);
            $enrollmentStatus = $normalizeChoice($_POST['enrollment_status'] ?? '', $allowedEnrollmentStatuses);
            $academicStanding = $normalizeChoice($_POST['academic_standing'] ?? '', $allowedAcademicStandings);
            $gender = $normalizeChoice($_POST['gender'] ?? '', $allowedGenders);
            $citizenship = $normalizeChoice($_POST['citizenship'] ?? '', $allowedCitizenships);
            $householdIncomeBracket = $normalizeChoice($_POST['household_income_bracket'] ?? '', $allowedIncomeBrackets);
            $specialCategory = $normalizeChoice($_POST['special_category'] ?? '', $allowedSpecialCategories);
            $shsSchool = trim((string) ($_POST['shs_school'] ?? ''));
            $shsStrand = trim((string) ($_POST['shs_strand'] ?? ''));
            $shsGraduationYear = trim((string) ($_POST['shs_graduation_year'] ?? ''));
            $hasShsAverageField = array_key_exists('shs_average', $_POST);
            $shsAverage = $hasShsAverageField ? trim((string) ($_POST['shs_average'] ?? '')) : null;
            $targetCollege = trim((string) ($_POST['target_college'] ?? ''));
            $targetCourse = trim((string) ($_POST['target_course'] ?? ''));
            $mobileNumberInput = trim((string) ($_POST['mobile_number'] ?? ''));
            $mobileNumber = normalizePhilippineMobileNumber($mobileNumberInput);

            if ($admissionStatus === null && $isCollegeApplicant) {
                $admissionStatus = 'enrolled';
            }

            if ($applicantType === null) {
                $errors[] = 'Applicant type is required';
            }

            if ($isCollegeApplicant && empty(trim($_POST['school'] ?? ''))) {
                $errors[] = 'School information is required';
            }

            if (empty(trim($_POST['course'] ?? ''))) {
                $errors[] = 'Course/Program is required';
            }

            if ($isIncomingApplicant) {
                if ($shsSchool === '') {
                    $errors[] = 'Senior high school name is required for incoming freshmen';
                }
                if ($shsStrand === '') {
                    $errors[] = 'Senior high school strand is required for incoming freshmen';
                }
                if ($shsGraduationYear === '') {
                    $errors[] = 'Senior high school graduation year is required for incoming freshmen';
                }
                if ($admissionStatus === null) {
                    $errors[] = 'Admission status is required for incoming freshmen';
                }
                if ($targetCollege === '') {
                    $errors[] = 'Preferred college or university is required for incoming freshmen';
                }
            } elseif ($applicantType !== null) {
                if ($yearLevel === null) {
                    $errors[] = 'Year level is required for current college applicants';
                }
                if ($enrollmentStatus === null) {
                    $errors[] = 'Enrollment status is required for current college applicants';
                }
            }

            $houseNo = trim((string) ($_POST['house_no'] ?? ''));
            $street = trim((string) ($_POST['street'] ?? ''));
            $barangay = trim((string) ($_POST['barangay'] ?? ''));
            $city = trim((string) ($_POST['city'] ?? ''));
            $province = trim((string) ($_POST['province'] ?? ''));

            $addressParts = [$houseNo, $street, $barangay, $city, $province];
            $filledAddressParts = array_filter($addressParts, function ($value) {
                return $value !== '';
            });
            if (!empty($filledAddressParts) && count($filledAddressParts) < 5) {
                $errors[] = 'Complete address is required (House #, Street, Barangay, City, Province)';
            }

            foreach ([
                'house_no' => $houseNo,
                'street' => $street,
                'barangay' => $barangay,
                'city' => $city,
                'province' => $province
            ] as $label => $value) {
                if ($value !== '' && (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > 120) {
                    $errors[] = ucfirst(str_replace('_', ' ', $label)) . ' is too long';
                }
            }

            $latitudeRaw = trim((string) ($_POST['latitude'] ?? ''));
            $longitudeRaw = trim((string) ($_POST['longitude'] ?? ''));
            if (($latitudeRaw === '') xor ($longitudeRaw === '')) {
                $errors[] = 'Please provide both latitude and longitude, or leave both empty';
            } elseif ($latitudeRaw !== '' && $longitudeRaw !== '') {
                if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
                    $errors[] = 'Invalid coordinate format';
                } else {
                    $lat = (float) $latitudeRaw;
                    $lng = (float) $longitudeRaw;
                    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                        $errors[] = 'Coordinates are out of range';
                    }
                }
            }

            foreach ([
                'school information' => $isCollegeApplicant ? trim((string) ($_POST['school'] ?? '')) : '',
                'course/program' => trim((string) ($_POST['course'] ?? '')),
                'senior high school' => $shsSchool,
                'senior high school strand' => $shsStrand,
                'preferred college' => $targetCollege,
                'target course' => $targetCourse
            ] as $label => $value) {
                if ($value !== '' && (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > 150) {
                    $errors[] = ucfirst($label) . ' is too long';
                }
            }

            if (!isValidPhilippineMobileNumber($mobileNumberInput, false)) {
                $errors[] = 'Mobile number must be a valid +63 mobile number';
            }

            if (trim((string) ($_POST['gender'] ?? '')) !== '' && $gender === null) {
                $errors[] = 'Invalid gender selected';
            }

            if (trim((string) ($_POST['citizenship'] ?? '')) !== '' && $citizenship === null) {
                $errors[] = 'Invalid citizenship selected';
            }

            if (trim((string) ($_POST['household_income_bracket'] ?? '')) !== '' && $householdIncomeBracket === null) {
                $errors[] = 'Invalid household income bracket selected';
            }

            if (trim((string) ($_POST['special_category'] ?? '')) !== '' && $specialCategory === null) {
                $errors[] = 'Invalid scholarship category selected';
            }

            if ($shsGraduationYear !== '') {
                if (!ctype_digit($shsGraduationYear)) {
                    $errors[] = 'Senior high school graduation year must be a valid year';
                } else {
                    $graduationYearValue = (int) $shsGraduationYear;
                    $currentYear = (int) date('Y') + 6;
                    if ($graduationYearValue < 2000 || $graduationYearValue > $currentYear) {
                        $errors[] = 'Senior high school graduation year is out of range';
                    }
                }
            }

            if ($hasShsAverageField && $shsAverage !== '') {
                if (!is_numeric($shsAverage)) {
                    $errors[] = 'Senior high school average must be a valid number';
                } else {
                    $shsAverageValue = (float) $shsAverage;
                    if ($shsAverageValue < 60 || $shsAverageValue > 100) {
                        $errors[] = 'Senior high school average must be between 60 and 100';
                    }
                }
            }

            if (!empty($errors)) {
                echo json_encode([
                    'success' => false,
                    'message' => implode('<br>', $errors)
                ]);
                break;
            }

            $middleInitial = trim($_POST['middleinitial'] ?? '');
            if (!empty($middleInitial)) {
                $middleInitial = str_replace('.', '', $middleInitial);
                if (strlen($middleInitial) > 1) {
                    $middleInitial = substr($middleInitial, 0, 1);
                }
                $middleInitial = strtoupper($middleInitial) . '.';
            }

            $data = [
                'firstname' => trim($_POST['firstname']),
                'lastname' => trim($_POST['lastname']),
                'middleinitial' => $middleInitial,
                'gender' => $gender,
                'applicant_type' => $applicantType,
                'school' => $isCollegeApplicant ? trim((string) ($_POST['school'] ?? '')) : '',
                'course' => trim($_POST['course']),
                'shs_school' => $isIncomingApplicant ? $shsSchool : ($existingStudentData['shs_school'] ?? ''),
                'shs_strand' => $isIncomingApplicant ? $shsStrand : ($existingStudentData['shs_strand'] ?? ''),
                'shs_graduation_year' => $isIncomingApplicant ? $shsGraduationYear : ($existingStudentData['shs_graduation_year'] ?? ''),
                'admission_status' => $isIncomingApplicant ? $admissionStatus : ($existingStudentData['admission_status'] ?? ''),
                'target_college' => $isIncomingApplicant ? $targetCollege : ($existingStudentData['target_college'] ?? ''),
                'target_course' => $isIncomingApplicant ? $targetCourse : ($existingStudentData['target_course'] ?? ''),
                'year_level' => $isCollegeApplicant ? $yearLevel : ($existingStudentData['year_level'] ?? ''),
                'enrollment_status' => $isCollegeApplicant ? $enrollmentStatus : ($existingStudentData['enrollment_status'] ?? ''),
                'academic_standing' => $isCollegeApplicant ? $academicStanding : ($existingStudentData['academic_standing'] ?? ''),
                'mobile_number' => $mobileNumber,
                'citizenship' => $citizenship,
                'household_income_bracket' => $householdIncomeBracket,
                'special_category' => $specialCategory,
                'house_no' => $houseNo,
                'street' => $street,
                'barangay' => $barangay,
                'city' => $city,
                'province' => $province,
                'latitude' => $latitudeRaw,
                'longitude' => $longitudeRaw,
                'location_name' => trim((string) ($_POST['location_name'] ?? ''))
            ];

            if ($hasShsAverageField) {
                $data['shs_average'] = $shsAverage;
            }

            $result = $controller->updateProfile($_SESSION['user_id'], $data);
            echo json_encode($result);
            break;

        case 'change_password':
            $currentPass = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'All password fields are required'
                ]);
                break;
            }

            if ($newPass !== $confirmPass) {
                echo json_encode([
                    'success' => false,
                    'message' => 'New passwords do not match'
                ]);
                break;
            }

            $passwordValidation = validateStrongPassword($newPass, [
                'username' => $_SESSION['user_username'] ?? '',
                'email' => $_SESSION['user_email'] ?? '',
                'name' => $_SESSION['user_display_name'] ?? trim((string) (($_SESSION['user_firstname'] ?? '') . ' ' . ($_SESSION['user_lastname'] ?? '')))
            ]);

            if (!$passwordValidation['valid']) {
                echo json_encode([
                    'success' => false,
                    'message' => $passwordValidation['errors'][0] ?? passwordPolicyHint()
                ]);
                break;
            }

            $result = $controller->changePassword(
                $_SESSION['user_id'],
                $currentPass,
                $newPass
            );
            echo json_encode($result);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action requested.'
            ]);
    }
    exit();
}
?>
