(function () {
    function isTinyMobileLogoutLayout() {
        return window.matchMedia('(max-width: 481px)').matches;
    }

    function isMobileLogoutLayout() {
        return window.matchMedia('(max-width: 576px)').matches;
    }

    function getLogoutConfirmWidth() {
        return isMobileLogoutLayout() ? '18.5rem' : '22rem';
    }

    function getLogoutLoadingWidth() {
        return isMobileLogoutLayout() ? '17.5rem' : '20rem';
    }

    function getLogoutDialogDelay() {
        return isMobileLogoutLayout() ? 220 : 40;
    }

    function getMobileLogoutPanel() {
        return document.getElementById('mobileLogoutConfirm');
    }

    function isMobileHeaderLogoutTrigger(logoutTrigger) {
        return !!logoutTrigger && logoutTrigger.hasAttribute('data-mobile-header-logout');
    }

    function setMobileLogoutPanelOpen(open) {
        const panel = getMobileLogoutPanel();
        if (!panel) {
            return;
        }

        panel.hidden = !open;
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        panel.classList.toggle('is-active', open);
    }

    /**
     * Close the mobile nav drawer if it is currently open.
     * This must happen BEFORE showing any dialog, otherwise the drawer
     * sits on top of (or behind) the SweetAlert popup on small screens.
     */
    function closeMobileNav() {
        const nav = document.querySelector('[data-mobile-nav]');
        if (!nav) return;
        nav.classList.remove('is-open');
        document.body.classList.remove('nav-open');
        const toggle = nav.querySelector('[data-nav-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Open navigation');
            const icon = toggle.querySelector('i');
            if (icon) icon.className = 'fas fa-bars';
        }
    }

    function confirmLogout(logoutTrigger, event) {
        if (!logoutTrigger) {
            return true;
        }

        if (event) {
            event.preventDefault();
            // NOTE: do NOT call stopPropagation — the nav-close listener in
            // header.php listens on the same click and needs to run too.
        }

        const logoutUrl = logoutTrigger.getAttribute('href') || 'logout.php';

        if (isTinyMobileLogoutLayout() && !isMobileHeaderLogoutTrigger(logoutTrigger)) {
            setMobileLogoutPanelOpen(true);
            return false;
        }

        // Always close the mobile nav first so it doesn't overlap the dialog.
        closeMobileNav();

        // Use SweetAlert2 on all screen sizes.
        // The old window.confirm() fallback was unreliable on mobile browsers
        // (Android Chrome and iOS Safari can suppress it when called from a
        // capture-phase listener or while an overlay element is focused).
        if (typeof Swal === 'undefined') {
            // SweetAlert not loaded at all — plain confirm as last resort.
            if (window.confirm('Log out from your account?')) {
                window.location.href = logoutUrl;
            }
            return false;
        }

        window.setTimeout(() => {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out from your account.',
                icon: 'warning',
                width: getLogoutConfirmWidth(),
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, logout!',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showCloseButton: true,
                customClass: {
                    container: 'logout-confirm-container',
                    popup: 'logout-confirm-popup'
                }
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Logging out...',
                    text: 'Please wait',
                    width: getLogoutLoadingWidth(),
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    showCloseButton: false,
                    customClass: {
                        container: 'logout-loading-container',
                        popup: 'logout-loading-popup'
                    },
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                window.setTimeout(() => {
                    window.location.href = logoutUrl;
                }, 250);
            });
        }, getLogoutDialogDelay());

        return false;
    }

    window.handleLogoutTrigger = function (event, trigger) {
        return confirmLogout(trigger || event?.target?.closest?.('[data-logout-trigger]') || null, event);
    };

    document.addEventListener('click', function (event) {
        const cancelButton = event.target.closest('[data-logout-cancel]');
        if (cancelButton) {
            event.preventDefault();
            setMobileLogoutPanelOpen(false);
            return;
        }

        const confirmButton = event.target.closest('[data-logout-confirm]');
        if (confirmButton) {
            event.preventDefault();
            const logoutUrl = confirmButton.getAttribute('data-logout-url') || 'logout.php';
            window.location.href = logoutUrl;
            return;
        }

        const logoutTrigger = event.target.closest('[data-logout-trigger]');
        if (!logoutTrigger) {
            return;
        }

        confirmLogout(logoutTrigger, event);
    }, true);

    window.addEventListener('resize', function () {
        if (!isTinyMobileLogoutLayout()) {
            setMobileLogoutPanelOpen(false);
        }
    });
})();
