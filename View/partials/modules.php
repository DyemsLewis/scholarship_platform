<div class="modules-grid">
                <!-- Module 1 -->
                <div class="module-card">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3 class="module-title">User Registration & Authentication</h3>
                    </div>
                    <ul class="process-list">
                        <li><i class="fas fa-check"></i> Student creates account (Web/Mobile)</li>
                        <li><i class="fas fa-check"></i> Email verification</li>
                        <li><i class="fas fa-check"></i> Secure login with encryption</li>
                        <li><i class="fas fa-check"></i> Stores: Name, Email, School, Course</li>
                        <li><i class="fas fa-check"></i> Purpose: Verified unique profiles</li>
                    </ul>
                </div>
                
                <!-- Module 2 -->
                <div class="module-card">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-database"></i>
                        </div>
                        <h3 class="module-title">Academic Data Collection</h3>
                    </div>
                    <p><strong>Important Rule:</strong> User does NOT manually search scholarships</p>
                    <ul class="process-list">
                        <li><i class="fas fa-check"></i> System requests school & course info</li>
                        <li><i class="fas fa-check"></i> User uploads report card image</li>
                        <li><i class="fas fa-check"></i> No self-declared eligibility</li>
                        <li><i class="fas fa-check"></i> Prevents incorrect information</li>
                    </ul>
                    <?php if($isLoggedIn): ?>
                    <a href="upload.php" class="btn btn-outline" style="margin-top: 15px;">Upload Grades</a>
                    <?php else: ?>
                    <a href="login.php" class="btn btn-outline" style="margin-top: 15px;">Login to Upload</a>
                    <?php endif; ?>
                </div>
                
                <!-- Module 3 -->
                <div class="module-card">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-file-image"></i>
                        </div>
                        <h3 class="module-title">Processing for GWA Extraction</h3>
                    </div>
                    <ul class="process-list">
                        <li><i class="fas fa-check"></i> Upload image of grades</li>
                        <li><i class="fas fa-check"></i> Extract GWA & subject grades</li>
                        <li><i class="fas fa-check"></i> Clean and validate extracted text</li>
                        <li><i class="fas fa-check"></i> Output: JSON with GWA, school, academic year</li>
                    </ul>
                </div>
                
                <!-- Module 4 -->
                <div class="module-card">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3 class="module-title">Grade & School Validation</h3>
                    </div>
                    <ul class="process-list">
                        <li><i class="fas fa-check"></i> Checks GWA range validity</li>
                        <li><i class="fas fa-check"></i> Validates school accreditation (CHED/DepEd/TESDA)</li>
                        <li><i class="fas fa-check"></i> Cross-checks grade consistency</li>
                        <li><i class="fas fa-check"></i> Verifies minimum passing requirements</li>
                        <li><i class="fas fa-check"></i> Decision Rules: IF GWA <= 2.0 AND CHED-accredited</li>
                    </ul>
                </div>
                
                <!-- Module 5 -->
                <div class="module-card">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-filter"></i>
                        </div>
                        <h3 class="module-title">Pre-Qualification</h3>
                    </div>
                    <ul class="process-list">
                        <li><i class="fas fa-check"></i> Rule-based filtering system</li>
                        <li><i class="fas fa-check"></i> Applies scholarship rules automatically</li>
                        <li><i class="fas fa-check"></i> Generates eligibility flags</li>
                        <li><i class="fas fa-check"></i> Output: Eligible/Not Eligible status per scholarship</li>
                    </ul>
                </div>
                
                <!-- Module 6 -->
                <div class="module-card">
                    <div class="module-header">
                        <div class="module-icon">
                            <i class="fas fa-brain"></i>
                        </div>
                            <h3 class="module-title">Decision Support System</h3>
                    </div>
                    <ul class="process-list">
                        <li><i class="fas fa-check"></i> Inputs: GWA, School, Course, Region</li>
                        <li><i class="fas fa-check"></i> Predicts probability of acceptance</li>
                        <li><i class="fas fa-check"></i> Improves accuracy over time</li>
                    </ul>
                    <?php if($isLoggedIn): ?>
                    <a href="scholarships.php" class="btn btn-primary" style="margin-top: 15px;">View Scholarships</a>
                    <?php else: ?>
                    <a href="login.php" class="btn btn-primary" style="margin-top: 15px;">Login to View Matches</a>
                    <?php endif; ?>
                </div>
            </div>
