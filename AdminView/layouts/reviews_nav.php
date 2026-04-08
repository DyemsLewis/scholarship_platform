<?php
require_once __DIR__ . '/../../app/Config/access_control.php';
$reviewsCurrentView = $reviewsCurrentView ?? 'overview';
$canManageApplications = canAccessStaffApplications();
$canVerifyDocuments = canAccessStaffDocuments();
$canReviewProviders = canAccessProviderApprovals();
$canReviewScholarships = canAccessScholarshipApprovals();
$canReviewGwaReports = canAccessGwaIssueReports();
$canReviewUserIssueReports = canAccessUserIssueReports();
?>
<div class="reviews-switcher" aria-label="Reviews navigation">
    <a href="reviews.php" class="reviews-switcher-link <?php echo $reviewsCurrentView === 'overview' ? 'active' : ''; ?>">
        <i class="fas fa-table-columns" aria-hidden="true"></i>
        <span>Overview</span>
    </a>
    <?php if ($canManageApplications): ?>
    <a href="manage_applications.php" class="reviews-switcher-link <?php echo $reviewsCurrentView === 'applications' ? 'active' : ''; ?>">
        <i class="fas fa-file-lines" aria-hidden="true"></i>
        <span>Applications</span>
    </a>
    <?php endif; ?>
    <?php if ($canVerifyDocuments): ?>
    <a href="admin_verify_documents.php" class="reviews-switcher-link <?php echo $reviewsCurrentView === 'documents' ? 'active' : ''; ?>">
        <i class="fas fa-folder-open" aria-hidden="true"></i>
        <span>Documents</span>
    </a>
    <?php endif; ?>
    <?php if ($canReviewScholarships): ?>
    <a href="scholarship_reviews.php" class="reviews-switcher-link <?php echo $reviewsCurrentView === 'scholarships' ? 'active' : ''; ?>">
        <i class="fas fa-graduation-cap" aria-hidden="true"></i>
        <span>Scholarships</span>
    </a>
    <?php endif; ?>
    <?php if ($canReviewProviders): ?>
    <a href="provider_reviews.php" class="reviews-switcher-link <?php echo $reviewsCurrentView === 'providers' ? 'active' : ''; ?>">
        <i class="fas fa-building-shield" aria-hidden="true"></i>
        <span>Providers</span>
    </a>
    <?php endif; ?>
    <?php if ($canReviewGwaReports): ?>
    <a href="gwa_reports.php" class="reviews-switcher-link <?php echo $reviewsCurrentView === 'gwa_reports' ? 'active' : ''; ?>">
        <i class="fas fa-flag" aria-hidden="true"></i>
        <span>GWA Reports</span>
    </a>
    <?php endif; ?>
    <?php if ($canReviewUserIssueReports): ?>
    <a href="user_issue_reports.php" class="reviews-switcher-link <?php echo $reviewsCurrentView === 'user_reports' ? 'active' : ''; ?>">
        <i class="fas fa-life-ring" aria-hidden="true"></i>
        <span>User Reports</span>
    </a>
    <?php endif; ?>
</div>
