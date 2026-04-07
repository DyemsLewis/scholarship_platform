const PROFILE_TAB_IDS = {
    view: 'profileViewContent',
    edit: 'profileEditContent',
    password: 'profilePasswordContent'
};

function getProfileTabButtonId(tab) {
    return `tab${tab.charAt(0).toUpperCase()}${tab.slice(1)}`;
}

function getProfileTabFromHash() {
    const hash = (window.location.hash || '').replace('#', '').toLowerCase();
    return Object.prototype.hasOwnProperty.call(PROFILE_TAB_IDS, hash) ? hash : 'view';
}

function setProfileTab(tab, options = {}) {
    const normalizedTab = Object.prototype.hasOwnProperty.call(PROFILE_TAB_IDS, tab) ? tab : 'view';
    const shouldUpdateHash = options.updateHash !== false;
    const shouldScroll = options.scroll !== false && normalizedTab !== 'view';

    Object.entries(PROFILE_TAB_IDS).forEach(([name, id]) => {
        const panel = document.getElementById(id);
        const button = document.getElementById(getProfileTabButtonId(name));
        const isActive = name === normalizedTab;

        if (panel) {
            panel.hidden = !isActive;
            panel.style.display = isActive ? 'block' : 'none';
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        }

        if (button) {
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        }
    });

    if (shouldUpdateHash) {
        const nextHash = normalizedTab === 'view' ? '#view' : `#${normalizedTab}`;
        if (window.location.hash !== nextHash) {
            history.replaceState(null, '', nextHash);
        }
    }

    if (shouldScroll) {
        const activePanel = document.getElementById(PROFILE_TAB_IDS[normalizedTab]);
        const tabBar = document.querySelector('.staff-profile-tab-bar');
        const scrollTarget = activePanel || tabBar;

        if (scrollTarget) {
            window.requestAnimationFrame(() => {
                scrollTarget.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            });
        }
    }
}

function switchProfileTab(tab, options = {}) {
    setProfileTab(tab, options);
}

function bindProfileTabTriggers() {
    document.querySelectorAll('[data-profile-tab]').forEach((element) => {
        element.addEventListener('click', () => {
            const tab = element.getAttribute('data-profile-tab') || 'view';
            const shouldScroll = tab !== 'view';
            setProfileTab(tab, {
                updateHash: true,
                scroll: shouldScroll
            });
        });
    });
}

function isValidStaffMiddleInitial(value) {
    return value === '' || /^[A-Za-z]$/.test(value.replace(/\./g, ''));
}

document.addEventListener('DOMContentLoaded', () => {
    bindProfileTabTriggers();
    setProfileTab(getProfileTabFromHash(), {
        updateHash: false,
        scroll: false
    });

    const editProfileForm = document.getElementById('editStaffProfileForm');
    if (editProfileForm) {
        editProfileForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const middleInitialInput = document.getElementById('middleinitial');
            const middleInitial = middleInitialInput ? middleInitialInput.value.trim() : '';
            if (!isValidStaffMiddleInitial(middleInitial)) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Invalid Middle Initial',
                    text: 'Middle initial must be a single letter.'
                });
                middleInitialInput?.focus();
                return;
            }

            const formData = new FormData(editProfileForm);
            formData.append('action', 'update_profile');

            try {
                const response = await fetch('../AdminController/staff_profile_controller.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    const noChanges = Boolean(data.no_changes);
                    await Swal.fire({
                        icon: noChanges ? 'info' : 'success',
                        title: noChanges ? 'No Changes' : 'Profile Updated',
                        text: data.message,
                        timer: 2200
                    });

                    if (!noChanges) {
                        window.location.reload();
                    } else {
                        setProfileTab('view', {
                            updateHash: true,
                            scroll: true
                        });
                    }
                } else {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Unable to Save',
                        text: data.message || 'An error occurred while saving your profile.'
                    });
                }
            } catch (error) {
                console.error(error);
                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to Save',
                    text: 'An unexpected error occurred while saving your profile.'
                });
            }
        });
    }

    const changePasswordForm = document.getElementById('changeStaffPasswordForm');
    if (changePasswordForm) {
        changePasswordForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const currentPassword = document.getElementById('currentPassword')?.value || '';
            const newPassword = document.getElementById('newPassword')?.value || '';
            const confirmPassword = document.getElementById('confirmPassword')?.value || '';

            if (!currentPassword) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Missing Current Password',
                    text: 'Please enter your current password.'
                });
                return;
            }

            if (newPassword !== confirmPassword) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Passwords Do Not Match',
                    text: 'The new password and confirmation password must match.'
                });
                return;
            }

            const passwordValidation = window.PasswordPolicy
                ? window.PasswordPolicy.validate(newPassword, {
                    username: document.getElementById('staffUsername')?.textContent || '',
                    email: document.getElementById('staffEmail')?.textContent || '',
                    name: document.getElementById('staffDisplayName')?.textContent || ''
                })
                : { valid: newPassword.length >= 10, errors: ['Use at least 10 characters with uppercase, lowercase, number, and symbol.'] };

            if (!passwordValidation.valid) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Weak Password',
                    text: passwordValidation.errors[0] || 'Use at least 10 characters with uppercase, lowercase, number, and symbol.'
                });
                return;
            }

            const submitButton = changePasswordForm.querySelector('button[type="submit"]');
            const originalLabel = submitButton ? submitButton.innerHTML : '';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            }

            try {
                const response = await fetch('../AdminController/staff_profile_controller.php', {
                    method: 'POST',
                    body: new FormData(changePasswordForm)
                });
                const data = await response.json();

                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Password Updated',
                        text: data.message,
                        timer: 2200
                    });
                    changePasswordForm.reset();
                    setProfileTab('view', {
                        updateHash: true,
                        scroll: true
                    });
                } else {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Unable to Update Password',
                        text: data.message || 'An error occurred while changing the password.'
                    });
                }
            } catch (error) {
                console.error(error);
                await Swal.fire({
                    icon: 'error',
                    title: 'Unable to Update Password',
                    text: 'An unexpected error occurred while changing the password.'
                });
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalLabel;
                }
            }
        });
    }
});

window.addEventListener('hashchange', () => {
    setProfileTab(getProfileTabFromHash(), {
        updateHash: false,
        scroll: false
    });
});

window.switchProfileTab = switchProfileTab;
