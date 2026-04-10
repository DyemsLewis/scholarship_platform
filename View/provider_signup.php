<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/csrf.php';
require_once __DIR__ . '/../app/Config/signup_verification.php';
require_once __DIR__ . '/../app/Models/StaffAccountProfile.php';

if ($isLoggedIn) {
    redirect($isProviderOrAdmin ? '../AdminView/admin_dashboard.php' : 'index.php');
}

$signupVerificationDbReady = signupVerificationTableReady($pdo);
$lastVerifiedEmail = trim((string) ($_SESSION['signup_verification_last_email'] ?? ''));
$providerOld = isset($_SESSION['provider_signup_old']) && is_array($_SESSION['provider_signup_old']) ? $_SESSION['provider_signup_old'] : [];
$providerErrors = isset($_SESSION['provider_signup_errors']) && is_array($_SESSION['provider_signup_errors']) ? $_SESSION['provider_signup_errors'] : [];
unset($_SESSION['provider_signup_old'], $_SESSION['provider_signup_errors']);

function providerOldValue(array $oldInput, string $key, string $default = ''): string
{
    $value = array_key_exists($key, $oldInput) ? trim((string) $oldInput[$key]) : $default;
    if ($key === 'mobile_number') {
        return formatPhilippineMobileNumber($value);
    }

    return $value;
}

function providerSelected(array $oldInput, string $key, string $expectedValue): string
{
    $actualValue = strtolower(providerOldValue($oldInput, $key));
    if ($actualValue === 'government') {
        $actualValue = 'government_agency';
    }

    return $actualValue === strtolower($expectedValue) ? 'selected' : '';
}

function providerChecked(array $oldInput, string $key): string
{
    return providerOldValue($oldInput, $key) !== '' ? 'checked' : '';
}

$emailValue = providerOldValue($providerOld, 'email', $lastVerifiedEmail);
$verifiedEmailStateValue = ($emailValue !== '' && isSignupEmailVerified($pdo, $emailValue))
    ? normalizeSignupVerificationEmail($emailValue)
    : '';
