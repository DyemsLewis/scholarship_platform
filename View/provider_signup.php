<?php
require_once '../Config/session_bootstrap.php';
require_once '../Config/init.php';
require_once '../Config/db_config.php';
require_once '../Config/csrf.php';
require_once '../Config/signup_verification.php';
require_once '../Model/StaffAccountProfile.php';

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
    return array_key_exists($key, $oldInput) ? trim((string) $oldInput[$key]) : $default;
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
    <style>
        .provider-page { min-height: calc(100vh - 200px); padding: 60px 0; }
        .provider-shell { max-width: 960px; margin: 0 auto; background: #fff; border-radius: var(--border-radius); box-shadow: var(--shadow); border-top: 5px solid var(--primary); padding: 36px; }
        .provider-header { text-align: center; margin-bottom: 24px; }
        .provider-header h1 { color: var(--primary); margin: 10px 0; }
        .provider-header p { color: var(--gray); max-width: 700px; margin: 0 auto; }
        .provider-note, .provider-errors { border-radius: 12px; padding: 14px 16px; margin-bottom: 20px; }
        .provider-note { background: #eef4ff; border: 1px solid #cfe0ff; color: #1d4f91; }
        .provider-errors { background: #fff4f4; border: 1px solid #f4caca; color: #9b1c1c; }
        .provider-errors ul { margin: 8px 0 0 18px; }
        .provider-section { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px; margin-bottom: 18px; }
        .provider-section h3 { margin-bottom: 16px; color: var(--primary); display: flex; align-items: center; gap: 10px; }
        .provider-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 18px; }
        .provider-full { grid-column: 1 / -1; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark); }
        .input-with-icon { position: relative; display: flex; align-items: center; }
        .input-with-icon > i { position: absolute; left: 12px; color: var(--primary); z-index: 1; }
        .input-with-icon input, .input-with-icon select, .input-with-icon textarea { width: 100%; padding: 12px 12px 12px 40px; border: 1px solid var(--light-gray); border-radius: 8px; background: #fff; }
        .input-with-icon textarea { min-height: 100px; resize: vertical; }
        .input-with-icon input:focus, .input-with-icon select:focus, .input-with-icon textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1); }
        .provider-file-input { width: 100%; padding: 12px 14px; border: 1px solid var(--light-gray); border-radius: 8px; background: #fff; }
        .provider-file-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1); }
        .verify-row { display: grid; grid-template-columns: 1fr auto auto; gap: 12px; }
        .verify-status { min-height: 22px; margin-top: 8px; font-size: 0.88rem; font-weight: 600; }
        .verify-status.pending { color: #9a6700; }
        .verify-status.verified { color: #0f766e; }
        .verify-status.error { color: #b91c1c; }
        .hint { display: block; margin-top: 6px; font-size: 0.8rem; color: #64748b; }
        .provider-pin-helper { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 14px 16px; border: 1px solid #dbe4ee; border-radius: 12px; background: #f8fbff; }
        .provider-pin-helper small { color: #475569; font-size: 0.84rem; line-height: 1.5; }
        .provider-confirm-card { display: flex; gap: 14px; align-items: flex-start; padding: 16px 18px; border: 1px solid #dbe4ee; border-radius: 12px; background: #f8fbff; }
        .provider-confirm-card input[type="checkbox"] { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--primary); flex-shrink: 0; }
        .provider-confirm-card label { margin: 0; display: block; cursor: pointer; }
        .provider-confirm-card strong { display: block; color: var(--dark); margin-bottom: 4px; }
        .provider-confirm-card span { display: block; color: #475569; line-height: 1.55; font-size: 0.92rem; }
        .provider-actions { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 24px; }
        .provider-actions p { margin: 0; color: var(--gray); }
        .provider-actions a { color: var(--primary); font-weight: 600; text-decoration: none; }
        @media (max-width: 768px) {
            .provider-shell { padding: 24px 18px; }
            .provider-grid, .verify-row { grid-template-columns: 1fr; }
            .provider-actions { flex-direction: column; align-items: stretch; }
            .provider-actions .btn { width: 100%; }
            .provider-pin-helper { flex-direction: column; align-items: stretch; }
            .provider-pin-helper .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<?php include 'layout/header.php'; ?>
<section class="provider-page">
    <div class="container">
        <div class="provider-shell">
            <div class="provider-header">
                <i class="fas fa-building-columns" style="font-size: 3rem; color: var(--primary);"></i>
                <h1>Provider Registration</h1>
                <p>Create a scholarship provider account for your institution or organization. New provider accounts are reviewed by an administrator before they can sign in and manage scholarship programs.</p>
            </div>

            <div class="provider-note">
                <strong>Review process:</strong> verify your login email first, complete the organization details, and wait for admin approval after account creation.
            </div>

            <?php if (!$signupVerificationDbReady): ?>
                <div class="provider-errors">
                    <strong>Email verification setup is incomplete.</strong>
                    <div>Run the signup verification migration first, then refresh this page.</div>
                </div>
            <?php endif; ?>

            <form id="providerSignupForm" method="POST" action="../Controller/providerRegisterController.php" enctype="multipart/form-data" novalidate>
                <?php echo csrfInputField('provider_signup'); ?>
                <input type="hidden" id="verifiedEmailState" value="<?php echo htmlspecialchars($verifiedEmailStateValue); ?>">

                <div class="provider-section">
                    <h3><i class="fas fa-user-lock"></i> Account Credentials</h3>
                    <div class="provider-grid">
                        <div class="form-group">
                            <label for="providerUsername">Username *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-at"></i>
                                <input type="text" id="providerUsername" name="username" maxlength="30" value="<?php echo htmlspecialchars(providerOldValue($providerOld, 'username')); ?>" placeholder="Choose a username">
                            </div>
                            <small class="hint">4 to 30 characters. Letters, numbers, dots, underscores, and hyphens only.</small>
                        </div>
                        <div class="form-group">
                            <label for="providerEmail">Login Email *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope"></i>
                                <input type="email" id="providerEmail" name="email" value="<?php echo htmlspecialchars($emailValue); ?>" placeholder="name@organization.org">
                            </div>
                        </div>
                        <div class="form-group provider-full">
                            <label>Email Verification *</label>
                            <div class="verify-row">
                                <div class="input-with-icon">
                                    <i class="fas fa-key"></i>
                                    <input type="text" id="providerVerificationCode" inputmode="numeric" maxlength="6" placeholder="Enter 6-digit verification code">
                                </div>
                                <button type="button" class="btn btn-outline" id="sendVerificationCodeBtn" <?php echo $signupVerificationDbReady ? '' : 'disabled'; ?>>Send Code</button>
                                <button type="button" class="btn btn-primary" id="verifyEmailCodeBtn" <?php echo $signupVerificationDbReady ? '' : 'disabled'; ?>>Verify Email</button>
                            </div>
                            <div class="verify-status" id="emailVerificationStatus"></div>
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

                <div class="provider-section">
                    <h3><i class="fas fa-building"></i> Organization Details</h3>
                    <div class="provider-grid">
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
                        <div class="form-group provider-full">
                            <label for="organizationDescription">Organization Description</label>
                            <div class="input-with-icon">
                                <i class="fas fa-file-lines"></i>
                                <textarea id="organizationDescription" name="description" placeholder="Briefly describe the organization and the scholarship programs it manages"><?php echo htmlspecialchars(providerOldValue($providerOld, 'description')); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="provider-section">
                    <h3><i class="fas fa-user-tie"></i> Contact Person</h3>
                    <div class="provider-grid">
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
                        </div>
                    </div>
                </div>

                <div class="provider-section">
                    <h3><i class="fas fa-location-dot"></i> Address And Verification</h3>
                    <div class="provider-grid">
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
                        <div class="form-group provider-full">
                            <div class="provider-pin-helper">
                                <small id="providerPinStatusText"><?php echo $providerHasSavedPin ? 'Pin selected. This map location will be saved with the organization address.' : 'No map pin selected yet. We can still geocode your address automatically, but you can set the exact organization pin here.'; ?></small>
                                <button type="button" class="btn btn-outline open-provider-location-modal">
                                    <i class="fas fa-map-marker-alt"></i> Set Pin on Map
                                </button>
                            </div>
                        </div>
                        <div class="form-group provider-full">
                            <label for="verificationDocument">Business Permit / Verification File</label>
                            <input type="file" id="verificationDocument" name="verification_document" class="provider-file-input" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="hint">Optional but recommended. Accepted formats: PDF, JPG, PNG. Maximum 5 MB.</small>
                            <?php if (!$providerVerificationUploadReady): ?>
                                <small class="hint" style="color: #9a6700; font-weight: 600;">
                                    <?php echo htmlspecialchars($providerVerificationUploadMessage); ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group provider-full">
                            <div class="provider-confirm-card">
                                <input type="checkbox" id="providerReviewPolicy" name="agree_terms" value="1" <?php echo providerChecked($providerOld, 'agree_terms'); ?>>
                                <label for="providerReviewPolicy">
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

                <div class="provider-actions">
                    <p>Already approved and active? <a href="login.php">Go to login</a></p>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Create Provider Account</button>
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
    function setStatus(message, type) { statusEl.textContent = message || ''; statusEl.className = 'verify-status' + (type ? ' ' + type : ''); }
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
            const response = await fetch('../Controller/send_signup_verification.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: new URLSearchParams({ email }) });
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
            const response = await fetch('../Controller/verify_signup_code.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: new URLSearchParams({ email, code }) });
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
<?php if (!empty($providerErrors)): ?>
    <div data-swal-errors data-swal-title="Provider Registration Error" style="display: none;">
        <?php echo json_encode(array_values($providerErrors)); ?>
    </div>
<?php endif; ?>
</body>
</html>
