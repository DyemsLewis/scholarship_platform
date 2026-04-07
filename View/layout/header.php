<?php
// header.php
require_once '../Config/init.php';

$headerUserName = $userName;
if ($isLoggedIn && ($userRole ?? 'guest') === 'student') {
    $sessionUsername = trim((string) ($_SESSION['user_username'] ?? ''));
    if ($sessionUsername !== '') {
        $headerUserName = $sessionUsername;
    }
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
.mobile-header-logout {
    display: none;
}
.mobile-logout-confirm {
    display: none;
    margin-top: 10px;
    padding: 14px 16px;
    background: #fff8f8;
    border: 1px solid #fca5a5;
    border-radius: 14px;
    text-align: center;
}
.mobile-logout-confirm.is-active {
    display: block;
}
.mobile-logout-confirm p {
    margin: 0 0 12px;
    font-size: 0.92rem;
    font-weight: 600;
    color: #7f1d1d;
}
.mobile-logout-confirm-actions {
    display: flex;
    gap: 8px;
}
.mobile-logout-confirm-actions .btn {
    flex: 1;
    font-size: 0.88rem;
    min-height: 40px;
    padding: 0 12px;
}
@media (max-width: 900px) {
    .mobile-header-logout {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        margin-left: auto;
        min-height: 40px;
        padding: 0 12px;
        border: 1px solid #dce6f5;
        border-radius: 12px;
        background: #ffffff;
        color: #c2410c;
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
    }
    .mobile-header-logout i {
        font-size: 0.9rem;
    }
    .drawer-logout-button {
        display: none !important;
    }
}
@media (max-width: 576px) {
    .mobile-header-logout {
        width: 40px;
        min-width: 40px;
        height: 40px;
        min-height: 40px;
        padding: 0;
        border-radius: 12px;
    }
    .mobile-header-logout span {
        display: none;
    }
}
</style>
<header>
    <div class="container">
        <nav class="navbar" data-mobile-nav>
            <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="siteNavPanel" aria-label="Open navigation" data-nav-toggle>
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>

            <a href="index.php" class="logo">
                <i class="fas fa-graduation-cap"></i>
                <span>Scholarship Finder</span>
            </a>

            <?php if($isLoggedIn): ?>
            <a
                href="logout.php"
                class="mobile-header-logout"
                data-logout-trigger="true"
                data-mobile-header-logout="true"
                aria-label="Logout"
                onclick="return window.handleLogoutTrigger ? window.handleLogoutTrigger(event, this) : true;"
            >
                <i class="fas fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
            <?php endif; ?>

            <div class="nav-panel" id="siteNavPanel" data-nav-panel>
                <div class="nav-drawer-heading">
                    <div class="nav-drawer-brand">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Scholarship Finder</span>
                    </div>
                </div>

                <div class="nav-links">
                    <a href="index.php" <?php echo getCurrentPage() == 'index.php' ? 'class="active"' : ''; ?>>Home</a>
                    <a href="scholarships.php" <?php echo in_array(getCurrentPage(), ['scholarships.php', 'results.php', 'scholarship_details.php', 'scholarship_requirements.php']) ? 'class="active"' : ''; ?>>Scholarships</a>
                    <?php if ($isLoggedIn): ?>
                    <a href="profile.php" <?php echo getCurrentPage() === 'profile.php' ? 'class="active"' : ''; ?>>Profile</a>
                    <a href="upload.php" <?php echo getCurrentPage() == 'upload.php' ? 'class="active"' : ''; ?>>Upload</a>
                    <a href="documents.php" class="<?php echo isActivePage('documents.php') ? 'active' : ''; ?>">Documents</a>
                    <a href="wizard.php" <?php echo getCurrentPage() === 'wizard.php' ? 'class="active"' : ''; ?>>Wizard</a>
                    <?php endif; ?>
                </div>

                <div class="auth-buttons">
                    <?php if(!$isLoggedIn): ?>
                    <div id="guestButtons">
                        <a href="login.php" class="btn btn-outline">Login</a>
                        <a href="signup.php" class="btn btn-primary">Sign Up</a>
                    </div>
                    <?php else: ?>
                    <div id="userButtons">
                        <div class="user-profile">
                            <div class="user-profile-meta">
                                <div class="user-avatar" id="userAvatar">
                                    <?php echo getUserInitials($headerUserName); ?>
                                </div>
                                <div class="user-profile-copy">
                                    <span class="user-profile-label">Signed in as</span>
                                    <span id="userName"><?php echo htmlspecialchars($headerUserName); ?></span>
                                </div>
                            </div>
                            <a href="logout.php" class="btn btn-outline drawer-logout-button" id="logoutButton" data-logout-trigger="true" onclick="return window.handleLogoutTrigger ? window.handleLogoutTrigger(event, this) : true;">Logout</a>
                            <div class="mobile-logout-confirm" id="mobileLogoutConfirm" hidden aria-hidden="true">
                                <p>Log out from your account?</p>
                                <div class="mobile-logout-confirm-actions">
                                    <button type="button" class="btn btn-primary" data-logout-confirm="true" data-logout-url="<?php echo htmlspecialchars(appBasePath() . '/View/logout.php'); ?>">Yes, logout</button>
                                    <button type="button" class="btn btn-outline" data-logout-cancel="true">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <button class="nav-backdrop" type="button" aria-label="Close navigation" data-nav-backdrop></button>
        </nav>
    </div>
</header>

<?php if(!$isLoggedIn && getCurrentPage() != 'index.php'): ?>
<div class="container">
    <div class="guest-warning">
        <i class="fas fa-info-circle"></i>
        <p>You are viewing as a guest. <a href="login.php">Login</a> to access personalized features.</p>
    </div>
</div>
<?php endif; ?>

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

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/logout.js')); ?>"></script>
<script>
(function () {
    const nav = document.querySelector('[data-mobile-nav]');
    const toggle = document.querySelector('[data-nav-toggle]');
    const panel = document.querySelector('[data-nav-panel]');
    const backdrop = document.querySelector('[data-nav-backdrop]');
    const mobileLogoutConfirm = panel.querySelector('#mobileLogoutConfirm');

    if (!nav || !toggle || !panel) {
        return;
    }

    const setOpen = (open) => {
        nav.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        const icon = toggle.querySelector('i');
        if (icon) {
            icon.className = open ? 'fas fa-times' : 'fas fa-bars';
        }
        document.body.classList.toggle('nav-open', open);
        if (!open && mobileLogoutConfirm) {
            mobileLogoutConfirm.hidden = true;
            mobileLogoutConfirm.setAttribute('aria-hidden', 'true');
            mobileLogoutConfirm.classList.remove('is-active');
        }
    };

    toggle.addEventListener('click', () => {
        setOpen(!nav.classList.contains('is-open'));
    });

    panel.querySelectorAll('a:not([data-logout-trigger])').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });

    if (backdrop) {
        backdrop.addEventListener('click', () => setOpen(false));
    }

    window.addEventListener('resize', () => {
        if (window.innerWidth > 900) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });
})();
</script>