$providerProfileModel = new StaffAccountProfile($pdo);
$providerVerificationUploadReady = $providerProfileModel->supportsProviderVerificationDocumentUpload();
$providerVerificationUploadMessage = $providerProfileModel->getProviderVerificationDocumentUploadMessage();
$organizationTypes = [
    'government_agency' => 'Government Agency',
    'local_government_unit' => 'Local Government Unit',
    'state_university' => 'State University / College',
    'private_school' => 'Private School / University',
    'foundation' => 'Foundation',
    'nonprofit' => 'Nonprofit / NGO',
    'corporate' => 'Corporate Sponsor',
    'other' => 'Other'
];
$providerHasSavedPin = providerOldValue($providerOld, 'latitude') !== '' && providerOldValue($providerOld, 'longitude') !== '';
$providerSignupCssVersion = @filemtime(__DIR__ . '/../public/css/provider-signup.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Finder - Provider Registration</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/signup.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/provider-signup.css') . '?v=' . rawurlencode((string) $providerSignupCssVersion)); ?>">
</head>
<body>
<?php include 'layout/header.php'; ?>
<section class="signup-page provider-signup-page">
    <div class="container">
        <div class="signup-container provider-signup-container">
            <div class="signup-card">
                <div class="signup-header">
                    <i class="fas fa-building-columns signup-header-icon"></i>
                    <h1>Create Provider Account</h1>
                    <p>Register your institution or organization so it can post scholarships after admin review and approval.</p>
                </div>

                <div class="signup-note">
                    <strong>Provider registration:</strong> verify your login email first, complete your organization and contact details, then wait for admin approval before the account can sign in.
                </div>

                <?php if (!$signupVerificationDbReady): ?>
                    <div class="signup-errors">
                        <strong>Email verification setup is incomplete.</strong>
                        <div>Run the signup verification migration first, then refresh this page.</div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($providerErrors)): ?>
                    <div class="signup-errors">
                        <strong>Please fix the following issues:</strong>
                        <ul>
                            <?php foreach ($providerErrors as $providerError): ?>
                                <li><?php echo htmlspecialchars((string) $providerError); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form id="providerSignupForm" method="POST" action="../app/Controllers/providerRegisterController.php" enctype="multipart/form-data" novalidate>
                    <?php echo csrfInputField('provider_signup'); ?>
                    <input type="hidden" id="verifiedEmailState" value="<?php echo htmlspecialchars($verifiedEmailStateValue); ?>">

                <div class="form-section">
                    <h3><i class="fas fa-user-lock"></i> Account Credentials</h3>
                    <div class="signup-grid">
                        <div class="form-group">
                            <label for="providerUsername">Username *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-at"></i>
                                <input type="text" id="providerUsername" name="username" maxlength="30" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'username')); ?>" placeholder="Choose a username">
                            </div>
                            <small class="hint">4 to 30 characters. Letters, numbers, dots, underscores, and hyphens only.</small>
                        </div>
                        <div class="form-group signup-full-width">
                            <label for="providerEmail">Login Email *</label>
                            <div class="email-verification-group">
                                <div class="input-with-icon">
                                    <i class="fas fa-envelope"></i>
                                    <input type="email" id="providerEmail" name="email" value="<?php echo htmlspecialchars($emailValue); ?>" placeholder="name@organization.org">
                                </div>
                                <button type="button" class="btn btn-outline btn-inline" id="sendVerificationCodeBtn" <?php echo $signupVerificationDbReady ? '' : 'disabled'; ?>>Send Code</button>
                            </div>
                            <small class="hint">We will send a 6-digit verification code to this email before provider account creation can continue.</small>
                            <div class="verification-code-row">
                                <div class="input-with-icon">
                                    <i class="fas fa-key"></i>
                                    <input type="text" id="providerVerificationCode" inputmode="numeric" maxlength="6" placeholder="Enter 6-digit verification code">
                                </div>
                                <button type="button" class="btn btn-primary btn-inline" id="verifyEmailCodeBtn" <?php echo $signupVerificationDbReady ? '' : 'disabled'; ?>>Verify Email</button>
                            </div>
                            <div class="verification-status" id="emailVerificationStatus"></div>
                        </div>
                        <div class="form-group">
                            <label for="providerPassword">Password *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="providerPassword" name="password" placeholder="Create a password" minlength="<?php echo passwordPolicyMinLength(); ?>" required>
                            </div>
                            <small class="hint"><?php echo htmlspecialchars(passwordPolicyHint()); ?></small>
                        </div>
                        <div class="form-group">
                            <label for="providerConfirmPassword">Confirm Password *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="providerConfirmPassword" name="confirm_password" placeholder="Confirm your password" minlength="<?php echo passwordPolicyMinLength(); ?>" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-building"></i> Organization Details</h3>
                    <div class="signup-grid">
                        <div class="form-group">
                            <label for="organizationName">Organization Name *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-building"></i>
                                <input type="text" id="organizationName" name="organization_name" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'organization_name')); ?>" placeholder="e.g., CHED Regional Office">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="organizationType">Organization Type *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-diagram-project"></i>
                                <select id="organizationType" name="organization_type">
                                    <option value="">Select organization type</option>
                                    <?php foreach ($organizationTypes as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>" <?php echo providerSelected($providerOld, 'organization_type', $value); ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="select-chevron"><i class="fas fa-chevron-down"></i></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="organizationEmail">Organization Email *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope"></i>
                                <input type="email" id="organizationEmail" name="organization_email" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'organization_email', $emailValue)); ?>" placeholder="scholarships@organization.org">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="organizationWebsite">Official Website</label>
                            <div class="input-with-icon">
                                <i class="fas fa-globe"></i>
                                <input type="url" id="organizationWebsite" name="website" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'website')); ?>" placeholder="https://example.org">
                            </div>
                        </div>
                        <div class="form-group signup-full-width">
                            <label for="organizationDescription">Organization Description</label>
                            <div class="input-with-icon">
                                <i class="fas fa-file-lines"></i>
                                <textarea id="organizationDescription" name="description" placeholder="Briefly describe the organization and the scholarship programs it manages"><?php echo htmlspecialchars(providerOldValue($providerOld, 'description')); ?></textarea>
                            </div>
                            <small class="hint">A short background helps admins understand the organization during account review.</small>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-user-tie"></i> Contact Person</h3>
                    <div class="signup-grid">
                        <div class="form-group">
                            <label for="contactPersonFirstName">First Name *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-user"></i>
                                <input type="text" id="contactPersonFirstName" name="contact_person_firstname" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'contact_person_firstname')); ?>" placeholder="Contact person's first name">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="contactPersonLastName">Last Name *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-user"></i>
                                <input type="text" id="contactPersonLastName" name="contact_person_lastname" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'contact_person_lastname')); ?>" placeholder="Contact person's last name">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="contactPersonPosition">Position *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-briefcase"></i>
                                <input type="text" id="contactPersonPosition" name="contact_person_position" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'contact_person_position')); ?>" placeholder="e.g., Scholarship Coordinator">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="providerPhoneNumber">Phone Number *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-phone"></i>
                                <input type="text" id="providerPhoneNumber" name="phone_number" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'phone_number')); ?>" placeholder="e.g., +63 2 8123 4567">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="providerMobileNumber">Mobile Number</label>
                            <div class="input-with-icon">
                                <i class="fas fa-mobile-screen"></i>
                                <input type="text" id="providerMobileNumber" name="mobile_number" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'mobile_number')); ?>" placeholder="e.g., +63 917 123 4567">
                            </div>
                            <small class="hint">Optional, but helpful for faster follow-up during account review.</small>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-location-dot"></i> Address And Verification</h3>
                    <div class="academic-path-note compact-doc-note">
                        <i class="fas fa-circle-info"></i>
                        <div>Use the official organization address and set a map pin if you want scholarship locations to point to the exact office or campus.</div>
                    </div>
                    <div class="signup-grid">
                        <div class="form-group">
                            <label for="houseNo">House No. / Building</label>
                            <div class="input-with-icon">
                                <i class="fas fa-house"></i>
                                <input type="text" id="houseNo" name="house_no" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'house_no')); ?>" placeholder="e.g., Blk 1 Lot 2">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="street">Street *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-road"></i>
                                <input type="text" id="street" name="street" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'street')); ?>" placeholder="Street name">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="barangay">Barangay *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-map-pin"></i>
                                <input type="text" id="barangay" name="barangay" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'barangay')); ?>" placeholder="Barangay">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="city">City / Municipality *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-city"></i>
                                <input type="text" id="city" name="city" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'city')); ?>" placeholder="City or municipality">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="province">Province *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-map"></i>
                                <input type="text" id="province" name="province" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'province')); ?>" placeholder="Province">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="zipCode">Zip Code</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope-open-text"></i>
                                <input type="text" id="zipCode" name="zip_code" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'zip_code')); ?>" placeholder="Zip code">
                            </div>
                        </div>
                        <div class="form-group signup-full-width">
                            <div class="pin-helper provider-pin-helper">
                                <small id="providerPinStatusText"><?php echo $providerHasSavedPin ? 'Pin selected. This map location will be saved with the organization address.' : 'No map pin selected yet. We can still geocode your address automatically, but you can set the exact organization pin here.'; ?></small>
                                <button type="button" class="btn btn-outline open-provider-location-modal">
                                    <i class="fas fa-map-marker-alt"></i> Set Pin on Map
                                </button>
                            </div>
                        </div>
                        <div class="form-group signup-full-width">
                            <label for="verificationDocument">Business Permit / Verification File</label>
                            <div class="compact-upload-card provider-upload-card">
                                <input type="file" id="verificationDocument" name="verification_document" class="compact-file-input" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <small class="hint">Optional but recommended. Accepted formats: PDF, JPG, PNG. Maximum 5 MB.</small>
                            <?php if (!$providerVerificationUploadReady): ?>
                                <small class="hint provider-upload-warning">
                                    <?php echo htmlspecialchars($providerVerificationUploadMessage); ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group signup-full-width">
                            <div class="terms-checkbox provider-confirm-card">
                                <input type="checkbox" id="providerReviewPolicy" name="agree_terms" value="1" <?php echo providerChecked($providerOld, 'agree_terms'); ?>>
                                <label for="providerReviewPolicy" class="provider-confirm-copy">
                                    <strong>This organization details are correct</strong>
                                    <span>I confirm that the organization information entered above is accurate and understand that this provider account will require admin review before it can sign in.</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="latitude" id="providerLatitude" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'latitude')); ?>">
                <input type="hidden" name="longitude" id="providerLongitude" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'longitude')); ?>">
                <input type="hidden" name="location_name" id="providerLocationName" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'location_name')); ?>">

                    <div class="provider-signup-actions">
                    <button type="submit" class="btn btn-primary btn1"><i class="fas fa-user-plus"></i> Create Provider Account</button>

                    <div class="form-footer">
                        <p>Already approved and active? <a href="login.php">Go to login</a></p>
                        <p>Admin review is required before provider accounts can sign in and manage scholarship postings.</p>
                    </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>
