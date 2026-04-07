<?php
header('Location: ../View/login.php');
exit();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship - Admin Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../public/css/admin_style.css">
</head>
<body>
    <!-- Admin Login Section -->
    <section class="admin-login-page">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <div class="admin-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h1>Admin Panel</h1>
                    <p>Scholarship Management System</p>
                </div>
                
                <?php 
                require_once __DIR__ . '/../Config/session_bootstrap.php';
                if (isset($_SESSION['error'])): 
                ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <p><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Login Form -->
                <form method="POST" action="../AdminController/admin_login_process.php">
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="username" name="username" placeholder="Enter your username or email" required autofocus>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">Login to Admin Panel</button>
                </form>
                
                <div class="login-footer">
                    <p><a href="../View/index.php">Back to User Portal</a></p>
                </div>
                
                <div class="demo-info">
                    <p><strong>Demo Credentials:</strong></p>
                    <p>Username: <code>admin</code></p>
                    <p>Password: <code>password</code> (default bcrypt)</p>
                </div>
            </div>
        </div>
    </section>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .admin-login-page {
            width: 100%;
        }

        .login-container {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
        }

        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .admin-icon {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 15px;
            display: inline-block;
        }

        .login-header h1 {
            font-size: 2rem;
            color: #333;
            margin-bottom: 5px;
        }

        .login-header p {
            color: #999;
            font-size: 0.95rem;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background-color: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 15px;
            color: #999;
        }

        .input-wrapper input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background-color: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background-color: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-block {
            width: 100%;
        }

        .login-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .login-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .demo-info {
            background-color: #f0f4ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 0.85rem;
            color: #333;
        }

        .demo-info p {
            margin: 5px 0;
        }

        .demo-info code {
            background-color: #e3ebff;
            padding: 3px 8px;
            border-radius: 4px;
            color: #667eea;
            font-family: 'Courier New', monospace;
        }
    </style>
</body>
</html>
