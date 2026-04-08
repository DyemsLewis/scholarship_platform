<?php
require_once __DIR__ . '/../app/Config/init.php';
if (!$isLoggedIn) {
    $_SESSION['error'] = 'Please login to access document upload';
    header('Location: login.php');
    exit();
}

$userId = isset($user_id) ? $user_id : $_SESSION['user_id'];

require_once __DIR__ . '/../app/Models/UserDocument.php';
$documentModel = new UserDocument($pdo);
$userDocuments = $documentModel->getUserDocuments($userId, true);
$documentTypes = $documentModel->getDocumentTypes();

function studentDocumentPreviewType(?string $storedPath, ?string $fileName = null): string
{
    $candidate = trim((string) ($fileName ?? ''));
    if ($candidate === '') {
        $candidate = trim(str_replace('\\', '/', (string) ($storedPath ?? '')));
    }

    $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
        return 'pdf';
    }

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        return 'image';
    }

    return 'document';
}

function isAcademicDocumentCard(string $documentType): bool
{
    return in_array(strtolower(trim($documentType)), ['grades', 'form_138'], true);
}

if (empty($documentTypes)) {
    $documentTypes = [
        ['code' => 'id', 'name' => 'Valid ID', 'icon' => 'id-card', 'description' => 'Government-issued ID (passport, driver\'s license, school ID)'],
        ['code' => 'birth_certificate', 'name' => 'Birth Certificate', 'icon' => 'baby', 'description' => 'PSA or NSO issued birth certificate'],
        ['code' => 'grades', 'name' => 'Transcript of Records', 'icon' => 'scroll', 'description' => 'Official transcript or grade slip'],
        ['code' => 'good_moral', 'name' => 'Good Moral Character', 'icon' => 'hand-holding-heart', 'description' => 'Certificate from school guidance office'],
        ['code' => 'enrollment', 'name' => 'Proof of Enrollment', 'icon' => 'user-graduate', 'description' => 'Certificate of enrollment or registration form'],
        ['code' => 'income_tax', 'name' => 'Income Tax Return', 'icon' => 'file-invoice', 'description' => 'ITR of parents or guardian (if applicable)'],
        ['code' => 'citizenship_proof', 'name' => 'Citizenship / Residency Proof', 'icon' => 'flag', 'description' => 'Birth certificate, passport, or residency document'],
        ['code' => 'income_proof', 'name' => 'Household Income Proof', 'icon' => 'wallet', 'description' => 'Certificate of indigency, payslip, ITR, or income certification'],
        ['code' => 'special_category_proof', 'name' => 'Special Category Proof', 'icon' => 'users', 'description' => 'PWD ID, 4Ps proof, solo parent proof, IP certification, or similar support document']
    ];
}

$normalizedApplicantType = strtolower(trim((string) ($userApplicantType ?? '')));
$isIncomingFreshman = $normalizedApplicantType === 'incoming_freshman';
$isCurrentCollegeApplicant = in_array($normalizedApplicantType, ['current_college', 'transferee', 'continuing_student'], true);

if ($isIncomingFreshman) {
    $documentTypes = array_values(array_filter($documentTypes, static function (array $type): bool {
        return (string) ($type['code'] ?? '') !== 'grades';
    }));
    $documentPageNote = 'Upload the documents needed for your current applicant profile and scholarship requirements.';
} elseif ($isCurrentCollegeApplicant) {
    $documentTypes = array_values(array_filter($documentTypes, static function (array $type): bool {
        return (string) ($type['code'] ?? '') !== 'form_138';
    }));
    $documentPageNote = 'Upload the documents needed for your current applicant profile and scholarship requirements.';
} else {
    $documentPageNote = 'Select and upload the documents that match your current scholarship profile.';
}

$visibleDocumentCodes = array_map(static fn(array $type): string => (string) ($type['code'] ?? ''), $documentTypes);
$visibleDocuments = array_filter($userDocuments, static function ($document, $documentType) use ($visibleDocumentCodes): bool {
    return in_array((string) $documentType, $visibleDocumentCodes, true);
}, ARRAY_FILTER_USE_BOTH);

