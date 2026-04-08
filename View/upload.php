<?php
require_once __DIR__ . '/../app/Config/init.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship - Grade Upload</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/upload.css')); ?>">
</head>
<body>
    <!-- Header -->
    <?php 
    include 'layout/header.php'; 
    
    // Check if user is logged in
    $isLoggedIn = isset($_SESSION['user_id']);
    $userName = $_SESSION['user_name'] ?? $_SESSION['user_display_name'] ?? $_SESSION['user_username'] ?? '';
    $userSchool = $_SESSION['user_school'] ?? '';
    $userCourse = $_SESSION['user_course'] ?? '';
    $userGWA = $_SESSION['user_gwa'] ?? null;
    $userFirstName = $_SESSION['user_firstname'] ?? '';
    $userLastName = $_SESSION['user_lastname'] ?? '';
    $userMiddleInitial = $_SESSION['user_middleinitial'] ?? '';
    $userEmail = $_SESSION['user_email'] ?? '';
    $userLatitude = $_SESSION['user_latitude'] ?? null;
    $userLongitude = $_SESSION['user_longitude'] ?? null;
    $lastRawOcrValue = isset($_SESSION['last_ocr_raw_gwa']) && is_numeric($_SESSION['last_ocr_raw_gwa']) ? (float) $_SESSION['last_ocr_raw_gwa'] : null;
    $lastExtractedGwa = isset($_SESSION['last_ocr_final_gwa']) && is_numeric($_SESSION['last_ocr_final_gwa']) ? (float) $_SESSION['last_ocr_final_gwa'] : null;
    if ($lastExtractedGwa === null && $userGWA !== null && is_numeric($userGWA)) {
        $lastExtractedGwa = (float) $userGWA;
    }
    $lastTorDocumentId = isset($_SESSION['last_uploaded_tor_document_id']) && is_numeric($_SESSION['last_uploaded_tor_document_id'])
        ? (int) $_SESSION['last_uploaded_tor_document_id']
        : 0;
    
    // Get document stats for consistency
    $documentsUploaded = 0;
    if ($isLoggedIn) {
        try {
            require_once __DIR__ . '/../app/Models/UserDocument.php';
            $docModel = new UserDocument($pdo);
            $docs = $docModel->getUserDocuments($_SESSION['user_id']);
            $documentsUploaded = count($docs);
        } catch (Exception $e) {
            $documentsUploaded = 0;
        }

    }
    
    // Helper to get initials
    function getUserInitialsUpload($name) {
        if (empty($name)) return 'U';
        $words = explode(' ', trim($name));
        $initials = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= strtoupper(substr($word, 0, 1));
            }
        }
        return substr($initials, 0, 2);
    }




    $uploadNoticeType = '';
    $uploadNoticeMessage = '';
    $uploadNoticeTitle = isset($_SESSION['upload_notice_title']) && trim((string) $_SESSION['upload_notice_title']) !== ''
        ? (string) $_SESSION['upload_notice_title']
        : 'Grade Document Scan Result';
    $cameFromDocuments = isset($_GET['from']) && (string) $_GET['from'] === 'documents';
    if (!empty($_SESSION['upload_success']) && !empty($_SESSION['message'])) {
        $uploadNoticeType = 'success';
        $uploadNoticeMessage = (string) $_SESSION['message'];
        unset($_SESSION['upload_success'], $_SESSION['message'], $_SESSION['upload_notice_title']);
    } elseif (!empty($_SESSION['upload_error'])) {
        $uploadNoticeType = 'error';
        $uploadNoticeMessage = (string) $_SESSION['upload_error'];
        unset($_SESSION['upload_error'], $_SESSION['upload_notice_title']);
    }
    ?>

    <section class="dashboard user-page-shell">
        <div class="container">
            <?php if ($uploadNoticeMessage !== ''): ?>
                <div style="margin-bottom: 16px; padding: 12px 14px; border-radius: 10px; background: <?php echo $uploadNoticeType === 'success' ? '#ecfdf5' : '#fef2f2'; ?>; border: 1px solid <?php echo $uploadNoticeType === 'success' ? '#86efac' : '#fecaca'; ?>; color: <?php echo $uploadNoticeType === 'success' ? '#166534' : '#b91c1c'; ?>; font-size: 0.9rem;">
                    <i class="fas <?php echo $uploadNoticeType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($uploadNoticeMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($cameFromDocuments): ?>
                <div style="margin-bottom: 16px; padding: 12px 14px; border-radius: 10px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 0.9rem;">
                    <i class="fas fa-circle-info"></i>
                    Academic documents are processed here so the system can scan your file and extract your GWA automatically.
                </div>
            <?php endif; ?>

            <div class="upload-page-hero app-page-hero">
                <div class="app-page-hero-copy">
                    <h2><i class="fas fa-cloud-arrow-up"></i> Grade Document Upload</h2>
                    <p>Upload your academic record and let the system extract your GWA automatically.</p>
                </div>
                <?php if ($isLoggedIn): ?>
                <div class="app-page-hero-side">
                <div class="upload-hero-chip app-page-hero-badge">
                    <i class="fas fa-file-circle-check"></i>
                    <?php echo number_format($documentsUploaded); ?> document<?php echo $documentsUploaded === 1 ? '' : 's'; ?>
                </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if(!$isLoggedIn): ?>
            <!-- Guest View - Card style matching dashboard -->
            <div class="upload-card">
                <div class="upload-card-header">
                    <h2><i class="fas fa-lock"></i> Login Required</h2>
                    <p>Access automatic GWA extraction and personalized scholarship matching</p>
                </div>
                <div class="upload-card-body" style="text-align: center; padding: 48px 24px;">
                    <i class="fas fa-cloud-upload-alt" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 20px;"></i>
                    <h3 style="font-size: 1.2rem; margin-bottom: 8px;">You need to login to upload grades</h3>
                    <p style="color: var(--gray); max-width: 400px; margin: 0 auto 24px;">Create an account or sign in to use the OCR-based GWA extraction and see your scholarship matches.</p>
                    <a href="login.php" class="btn btn-primary" style="padding: 12px 32px; border-radius: 40px;"><i class="fas fa-sign-in-alt"></i> Login Now</a>
                    
                    <!-- Example output preview (guest) -->
                    <div style="margin-top: 40px; background: #f8fafc; border-radius: 16px; padding: 20px; text-align: left;">
                        <h4 style="font-size: 0.9rem; margin-bottom: 16px; color: var(--primary);"><i class="fas fa-chart-line"></i> Example Output After Upload</h4>
                        <div class="info-grid-compact" style="margin-bottom: 0;">
                            <div class="info-card-compact">
                                <div class="label">Extracted GWA</div>
                                <div class="value"><i class="fas fa-calculator"></i> 1.75 (Excellent)</div>
                            </div>
                            <div class="info-card-compact">
                                <div class="label">Validation Status</div>
                                <div class="value"><i class="fas fa-check-circle" style="color: #10b981;"></i> CHED Accredited</div>
                            </div>
                            <div class="info-card-compact">
                                <div class="label">Grade Consistency</div>
                                <div class="value"><i class="fas fa-check-circle" style="color: #10b981;"></i> Verified</div>
                            </div>
                            <div class="info-card-compact">
                                <div class="label">Passing Requirements</div>
                                <div class="value"><i class="fas fa-check-circle" style="color: #10b981;"></i> Met</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <!-- Logged In View - Redesigned like dashboard profile -->
            <div class="upload-card">
                <div class="upload-card-body">
                    <!-- Compact user info row (matching dashboard stats) -->
                    <div class="stats-grid-compact">
                        <div class="stat-item-compact">
                            <div class="stat-value-compact"><?php echo $userGWA ? number_format((float)$userGWA, 2) : '-'; ?></div>
                            <div class="stat-label-compact">Current GWA</div>
                        </div>
                        <div class="stat-item-compact">
                            <div class="stat-value-compact"><?php echo $documentsUploaded; ?></div>
                            <div class="stat-label-compact">Documents</div>
                        </div>
                        <div class="stat-item-compact">
                            <div class="stat-value-compact"><i class="fas fa-cloud-upload-alt" style="font-size: 1rem;"></i> OCR</div>
                            <div class="stat-label-compact">Auto Extract</div>
                        </div>
                    </div>
                    
                    <!-- Personal info grid (compact) -->
                    <div class="info-grid-compact">
                        <div class="info-card-compact">
                            <div class="label">Full Name</div>
                            <div class="value">
                                <i class="fas fa-user-circle" style="color: var(--primary);"></i>
                                <?php 
                                $fullName = trim($userFirstName . ' ' . $userMiddleInitial . ' ' . $userLastName);
                                echo htmlspecialchars($fullName ?: $userName ?: 'Student');
                                ?>
                            </div>
                        </div>
                        <div class="info-card-compact">
                            <div class="label">School</div>
                            <div class="value">
                                <i class="fas fa-university"></i>
                                <?php echo htmlspecialchars($userSchool ?: 'Not set'); ?>
                            </div>
                        </div>
                        <div class="info-card-compact">
                            <div class="label">Course</div>
                            <div class="value">
                                <i class="fas fa-book-open"></i>
                                <?php echo htmlspecialchars($userCourse ?: 'Not set'); ?>
                            </div>
                        </div>
                        <div class="info-card-compact">
                            <div class="label">Email</div>
                            <div class="value">
                                <i class="fas fa-envelope"></i>
                                <?php echo htmlspecialchars($userEmail); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Upload Form -->
                    <form action="../app/Controllers/process_upload.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="upload-zone-modern" id="uploadArea">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <h3>Drag & drop your academic record</h3>
                            <p>Supported: JPG, PNG, PDF (Max 5MB)</p>
                            <div id="fileName" class="file-name-badge" style="display: none;"></div>
                            <input type="file" id="gradeFile" name="grade_file" accept=".jpg,.jpeg,.png,.pdf" style="display: none;" required>
                            <div style="margin-top: 12px;">
                                <span style="font-size: 0.75rem; color: var(--gray);"><i class="fas fa-folder-open"></i> Click to browse</span>
                            </div>
                        </div>
                        
                        <div class="process-section">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                                <div>
                                    <h4 style="margin: 0 0 4px 0; font-size: 0.9rem;"><i class="fas fa-microchip"></i> OCR Processing</h4>
                                    <p style="margin: 0; font-size: 0.75rem; color: var(--gray);">Extract GWA automatically from your document</p>
                                </div>
                                <button type="submit" class="btn-primary-modern" id="processBtn">
                                    <i class="fas fa-magic"></i> Process Document
                                </button>
                            </div>
                            
                            <!-- GWA status if already uploaded -->
                            <?php if($userGWA): ?>
                            <div class="gwa-status">
                                <i class="fas fa-chart-simple"></i> 
                                <strong>Current GWA:</strong> <?php echo number_format((float)$userGWA, 2); ?> 
                                <span style="font-size: 0.7rem;">(Upload new file to update)</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="upload-card upload-report-card" style="margin-bottom: 20px;">
                <div class="upload-card-header">
                    <h2><i class="fas fa-flag"></i> Report Incorrect GWA</h2>
                    <p>Submit a correction if OCR read your academic record incorrectly</p>
                </div>
                <div class="upload-card-body">
                    <p class="upload-report-note">
                        Your report will be marked for manual verification by admin.
                    </p>
                    <div class="upload-report-chip-row">
                        <span class="upload-report-chip">
                            <i class="fas fa-calculator"></i>
                            Last extracted GWA:
                            <?php echo $lastExtractedGwa !== null ? number_format((float) $lastExtractedGwa, 2) : 'Not detected'; ?>
                        </span>
                        <?php if ($lastRawOcrValue !== null): ?>
                        <span class="upload-report-chip upload-report-chip-muted">
                            <i class="fas fa-microchip"></i>
                            Raw OCR value: <?php echo number_format((float) $lastRawOcrValue, 2); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <form action="../app/Controllers/report_gwa_issue.php" method="POST" id="gwaReportForm">
                        <input type="hidden" name="document_id" value="<?php echo $lastTorDocumentId > 0 ? (int) $lastTorDocumentId : ''; ?>">
                        <input type="hidden" name="extracted_gwa" value="<?php echo $lastExtractedGwa !== null ? htmlspecialchars(number_format((float) $lastExtractedGwa, 2, '.', '')) : ''; ?>">

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px;">
                            <div>
                                <label for="reportReason" style="display: block; margin-bottom: 6px; font-size: 0.82rem; font-weight: 600; color: var(--dark);">Reason</label>
                                <select id="reportReason" name="reason_code" required style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px;">
                                    <option value="">Select reason</option>
                                    <option value="wrong_detected_gwa">Detected GWA is wrong</option>
                                    <option value="gwa_not_detected">GWA was not detected</option>
                                    <option value="wrong_conversion">Wrong percentage to GWA conversion</option>
                                    <option value="blurry_scan">OCR misread due to scan quality</option>
                                    <option value="other">Other issue</option>
                                </select>
                            </div>
                            <div>
                                <label for="reportedGwa" style="display: block; margin-bottom: 6px; font-size: 0.82rem; font-weight: 600; color: var(--dark);">Correct GWA (optional)</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="1"
                                    max="5"
                                    id="reportedGwa"
                                    name="reported_gwa"
                                    placeholder="Example: 1.75"
                                    style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px;"
                                >
                            </div>
                        </div>

                        <div style="margin-top: 14px;">
                            <label for="reportDetails" style="display: block; margin-bottom: 6px; font-size: 0.82rem; font-weight: 600; color: var(--dark);">Details (optional)</label>
                            <textarea id="reportDetails" name="details" rows="3" placeholder="Example: OCR read 1st sem as 1.00. My actual average is 1.75." style="width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; resize: vertical;"></textarea>
                        </div>

                        <div class="upload-report-actions">
                            <button type="submit" class="btn-primary-modern" id="reportGwaBtn">
                                <i class="fas fa-flag"></i> Submit Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- Processing Steps (3-step card) like dashboard modules -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin: 20px 0 30px;">
                <div class="process-step-mini">
                    <div class="step-icon"><i class="fas fa-upload"></i></div>
                    <h4>Step 1: Upload</h4>
                    <p>Upload an image or PDF of your report card, transcript, or grades slip.</p>
                </div>
                <div class="process-step-mini">
                    <div class="step-icon"><i class="fas fa-cogs"></i></div>
                    <h4>Step 2: OCR Extraction</h4>
                    <p>System extracts text and identifies your GWA, subjects, and grades automatically.</p>
                </div>
                <div class="process-step-mini">
                    <div class="step-icon"><i class="fas fa-check-double"></i></div>
                    <h4>Step 3: Validation</h4>
                    <p>Validates GWA consistency, school accreditation, and matches eligibility criteria.</p>
                </div>
            </div>
            
            <!-- Document Management Card (similar to dashboard profile extra card) -->
            <div class="upload-card" style="margin-bottom: 20px;">
                <div class="upload-card-header" style="background: linear-gradient(135deg, #2c5aa0, #1e3a6b);">
                    <h2><i class="fas fa-file-alt"></i> Document Management</h2>
                    <p>Manage required scholarship documents</p>
                </div>
                <div class="upload-card-body">
                    <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center; justify-content: space-between;">
                        <div style="flex: 1;">
                            <p style="margin-bottom: 12px;">Complete your scholarship profile by uploading these essential documents:</p>
                            <div style="display: flex; flex-wrap: wrap; gap: 12px;">
                                <span style="background: #f0f4ff; padding: 5px 12px; border-radius: 20px; font-size: 0.8rem;"><i class="fas fa-id-card" style="color: var(--primary);"></i> Valid ID</span>
                                <span style="background: #f0f4ff; padding: 5px 12px; border-radius: 20px; font-size: 0.8rem;"><i class="fas fa-baby" style="color: var(--primary);"></i> Birth Certificate</span>
                                <span style="background: #f0f4ff; padding: 5px 12px; border-radius: 20px; font-size: 0.8rem;"><i class="fas fa-scroll" style="color: var(--primary);"></i> Transcript</span>
                                <span style="background: #f0f4ff; padding: 5px 12px; border-radius: 20px; font-size: 0.8rem;"><i class="fas fa-hand-holding-heart"></i> Good Moral</span>
                            </div>
                        </div>
                        <a href="documents.php" class="btn-primary-modern" style="background: white; color: var(--primary); border: 1px solid var(--primary);">
                            <i class="fas fa-upload"></i> Manage Documents
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Previous Uploads Info (if GWA exists) -->
            <?php if($userGWA): ?>
            <div class="upload-card" style="background: #fefce8; border-left: 5px solid var(--accent);">
                <div class="upload-card-body" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h4 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 8px;"><i class="fas fa-chart-line" style="color: var(--success);"></i> Your Current GWA Information</h4>
                        <p style="margin: 0; font-size: 0.85rem;"><strong>GWA:</strong> <?php echo number_format((float)$userGWA, 2); ?> &nbsp;|&nbsp; <strong>Last Updated:</strong> <?php echo isset($_SESSION['gwa_updated']) ? date('F j, Y', strtotime($_SESSION['gwa_updated'])) : 'Recently'; ?></p>
                        <p style="margin: 5px 0 0 0; font-size: 0.75rem; color: var(--gray);"><i class="fas fa-badge-check"></i> Ready for scholarship matching</p>
                    </div>
                    <a href="scholarships.php" class="btn-outline-modern"><i class="fas fa-gem"></i> View Scholarships</a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </section>

    <?php include 'layout/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
            
            if (isLoggedIn) {
                const fileInput = document.getElementById('gradeFile');
                const fileNameDisplay = document.getElementById('fileName');
                const uploadArea = document.getElementById('uploadArea');
                
                if (fileInput) {
                    fileInput.addEventListener('change', function(e) {
                        if (this.files && this.files[0]) {
                            const file = this.files[0];
                            const fileName = file.name;
                            const fileSize = file.size;
                            const maxSize = 5 * 1024 * 1024;
                            
                            if (fileSize > maxSize) {
                                fileNameDisplay.innerHTML = '<i class="fas fa-exclamation-triangle"></i> File too large! Max 5MB.';
                                fileNameDisplay.style.backgroundColor = '#fee2e2';
                                fileNameDisplay.style.color = '#b91c1c';
                                fileNameDisplay.style.display = 'inline-flex';
                                this.value = '';
                            } else {
                                fileNameDisplay.innerHTML = '<i class="fas fa-file-pdf"></i> Selected: ' + fileName + ' (' + (fileSize / 1024 / 1024).toFixed(2) + ' MB)';
                                fileNameDisplay.style.backgroundColor = '#eef2ff';
                                fileNameDisplay.style.color = 'var(--primary)';
                                fileNameDisplay.style.display = 'inline-flex';
                            }
                        } else {
                            fileNameDisplay.style.display = 'none';
                        }
                    });
                }
                
                // Drag and drop
                if (uploadArea) {
                    uploadArea.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        this.style.borderColor = 'var(--primary)';
                        this.style.backgroundColor = 'rgba(44, 90, 160, 0.02)';
                    });
                    
                    uploadArea.addEventListener('dragleave', function(e) {
                        e.preventDefault();
                        this.style.borderColor = '#cbd5e1';
                        this.style.backgroundColor = '#fafcff';
                    });
                    
                    uploadArea.addEventListener('drop', function(e) {
                        e.preventDefault();
                        this.style.borderColor = '#cbd5e1';
                        this.style.backgroundColor = '#fafcff';
                        
                        if (e.dataTransfer.files.length) {
                            fileInput.files = e.dataTransfer.files;
                            const event = new Event('change');
                            fileInput.dispatchEvent(event);
                        }
                    });
                    
                    uploadArea.addEventListener('click', function() {
                        fileInput.click();
                    });
                }
                
                // Form validation and submission
                const uploadForm = document.getElementById('uploadForm');
                const processBtn = document.getElementById('processBtn');
                const reportForm = document.getElementById('gwaReportForm');
                const reportBtn = document.getElementById('reportGwaBtn');
                const reportedGwaInput = document.getElementById('reportedGwa');
                
                if (uploadForm) {
                    uploadForm.addEventListener('submit', function(e) {
                        if (!fileInput.value) {
                            e.preventDefault();
                            Swal.fire({
                                icon: 'warning',
                                title: 'No file selected',
                                text: 'Please select an academic record to upload.',
                                confirmButtonColor: '#2c5aa0'
                            });
                            return false;
                        }
                        
                        const allowedExtensions = /(\.jpg|\.jpeg|\.png|\.pdf)$/i;
                        if (!allowedExtensions.exec(fileInput.value)) {
                            e.preventDefault();
                            Swal.fire({
                                icon: 'error',
                                title: 'Invalid file type',
                                text: 'Please upload only JPG, PNG, or PDF files.',
                                confirmButtonColor: '#2c5aa0'
                            });
                            return false;
                        }
                        
                        // Show processing state
                        if (processBtn) {
                            processBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                            processBtn.disabled = true;
                        }
                        return true;
                    });
                }

                if (reportForm) {
                    reportForm.addEventListener('submit', function(e) {
                        const reason = (document.getElementById('reportReason')?.value || '').trim();
                        const gwaValue = (reportedGwaInput?.value || '').trim();

                        if (!reason) {
                            e.preventDefault();
                            Swal.fire({
                                icon: 'warning',
                                title: 'Reason is required',
                                text: 'Please select why the extracted GWA is incorrect.',
                                confirmButtonColor: '#9a3412'
                            });
                            return false;
                        }

                        if (gwaValue !== '') {
                            const gwa = parseFloat(gwaValue);
                            if (Number.isNaN(gwa) || gwa < 1 || gwa > 5) {
                                e.preventDefault();
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Invalid correct GWA',
                                    text: 'Correct GWA must be between 1.00 and 5.00.',
                                    confirmButtonColor: '#9a3412'
                                });
                                return false;
                            }
                        }

                        if (reportBtn) {
                            reportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                            reportBtn.disabled = true;
                        }
                        return true;
                    });
                }
            }
        });
        
        // Helper function for initials (already defined)
        function getUserInitialsUpload(name) {
            if (!name) return 'U';
            const parts = name.split(' ');
            let initials = '';
            for (let i = 0; i < Math.min(parts.length, 2); i++) {
                if (parts[i].length > 0) initials += parts[i][0].toUpperCase();
            }
            return initials || 'U';
        }
    </script>

</body>
</html>

