<?php
// Controller/get_scholarship_details.php
require_once __DIR__ . '/../Config/db_config.php';
require_once __DIR__ . '/../Config/helpers.php';
require_once __DIR__ . '/../Models/Scholarship.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Scholarship ID is required']);
    exit;
}

$scholarshipId = (int)$_GET['id'];

if ($scholarshipId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid scholarship ID']);
    exit;
}

try {
    $scholarshipModel = new Scholarship($pdo);
    $scholarship = $scholarshipModel->getScholarshipById($scholarshipId);
    
    if (!$scholarship) {
        echo json_encode(['success' => false, 'message' => 'Scholarship not found or inactive']);
        exit;
    }
    
    // Determine image path
    $imagePath = resolvePublicUploadUrl($scholarship['image'] ?? null, '../');
    
    // Format deadline
    $formattedDeadline = 'No deadline set';
    $daysLeft = null;
    
    if (!empty($scholarship['deadline'])) {
        $deadline = new DateTime($scholarship['deadline']);
        $formattedDeadline = $deadline->format('F d, Y');
        $daysLeft = $scholarship['days_remaining'] ?? null;
    }

    $requiredGwa = null;
    if (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') {
        $requiredGwa = (float) $scholarship['min_gwa'];
    } elseif (isset($scholarship['max_gwa']) && $scholarship['max_gwa'] !== null && $scholarship['max_gwa'] !== '') {
        $requiredGwa = (float) $scholarship['max_gwa'];
    }
    
    $response = [
        'success' => true,
        'id' => $scholarship['id'],
        'name' => htmlspecialchars($scholarship['name']),
        'description' => htmlspecialchars($scholarship['description'] ?? 'No description available'),
        'eligibility' => htmlspecialchars($scholarship['eligibility'] ?? 'No eligibility requirements specified'),
        'benefits' => $scholarship['benefits'] ?? 'No benefits specified',
        'required_gwa' => $requiredGwa,
        'max_gwa' => $scholarship['max_gwa'],
        'min_gwa' => $scholarship['min_gwa'] ?? 1.00,
        'assessment_requirement' => $scholarship['assessment_requirement'] ?? 'none',
        'assessment_link' => $scholarship['assessment_link'] ?? '',
        'assessment_details' => $scholarship['assessment_details'] ?? '',
        'target_applicant_type' => $scholarship['target_applicant_type'] ?? 'all',
        'target_year_level' => $scholarship['target_year_level'] ?? 'any',
        'required_admission_status' => $scholarship['required_admission_status'] ?? 'any',
        'target_strand' => $scholarship['target_strand'] ?? '',
        'target_citizenship' => $scholarship['target_citizenship'] ?? 'all',
        'target_income_bracket' => $scholarship['target_income_bracket'] ?? 'any',
        'target_special_category' => $scholarship['target_special_category'] ?? 'any',
        'deadline' => $formattedDeadline,
        'days_left' => $daysLeft,
        'provider' => htmlspecialchars($scholarship['provider'] ?? 'Not specified'),
        'address' => $scholarship['address'] ?? '',
        'city' => $scholarship['city'] ?? '',
        'province' => $scholarship['province'] ?? '',
        'latitude' => $scholarship['latitude'] ?? null,
        'longitude' => $scholarship['longitude'] ?? null,
        'status' => $scholarship['status'],
        'image' => $imagePath
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error in get_scholarship_details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load scholarship details right now. Please try again later.']);
}
?>
