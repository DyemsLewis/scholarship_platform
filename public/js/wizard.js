class ScholarshipWizard {
    constructor() {
        this.form = document.getElementById('applicationForm');
        if (!this.form) {
            return;
        }

        this.stepPills = Array.from(document.querySelectorAll('[data-step-target]'));
        this.stepPanels = Array.from(document.querySelectorAll('[data-step-panel]'));
        this.currentStep = 1;

        this.bindStepControls();
        this.bindSubmit();
        this.render();
    }

    bindStepControls() {
        document.querySelectorAll('[data-next-step]').forEach((button) => {
            button.addEventListener('click', () => {
                const nextStep = parseInt(button.getAttribute('data-next-step') || '1', 10);
                this.goToStep(nextStep);
            });
        });

        document.querySelectorAll('[data-prev-step]').forEach((button) => {
            button.addEventListener('click', () => {
                const prevStep = parseInt(button.getAttribute('data-prev-step') || '1', 10);
                this.currentStep = prevStep;
                this.render();
            });
        });

        this.stepPills.forEach((pill) => {
            pill.addEventListener('click', () => {
                const target = parseInt(pill.getAttribute('data-step-target') || '1', 10);
                if (target > this.currentStep) {
                    this.goToStep(target);
                    return;
                }

                this.currentStep = target;
                this.render();
            });
        });
    }

    goToStep(targetStep) {
        if (targetStep <= this.currentStep) {
            this.currentStep = targetStep;
            this.render();
            return;
        }

        if (!this.validateStep(this.currentStep)) {
            return;
        }

        this.currentStep = targetStep;
        this.render();
    }

    validateStep(stepNumber) {
        if (stepNumber === 1) {
            return this.validateEligibilityStep();
        }

        if (stepNumber === 2) {
            return this.validateDocumentsStep();
        }

        if (stepNumber === 3) {
            return this.validateReviewStep();
        }

        return true;
    }

    validateEligibilityStep() {
        const gwaRequired = this.getIntValue('wizardGwaRequired');
        const hasGwa = this.getIntValue('wizardHasGwa');
        const gwaWithinRequirement = this.getIntValue('wizardGwaWithinRequirement');
        const profilePending = this.getIntValue('wizardProfilePendingCount');
        const profileFailed = this.getIntValue('wizardProfileFailedCount');

        if (profilePending > 0) {
            this.showToast('Please complete your applicant profile first.', 'warning');
            return false;
        }

        if (profileFailed > 0) {
            this.showToast('Your current profile does not match this scholarship policy.', 'warning');
            return false;
        }

        if (gwaRequired === 1 && hasGwa === 0) {
            this.showToast('Please upload your TOR/grades to set your GWA first.', 'warning');
            return false;
        }

        if (gwaRequired === 1 && gwaWithinRequirement === 0) {
            this.showToast('Your GWA is above the required limit for this scholarship.', 'warning');
            return false;
        }

        return true;
    }

    validateDocumentsStep() {
        const missingDocs = this.getIntValue('missingDocsCount');
        const rejectedDocs = this.getIntValue('rejectedDocsCount');

        if (missingDocs > 0) {
            this.showToast('Please upload all missing required documents.', 'warning');
            return false;
        }

        if (rejectedDocs > 0) {
            this.showToast('Please re-upload rejected required documents.', 'warning');
            return false;
        }

        return true;
    }

    validateReviewStep() {
        const agreeTerms = document.getElementById('agreeTerms');
        const confirmInfo = document.getElementById('confirmInfo');

        if (!agreeTerms || !confirmInfo) {
            return false;
        }

        if (!agreeTerms.checked || !confirmInfo.checked) {
            this.showToast('Please agree to the terms and confirm your information.', 'warning');
            return false;
        }

        return true;
    }

    bindSubmit() {
        this.form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!this.validateReviewStep()) {
                return;
            }

            const canSubmit = this.form.getAttribute('data-can-submit') === '1';
            if (!canSubmit) {
                const reason = this.form.getAttribute('data-block-reason') || 'Application is not ready for submission yet.';
                this.showToast(reason, 'warning');
                return;
            }

            const submitButton = document.getElementById('submitApplication');
            const originalLabel = submitButton ? submitButton.innerHTML : '';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            }

            try {
                const payload = new FormData(this.form);
                const response = await fetch('../Controller/submit_application.php', {
                    method: 'POST',
                    body: payload,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();
                if (!response.ok || !data || !data.success) {
                    throw new Error((data && data.message) ? data.message : 'Application submission failed.');
                }

                await this.showSubmissionSuccess(data.message || 'Application submitted successfully.');
                window.location.href = 'scholarships.php';
            } catch (error) {
                this.showToast(error.message || 'Failed to submit application.', 'error');
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalLabel;
                }
            }
        });
    }

    render() {
        this.stepPanels.forEach((panel) => {
            const panelStep = parseInt(panel.getAttribute('data-step-panel') || '0', 10);
            panel.classList.toggle('is-active', panelStep === this.currentStep);
        });

        this.stepPills.forEach((pill) => {
            const pillStep = parseInt(pill.getAttribute('data-step-target') || '0', 10);
            pill.classList.toggle('is-active', pillStep === this.currentStep);
        });
    }

    getIntValue(id) {
        const element = document.getElementById(id);
        if (!element) {
            return 0;
        }

        const parsed = parseInt(element.value || '0', 10);
        return Number.isNaN(parsed) ? 0 : parsed;
    }

    showSubmissionSuccess(message) {
        if (typeof Swal !== 'undefined') {
            return Swal.fire({
                icon: 'success',
                title: 'Application Submitted',
                text: message,
                confirmButtonText: 'Continue',
                confirmButtonColor: '#3085d6'
            });
        }

        window.alert(message);
        return Promise.resolve();
    }

    showToast(message, type = 'info') {
        const existing = document.querySelector('.wizard-toast');
        if (existing) {
            existing.remove();
        }

        const toast = document.createElement('div');
        toast.className = `wizard-toast ${type}`;

        let icon = 'fa-circle-info';
        if (type === 'success') icon = 'fa-check-circle';
        if (type === 'warning') icon = 'fa-triangle-exclamation';
        if (type === 'error') icon = 'fa-circle-xmark';

        toast.innerHTML = `<i class="fas ${icon}"></i><span>${message}</span>`;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 4500);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new ScholarshipWizard();
});
