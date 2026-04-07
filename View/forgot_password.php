<?php
require_once '../Config/init.php';
require_once '../Config/password_reset.php';

$passwordResetDbReady = passwordResetColumnsReady($pdo);
$oldInput = $_SESSION['forgot_password_old'] ?? [];
unset($_SESSION['forgot_password_old']);

$flashType = (string) ($_SESSION['forgot_password_flash_type'] ?? '');
$flashMessage = (string) ($_SESSION['forgot_password_flash_message'] ?? '');
unset($_SESSION['forgot_password_flash_type'], $_SESSION['forgot_password_flash_message']);

$sessionResetEmail = trim((string) ($_SESSION['forgot_password_last_email'] ?? ''));
$prefillEmail = trim((string) ($oldInput['email'] ?? $sessionResetEmail));
$prefillCode = trim((string) ($oldInput['code'] ?? ''));
$resetState = $prefillEmail !== '' ? getPasswordResetState($pdo, $prefillEmail) : [];
$cooldownSeconds = ($passwordResetDbReady && $prefillEmail !== '') ? getPasswordResetCooldown($pdo, $prefillEmail) : 0;
$hasActiveCode = ($passwordResetDbReady && $prefillEmail !== '') ? hasActivePasswordResetCode($pdo, $prefillEmail) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship - Forgot Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
    <style>
        .forgot-password-page {
            min-height: calc(100vh - 200px);
            padding: 60px 0;
        }

        .forgot-password-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        .forgot-password-card {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 40px;
            box-shadow: var(--shadow);
            border-top: 5px solid var(--primary);
        }

        .forgot-password-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .forgot-password-header h1 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 2rem;
        }

        .forgot-password-header p {
            color: var(--gray);
            font-size: 0.95rem;
            margin: 0;
        }

        .form-section {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid #e5e7eb;
        }

        .form-section h3 {
            color: var(--primary);
            font-size: 1.2rem;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h3 i {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .helper-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }

        .status-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pill.ready {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #047857;
        }

        .status-pill.waiting {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #c2410c;
        }

        .forgot-password-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .forgot-password-full-width {
            grid-column: span 2;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .input-with-icon {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-with-icon i {
            position: absolute;
            left: 12px;
            color: var(--primary);
            font-size: 1rem;
            z-index: 1;
        }

        .input-with-icon input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid var(--light-gray);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .input-with-icon input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
        }

        .field-note {
            margin-top: 6px;
            font-size: 0.8rem;
            color: #666;
        }

        .forgot-password-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
        }

        .btn-inline {
            width: auto;
            min-width: 160px;
            padding: 12px 16px;
        }

        .btn1 {
            width: 100%;
            padding: 14px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .link-inline {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .link-inline:hover {
            text-decoration: underline;
        }

        .form-footer {
            text-align: center;
            margin-top: 20px;
            color: var(--gray);
        }

        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .form-footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .forgot-password-grid {
                grid-template-columns: 1fr;
            }

            .forgot-password-full-width {
                grid-column: span 1;
            }

            .btn-inline {
                width: 100%;
            }
        }

        @media (max-width: 640px) {
            .forgot-password-page {
                padding: 32px 0;
            }

            .forgot-password-card {
                padding: 30px 20px;
            }

            .forgot-password-actions {
                align-items: stretch;
            }

            .forgot-password-actions .btn,
            .forgot-password-actions .btn-inline {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include 'layout/header.php'; ?>

    <section class="forgot-password-page">
        <div class="container">
            <div class="forgot-password-container">
                <div class="forgot-password-card">
                    <div class="forgot-password-header">
                        <i class="fas fa-shield-heart" style="font-size: 3rem; color: var(--primary); margin-bottom: 15px;"></i>
                        <h1>Forgot Password?</h1>
                        <p>Recover your account by requesting a reset code and setting a new password.</p>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-paper-plane"></i> Request Reset Code</h3>
                        <div class="helper-banner">
                            <i class="fas fa-envelope-open-text"></i>
                            <span>Reset codes are saved in your account and stay valid for 10 minutes.</span>
                        </div>
                        <?php if (!$passwordResetDbReady): ?>
                            <div class="helper-banner" style="background: #fff7ed; border-color: #fed7aa; color: #c2410c;">
                                <i class="fas fa-triangle-exclamation"></i>
                                <span>Password reset database fields are missing. Run the provided ALTER migration first.</span>
                            </div>
                        <?php endif; ?>
                        <div class="status-row">
                            <span class="status-pill ready" id="codeStatusPill" style="<?php echo $hasActiveCode ? '' : 'display: none;'; ?>">
                                <i class="fas fa-check-circle" id="codeStatusIcon"></i>
                                <span id="codeStatusText">Reset code ready</span>
                            </span>
                            <span class="status-pill waiting" id="cooldownPill" style="<?php echo $cooldownSeconds > 0 ? '' : 'display: none;'; ?>">
                                <i class="fas fa-stopwatch"></i>
                                Resend available in <span id="cooldownValue"><?php echo (int) $cooldownSeconds; ?></span>s
                            </span>
                        </div>
                        <form id="requestResetCodeForm">
                            <div class="forgot-password-grid">
                                <div class="form-group forgot-password-full-width">
                                    <label for="resetRequestEmail">Registered Email Address</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-envelope"></i>
                                        <input type="email" id="resetRequestEmail" name="email" value="<?php echo htmlspecialchars($prefillEmail); ?>" placeholder="Enter your registered email" required>
                                    </div>
                                    <div class="field-note">We will send a 6-digit code to this email if the account is active.</div>
                                </div>
                            </div>
                            <div class="forgot-password-actions">
                                <button type="submit" class="btn btn-primary btn-inline" id="sendResetCodeBtn" <?php echo $passwordResetDbReady ? '' : 'disabled'; ?>>
                                    <i class="fas fa-paper-plane"></i> Send Reset Code
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-unlock-alt"></i> Create New Password</h3>
                        <form method="POST" action="../Controller/reset_password.php" id="resetPasswordForm">
                            <div class="forgot-password-grid">
                                <div class="form-group">
                                    <label for="resetEmail">Email Address</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-user"></i>
                                        <input type="email" id="resetEmail" name="email" value="<?php echo htmlspecialchars($prefillEmail); ?>" placeholder="Enter your email again" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="resetCode">Reset Code</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-key"></i>
                                        <input type="text" id="resetCode" name="code" value="<?php echo htmlspecialchars($prefillCode); ?>" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="6-digit code" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="newPassword">New Password</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-lock"></i>
                                        <input type="password" id="newPassword" name="new_password" placeholder="<?php echo htmlspecialchars(passwordPolicyHint()); ?>" minlength="<?php echo passwordPolicyMinLength(); ?>" required>
                                    </div>
                                    <div class="field-note"><?php echo htmlspecialchars(passwordPolicyHint()); ?></div>
                                </div>
                                <div class="form-group">
                                    <label for="confirmPassword">Confirm New Password</label>
                                    <div class="input-with-icon">
                                        <i class="fas fa-check-circle"></i>
                                        <input type="password" id="confirmPassword" name="confirm_password" placeholder="Repeat your new password" minlength="<?php echo passwordPolicyMinLength(); ?>" required>
                                    </div>
                                </div>
                            </div>
                            <div class="forgot-password-actions">
                                <button type="submit" class="btn btn1 btn-primary" <?php echo $passwordResetDbReady ? '' : 'disabled'; ?>>
                                    <i class="fas fa-rotate"></i> Reset Password
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="form-footer">
                        <p>Remembered your password? <a href="login.php">Back to login</a></p>
                        <p>Need an account? <a href="signup.php">Create one here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include 'layout/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/sweetalert.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(assetUrl('public/js/password-policy.js')); ?>"></script>
    <script>
    (function () {
        const requestForm = document.getElementById('requestResetCodeForm');
        const sendButton = document.getElementById('sendResetCodeBtn');
        const requestEmail = document.getElementById('resetRequestEmail');
        const resetEmail = document.getElementById('resetEmail');
        const resetCode = document.getElementById('resetCode');
        const codeStatusPill = document.getElementById('codeStatusPill');
        const codeStatusIcon = document.getElementById('codeStatusIcon');
        const codeStatusText = document.getElementById('codeStatusText');
        const cooldownPill = document.getElementById('cooldownPill');
        const cooldownValue = document.getElementById('cooldownValue');
        const passwordResetEnabled = <?php echo $passwordResetDbReady ? 'true' : 'false'; ?>;
        let cooldownSeconds = <?php echo (int) $cooldownSeconds; ?>;

        function updateSendButton() {
            if (!sendButton) {
                return;
            }

            if (!passwordResetEnabled) {
                sendButton.disabled = true;
                sendButton.innerHTML = '<i class="fas fa-ban"></i> Migration Required';
                return;
            }

            if (cooldownSeconds > 0) {
                sendButton.disabled = true;
                sendButton.innerHTML = '<i class="fas fa-clock"></i> Try Again in ' + cooldownSeconds + 's';
            } else {
                sendButton.disabled = false;
                sendButton.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reset Code';
            }

            if (cooldownValue) {
                cooldownValue.textContent = String(cooldownSeconds);
            }

            if (cooldownPill) {
                cooldownPill.style.display = cooldownSeconds > 0 ? 'inline-flex' : 'none';
            }
        }

        function markCodeReady() {
            if (codeStatusPill) {
                codeStatusPill.style.display = 'inline-flex';
                codeStatusPill.classList.remove('waiting');
                codeStatusPill.classList.add('ready');
            }

            if (codeStatusIcon) {
                codeStatusIcon.className = 'fas fa-check-circle';
            }

            if (codeStatusText) {
                codeStatusText.textContent = 'Reset code ready';
            }
        }

        if (cooldownSeconds > 0) {
            updateSendButton();
            window.setInterval(function () {
                if (cooldownSeconds <= 0) {
                    return;
                }

                cooldownSeconds -= 1;
                updateSendButton();
            }, 1000);
        }

        if (requestForm) {
            requestForm.addEventListener('submit', function (event) {
                event.preventDefault();

                if (!passwordResetEnabled) {
                    SweetAlertHelper.showError('Reset Not Ready', 'Password reset database fields are missing. Run the migration first.');
                    return;
                }

                const emailValue = (requestEmail?.value || '').trim();
                if (emailValue === '') {
                    SweetAlertHelper.showError('Missing Email', 'Please enter your registered email address first.');
                    return;
                }

                sendButton.disabled = true;
                sendButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

                fetch('../Controller/send_password_reset_code.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({
                        email: emailValue
                    })
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            return { ok: response.ok, data: data };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.data.success) {
                            throw new Error(result.data.message || 'Unable to send reset code.');
                        }

                        resetEmail.value = emailValue;
                        cooldownSeconds = Number(result.data.cooldown_seconds || 0);
                        markCodeReady();
                        updateSendButton();
                        SweetAlertHelper.showSuccess('Reset Code Sent', result.data.message || 'Please check your inbox.');
                    })
                    .catch(function (error) {
                        cooldownSeconds = 0;
                        updateSendButton();
                        SweetAlertHelper.showError('Reset Failed', error.message || 'Unable to send reset code.');
                    });
            });
        }

        const resetForm = document.getElementById('resetPasswordForm');
        if (resetForm) {
            resetForm.addEventListener('submit', function (event) {
                if (!passwordResetEnabled) {
                    event.preventDefault();
                    SweetAlertHelper.showError('Reset Not Ready', 'Password reset database fields are missing. Run the migration first.');
                    return;
                }

                const emailValue = (resetEmail?.value || '').trim();
                const codeValue = (resetCode?.value || '').trim();
                const newPassword = (document.getElementById('newPassword')?.value || '').trim();
                const confirmPassword = (document.getElementById('confirmPassword')?.value || '').trim();

                if (!emailValue || !codeValue || !newPassword || !confirmPassword) {
                    event.preventDefault();
                    SweetAlertHelper.showError('Missing Fields', 'Please complete all fields before resetting your password.');
                    return;
                }

                const passwordValidation = window.PasswordPolicy
                    ? window.PasswordPolicy.validate(newPassword, {
                        email: emailValue
                    })
                    : { valid: newPassword.length >= <?php echo passwordPolicyMinLength(); ?>, errors: [<?php echo json_encode(passwordPolicyHint()); ?>] };

                if (!passwordValidation.valid) {
                    event.preventDefault();
                    SweetAlertHelper.showError('Weak Password', passwordValidation.errors[0] || <?php echo json_encode(passwordPolicyHint()); ?>);
                    return;
                }

                if (newPassword !== confirmPassword) {
                    event.preventDefault();
                    SweetAlertHelper.showError('Password Mismatch', 'New password and confirmation do not match.');
                }
            });
        }
    }());
    </script>

    <?php if ($flashType === 'success' && $flashMessage !== ''): ?>
        <div data-swal-success data-swal-title="Success!" style="display: none;">
            <?php echo htmlspecialchars($flashMessage); ?>
        </div>
    <?php elseif ($flashType === 'error' && $flashMessage !== ''): ?>
        <div data-swal-error data-swal-title="Reset Error" style="display: none;">
            <?php echo htmlspecialchars($flashMessage); ?>
        </div>
    <?php endif; ?>
</body>
</html>
