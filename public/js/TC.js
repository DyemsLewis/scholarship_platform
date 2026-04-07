let currentStep = 1;
let uploadedDocuments = [];

function showTermsAlert(title, text = '', icon = 'info') {
    if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
        Swal.fire({
            icon,
            title,
            text,
            confirmButtonColor: '#2c5aa0'
        });
        return;
    }

    window.alert(text || title);
}
        
// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateStepIndicators();
});
        
// Update step indicators
function updateStepIndicators() {
    const steps = document.querySelectorAll('.wizard-step');
    steps.forEach((step, index) => {
        step.classList.remove('active');
        if (index + 1 === currentStep) {
            step.classList.add('active');
        }
    });
}
        
// Show current step content
function showStep(stepNumber) {
// Hide all step contents
    for (let i = 1; i <= 3; i++) {
        const content = document.getElementById(`step${i}Content`);
        if (content) {
            content.style.display = 'none';
        }
    }
            
            // Show current step
    const currentContent = document.getElementById(`step${stepNumber}Content`);
    if (currentContent) {
     currentContent.style.display = 'block';
    }
            
    // Update step indicators
    updateStepIndicators();
}
        
        // Next step
function nextStep() {
    if (currentStep < 3) {
        currentStep++;
        showStep(currentStep);
    }
            
    // Update documents status in review step
    if (currentStep === 3) {
        updateDocumentsStatus();
    }
}
        
    // Previous step
function prevStep() {
    if (currentStep > 1) {
        currentStep--;
        showStep(currentStep);
    }
}
        
    // Update document status
function updateDocumentStatus(fileInputId, statusId) {
    const fileInput = document.getElementById(fileInputId);
    const status = document.getElementById(statusId);
            
    if (fileInput.files.length > 0) {
        const fileName = fileInput.files[0].name;
        status.textContent = fileName;
        status.style.color = 'var(--success)';
                
            // Add to uploaded documents array
        if (!uploadedDocuments.includes(fileInputId)) {
            uploadedDocuments.push(fileInputId);
        }
    } else {
        status.textContent = 'No file chosen';
        status.style.color = '#666';
                
            // Remove from uploaded documents array
        const index = uploadedDocuments.indexOf(fileInputId);
        if (index > -1) {
            uploadedDocuments.splice(index, 1);
        }
    }
}
        
        // Update documents status for review
function updateDocumentsStatus() {
    const statusElement = document.getElementById('documentsStatus');
    if (uploadedDocuments.length > 0) {
        statusElement.innerHTML = `<span style="color: var(--success);">
            <i class="fas fa-check-circle"></i> ${uploadedDocuments.length} document(s) uploaded
            </span>`;
    } else {
        statusElement.innerHTML = `<span style="color: var(--danger);">
        <i class="fas fa-exclamation-circle"></i> No documents uploaded
        </span>`;
    }
}
        
// Open Terms Modal
function openTermsModal() {
    const modal = document.getElementById('termsModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Prevent scrolling
}
        
// Close Terms Modal
function closeTermsModal() {
    const modal = document.getElementById('termsModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto'; // Restore scrolling
}
        
// Accept Terms from Modal
function acceptTerms() {
    const agreeCheckbox = document.getElementById('agreeTerms');
    if (agreeCheckbox) {
        agreeCheckbox.checked = true;
    }
    closeTermsModal();
}
        
// Submit application
function submitApplication() {
    const agreeTerms = document.getElementById('agreeTerms');
            
    if (!agreeTerms || !agreeTerms.checked) {
        showTermsAlert('Terms Required', 'Please read and accept the Terms and Conditions before submitting.', 'warning');
        return;
    }
            
    // Simple submission
    showTermsAlert('Application Submitted', 'Application submitted successfully! You will receive a confirmation email.', 'success');
            
        // Reset form (optional)
        // currentStep = 1;
        // showStep(currentStep);
        // uploadedDocuments = [];
        // document.getElementById('agreeTerms').checked = false;
            
        // You could redirect to dashboard
        // window.location.href = 'dashboard.php?submitted=true';
}
        
        // Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('termsModal');
    if (event.target === modal) {
        closeTermsModal();
    }
}
        
        // Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeTermsModal();
    }
});