<?php include 'layout/footer.php'; ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<?php include 'partials/provider_location_modal.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/sweetalert.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/password-policy.js')); ?>"></script>
<script>
(function () {
    const enabled = <?php echo $signupVerificationDbReady ? 'true' : 'false'; ?>;
    const emailInput = document.getElementById('providerEmail');
    const orgEmailInput = document.getElementById('organizationEmail');
    const codeInput = document.getElementById('providerVerificationCode');
    const sendBtn = document.getElementById('sendVerificationCodeBtn');
    const verifyBtn = document.getElementById('verifyEmailCodeBtn');
    const statusEl = document.getElementById('emailVerificationStatus');
    const verifiedState = document.getElementById('verifiedEmailState');
    const form = document.getElementById('providerSignupForm');
    const reviewPolicyCheckbox = document.getElementById('providerReviewPolicy');
    const usernameInput = document.getElementById('providerUsername');
    const passwordInput = document.getElementById('providerPassword');
    const confirmPasswordInput = document.getElementById('providerConfirmPassword');
    const firstNameInput = document.getElementById('contactPersonFirstName');
    const lastNameInput = document.getElementById('contactPersonLastName');
    const organizationNameInput = document.getElementById('organizationName');
    let countdown = 0;
    let timer = null;

    function normalizeEmail(value) { return (value || '').trim().toLowerCase(); }
    function validEmail(value) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value); }
    function setStatus(message, type) { statusEl.textContent = message || ''; statusEl.className = 'verification-status' + (type ? ' ' + type : ''); }
    function markVerified(email) { verifiedState.value = normalizeEmail(email); codeInput.value = ''; setStatus('Email verified. You can now create the provider account.', 'verified'); }
    function clearVerified(showMessage) { verifiedState.value = ''; codeInput.value = ''; setStatus(showMessage ? 'Please verify your login email before creating a provider account.' : '', showMessage ? 'pending' : ''); }
    function refreshSendButton() {
        if (countdown > 0) { sendBtn.disabled = true; sendBtn.textContent = `Resend in ${countdown}s`; return; }
        sendBtn.disabled = !enabled; sendBtn.textContent = 'Send Code';
    }
    function startCountdown(seconds) {
        countdown = Number(seconds) || 0;
        refreshSendButton();
        if (timer) { clearInterval(timer); }
        if (countdown <= 0) { return; }
        timer = setInterval(function () {
            countdown -= 1;
            if (countdown <= 0) { clearInterval(timer); timer = null; countdown = 0; }
            refreshSendButton();
        }, 1000);
    }
    async function sendCode() {
        const email = normalizeEmail(emailInput.value);
        if (!enabled) { setStatus('Email verification setup is incomplete. Please run the verification migration first.', 'error'); return; }
        if (!email || !validEmail(email)) { Swal.fire({ icon: 'error', title: 'Invalid Email', text: 'Enter a valid login email address first.', confirmButtonColor: '#3085d6' }); return; }
        sendBtn.disabled = true;
        try {
            const response = await fetch('../app/Controllers/send_signup_verification.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: new URLSearchParams({ email }) });
            const data = await response.json();
            if (!response.ok || !data.success) { throw new Error(data.message || 'Unable to send verification code.'); }
            if (data.already_verified) { markVerified(email); } else { clearVerified(false); setStatus(data.message || 'Verification code sent. Please check your inbox.', 'pending'); }
            startCountdown(data.cooldown_seconds || 60);
        } catch (error) {
            setStatus(error.message || 'Unable to send verification code.', 'error');
            refreshSendButton();
            Swal.fire({ icon: 'error', title: 'Verification Error', text: error.message || 'Unable to send verification code.', confirmButtonColor: '#3085d6' });
        }
    }
    async function verifyCode() {
        const email = normalizeEmail(emailInput.value);
        const code = (codeInput.value || '').trim();
        if (!enabled) { setStatus('Email verification setup is incomplete. Please run the verification migration first.', 'error'); return; }
        if (!email || !validEmail(email)) { Swal.fire({ icon: 'error', title: 'Invalid Email', text: 'Enter a valid login email address before verifying.', confirmButtonColor: '#3085d6' }); return; }
        if (!/^\d{6}$/.test(code)) { Swal.fire({ icon: 'error', title: 'Invalid Code', text: 'Enter the 6-digit verification code sent to your email.', confirmButtonColor: '#3085d6' }); return; }
        verifyBtn.disabled = true;
        try {
            const response = await fetch('../app/Controllers/verify_signup_code.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: new URLSearchParams({ email, code }) });
            const data = await response.json();
            if (!response.ok || !data.success) { throw new Error(data.message || 'Unable to verify email.'); }
            markVerified(email);
        } catch (error) {
            setStatus(error.message || 'Unable to verify email.', 'error');
            Swal.fire({ icon: 'error', title: 'Verification Failed', text: error.message || 'Unable to verify email.', confirmButtonColor: '#3085d6' });
        } finally {
            verifyBtn.disabled = false;
        }
    }
    sendBtn.addEventListener('click', sendCode);
    verifyBtn.addEventListener('click', verifyCode);
    codeInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
    emailInput.addEventListener('input', function () {
        const email = normalizeEmail(emailInput.value);
        if (orgEmailInput.value.trim() === '') { orgEmailInput.value = email; }
        if (email !== normalizeEmail(verifiedState.value)) { clearVerified(email !== ''); }
    });
    form.addEventListener('submit', function (event) {
        if (reviewPolicyCheckbox && !reviewPolicyCheckbox.checked) {
            event.preventDefault();
            Swal.fire({ icon: 'error', title: 'Confirmation Required', text: 'Please confirm that the organization details are correct before creating the provider account.', confirmButtonColor: '#3085d6' });
            return;
        }
        const email = normalizeEmail(emailInput.value);
        if (email === '' || email !== normalizeEmail(verifiedState.value)) {
            event.preventDefault();
            setStatus('Please verify your login email before creating a provider account.', 'pending');
            Swal.fire({ icon: 'error', title: 'Email Not Verified', text: 'Please verify your login email before creating a provider account.', confirmButtonColor: '#3085d6' });
            return;
        }
        const password = passwordInput ? passwordInput.value : '';
        const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : '';
        if (!password) {
            event.preventDefault();
            Swal.fire({ icon: 'error', title: 'Missing Password', text: 'Please create a password for the provider account.', confirmButtonColor: '#3085d6' });
            return;
        }
        if (password !== confirmPassword) {
            event.preventDefault();
            Swal.fire({ icon: 'error', title: 'Password Mismatch', text: 'Passwords do not match.', confirmButtonColor: '#3085d6' });
            return;
        }
        const passwordValidation = window.PasswordPolicy
            ? window.PasswordPolicy.validate(password, {
                username: usernameInput ? usernameInput.value.trim() : '',
                email: email,
                firstname: firstNameInput ? firstNameInput.value.trim() : '',
                lastname: lastNameInput ? lastNameInput.value.trim() : '',
                name: organizationNameInput ? organizationNameInput.value.trim() : ''
            })
            : { valid: password.length >= <?php echo passwordPolicyMinLength(); ?>, errors: [<?php echo json_encode(passwordPolicyHint()); ?>] };
        if (!passwordValidation.valid) {
            event.preventDefault();
            Swal.fire({ icon: 'error', title: 'Weak Password', text: passwordValidation.errors[0] || <?php echo json_encode(passwordPolicyHint()); ?>, confirmButtonColor: '#3085d6' });
        }
    });
    if (verifiedState.value) { markVerified(verifiedState.value); } else if (emailInput.value.trim() !== '') { clearVerified(true); }
    refreshSendButton();
})();
</script>
</body>
</html>
