<div class="section-title">
                <h2>Quick Actions</h2>
                <p>Get started with the scholarship finding process</p>
            </div>
            
            <div class="process-flow">
                <?php if($isLoggedIn): ?>
                <a href="upload.php" class="process-step" style="text-decoration: none; color: inherit;">
                    <div class="step-number">
                        <i class="fas fa-upload"></i>
                    </div>
                    <div class="step-title">Upload Grades</div>
                    <p>The System extracts your GWA</p>
                </a>
                
                <a href="scholarships.php" class="process-step" style="text-decoration: none; color: inherit;">
                    <div class="step-number">
                        <i class="fas fa-search"></i>
                    </div>
                    <div class="step-title">Find Scholarships</div>
                    <p>View personalized matches</p>
                </a>
                
                <a href="applications.php" class="process-step" style="text-decoration: none; color: inherit;">
                    <div class="step-number">
                        <i class="fas fa-magic"></i>
                    </div>
                    <div class="step-title">Apply Now</div>
                    <p>Use guided application steps</p>
                </a>
                <?php else: ?>
                <a href="login.php" class="process-step" style="text-decoration: none; color: inherit;">
                    <div class="step-number">
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <div class="step-title">Login</div>
                    <p>Access your account</p>
                </a>
                
                <a href="login.php" class="process-step" style="text-decoration: none; color: inherit;">
                    <div class="step-number">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="step-title">Register</div>
                    <p>Create a new account</p>
                </a>
                <?php endif; ?>
            </div>
