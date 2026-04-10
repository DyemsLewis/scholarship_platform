<?php
require_once __DIR__ . '/../app/Config/init.php';
$guestPagesCssVersion = @filemtime(__DIR__ . '/../public/css/guest-pages.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Finder - About the Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/guest-pages.css') . '?v=' . rawurlencode((string) $guestPagesCssVersion)); ?>">
</head>
<body class="guest-public-page">
<?php include 'layout/header.php'; ?>

<main class="dashboard user-page-shell guest-page-shell">
    <div class="container">
        <section class="app-page-hero guest-page-header">
            <div class="app-page-hero-copy">
                <span class="guest-page-kicker">Guest Page</span>
                <h1>About Scholarship Finder</h1>
                <p>A student-first platform that helps Filipino learners understand scholarship options before they spend time preparing an application.</p>
            </div>
            <div class="app-page-hero-side">
                <a href="scholarships.php" class="app-page-hero-action">
                    <i class="fas fa-compass"></i> Browse Scholarships
                </a>
                <?php if (!$isLoggedIn): ?>
                <a href="signup.php" class="guest-page-secondary-action">
                    <i class="fas fa-user-plus"></i> Create Account
                </a>
                <?php endif; ?>
            </div>
        </section>

        <section class="guest-section" id="mission">
            <div class="guest-section-heading">
                <h2>Our Mission</h2>
                <p>Make scholarship discovery clearer, faster, and easier to understand for students who often feel lost in requirements and deadlines.</p>
            </div>

            <div class="guest-card-grid">
                <article class="guest-info-card">
                    <div class="guest-icon"><i class="fas fa-lightbulb"></i></div>
                    <h3>Clear Matching</h3>
                    <p>The platform highlights scholarships that fit a student's academic profile, applicant type, and supporting documents.</p>
                </article>

                <article class="guest-info-card">
                    <div class="guest-icon"><i class="fas fa-list-check"></i></div>
                    <h3>Less Guesswork</h3>
                    <p>Students can see why they can apply, why they cannot apply yet, and what still needs attention before submitting.</p>
                </article>

                <article class="guest-info-card">
                    <div class="guest-icon"><i class="fas fa-route"></i></div>
                    <h3>Guided Flow</h3>
                    <p>Instead of jumping between pages and requirements, the platform walks students through profile readiness, documents, and application steps.</p>
                </article>
            </div>
        </section>

        <section class="guest-section" id="who-we-help">
            <div class="guest-section-heading">
                <h2>Who We Help</h2>
                <p>The platform is designed for the students who usually need the most clarity before applying.</p>
            </div>

            <div class="guest-card-grid">
                <article class="guest-info-card">
                    <h3>Senior High School Students</h3>
                    <p>Students preparing for college who want to know which scholarships may fit before they start college applications.</p>
                </article>

                <article class="guest-info-card">
                    <h3>Current College Students</h3>
                    <p>Students who need support while already enrolled and want to understand document requirements and renewal expectations early.</p>
                </article>

                <article class="guest-info-card">
                    <h3>Transferees and Continuing Students</h3>
                    <p>Students whose academic path changed and need scholarship options that still match their updated status and record.</p>
                </article>
            </div>
        </section>

        <section class="guest-section">
            <div class="guest-highlight-panel">
                <div>
                    <h2>What makes this platform practical for guests?</h2>
                    <p>You can browse opportunities first, understand how matching works, and learn what documents are commonly needed before signing up.</p>
                </div>
                <ul class="guest-check-list">
                    <li>Browse active scholarships before creating an account</li>
                    <li>See student-friendly explanations instead of raw requirement text</li>
                    <li>Prepare academic and family information earlier</li>
                    <li>Know the next step before starting an application</li>
                </ul>
            </div>
        </section>

        <section class="guest-cta-strip">
            <div>
                <h2>Ready to explore scholarships?</h2>
                <p>Guests can browse first, then create an account when they are ready to complete their profile and documents.</p>
            </div>
            <div class="guest-cta-actions">
                <a href="scholarships.php" class="btn btn-primary">Browse Scholarships</a>
                <?php if (!$isLoggedIn): ?>
                <a href="signup.php" class="btn btn-outline">Sign Up</a>
                <?php else: ?>
                <a href="applications.php" class="btn btn-outline">Go to Applications</a>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php include 'layout/footer.php'; ?>
</body>
</html>