$totalUploaded = count($visibleDocuments);
$verifiedCount = count(array_filter($visibleDocuments, static fn(array $doc): bool => (string) ($doc['status'] ?? '') === 'verified'));
$pendingCount = count(array_filter($visibleDocuments, static fn(array $doc): bool => (string) ($doc['status'] ?? '') === 'pending'));
$rejectedCount = count(array_filter($visibleDocuments, static fn(array $doc): bool => (string) ($doc['status'] ?? '') === 'rejected'));
$totalTypes = count($documentTypes);
$completion = $totalTypes > 0 ? round(($totalUploaded / $totalTypes) * 100) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Documents - Scholarship Finder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/document.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/card-pagination.css')); ?>">
</head>
<body>
    <!-- Header -->
    <?php include 'layout/header.php'; ?>
    
    <!-- Dashboard Section -->
    <section class="dashboard user-page-shell">
        <div class="container">
            <div class="documents-page-header app-page-hero">
                <div class="documents-header-copy app-page-hero-copy">
                    <h2><i class="fas fa-folder-open"></i> My Documents</h2>
                    <p><?php echo htmlspecialchars($documentPageNote); ?></p>
                </div>
            </div>

            <div class="documents-help-strip" aria-label="Document upload tips">
                <div class="documents-help-item">
                    <i class="fas fa-file-circle-check"></i>
                    <span>Upload clear PDF, JPG, or PNG files only.</span>
                </div>
                <div class="documents-help-item">
                    <i class="fas fa-microchip"></i>
                    <span>Academic records are routed to OCR for GWA scanning.</span>
                </div>
                <div class="documents-help-item">
                    <i class="fas fa-rotate"></i>
                    <span>You can update a file anytime if it needs correction.</span>
                </div>
            </div>

            <!-- Progress Card -->
            <div class="progress-card">
                <div class="progress-header">
                    <h3><i class="fas fa-chart-line"></i> Completion Progress</h3>
                    <span class="progress-percentage"><?php echo $completion; ?>%</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar" style="width: <?php echo $completion; ?>%;"></div>
                </div>
                <div class="progress-stats">
                    <span><i class="fas fa-check-circle" style="color: var(--success);"></i> <?php echo $verifiedCount; ?> Verified</span>
                    <span><i class="fas fa-clock" style="color: #f59e0b;"></i> <?php echo $pendingCount; ?> Pending</span>
                    <span><i class="fas fa-cloud-upload-alt" style="color: var(--primary);"></i> <?php echo $totalUploaded; ?>/<?php echo $totalTypes; ?> Uploaded</span>
                </div>
            </div>

            <div class="document-filter-card">
                <div class="document-filter-controls">
                    <label class="document-search-field" for="documentSearch">
                        <i class="fas fa-search"></i>
                        <input
                            type="search"
                            id="documentSearch"
                            placeholder="Search document name, description, or file name"
                            autocomplete="off"
                        >
                    </label>

                    <label class="document-filter-select" for="documentStatusFilter">
                        <span>Status</span>
                        <select id="documentStatusFilter">
                            <option value="all">All documents</option>
                            <option value="uploaded">Uploaded only</option>
                            <option value="missing">Missing</option>
                            <option value="verified">Verified</option>
                            <option value="pending">Pending</option>
                            <option value="rejected">Needs attention</option>
                        </select>
                    </label>

                    <button type="button" class="btn btn-outline document-filter-reset" id="documentFilterReset">
                        <i class="fas fa-rotate-left"></i> Clear
                    </button>
                </div>

                <div class="document-filter-summary" id="documentFilterSummary">
                    Showing all <?php echo $totalTypes; ?> document<?php echo $totalTypes === 1 ? '' : 's'; ?>.
                </div>
            </div>
             
            <!-- Documents Grid -->
            <div id="documentsGrid" class="documents-grid" data-pagination="cards" data-page-size="6" data-item-selector=".doc-card" data-pagination-label="documents">
                <?php foreach ($documentTypes as $type): 
                    $docType = $type['code'];
                    $docName = $type['name'];
                    $docIcon = $type['icon'] ?? 'file-lines';
                    $docDescription = $type['description'] ?? '';
                    $uploadedDoc = isset($userDocuments[$docType]) ? $userDocuments[$docType] : null;
                    $status = $uploadedDoc ? $uploadedDoc['status'] : 'missing';
                    $isAcademicDocument = isAcademicDocumentCard($docType);
                    $fileUrl = $uploadedDoc ? resolveStoredFileUrl($uploadedDoc['file_path'] ?? null, '../') : null;
                    $filePreviewType = $uploadedDoc ? studentDocumentPreviewType($uploadedDoc['file_path'] ?? null, $uploadedDoc['file_name'] ?? null) : 'document';
                    $statusIcon = $status == 'verified' ? 'check-circle' : 
                                 ($status == 'pending' ? 'clock' : 
                                 ($status == 'rejected' ? 'times-circle' : 'exclamation-circle'));
                    $searchBlob = strtolower(trim(implode(' ', array_filter([
                        $docName,
                        $docType,
                        $docDescription,
                        $uploadedDoc['file_name'] ?? '',
                        $status
                    ]))));
                ?>
                <div
                    class="doc-card"
                    id="doc-card-<?php echo $docType; ?>"
                    data-document-name="<?php echo htmlspecialchars(strtolower($docName)); ?>"
                    data-document-type="<?php echo htmlspecialchars(strtolower($docType)); ?>"
                    data-document-description="<?php echo htmlspecialchars(strtolower($docDescription)); ?>"
                    data-document-status="<?php echo htmlspecialchars(strtolower($status)); ?>"
                    data-document-file="<?php echo htmlspecialchars(strtolower((string) ($uploadedDoc['file_name'] ?? ''))); ?>"
                    data-document-keywords="<?php echo htmlspecialchars($searchBlob); ?>"
                >
                    <div class="doc-card-header">
                        <div class="doc-info">
                            <div class="doc-icon">
                                <i class="fas fa-<?php echo $docIcon; ?>"></i>
                            </div>
                            <div class="doc-title">
                                <h4><?php echo htmlspecialchars($docName); ?></h4>
                                <p><?php echo htmlspecialchars($docDescription); ?></p>
                            </div>
                        </div>
                        <span class="status-badge <?php echo $status; ?>">
                            <i class="fas fa-<?php echo $statusIcon; ?>"></i>
                            <?php echo ucfirst($status); ?>
                        </span>
                    </div>
                    
                    <div class="doc-card-body">
                        <?php if ($uploadedDoc): ?>
                            <!-- Uploaded Document View -->
                            <div class="uploaded-preview">
                                <i class="fas fa-<?php echo strpos($uploadedDoc['mime_type'], 'pdf') !== false ? 'file-pdf' : 'file-image'; ?>"></i>
                                <div class="file-details">
                                    <div class="file-name"><?php echo htmlspecialchars($uploadedDoc['file_name']); ?></div>
                                    <div class="file-meta">
                                        <?php echo round($uploadedDoc['file_size'] / 1024, 2); ?> KB | 
                                        Uploaded <?php echo date('M d, Y', strtotime($uploadedDoc['uploaded_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($status == 'rejected' && !empty($uploadedDoc['admin_notes'])): ?>
                            <div class="rejection-note">
                                <i class="fas fa-exclamation-circle"></i>
                                <strong>Reason:</strong> <?php echo htmlspecialchars($uploadedDoc['admin_notes']); ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($isAcademicDocument): ?>
                            <div class="academic-upload-note">
                                <i class="fas fa-microchip"></i>
                                Academic documents are updated through the OCR upload page so your GWA can be scanned automatically.
                            </div>
                            <?php endif; ?>
                            
                            <div class="doc-actions">
                                <?php if ($fileUrl !== null): ?>
                                <button
                                    type="button"
                                    class="btn btn-outline btn-sm"
                                    data-file-preview-trigger="true"
                                    data-file-url="<?php echo htmlspecialchars($fileUrl); ?>"
                                    data-file-type="<?php echo htmlspecialchars($filePreviewType); ?>"
                                    data-file-name="<?php echo htmlspecialchars((string) ($uploadedDoc['file_name'] ?? $docName)); ?>"
                                >
                                    <i class="fas fa-eye"></i> View File
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-outline btn-sm" disabled>
                                    <i class="fas fa-file-circle-xmark"></i> File Unavailable
                                </button>
                                <?php endif; ?>
                                <?php if ($isAcademicDocument): ?>
                                <a
                                    href="upload.php?from=documents&document_type=<?php echo urlencode($docType); ?>"
                                    class="btn btn-primary btn-sm"
                                >
                                    <i class="fas fa-microchip"></i> Update with OCR
                                </a>
                                <?php else: ?>
                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    onclick="openUploadModal('<?php echo $docType; ?>', '<?php echo htmlspecialchars($docName); ?>', 'update')"
                                >
                                    <i class="fas fa-rotate"></i> Update File
                                </button>
                                <?php endif; ?>
                            </div>
                            
                        <?php else: ?>
                            <!-- Missing Document View -->
                            <div class="missing-state">
                                <div class="missing-icon">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>
                                <h5>Not uploaded yet</h5>
                                <p>Upload your <?php echo strtolower(htmlspecialchars($docName)); ?> to complete your profile</p>
                                
                                <div class="requirements-mini">
                                    <h6><i class="fas fa-list"></i> Requirements</h6>
                                    <ul>
                                        <li>PDF, JPG, or PNG format</li>
                                        <li>Max size 5MB</li>
                                        <li>Clear and readable</li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="doc-actions">
                                <?php if ($isAcademicDocument): ?>
                                <a href="upload.php?from=documents&document_type=<?php echo urlencode($docType); ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-microchip"></i> Scan & Upload
                                </a>
                                <?php else: ?>
                                <button class="btn btn-primary btn-sm" onclick="openUploadModal('<?php echo $docType; ?>', '<?php echo htmlspecialchars($docName); ?>')">
                                    <i class="fas fa-cloud-upload-alt"></i> Upload Document
                                </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="empty-state document-filter-empty-state" id="documentFilterEmpty" hidden>
                <i class="fas fa-search"></i>
                <h3>No matching documents found</h3>
                <p>Try a different keyword or change the filter to see more document cards.</p>
            </div>
             
            <?php if (empty($documentTypes)): ?>
            <div class="empty-state">
                <i class="fas fa-file-upload"></i>
                <h3>No Document Types Available</h3>
                <p>Please check back later for document upload requirements.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>
    
    <!-- Upload Modal -->
    <div class="modal-overlay" id="uploadModal">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fas fa-cloud-upload-alt"></i> <span id="modalTitle">Upload Document</span></h3>
                <button class="modal-close" onclick="closeUploadModal()"><i class="fas fa-times"></i></button>
            </div>
            
            <form id="uploadForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="documentType" name="document_type">
                
                <div class="modal-body">
                    <div class="upload-mode-note" id="uploadModeNote" hidden></div>

                    <div class="upload-zone" id="uploadArea">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <h4>Click to browse or drag & drop</h4>
                        <p>Supported formats: JPG, PNG, PDF (Max: 5MB)</p>
                        <input type="file" id="fileInput" name="document_file" accept=".jpg,.jpeg,.png,.pdf" style="display: none;" required>
                        <div id="fileName" class="file-info" style="display: none;"></div>
                    </div>
                    
                    <div class="requirements-list">
                        <h6><i class="fas fa-clipboard-check"></i> Document Requirements:</h6>
                        <ul id="requirementsList">
                            <li><i class="fas fa-check"></i> File must be clear and readable</li>
                            <li><i class="fas fa-check"></i> All information must be visible</li>
                            <li><i class="fas fa-check"></i> No alterations or edits</li>
                            <li><i class="fas fa-check"></i> Valid and not expired</li>
                        </ul>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeUploadModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="uploadBtn">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay file-preview-modal" id="filePreviewModal" hidden>
        <div class="file-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="filePreviewTitle">
            <div class="file-preview-header">
                <div>
                    <h3 id="filePreviewTitle"><i class="fas fa-file-lines"></i> View Uploaded File</h3>
                    <p id="filePreviewName">Preview your uploaded document</p>
                </div>
                <button type="button" class="file-preview-close" id="filePreviewClose" aria-label="Close file preview">&times;</button>
            </div>

            <div class="file-preview-body" id="filePreviewBody">
                <div class="file-preview-fallback">
                    <i class="fas fa-file-circle-question"></i>
                    <h4>No file selected</h4>
                    <p>Select a document to preview it here.</p>
                </div>
            </div>

            <div class="file-preview-footer">
                <button type="button" class="btn btn-primary" id="filePreviewDone">Done</button>
            </div>
        </div>
    </div>
    
    <?php include 'layout/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?php echo htmlspecialchars(assetUrl('public/js/sweetalert.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(assetUrl('public/js/document.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(assetUrl('public/js/card-pagination.js')); ?>"></script>
</body>
</body>
</html>




