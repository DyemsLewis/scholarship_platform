<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/provider_scope.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/helpers.php';
require_once __DIR__ . '/../app/Models/UserDocument.php';
require_once __DIR__ . '/../app/Config/access_control.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to verify documents.');

// Get filter parameter
$filter = $_GET['filter'] ?? 'pending';
$search = $_GET['search'] ?? '';
$validFilters = ['all', 'pending', 'verified', 'rejected'];
$activeFilter = in_array($filter, $validFilters, true) ? $filter : 'pending';

$documentModel = new UserDocument($pdo);
$providerScope = getCurrentProviderScope($pdo);
$documents = $documentModel->getDocumentsForAdmin($activeFilter, $search, $providerScope);
$stats = $documentModel->getAdminStats($providerScope);
$filterOptions = [
    'all' => ['label' => 'All', 'count' => (int) ($stats['total'] ?? 0)],
    'pending' => ['label' => 'Pending', 'count' => (int) ($stats['pending'] ?? 0)],
    'verified' => ['label' => 'Verified', 'count' => (int) ($stats['verified'] ?? 0)],
    'rejected' => ['label' => 'Rejected', 'count' => (int) ($stats['rejected'] ?? 0)],
];
$filterLabel = ucfirst($activeFilter) . ' documents';
if ($activeFilter === 'all') {
    $filterLabel = 'All documents';
}
$documentsVisibleCount = count($documents);
$documentsHeaderCopy = !empty($providerScope['is_provider']) && !empty($providerScope['organization_name'])
    ? 'Document submissions linked to scholarships under ' . $providerScope['organization_name'] . '.'
    : 'Review uploaded applicant documents and complete verification decisions.';
