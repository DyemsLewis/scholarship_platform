<?php
// View/scholarship_requirements.php
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/url_token.php';

if (!$isLoggedIn) {
    $_SESSION['error'] = 'Please login to view scholarship requirements';
    header('Location: login.php');
    exit();
}

$scholarshipId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$scholarshipId) {
    $_SESSION['error'] = 'Invalid scholarship ID';
    header('Location: scholarships.php');
    exit();
}

require_once __DIR__ . '/../app/Models/Scholarship.php';
require_once __DIR__ . '/../app/Models/UserDocument.php';
require_once __DIR__ . '/../app/Controllers/scholarshipResultController.php';

$scholarshipModel = new Scholarship($pdo);
$scholarship = $scholarshipModel->getScholarshipById($scholarshipId);

if (!$scholarship) {
    $_SESSION['error'] = 'Scholarship not found';
    header('Location: scholarships.php');
    exit();
}

$documentModel = new UserDocument($pdo);
$requirements = $documentModel->checkScholarshipRequirements($user_id, $scholarshipId);
$totalRequired = (int) ($requirements['total_required'] ?? 0);
$uploadedCount = (int) ($requirements['uploaded'] ?? 0);
$verifiedCount = (int) ($requirements['verified'] ?? 0);
$pendingCount = (int) ($requirements['pending'] ?? 0);
$missingCount = count($requirements['missing'] ?? []);
$rejectedCount = 0;
foreach (($requirements['requirements'] ?? []) as $requirement) {
    if (($requirement['status'] ?? '') === 'rejected') {
        $rejectedCount++;
    }
}
$scholarshipService = new ScholarshipService($pdo);
$profileEvaluation = $scholarshipService->evaluateProfileRequirements($scholarship, [
    'applicant_type' => $userApplicantType,
    'year_level' => $userYearLevel,
    'admission_status' => $userAdmissionStatus,
    'shs_strand' => $userShsStrand,
    'course' => $userCourse
]);
$canProceedToApply = ($missingCount === 0 && $rejectedCount === 0 && !empty($profileEvaluation['eligible']));
$wizardApplyUrl = buildEntityUrl('applications.php', 'scholarship', $scholarshipId, 'apply', ['scholarship_id' => $scholarshipId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Requirements - <?php echo htmlspecialchars($scholarship['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
    <style>
        .requirements-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .scholarship-header {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .scholarship-header h1 {
            color: var(--primary);
            margin-bottom: 10px;
        }
        
        .provider {
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .requirements-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .progress-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .progress-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
        }
        
        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .progress-container {
            width: 100%;
            background: #e9ecef;
            border-radius: 10px;
            height: 10px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .status-message {
            text-align: center;
            margin-top: 15px;
            padding: 15px;
            border-radius: 8px;
        }
        
        .status-message.ready {
            background: #d4edda;
            color: #155724;
        }
        
        .status-message.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .requirements-list {
            list-style: none;
            padding: 0;
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .requirement-item:last-child {
            border-bottom: none;
        }
        
        .requirement-status {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .status-verified {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-missing {
            background: #f8d7da;
            color: #721c24;
        }
        
        .requirement-info {
            flex: 1;
        }
        
        .requirement-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .requirement-desc {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        .document-details {
            font-size: 0.85rem;
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 5px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 30px;
        }
        
        .btn-apply {
            padding: 15px 30px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-apply:hover:not(:disabled) {
            background: var(--primary-light);
        }
        
        .btn-apply:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .btn-back {
            padding: 15px 30px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-back:hover {
            background: #5a6268;
        }
        
        .all-verified {
            background: #d4edda;
            border: 2px solid #28a745;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .all-verified i {
            font-size: 3rem;
            color: #28a745;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <?php include 'layout/header.php'; ?>

    <div class="dashboard">
        <div class="container">
            <div class="requirements-container">
                <!-- Scholarship Header -->
                <div class="scholarship-header">
                    <h1><?php echo htmlspecialchars($scholarship['name']); ?></h1>
                    <p class="provider">
                        <i class="fas fa-building"></i> 
                        <?php echo htmlspecialchars($scholarship['provider'] ?? 'Provider not specified'); ?>
                    </p>
                </div>
                
                <!-- Requirements Card -->
                <div class="requirements-card">
                    <h2><i class="fas fa-check-circle"></i> Document Requirements</h2>
                    <p>Please upload all required documents to proceed with your application.</p>
                    
                    <!-- Progress Section -->
                    <div class="progress-section">
                        <div class="progress-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $uploadedCount; ?></div>
                                <div class="stat-label">Uploaded</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $verifiedCount; ?></div>
                                <div class="stat-label">Verified</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $totalRequired; ?></div>
                                <div class="stat-label">Required</div>
                            </div>
                        </div>
                        
                        <div class="progress-container">
                            <?php
                            $percentage = $totalRequired > 0
                                ? ($uploadedCount / $totalRequired) * 100
                                : 100;
                            ?>
                            <div class="progress-bar" style="width: <?php echo $percentage; ?>%;"></div>
                        </div>
                        
                        <?php if ($missingCount > 0): ?>
                            <div class="status-message pending">
                                <i class="fas fa-exclamation-triangle"></i> 
                                Missing required documents. Upload the missing files before you apply.
                            </div>
                        <?php elseif ($rejectedCount > 0): ?>
                            <div class="status-message pending">
                                <i class="fas fa-times-circle"></i> 
                                You have <?php echo $rejectedCount; ?> rejected required document(s). Please re-upload them before applying.
                            </div>
                        <?php elseif ($totalRequired === 0): ?>
                            <div class="status-message ready">
                                <i class="fas fa-check-circle"></i>
                                No required documents configured. You can proceed to application.
                            </div>
                        <?php elseif ($pendingCount > 0): ?>
                            <div class="status-message pending">
                                <i class="fas fa-clock"></i> 
                                All required documents are uploaded. You may apply while <?php echo $pendingCount; ?> document(s) are pending verification.
                            </div>
                        <?php elseif (($profileEvaluation['pending'] ?? 0) > 0): ?>
                            <div class="status-message pending">
                                <i class="fas fa-user-gear"></i>
                                Complete your applicant profile first. <?php echo htmlspecialchars((string) ($profileEvaluation['label'] ?? '')); ?>
                            </div>
                        <?php elseif (($profileEvaluation['failed'] ?? 0) > 0): ?>
                            <div class="status-message pending">
                                <i class="fas fa-user-xmark"></i>
                                Your current profile does not match this scholarship policy. <?php echo htmlspecialchars((string) ($profileEvaluation['label'] ?? '')); ?>
                            </div>
                        <?php else: ?>
                            <div class="status-message ready">
                                <i class="fas fa-check-circle"></i> 
                                All required documents are verified! You can now apply.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Requirements List -->
                    <ul class="requirements-list">
                        <?php foreach ($requirements['requirements'] as $req): ?>
                        <li class="requirement-item">
                            <div class="requirement-status status-<?php echo $req['status']; ?>">
                                <i class="fas fa-<?php 
                                    echo $req['status'] == 'verified' ? 'check' : 
                                        ($req['status'] == 'pending' ? 'clock' : 
                                        ($req['status'] == 'missing' ? 'exclamation' : 'question')); 
                                ?>"></i>
                            </div>
                            
                            <div class="requirement-info">
                                <div class="requirement-name"><?php echo htmlspecialchars($req['name']); ?></div>
                                <div class="requirement-desc">
                                    <?php echo ucfirst($req['status']); ?>
                                    <?php if ($req['status'] == 'missing'): ?>
                                        - <a href="documents.php" style="color: var(--primary);">Upload now</a>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($req['document']): ?>
                                <div class="document-details">
                                    <i class="fas fa-file"></i> 
                                    <?php echo htmlspecialchars($req['document']['file_name']); ?>
                                    <br>
                                    <small>Uploaded: <?php echo date('M d, Y', strtotime($req['document']['uploaded_at'])); ?></small>
                                    <?php if (!empty($req['document']['admin_notes'])): ?>
                                    <br>
                                    <small style="color: #856404;">
                                        <i class="fas fa-info-circle"></i> 
                                        <?php echo htmlspecialchars($req['document']['admin_notes']); ?>
                                    </small>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="scholarships.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back to Scholarships
                        </a>
                        
                        <?php if ($canProceedToApply): ?>
                            <a href="<?php echo htmlspecialchars($wizardApplyUrl); ?>" class="btn-apply">
                                <i class="fas fa-paper-plane"></i> Proceed to Application
                            </a>
                        <?php elseif (($profileEvaluation['pending'] ?? 0) > 0 || ($profileEvaluation['failed'] ?? 0) > 0): ?>
                            <a href="profile.php" class="btn-apply" style="background: #2563eb;">
                                <i class="fas fa-user-pen"></i> Update Profile
                            </a>
                        <?php else: ?>
                            <a href="documents.php" class="btn-apply" style="background: #ffc107;">
                                <i class="fas fa-upload"></i> Fix Required Documents
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <?php include 'layout/footer.php'; ?>
</body>
</html>
