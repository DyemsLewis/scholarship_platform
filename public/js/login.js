document.addEventListener('DOMContentLoaded', function() {
    const loginTab = document.getElementById('loginTab');
    const signupTab = document.getElementById('signupTab');
    const loginForm = document.getElementById('loginForm');
    const signupForm = document.getElementById('signupForm');
    const switchToSignup = document.getElementById('switchToSignup');
    const switchToLogin = document.getElementById('switchToLogin');
    
    // Switch between login and signup tabs
    if (loginTab) {
        loginTab.addEventListener('click', function() {
            loginTab.classList.add('active');
            signupTab.classList.remove('active');
            loginForm.style.display = 'block';
            signupForm.style.display = 'none';
        });
    }
    
    if (signupTab) {
        signupTab.addEventListener('click', function() {
            signupTab.classList.add('active');
            loginTab.classList.remove('active');
            signupForm.style.display = 'block';
            loginForm.style.display = 'none';
        });
    }
    
    if (switchToSignup) {
        switchToSignup.addEventListener('click', function(e) {
            e.preventDefault();
            signupTab.click();
        });
    }
    
    if (switchToLogin) {
        switchToLogin.addEventListener('click', function(e) {
            e.preventDefault();
            loginTab.click();
        });
    }
    
    // Client-side validation for signup
    const signupFormElement = document.getElementById('signupForm');
    if (signupFormElement) {
        signupFormElement.addEventListener('submit', function(e) {
            const password = document.getElementById('signupPassword').value;
            const confirmPassword = document.getElementById('signupConfirmPassword').value;
            const passwordValidation = window.PasswordPolicy
                ? window.PasswordPolicy.validate(password)
                : {
                    valid: password.length >= 10
                        && /[A-Z]/.test(password)
                        && /[a-z]/.test(password)
                        && /\d/.test(password)
                        && /[^A-Za-z0-9]/.test(password),
                    errors: ['Use at least 10 characters with uppercase, lowercase, number, and symbol.']
                };
            
            if (password !== confirmPassword) {
                e.preventDefault();
                SweetAlertHelper.showError('Password Mismatch', 'Passwords do not match!');
                return false;
            }
            
            if (!passwordValidation.valid) {
                e.preventDefault();
                SweetAlertHelper.showError('Weak Password', (passwordValidation.errors && passwordValidation.errors[0]) || 'Use at least 10 characters with uppercase, lowercase, number, and symbol.');
                return false;
            }
            
            return true;
        });
    }
});