$documentReviewsStyleVersion = @filemtime(__DIR__ . '/../AdminPublic/css/document-reviews.css') ?: time();
$canEditReviewedGwa = in_array((string) ($_SESSION['role'] ?? ''), ['admin', 'super_admin'], true);
$gradeEditableDocumentTypes = ['grades', 'form_138'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Documents - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
    <link rel="stylesheet" href="../AdminPublic/css/manage-scholarship.css">
    <link rel="stylesheet" href="../AdminPublic/css/reviews.css">
    <style>
        /* Document Verification Styles */
        .document-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
            align-items: stretch;
        }

        .document-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .document-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }

        .document-preview {
            background: #f8fafc;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
            min-height: 180px;
            height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .document-preview img {
            max-width: 100%;
            max-height: 140px;
            object-fit: contain;
            border-radius: 8px;
        }

        .document-preview .file-icon {
            font-size: 4rem;
            color: var(--primary);
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .document-info {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .student-info {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
            min-height: 86px;
        }

        .student-avatar {
            width: 48px;
            height: 48px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .student-details h4 {
            margin: 0 0 5px 0;
            font-size: 1rem;
            color: var(--dark);
            line-height: 1.35;
        }

        .student-details p {
            margin: 0;
            font-size: 0.8rem;
            color: var(--gray);
        }

        .student-details {
            min-width: 0;
            flex: 1;
            display: grid;
            gap: 4px;
        }

        .document-meta {
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
            display: grid;
            gap: 10px;
            flex: 1;
        }

        .meta-row {
            display: grid;
            grid-template-columns: 104px minmax(0, 1fr);
            align-items: start;
            column-gap: 12px;
            font-size: 0.8rem;
        }

        .meta-row:last-child {
            margin-bottom: 0;
        }

        .meta-label {
            color: var(--gray);
            font-weight: 500;
        }

        .meta-value {
            color: var(--dark);
            font-weight: 600;
            min-width: 0;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .document-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
            padding-top: 15px;
            align-items: stretch;
        }

        .document-actions .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
        }

        .btn-verify {
            background: #10b981;
            color: white;
            flex: 1;
        }

        .btn-verify:hover {
            background: #059669;
        }

        .btn-reject {
            background: #ef4444;
            color: white;
            flex: 1;
        }

        .btn-reject:hover {
            background: #dc2626;
        }

        .btn-view {
            background: var(--primary);
            color: white;
            flex: 1;
        }

        .rejection-modal .modal-body {
            padding: 20px;
        }

        .rejection-reason {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
            resize: vertical;
            margin-top: 10px;
        }

        .status-badge.verified {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .rejection-note {
            background: #fef2f2;
            border-left: 3px solid #ef4444;
            padding: 10px;
            margin-top: 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            color: #991b1b;
            line-height: 1.5;
        }

        .status-badge {
            justify-self: end;
        }

        .empty-state {
            grid-column: 1/-1;
        }

        .documents-search-form {
            display: grid;
            grid-template-columns: minmax(200px, 0.8fr) minmax(320px, 1.4fr) minmax(260px, auto);
            gap: 14px;
            align-items: end;
            margin-bottom: 0;
        }

        .documents-search-copy h2 {
            margin-bottom: 6px;
            color: var(--dark);
        }

        .documents-search-copy p {
            margin: 0;
            color: var(--gray);
            font-size: 0.95rem;
        }

        .documents-filter-form {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 220px;
        }

        .documents-search-form .search-wrapper {
            margin-bottom: 0;
            min-width: 0;
            width: 100%;
        }

        .documents-search-form .search-wrapper input {
            width: 100%;
        }

        .documents-search-actions {
            display: flex;
            align-items: end;
            justify-content: flex-end;
            gap: 10px;
            min-width: 0;
        }

        .filter-select-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray);
        }

        .filter-select-wrap {
            position: relative;
        }

        .filter-select {
            width: 100%;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            padding: 11px 42px 11px 14px;
            border: 1px solid #d7e0ee;
            border-radius: 12px;
            background: #f8fbff;
            color: var(--dark);
            font-size: 0.95rem;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.12);
        }

        .filter-select-icon {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            color: var(--gray);
            pointer-events: none;
        }

        .documents-apply-btn,
        .documents-clear-btn {
            min-height: 46px;
            height: 46px;
            align-self: flex-end;
            white-space: nowrap;
        }

        .documents-section {
            padding: 24px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow: auto;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.2rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #999;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        @media (max-width: 768px) {
            .documents-search-form {
                grid-template-columns: 1fr;
                align-items: stretch;
            }

            .documents-filter-form {
                width: 100%;
            }

            .documents-search-actions {
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            .document-grid {
                grid-template-columns: 1fr;
            }
            
            .document-actions {
                flex-direction: column;
            }

            .rejection-modal .modal-content {
                width: calc(100% - 24px);
                max-width: none;
                max-height: calc(100vh - 24px);
            }

            .rejection-modal .modal-body,
            .rejection-modal .modal-footer {
                padding: 16px !important;
            }

            .rejection-modal .modal-footer {
                flex-direction: column-reverse;
            }

            .rejection-modal .modal-footer .btn {
                width: 100%;
            }
        }
    </style>
    <link rel="stylesheet" href="../AdminPublic/css/document-reviews.css?v=<?php echo urlencode((string) $documentReviewsStyleVersion); ?>">
</head>
<body>
    <?php include 'layouts/admin_header.php'; ?>

    <section class="admin-dashboard documents-review-page">
        <div class="container">
            <div class="page-header documents-page-header">
                <div class="documents-page-header-copy">
                    <h1>
                        <i class="fas fa-file-circle-check"></i>Verify Documents
                    </h1>
                    <p><?php echo htmlspecialchars($documentsHeaderCopy); ?></p>
                </div>
            </div>

            <?php $reviewsCurrentView = 'documents'; include 'layouts/reviews_nav.php'; ?>

            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <p><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></p>
            </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <p><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></p>
            </div>
            <?php endif; ?>

            <div class="search-section">
                <div class="documents-summary-grid">
                    <article class="documents-summary-card">
                        <span class="documents-summary-label">Total Documents</span>
                        <strong class="documents-summary-value"><?php echo number_format((int) ($stats['total'] ?? 0)); ?></strong>
                        <span class="documents-summary-meta">All uploaded records</span>
                    </article>
                    <article class="documents-summary-card">
                        <span class="documents-summary-label">Pending</span>
                        <strong class="documents-summary-value"><?php echo number_format((int) ($stats['pending'] ?? 0)); ?></strong>
                        <span class="documents-summary-meta">Awaiting verification</span>
                    </article>
                    <article class="documents-summary-card">
                        <span class="documents-summary-label">Verified</span>
                        <strong class="documents-summary-value"><?php echo number_format((int) ($stats['verified'] ?? 0)); ?></strong>
                        <span class="documents-summary-meta">Completed reviews</span>
                    </article>
                    <article class="documents-summary-card">
                        <span class="documents-summary-label">Rejected</span>
                        <strong class="documents-summary-value"><?php echo number_format((int) ($stats['rejected'] ?? 0)); ?></strong>
                        <span class="documents-summary-meta">Requires resubmission</span>
                    </article>
                </div>

                <div class="documents-toolbar-card">
                    <div class="documents-toolbar-copy">
                        <h2>Document Queue</h2>
                        <p><?php echo htmlspecialchars($filterLabel); ?> | <?php echo number_format($documentsVisibleCount); ?> visible record<?php echo $documentsVisibleCount === 1 ? '' : 's'; ?></p>
                    </div>
                    <form method="GET" action="admin_verify_documents.php" class="documents-toolbar-form">
                        <div class="search-wrapper">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" placeholder="Search by name, username, or email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="documents-filter-form">
                            <label for="document-filter" class="filter-select-label">Filter status</label>
                            <div class="filter-select-wrap">
                                <select name="filter" id="document-filter" class="filter-select">
                                    <?php foreach ($filterOptions as $filterValue => $option): ?>
                                        <option value="<?php echo htmlspecialchars($filterValue); ?>" <?php echo $activeFilter === $filterValue ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($option['label'] . ' (' . number_format($option['count']) . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-chevron-down filter-select-icon" aria-hidden="true"></i>
                            </div>
                        </div>
                        <div class="documents-search-actions">
                            <button type="submit" class="btn-search documents-apply-btn">
                                <i class="fas fa-search"></i> Apply
                            </button>
                            <?php if($search || $activeFilter !== 'pending'): ?>
                            <a href="admin_verify_documents.php" class="btn-clear documents-clear-btn">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="documents-surface">
                <div class="documents-surface-heading">
                    <div>
                        <h2>Submitted Documents</h2>
                        <p>Open files and complete verification decisions from this queue.</p>
                    </div>
                </div>
                <div class="documents-section">
                    <div class="document-grid">
                        <?php if(empty($documents)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-folder-open"></i>
                            </div>
                            <h3>No documents found</h3>
                            <p>No documents matching your current search or filter.</p>
                        </div>
                        <?php else: ?>
                            <?php foreach($documents as $doc): 
                        $displayName = $doc['full_name'] ?? $doc['username'] ?? 'Unknown User';
                        $initials = '';
                        $names = explode(' ', $displayName);
                        foreach($names as $name) {
                            $initials .= strtoupper(substr($name, 0, 1));
                            if(strlen($initials) >= 2) break;
                        }
                        $initials = $initials ?: 'U';
                        
                        // Determine file preview
                        $fileUrl = resolveStoredFileUrl($doc['file_path'] ?? null, '../');
                        $previewType = storedFilePreviewType($doc['file_path'] ?? null, $doc['file_name'] ?? null, $doc['mime_type'] ?? null);
                        $isImage = $previewType === 'image';
                        $documentTypeCode = (string) ($doc['document_type'] ?? '');
                        $documentDisplayName = (string) ($doc['document_display_name'] ?? str_replace('_', ' ', $documentTypeCode));
                        $isGradeEditable = $canEditReviewedGwa && in_array($documentTypeCode, $gradeEditableDocumentTypes, true);
                        $currentGwaRaw = isset($doc['current_gwa']) ? trim((string) $doc['current_gwa']) : '';
                        $currentGwaDisplay = $currentGwaRaw !== '' ? number_format((float) $currentGwaRaw, 2) : 'Not set';
                    ?>
                            <article class="document-card status-<?php echo htmlspecialchars($doc['status']); ?>" data-doc-id="<?php echo $doc['id']; ?>">
                                <div class="document-preview">
                                    <?php if($isImage && $fileUrl !== null): ?>
                                        <img src="<?php echo htmlspecialchars($fileUrl); ?>" alt="Document preview" onerror="this.style.display='none'; this.parentElement.querySelector('.file-icon').style.display='flex';">
                                        <div class="file-icon" style="display: none;">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="file-icon">
                                            <i class="fas fa-file-pdf"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="document-info">
                                    <div class="document-card-top">
                                        <div class="student-info">
                                            <div class="student-avatar"><?php echo $initials; ?></div>
                                            <div class="student-details">
                                                <h4><?php echo htmlspecialchars($displayName); ?></h4>
                                                <p><?php echo htmlspecialchars($doc['email']); ?></p>
                                                <?php if($doc['school']): ?>
                                                <p><i class="fas fa-school"></i> <?php echo htmlspecialchars($doc['school']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="status-badge <?php echo htmlspecialchars($doc['status']); ?>"><?php echo ucfirst($doc['status']); ?></span>
                                    </div>

                                    <div class="document-meta">
                                        <div class="meta-row">
                                            <span class="meta-label">Document Type:</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($documentDisplayName); ?></span>
                                        </div>
                                        <div class="meta-row">
                                            <span class="meta-label">File Name:</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($doc['file_name']); ?></span>
                                        </div>
                                        <div class="meta-row">
                                            <span class="meta-label">File Size:</span>
                                            <span class="meta-value"><?php echo round($doc['file_size'] / 1024, 2); ?> KB</span>
                                        </div>
                                        <div class="meta-row">
                                            <span class="meta-label">Uploaded:</span>
                                            <span class="meta-value"><?php echo date('M d, Y h:i A', strtotime($doc['uploaded_at'])); ?></span>
                                        </div>
                                        <?php if($isGradeEditable): ?>
                                        <div class="meta-row">
                                            <span class="meta-label">Recorded GWA:</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($currentGwaDisplay); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if($doc['status'] == 'rejected' && !empty($doc['rejection_reason'])): ?>
                                    <div class="rejection-note">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($doc['rejection_reason']); ?>
                                    </div>
                                    <?php endif; ?>

                                    <div class="document-actions">
                                        <?php if ($fileUrl !== null): ?>
                                            <button
                                                type="button"
                                                class="btn btn-view btn-sm open-file-modal"
                                                data-file-url="<?php echo htmlspecialchars($fileUrl); ?>"
                                                data-file-name="<?php echo htmlspecialchars((string) ($doc['file_name'] ?? 'Document'), ENT_QUOTES); ?>"
                                                data-file-type="<?php echo htmlspecialchars($previewType); ?>">
                                                <i class="fas fa-eye"></i> View File
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-view btn-sm" disabled>
                                                <i class="fas fa-file-circle-xmark"></i> File Unavailable
                                            </button>
                                        <?php endif; ?>
                                        <?php if($doc['status'] == 'pending'): ?>
                                        <button
                                            type="button"
                                            class="btn btn-verify btn-sm"
                                            data-document-id="<?php echo (int) $doc['id']; ?>"
                                            data-user-id="<?php echo (int) $doc['user_id']; ?>"
                                            data-document-name="<?php echo htmlspecialchars($documentDisplayName, ENT_QUOTES); ?>"
                                            data-requires-gwa="<?php echo $isGradeEditable ? '1' : '0'; ?>"
                                            data-current-gwa="<?php echo htmlspecialchars($currentGwaRaw, ENT_QUOTES); ?>"
                                            onclick="verifyDocument(this)">
                                            <i class="fas fa-check-circle"></i> Verify
                                        </button>
                                        <button onclick="openRejectModal(<?php echo $doc['id']; ?>, <?php echo $doc['user_id']; ?>)" class="btn btn-reject btn-sm">
                                            <i class="fas fa-times-circle"></i> Reject
                                        </button>
                                        <?php elseif($isGradeEditable): ?>
                                        <button
                                            type="button"
                                            class="btn btn-gwa btn-sm"
                                            data-document-id="<?php echo (int) $doc['id']; ?>"
                                            data-user-id="<?php echo (int) $doc['user_id']; ?>"
                                            data-document-name="<?php echo htmlspecialchars($documentDisplayName, ENT_QUOTES); ?>"
                                            data-current-gwa="<?php echo htmlspecialchars($currentGwaRaw, ENT_QUOTES); ?>"
                                            onclick="editDocumentGwa(this)">
                                            <i class="fas fa-pen"></i> Edit GWA
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div id="filePreviewModal" class="modal file-preview-modal" aria-hidden="true">
        <div class="modal-content file-preview-content">
            <div class="modal-header">
                <div class="file-preview-heading">
                    <h3><i class="fas fa-file-lines"></i> Document Preview</h3>
                    <p id="filePreviewName" class="file-preview-subtitle">Selected document</p>
                </div>
                <button type="button" class="close-modal" onclick="closeFilePreviewModal()" aria-label="Close file preview modal">&times;</button>
            </div>
            <div class="modal-body file-preview-body">
                <div class="file-preview-frame-wrap">
                    <iframe id="filePreviewFrame" title="Document preview"></iframe>
                    <img id="filePreviewImage" alt="Document preview">
                </div>
                <p id="filePreviewFallback" class="file-preview-fallback" hidden>Preview is limited for this file type inside the modal.</p>
            </div>
            <div class="modal-footer">
                <div class="file-preview-zoom-controls" aria-label="Preview zoom controls">
                    <button type="button" class="btn btn-outline file-preview-zoom-btn" id="filePreviewZoomOut" title="Zoom out">
                        <i class="fas fa-magnifying-glass-minus"></i>
                    </button>
                    <span id="filePreviewZoomLevel" class="file-preview-zoom-level">100%</span>
                    <button type="button" class="btn btn-outline file-preview-zoom-btn" id="filePreviewZoomIn" title="Zoom in">
                        <i class="fas fa-magnifying-glass-plus"></i>
                    </button>
                    <button type="button" class="btn btn-outline file-preview-reset-btn" id="filePreviewZoomReset">Reset</button>
                </div>
                <button type="button" class="btn btn-primary" onclick="closeFilePreviewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectModal" class="modal rejection-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle" style="color: #ef4444;"></i> Reject Document</h3>
                <button class="close-modal" onclick="closeRejectModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 10px;">
                <p>Please provide a reason for rejecting this document. This will be shown to the student.</p>
                <textarea id="rejectionReason" class="rejection-reason" placeholder="Enter rejection reason (e.g., Document is blurry, Missing signature, Wrong document type, etc.)"></textarea>
            </div>
            <div class="modal-footer" style="padding: 10px;">
                <button onclick="confirmReject()" class="btn btn-danger">Confirm Rejection</button>
                <button onclick="closeRejectModal()" class="btn btn-outline">Cancel</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let currentDocumentId = null;
        let currentUserId = null;
        const documentReviewCsrfToken = <?php echo json_encode(csrfGetToken('document_review')); ?>;
        const filePreviewModal = document.getElementById('filePreviewModal');
        const filePreviewFrame = document.getElementById('filePreviewFrame');
        const filePreviewImage = document.getElementById('filePreviewImage');
        const filePreviewName = document.getElementById('filePreviewName');
        const filePreviewFallback = document.getElementById('filePreviewFallback');
        const filePreviewZoomOut = document.getElementById('filePreviewZoomOut');
        const filePreviewZoomIn = document.getElementById('filePreviewZoomIn');
        const filePreviewZoomReset = document.getElementById('filePreviewZoomReset');
        const filePreviewZoomLevel = document.getElementById('filePreviewZoomLevel');
        let filePreviewType = 'document';
        let filePreviewBaseUrl = '';
        let filePreviewZoom = 1;

        function normalizeGwaInput(value) {
            const trimmed = String(value ?? '').trim();
            if (!trimmed) {
                return null;
            }

            const numericValue = Number(trimmed);
            if (!Number.isFinite(numericValue) || numericValue < 1 || numericValue > 5) {
                return false;
            }

            return numericValue.toFixed(2);
        }

        function submitDocumentReviewAction(action, payload, loadingTitle, successTitle, fallbackErrorMessage) {
            Swal.fire({
                title: loadingTitle,
                text: 'Please wait',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('../app/AdminControllers/verify_document_process.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    csrf_token: documentReviewCsrfToken,
                    action,
                    ...payload
                }).toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: successTitle,
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: data.message
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: fallbackErrorMessage
                });
            });
        }

        document.querySelectorAll('.open-file-modal').forEach((button) => {
            button.addEventListener('click', function() {
                openFilePreviewModal(
                    button.dataset.fileUrl || '',
                    button.dataset.fileName || 'Document',
                    button.dataset.fileType || 'document'
                );
            });
        });

        if (filePreviewZoomOut) {
            filePreviewZoomOut.addEventListener('click', () => changeFilePreviewZoom(-0.25));
        }

        if (filePreviewZoomIn) {
            filePreviewZoomIn.addEventListener('click', () => changeFilePreviewZoom(0.25));
        }

        if (filePreviewZoomReset) {
            filePreviewZoomReset.addEventListener('click', resetFilePreviewZoom);
        }

        function openFilePreviewModal(fileUrl, fileName, fileType) {
            if (!filePreviewModal || !filePreviewFrame || !filePreviewImage || !filePreviewName || !filePreviewFallback || !fileUrl) {
                return;
            }

            filePreviewType = fileType;
            filePreviewBaseUrl = fileUrl;
            filePreviewZoom = 1;
            filePreviewName.textContent = fileName;
            filePreviewFrame.style.display = 'none';
            filePreviewImage.style.display = 'none';
            filePreviewFrame.removeAttribute('src');
            filePreviewImage.removeAttribute('src');
            filePreviewFallback.hidden = true;

            if (fileType === 'image') {
                filePreviewImage.src = fileUrl;
                filePreviewImage.style.display = 'block';
                applyFilePreviewZoom();
            } else {
                if (fileType === 'pdf') {
                    applyFilePreviewZoom();
                } else {
                    filePreviewFrame.src = fileUrl;
                }
                filePreviewFrame.style.display = 'block';
                if (fileType !== 'pdf') {
                    filePreviewFallback.hidden = false;
                }
            }

            updateFilePreviewZoomControls();
            filePreviewModal.style.display = 'flex';
            filePreviewModal.setAttribute('aria-hidden', 'false');
        }

        function closeFilePreviewModal() {
            if (!filePreviewModal || !filePreviewFrame || !filePreviewImage) {
                return;
            }

            filePreviewModal.style.display = 'none';
            filePreviewModal.setAttribute('aria-hidden', 'true');
            filePreviewFrame.removeAttribute('src');
            filePreviewImage.removeAttribute('src');
            filePreviewFrame.style.display = 'none';
            filePreviewImage.style.display = 'none';
            filePreviewImage.style.width = '';
            filePreviewImage.style.maxWidth = '';
            filePreviewImage.style.maxHeight = '';
            filePreviewFallback.hidden = true;
            filePreviewType = 'document';
            filePreviewBaseUrl = '';
            filePreviewZoom = 1;
            updateFilePreviewZoomControls();
        }

        function changeFilePreviewZoom(delta) {
            if (filePreviewType !== 'image' && filePreviewType !== 'pdf') {
                return;
            }

            filePreviewZoom = Math.max(0.5, Math.min(3, Number((filePreviewZoom + delta).toFixed(2))));
            applyFilePreviewZoom();
            updateFilePreviewZoomControls();
        }

        function resetFilePreviewZoom() {
            filePreviewZoom = 1;
            applyFilePreviewZoom();
            updateFilePreviewZoomControls();
        }

        function applyFilePreviewZoom() {
            if (filePreviewType === 'image') {
                if (Math.abs(filePreviewZoom - 1) < 0.01) {
                    filePreviewImage.style.width = '';
                    filePreviewImage.style.maxWidth = '';
                    filePreviewImage.style.maxHeight = '';
                } else {
                    filePreviewImage.style.width = `${Math.round(filePreviewZoom * 100)}%`;
                    filePreviewImage.style.maxWidth = 'none';
                    filePreviewImage.style.maxHeight = 'none';
                }
                return;
            }

            if (filePreviewType === 'pdf' && filePreviewBaseUrl) {
                filePreviewFrame.src = `${filePreviewBaseUrl}#toolbar=1&navpanes=0&zoom=${Math.round(filePreviewZoom * 100)}`;
            }
        }

        function updateFilePreviewZoomControls() {
            const isZoomable = filePreviewType === 'image' || filePreviewType === 'pdf';

            if (filePreviewZoomOut) {
                filePreviewZoomOut.disabled = !isZoomable;
            }
            if (filePreviewZoomIn) {
                filePreviewZoomIn.disabled = !isZoomable;
            }
            if (filePreviewZoomReset) {
                filePreviewZoomReset.disabled = !isZoomable || Math.abs(filePreviewZoom - 1) < 0.01;
            }
            if (filePreviewZoomLevel) {
                filePreviewZoomLevel.textContent = isZoomable
                    ? `${Math.round(filePreviewZoom * 100)}%`
                    : 'N/A';
            }
        }

        function verifyDocument(button) {
            const docId = button.dataset.documentId;
            const userId = button.dataset.userId;
            const documentName = button.dataset.documentName || 'Document';
            const requiresGwa = button.dataset.requiresGwa === '1';
            const currentGwa = button.dataset.currentGwa || '';

            if (requiresGwa) {
                Swal.fire({
                    title: `Verify ${documentName}`,
                    text: 'Confirm the reviewed GWA before verifying this uploaded grade document.',
                    icon: 'question',
                    input: 'number',
                    inputLabel: 'Reviewed GWA (1.00 to 5.00)',
                    inputValue: currentGwa,
                    inputAttributes: {
                        min: '1',
                        max: '5',
                        step: '0.01',
                        placeholder: 'e.g. 1.75'
                    },
                    showCancelButton: true,
                    confirmButtonColor: '#10b981',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Save GWA and Verify',
                    cancelButtonText: 'Cancel',
                    preConfirm: (value) => {
                        const normalized = normalizeGwaInput(value);
                        if (normalized === null) {
                            Swal.showValidationMessage('Enter the reviewed GWA before verifying this document.');
                            return false;
                        }
                        if (normalized === false) {
                            Swal.showValidationMessage('GWA must be a valid number between 1.00 and 5.00.');
                            return false;
                        }
                        return normalized;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitDocumentReviewAction(
                            'verify',
                            {
                                document_id: docId,
                                user_id: userId,
                                gwa: result.value
                            },
                            'Verifying...',
                            'Verified!',
                            'An error occurred while verifying the document.'
                        );
                    }
                });
                return;
            }

            Swal.fire({
                title: 'Verify Document',
                text: 'Are you sure you want to verify this document?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, verify it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitDocumentReviewAction(
                        'verify',
                        {
                            document_id: docId,
                            user_id: userId
                        },
                        'Verifying...',
                        'Verified!',
                        'An error occurred while verifying the document.'
                    );
                }
            });
        }

        function editDocumentGwa(button) {
            const docId = button.dataset.documentId;
            const userId = button.dataset.userId;
            const documentName = button.dataset.documentName || 'Document';
            const currentGwa = button.dataset.currentGwa || '';

            Swal.fire({
                title: `Update GWA for ${documentName}`,
                text: 'Save the reviewed GWA linked to this uploaded grade document.',
                icon: 'info',
                input: 'number',
                inputLabel: 'Reviewed GWA (1.00 to 5.00)',
                inputValue: currentGwa,
                inputAttributes: {
                    min: '1',
                    max: '5',
                    step: '0.01',
                    placeholder: 'e.g. 1.75'
                },
                showCancelButton: true,
                confirmButtonColor: '#2c5aa0',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Update GWA',
                cancelButtonText: 'Cancel',
                preConfirm: (value) => {
                    const normalized = normalizeGwaInput(value);
                    if (normalized === null || normalized === false) {
                        Swal.showValidationMessage('GWA must be a valid number between 1.00 and 5.00.');
                        return false;
                    }
                    return normalized;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    submitDocumentReviewAction(
                        'update_gwa',
                        {
                            document_id: docId,
                            user_id: userId,
                            gwa: result.value
                        },
                        'Updating GWA...',
                        'GWA Updated!',
                        'An error occurred while updating the GWA.'
                    );
                }
            });
        }

        function openRejectModal(docId, userId) {
            currentDocumentId = docId;
            currentUserId = userId;
            document.getElementById('rejectionReason').value = '';
            document.getElementById('rejectModal').style.display = 'flex';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            currentDocumentId = null;
            currentUserId = null;
        }

        function confirmReject() {
            const reason = document.getElementById('rejectionReason').value.trim();
            
            if (!reason) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Reason Required',
                    text: 'Please provide a reason for rejecting this document.'
                });
                return;
            }
            
            Swal.fire({
                title: 'Reject Document',
                text: 'Are you sure you want to reject this document?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, reject it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Rejecting...',
                        text: 'Please wait',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Submit rejection
                    fetch('../app/AdminControllers/verify_document_process.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'csrf_token=' + encodeURIComponent(documentReviewCsrfToken) + '&action=reject&document_id=' + currentDocumentId + '&user_id=' + currentUserId + '&reason=' + encodeURIComponent(reason)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Rejected!',
                                text: data.message,
                                timer: 2000,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: data.message
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'An error occurred while rejecting the document.'
                        });
                    });
                }
            });
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeRejectModal();
                closeFilePreviewModal();
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const rejectModal = document.getElementById('rejectModal');
            if (event.target === rejectModal) {
                closeRejectModal();
            }
            if (event.target === filePreviewModal) {
                closeFilePreviewModal();
            }
        };
    </script>

    <?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
