<?php
require_once __DIR__ . '/../app/Config/init.php';

if ($isLoggedIn) {
    redirect($isProviderOrAdmin ? '../AdminView/admin_dashboard.php' : 'index.php');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
    <style>
        /* Login page specific styles */
        .login-page {
            min-height: calc(100vh - 200px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px 0;
        }
        
        .login-container {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
        }
        
        .login-card {
            background-color: white;
            border-radius: var(--border-radius);
            padding: 40px;
            box-shadow: var(--shadow);
            border-top: 5px solid var(--primary);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 2rem;
        }
        
        .login-header p {
            color: var(--gray);
            font-size: 0.95rem;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-group label i {
            color: var(--primary);
            margin-right: 5px;
            width: 18px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(44, 90, 160, 0.1);
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

        .form-meta {
            display: flex;
            justify-content: flex-end;
            margin-top: -8px;
            margin-bottom: 18px;
        }

        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 600;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }
        
        .guest-warning {
            background-color: #fff3cd;
            border-left: 5px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .guest-warning i {
            color: #856404;
            font-size: 1.5rem;
        }
        
        .guest-warning p {
            color: #856404;
            margin: 0;
        }
        
        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <?php include 'layout/header.php'; ?>

    <!-- Login Section -->
    <section class="login-page">
        <div class="container">
            <div class="login-container">
                <div class="login-card">
                    <div class="login-header">
                        <i class="fas fa-graduation-cap" style="font-size: 3rem; color: var(--primary); margin-bottom: 15px;"></i>
                        <h1>Welcome Back!</h1>
                        <p>Sign in to access your scholarship opportunities</p>
                    </div>
                    
                    <!-- Login Form -->
                    <form id="loginForm" method="POST" action="../app/Controllers/loginController.php">
                        <div class="form-group">
                            <label for="loginEmail"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" id="loginEmail" name="email" placeholder="Enter your email" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="loginPassword"><i class="fas fa-lock"></i> Password</label>
                            <input type="password" id="loginPassword" name="password" placeholder="Enter your password" required>
                        </div>

                        <div class="form-meta">
                            <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                        </div>
                        
                        <button type="submit" class="btn btn1 btn-primary">
                            <i class="fas fa-sign-in-alt"></i> Login to Your Account
                        </button>
                        
                        <div class="form-footer">
                            <p>Don't have an account? <a href="signup.php">Create one here</a></p>
                            <p>Need a provider account? <a href="provider_signup.php">Register here</a></p>
                        </div>
                    </form>
                    
                    <div class="guest-warning" id="loginWarning" style="display: none; margin-top: 20px;">
                        <i class="fas fa-exclamation-circle"></i>
                        <p id="warningText">Please fill in all required fields.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'layout/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../public/js/sweetalert.js"></script>
    
    <script>
    // Client-side validation
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const email = document.getElementById('loginEmail').value.trim();
        const password = document.getElementById('loginPassword').value.trim();
        
        if (!email || !password) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Please fill in all fields.',
                confirmButtonColor: '#3085d6'
            });
        }
    });
    </script>

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
</body>
</html>
