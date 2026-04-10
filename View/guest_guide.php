<?php
require_once __DIR__ . '/../app/Config/init.php';
$guestPagesCssVersion = @filemtime(__DIR__ . '/../public/css/guest-pages.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Finder - Guest Guide</title>
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
                <h1>Guest Guide</h1>
                <p>A quick guide for students who want to prepare the right information before signing up and starting scholarship applications.</p>
            </div>
            <div class="app-page-hero-side">
                <a href="scholarships.php" class="app-page-hero-action">
                    <i class="fas fa-book-open"></i> Start Browsing
                </a>
            </div>
        </section>

        <section class="guest-section">
            <div class="guest-section-heading">
                <h2>What to prepare first</h2>
                <p>Students usually have a smoother experience when these details are ready before account creation.</p>
            </div>

            <div class="guest-card-grid">
                <article class="guest-info-card">
                    <div class="guest-icon"><i class="fas fa-graduation-cap"></i></div>
                    <h3>Academic Record</h3>
                    <p>Keep your latest grades, report card, TOR, or Form 138 ready because many scholarships depend on academic standing.</p>
                </article>

                <article class="guest-info-card">
                    <div class="guest-icon"><i class="fas fa-school"></i></div>
                    <h3>Student Information</h3>
                    <p>Know your applicant type, school, course, year level, and admission or enrollment status before starting.</p>
                </article>

                <article class="guest-info-card">
                    <div class="guest-icon"><i class="fas fa-house-user"></i></div>
                    <h3>Family Details</h3>
                    <p>Some scholarships consider income bracket, citizenship, or special category, so it helps to prepare those details early.</p>
                </article>

                <article class="guest-info-card">
                    <div class="guest-icon"><i class="fas fa-folder-open"></i></div>
                    <h3>Supporting Proofs</h3>
                    <p>Birth certificate, income proof, residency proof, and category certifications are common supporting documents.</p>
                </article>
            </div>
        </section>

        <section class="guest-section">
            <div class="guest-highlight-panel">
                <div>
                    <h2>What usually affects whether you can apply</h2>
                    <p>These are the checks students most often need to satisfy before the system allows a full application flow.</p>
                </div>
                <ul class="guest-check-list">
                    <li>Required GWA or grade threshold</li>
                    <li>Target applicant type or academic status</li>
                    <li>Deadline or opening schedule</li>
                    <li>Required documents already uploaded</li>
                </ul>
            </div>
        </section>

        <section class="guest-section">
            <div class="guest-section-heading">
                <h2>What guests can do before signing up</h2>
                <p>You do not need an account yet to start understanding the scholarship landscape.</p>
            </div>

            <div class="guest-card-grid">
                <article class="guest-info-card">
                    <h3>Check Benefits</h3>
                    <p>Review what each scholarship covers so you know which opportunities are worth preparing for.</p>
                </article>

                <article class="guest-info-card">
                    <h3>Read Requirements Early</h3>
                    <p>Find out if a scholarship is likely to need academic records, income proof, or other supporting documents.</p>
                </article>

                <article class="guest-info-card">
                    <h3>Plan Your Next Step</h3>
                    <p>Decide whether you only need to browse more, complete your profile, or start gathering missing proofs.</p>
                </article>
            </div>
        </section>

        <section class="guest-cta-strip">
            <div>
                <h2>Ready to move from browsing to applying?</h2>
                <p>Create an account when you are ready to save profile details, upload documents, and use application tracking.</p>
            </div>
            <div class="guest-cta-actions">
                <a href="scholarships.php" class="btn btn-primary">Browse Scholarships</a>
                <?php if (!$isLoggedIn): ?>
                <a href="signup.php" class="btn btn-outline">Create Account</a>
                <?php else: ?>
                <a href="documents.php" class="btn btn-outline">Open Documents</a>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php include 'layout/footer.php'; ?>
</body>
</html>
