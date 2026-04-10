<?php
require_once __DIR__ . '/../app/Config/init.php';
$guestPagesCssVersion = @filemtime(__DIR__ . '/../public/css/guest-pages.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Finder - FAQ</title>
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
                <h1>Frequently Asked Questions</h1>
                <p>Quick answers for guests and students who want to understand how the platform works before signing up or applying.</p>
            </div>
            <div class="app-page-hero-side">
                <a href="how_it_works.php" class="app-page-hero-action">
                    <i class="fas fa-circle-info"></i> How It Works
                </a>
            </div>
        </section>

        <section class="guest-section">
            <div class="guest-faq-list">
                <details class="guest-faq-item" open>
                    <summary>Can I browse scholarships without creating an account?</summary>
                    <p>Yes. Guests can already browse active scholarships and read the main scholarship details before signing up.</p>
                </details>

                <details class="guest-faq-item">
                    <summary>Why do I still need an account later?</summary>
                    <p>An account is needed for profile matching, document uploads, guided applications, notifications, and application tracking.</p>
                </details>

                <details class="guest-faq-item">
                    <summary>What does the match score mean?</summary>
                    <p>The match score is a guide based on your profile fit. It helps show how aligned a scholarship is, but it is not the final approval decision.</p>
                </details>

                <details class="guest-faq-item">
                    <summary>Why might I see a good match but still not be able to apply?</summary>
                    <p>A scholarship can still be blocked by missing documents, deadline rules, application opening dates, or profile details that need completion.</p>
                </details>

                <details class="guest-faq-item">
                    <summary>Do I need to upload documents before every application?</summary>
                    <p>Usually no. Once your documents are uploaded and reviewed, they can support multiple scholarships depending on each requirement.</p>
                </details>

                <details class="guest-faq-item">
                    <summary>What if the OCR or extracted GWA is wrong?</summary>
                    <p>The system provides a way to report GWA issues so the academic record can be reviewed and corrected when needed.</p>
                </details>

                <details class="guest-faq-item">
                    <summary>Can I track more than one scholarship application?</summary>
                    <p>Yes. The Applications page can show multiple scholarship applications, each with its own status, timeline, and admin notes.</p>
                </details>

                <details class="guest-faq-item">
                    <summary>What happens after a scholarship is approved?</summary>
                    <p>Students can accept the scholarship, then receive follow-up updates such as additional requirements, provider instructions, or post-approval steps.</p>
                </details>
            </div>
        </section>

        <section class="guest-cta-strip">
            <div>
                <h2>Still deciding where to start?</h2>
                <p>Browse first if you only want to understand opportunities, or create an account if you are ready to complete your profile.</p>
            </div>
            <div class="guest-cta-actions">
                <a href="scholarships.php" class="btn btn-primary">Browse Scholarships</a>
                <?php if (!$isLoggedIn): ?>
                <a href="signup.php" class="btn btn-outline">Sign Up</a>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php include 'layout/footer.php'; ?>
</body>
</html>
