<?php
require_once __DIR__ . '/../app/Config/init.php';

if ($isProviderOrAdmin) {
    redirect('../AdminView/admin_dashboard.php');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Finder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/carousel.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/scholarship_style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/card-pagination.css')); ?>">
</head>
<body>
    <?php 
    include 'layout/header.php';
    require_once __DIR__ . '/../app/Config/db_config.php';
    require_once __DIR__ . '/../app/Models/Scholarship.php';

    $scholarshipModel = new Scholarship($pdo);
    $scholarships = $scholarshipModel->getActiveScholarships();
    ?>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <!-- Carousel Background -->
    <div class="hero-carousel">
        <div class="carousel-slide active">
            <img src="../public/images/students.jpg" alt="Student studying">
        </div>
        <div class="carousel-slide">
            <img src="../public/images/students1.jpg" alt="Students in campus">
        </div>
        <div class="carousel-slide">
            <img src="../public/images/students2.jpg" alt="Graduation ceremony">
        </div>
        <div class="carousel-slide">
            <img src="../public/images/students3.jpg" alt="Library">
        </div>
        <div class="carousel-slide">
            <img src="../public/images/students4.jpg" alt="Classroom">
        </div>
    </div>
    
    <!-- Dark Overlay -->
    <div class="hero-overlay"></div>
    
    <!-- Carousel Controls -->
    <button class="carousel-control prev" onclick="changeSlide(-1)">
        <i class="fas fa-chevron-left"></i>
    </button>
    <button class="carousel-control next" onclick="changeSlide(1)">
        <i class="fas fa-chevron-right"></i>
    </button>
    
    <!-- Carousel Indicators -->
    <div class="carousel-indicators">
        <span class="indicator active" onclick="currentSlide(0)"></span>
        <span class="indicator" onclick="currentSlide(1)"></span>
        <span class="indicator" onclick="currentSlide(2)"></span>
        <span class="indicator" onclick="currentSlide(3)"></span>
        <span class="indicator" onclick="currentSlide(4)"></span>
    </div>
        <div class="container">
            <div class="hero-content">
                <div class="hero-text">
                    <h1>Scholarship Finder for Filipino Students</h1>
                    <p>An intelligent platform with decision support system that automates scholarship discovery and eligibility assessment for Filipino students.</p>
                    
                    <?php if(!isset($_SESSION['user_id'])): ?>
                    <a href="login.php" class="btn btn-primary">Get Started <i class="fas fa-arrow-right"></i></a>
                    <?php else: ?>
                    <a href="scholarships.php" class="btn btn-primary">Go to Scholarships <i class="fas fa-arrow-right"></i></a>
                    <?php endif; ?>
                    
                    <div class="features">
                        <div class="feature">
                            <i class="fas fa-check-circle"></i>
                            <span>No Manual Searching</span>
                        </div>
                        <div class="feature">
                            <i class="fas fa-file-image"></i>
                            <span>Grade Extraction</span>
                        </div>
                        <div class="feature">
                            <i class="fas fa-magic"></i>
                            <span>Applications</span>
                        </div>
                    </div>
                </div>
                <div class="hero-image">
                </div>
            </div>
        </div>
    </section>

    <!-- Available Scholarships Section -->
    <section class="available-scholarships" id="scholarships" style="padding-top: 48px;">
        <div class="container">
            <div class="section-title">
                <h2>Available Scholarships</h2>
                <p>Browse and apply for scholarships that match your profile</p>
            </div>
            
            <?php if(empty($scholarships)): ?>
                <div class="no-scholarships">
                    <i class="fas fa-search" style="font-size: 3rem; color: #ccc; margin-bottom: 20px;"></i>
                    <h3>No scholarships available at the moment</h3>
                    <p>Please check back later for new opportunities.</p>
                </div>
            <?php else: ?>
                <div class="scholarships-grid" data-pagination="cards" data-page-size="6" data-item-selector=".scholarship-card" data-pagination-label="scholarships">
                    <?php foreach($scholarships as $scholarship): ?>
                    <?php 
                    $deadline = !empty($scholarship['deadline']) ? new DateTime($scholarship['deadline']) : null;
                    $today = new DateTime('today');
                    if ($deadline) {
                        $deadline->setTime(0, 0, 0);
                    }
                    $isExpired = $deadline && $deadline < $today;
                    $daysLeft = (!$isExpired && $deadline) ? $today->diff($deadline)->days : null;
                    $isUrgent = !$isExpired && $deadline && $daysLeft <= 7;
                    $provider = !empty($scholarship['provider']) ? $scholarship['provider'] : 'Provider not specified';
                    
                    // Determine logo path
                    $defaultScholarshipImage = resolvePublicUploadUrl(null, '../');
                    $logoPath = resolvePublicUploadUrl($scholarship['image'] ?? null, '../');
                    ?>
                    
                    <div class="scholarship-card" data-id="<?php echo $scholarship['id']; ?>">
                        <div class="compact-card">
                            <div class="compact-logo">
                                <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="<?php echo htmlspecialchars($scholarship['name']); ?> logo" 
                                     onerror="this.src='<?php echo htmlspecialchars($defaultScholarshipImage); ?>'">
                            </div>
                            
                            <div class="compact-info">
                                <h3 class="compact-title"><?php echo htmlspecialchars($scholarship['name']); ?></h3>
                                <div class="compact-provider">
                                    <i class="fas fa-building"></i>
                                    <span><?php echo htmlspecialchars($provider); ?></span>
                                </div>
                                
                                <?php if($deadline): ?>
                                    <div class="compact-deadline">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span class="<?php echo ($isExpired || $isUrgent) ? 'urgent' : ''; ?>">
                                            <?php echo date('M d, Y', strtotime($scholarship['deadline'])); ?>
                                            <?php if($isExpired): ?>
                                                <span class="urgent-badge">(Expired)</span>
                                            <?php elseif($isUrgent): ?>
                                                <span class="urgent-badge"><?php echo '(' . $daysLeft . ' days left)'; ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="compact-actions">
                                <button class="compact-btn details-btn" data-id="<?php echo $scholarship['id']; ?>">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                                
                                <?php if(!empty($scholarship['latitude']) && !empty($scholarship['longitude'])): ?>
                                    <button class="compact-btn location-btn" 
                                            data-lat="<?php echo $scholarship['latitude']; ?>" 
                                            data-lng="<?php echo $scholarship['longitude']; ?>"
                                            data-name="<?php echo htmlspecialchars($scholarship['name']); ?>">
                                        <i class="fas fa-map-marker-alt"></i> Location
                                    </button>
                                <?php else: ?>
                                    <button class="compact-btn location-btn disabled" disabled title="Location not available">
                                        <i class="fas fa-map-marker-alt"></i> Location
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Dashboard Section -->
    <section>
        <div class="container">
            <div class="section-title">
                <h2>Why Choose Our Platform?</h2>
                <p>Key advantages for Filipino students</p>
            </div>
                <div class="modules-grid">
                    <div class="module-card">
                        <div class="module-header">
                            <div class="module-icon">
                                <i class="fas fa-file-image"></i>
                            </div>
                            <h3 class="module-title">Image Processing Technology</h3>
                        </div>
                        <p>Upload a photo of your grades and our system automatically extracts your GWA.</p>
                    </div>
                    
                    <div class="module-card">
                        <div class="module-header">
                            <div class="module-icon">
                                <i class="fas fa-check-double"></i>
                            </div>
                            <h3 class="module-title">Validation System</h3>
                        </div>
                        <p>Automatic verification of school accreditation and grade validity.</p>
                    </div>
                    
                    <div class="module-card">
                        <div class="module-header">
                            <div class="module-icon">
                                <i class="fas fa-magic"></i>
                            </div>
                            <h3 class="module-title">Guided Application</h3>
                        </div>
                        <p>Step-by-step guidance through the entire application process.</p>
                    </div>
                </div>
            <?php if(isset($_SESSION['user_id'])): ?>
            <div class="section-title">
                <a href="profile.php" class="btn btn-primary">View Full Profile <i class="fas fa-arrow-right"></i></a>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Scholarship Details Modal -->
    <div class="modal-overlay" id="modalOverlay"></div>
    
    <div class="modal-container" id="scholarshipModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Loading...</h3>
                <div class="modal-provider" id="modalProvider">
                    <i class="fas fa-building"></i>
                    <span>Loading...</span>
                </div>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="modal-body">
                <div class="loading" id="modalLoading">
                    <div class="loading-spinner"></div>
                    <p>Loading scholarship details...</p>
                </div>
                
                <div class="modal-content-wrapper" id="modalContent" style="display: none;">
                    <div class="modal-info-full">
                        <div class="modal-details">
                            <div class="detail-row">
                                <span class="detail-label">GWA Requirement:</span>
                                <span class="detail-value" id="modalGWA"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Deadline:</span>
                                <span class="detail-value" id="modalDeadline"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Location:</span>
                                <span class="detail-value" id="modalLocation"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Status:</span>
                                <span class="detail-value" id="modalStatus"></span>
                            </div>
                        </div>
                        
                        <div class="eligibility-box">
                            <h4><i class="fas fa-check-circle"></i> Eligibility Requirements</h4>
                            <p id="modalEligibility"></p>
                        </div>
                        
                        <div class="benefits-box">
                            <h4><i class="fas fa-gift"></i> Benefits</h4>
                            <div id="modalBenefits"></div>
                        </div>
                        
                        <div id="modalDescription"></div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer" id="modalFooter" style="display: none;">
                <button class="close-btn" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>

    <?php include 'layout/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/sweetalert.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/script.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/scholarship-modal.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/carousel.js')); ?>"></script>
      
    <script>
        const isUserLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, checking scholarship cards...');
            
            const cards = document.querySelectorAll('.scholarship-card');
            console.log('Found', cards.length, 'scholarship cards');
            
            cards.forEach((card, index) => {
                const id = card.getAttribute('data-id');
                console.log(`Card ${index}: data-id = "${id}"`);
                
                if (!id || id === 'undefined' || id === 'null') {
                    console.error(`Card ${index} has invalid data-id!`);
                }
            });
            
            const buttons = document.querySelectorAll('.details-btn');
            console.log('Found', buttons.length, 'details buttons');
            
            buttons.forEach((button, index) => {
                const id = button.getAttribute('data-id');
                console.log(`Button ${index}: data-id = "${id}"`);
            });
        });
    </script>

<script src="<?php echo htmlspecialchars(assetUrl('public/js/card-pagination.js')); ?>"></script>
</html>




</body>
</html>

