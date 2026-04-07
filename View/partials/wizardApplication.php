<div class="wizard-content">
                    <!-- Step 1: User Profile -->
                    <div id="step1Content">
                        <h3>Your Profile Information</h3>
                        <p>Here's the information we'll use for your scholarship applications:</p>
                        
                        <div class="profile-summary" style="background: #f9f9f9; padding: 20px; border-radius: 10px; margin: 20px 0;">
                            <div class="profile-item">
                                <strong>Name:</strong> <?php echo htmlspecialchars($userName); ?>
                            </div>
                            <div class="profile-item">
                                <strong>Email:</strong> <?php echo htmlspecialchars($userEmail); ?>
                            </div>
                            <div class="profile-item">
                                <strong>School:</strong> <?php echo htmlspecialchars($userSchool); ?>
                            </div>
                            <div class="profile-item">
                                <strong>Course:</strong> <?php echo htmlspecialchars($userCourse); ?>
                            </div>
                            <div class="profile-item">
                                <strong>GWA:</strong> 
                                <?php if($userGWA): ?>
                                <?php echo htmlspecialchars($userGWA); ?>
                                <?php else: ?>
                                <span style="color: var(--danger);">Not uploaded yet</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if(!$userGWA): ?>
                        <div class="alert" style="background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Upload your grades first for better scholarship matching!</span>
                            <a href="upload.php" class="btn btn-outline" style="margin-left: 30px;">Upload Grades</a>
                        </div>
                        <?php endif; ?>
                        
                        <button class="btn btn-primary" onclick="nextStep()">Continue to Documents</button>
                    </div>
                    
                    <!-- Step 2: Document Upload -->
                    <div id="step2Content" style="display: none;">
                        <h3>Upload Required Documents</h3>
                        <p>Upload the documents needed for scholarship applications:</p>
                        
                        <div class="document-list">
                            <!-- Document 1 -->
                            <div class="document-item" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <h4 style="margin: 0;">Certificate of Enrollment</h4>
                                        <p style="margin: 5px 0 0 0; color: #666;">Proof of current enrollment</p>
                                    </div>
                                    <div>
                                        <input type="file" id="doc1" style="display: none;" onchange="updateDocumentStatus('doc1', 'status1')">
                                        <button class="btn btn-outline" onclick="document.getElementById('doc1').click()">Choose File</button>
                                        <span id="status1" style="margin-left: 10px; color: #666;">No file chosen</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Document 2 -->
                            <div class="document-item" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <h4 style="margin: 0;">Barangay Certificate</h4>
                                        <p style="margin: 5px 0 0 0; color: #666;">Proof of residency</p>
                                    </div>
                                    <div>
                                        <input type="file" id="doc2" style="display: none;" onchange="updateDocumentStatus('doc2', 'status2')">
                                        <button class="btn btn-outline" onclick="document.getElementById('doc2').click()">Choose File</button>
                                        <span id="status2" style="margin-left: 10px; color: #666;">No file chosen</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Document 3 -->
                            <div class="document-item" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <h4 style="margin: 0;">ID Picture</h4>
                                        <p style="margin: 5px 0 0 0; color: #666;">2x2 ID Photo</p>
                                    </div>
                                    <div>
                                        <input type="file" id="doc3" style="display: none;" accept=".jpg,.jpeg,.png" onchange="updateDocumentStatus('doc3', 'status3')">
                                        <button class="btn btn-outline" onclick="document.getElementById('doc3').click()">Choose File</button>
                                        <span id="status3" style="margin-left: 10px; color: #666;">No file chosen</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 30px;">
                            <button class="btn btn-outline" onclick="prevStep()">Back</button>
                            <button class="btn btn-primary" onclick="nextStep()" style="margin-left: 10px;">Review Application</button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Review -->
                    <div id="step3Content" style="display: none;">
                        <h3>Review Your Application</h3>
                        <p>Review all information before submitting:</p>
                        
                        <div class="review-summary" style="background: #f9f9f9; padding: 20px; border-radius: 10px; margin: 20px 0;">
                            <h4>Personal Information</h4>
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($userName); ?></p>
                            <p><strong>School:</strong> <?php echo htmlspecialchars($userSchool); ?></p>
                            <p><strong>GWA:</strong> <?php echo $userGWA ? htmlspecialchars($userGWA) : 'Not uploaded'; ?></p>
                            
                            <h4 style="margin-top: 20px;">Documents Status</h4>
                            <p id="documentsStatus">No documents uploaded yet</p>
                            
                            <div class="form-check">
                                <input type="checkbox" id="agreeTerms">
                                <label for="agreeTerms">
                                    I have read and agree to the 
                                    <span class="terms-link" onclick="openTermsModal()">Terms and Conditions</span>
                                </label>
                            </div>
                        </div>
                        
                        <div style="margin-top: 30px;">
                            <button class="btn btn-outline" onclick="prevStep()">Back</button>
                            <button class="btn btn-success" onclick="submitApplication()" style="margin-left: 10px;">Submit Application</button>
                        </div>
                    </div>
                </div>
            </div>