<div id="termsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-file-contract"></i> Terms and Conditions</h3>
                <button class="close-modal" onclick="closeTermsModal()">&times;</button>
            </div>
            
            <div class="terms-content">
                <p><strong>Last Updated:</strong> <?php echo date('F d, Y'); ?></p>
                
                <div class="terms-section">
                    <h4>1. Application Process</h4>
                    <ul>
                        <li>By submitting this scholarship application, you confirm that all information provided is accurate and complete.</li>
                        <li>You authorize us to verify the information submitted with relevant institutions.</li>
                        <li>Incomplete or falsified applications will be automatically disqualified.</li>
                    </ul>
                </div>
                
                <div class="terms-section">
                    <h4>2. Document Requirements</h4>
                    <ul>
                        <li>All uploaded documents must be clear, legible, and authentic.</li>
                        <li>You retain ownership of all documents but grant permission for processing.</li>
                        <li>Documents will be stored securely in accordance with data protection laws.</li>
                    </ul>
                </div>
                
                <div class="terms-section">
                    <h4>3. Privacy and Data Protection</h4>
                    <ul>
                        <li>Your personal data will be used solely for scholarship processing.</li>
                        <li>We comply with the Data Privacy Act of 2012 (Republic Act No. 10173).</li>
                        <li>You may request deletion of your data after the application period.</li>
                    </ul>
                </div>
                
                <div class="terms-section">
                    <h4>4. Scholarship Awards</h4>
                    <ul>
                        <li>Scholarship decisions are final and at the discretion of the awarding bodies.</li>
                        <li>Awards are subject to availability of funds and fulfillment of requirements.</li>
                        <li>Recipients must maintain academic standards to continue receiving benefits.</li>
                    </ul>
                </div>
                
                <div class="terms-section">
                    <h4>5. Responsibilities of Recipients</h4>
                    <ul>
                        <li>Maintain minimum grade requirements as specified by each scholarship.</li>
                        <li>Notify of any changes in enrollment status or contact information.</li>
                        <li>Provide updates or additional documentation when requested.</li>
                    </ul>
                </div>
                
                <div class="terms-section">
                    <h4>6. General Provisions</h4>
                    <ul>
                        <li>The scholarship provider reserves the right to modify terms with notice.</li>
                        <li>Any misrepresentation may result in termination of benefits.</li>
                        <li>These terms are governed by the laws of the Republic of the Philippines.</li>
                    </ul>
                </div>
            </div>
            
            <div class="terms-footer">
                <button class="btn btn-outline" onclick="closeTermsModal()">Close</button>
                <button class="btn btn-primary" onclick="acceptTerms()">I Accept Terms</button>
            </div>
        </div>
    </div>