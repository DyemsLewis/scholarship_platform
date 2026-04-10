<?php
require_once __DIR__ . '/../app/Config/init.php';
$guestPagesCssVersion = @filemtime(__DIR__ . '/../public/css/guest-pages.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Finder - How It Works</title>
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
                <h1>How Matching Works</h1>
                <p>The platform keeps the process simple: understand the scholarship, complete the needed profile details, prepare documents, and apply when ready.</p>
            </div>
            <div class="app-page-hero-side">
                <a href="scholarships.php" class="app-page-hero-action">
                    <i class="fas fa-eye"></i> View Scholarships
                </a>
            </div>
        </section>

        <section class="guest-section">
            <div class="guest-section-heading">
                <h2>The Student Flow</h2>
                <p>These are the main steps students follow from guest browsing up to application tracking.</p>
            </div>

            <div class="guest-step-grid">
                <article class="guest-step-card">
                    <span class="guest-step-number">1</span>
                    <h3>Browse Scholarships</h3>
                    <p>Guests can check active scholarships first to understand providers, benefits, requirements, and deadlines.</p>
                </article>

                <article class="guest-step-card">
                    <span class="guest-step-number">2</span>
                    <h3>Complete Your Profile</h3>
                    <p>After signing up, students add academic, family, and location details used to assess scholarship fit.</p>
                </article>

                <article class="guest-step-card">
                    <span class="guest-step-number">3</span>
                    <h3>Prepare Documents</h3>
                    <p>Required uploads like academic records and supporting proofs help the system and reviewers verify readiness.</p>
                </article>

                <article class="guest-step-card">
                    <span class="guest-step-number">4</span>
                    <h3>Apply and Track</h3>
                    <p>Students submit through the guided application flow and then track updates, decisions, and admin notes.</p>
                </article>
            </div>
        </section>

        <section class="guest-section">
            <div class="guest-card-grid">
                <article class="guest-info-card">
                    <h3>Match Score</h3>
                    <p>The match score reflects how well the scholarship fits a student's current profile. It is a guide, not the final approval result.</p>
                </article>

                <article class="guest-info-card">
                    <h3>Application Readiness</h3>
                    <p>Readiness depends on more than the score. Missing documents, deadlines, or profile gaps can still block the application.</p>
                </article>

                <article class="guest-info-card">
                    <h3>Admin Review</h3>
                    <p>After submission, providers or administrators review the profile and documents before making a final decision.</p>
                </article>
            </div>
        </section>

        <section class="guest-highlight-panel">
            <div>
                <h2>What guests can learn before signing up</h2>
                <p>Even before creating an account, you can already understand what usually affects scholarship fit.</p>
            </div>
            <ul class="guest-check-list">
                <li>Required GWA or grade expectations</li>
                <li>Applicant type and audience restrictions</li>
                <li>Common required documents</li>
                <li>Whether a scholarship is currently open</li>
            </ul>
        </section>

        <section class="guest-cta-strip">
            <div>
                <h2>Want to see the flow in practice?</h2>
                <p>Browse scholarships as a guest now, then sign up when you want to unlock profile matching and applications.</p>
            </div>
            <div class="guest-cta-actions">
                <a href="scholarships.php" class="btn btn-primary">Browse Scholarships</a>
                <?php if (!$isLoggedIn): ?>
                <a href="signup.php" class="btn btn-outline">Create Account</a>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php include 'layout/footer.php'; ?>
</body>
</html>
