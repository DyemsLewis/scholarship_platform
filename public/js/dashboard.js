function switchProfileTab(tab) {
    document.getElementById('profileViewContent').style.display = 'none';
    document.getElementById('profileEditContent').style.display = 'none';
    document.getElementById('profilePasswordContent').style.display = 'none';
    
    document.getElementById('tabView').style.color = 'var(--gray)';
    document.getElementById('tabView').style.borderBottomColor = 'transparent';
    document.getElementById('tabEdit').style.color = 'var(--gray)';
    document.getElementById('tabEdit').style.borderBottomColor = 'transparent';
    document.getElementById('tabPassword').style.color = 'var(--gray)';
    document.getElementById('tabPassword').style.borderBottomColor = 'transparent';
    
    if (tab === 'view') {
        document.getElementById('profileViewContent').style.display = 'block';
        document.getElementById('tabView').style.color = 'var(--primary)';
        document.getElementById('tabView').style.borderBottomColor = 'var(--primary)';
    } else if (tab === 'edit') {
        document.getElementById('profileEditContent').style.display = 'block';
        document.getElementById('tabEdit').style.color = 'var(--primary)';
        document.getElementById('tabEdit').style.borderBottomColor = 'var(--primary)';
    } else if (tab === 'password') {
        document.getElementById('profilePasswordContent').style.display = 'block';
        document.getElementById('tabPassword').style.color = 'var(--primary)';
        document.getElementById('tabPassword').style.borderBottomColor = 'var(--primary)';
    }
}

function switchProfileTabFromHash() {
    const viewContent = document.getElementById('profileViewContent');
    const editContent = document.getElementById('profileEditContent');
    const passwordContent = document.getElementById('profilePasswordContent');
    if (!viewContent || !editContent || !passwordContent) {
        return;
    }

    const hash = (window.location.hash || '').toLowerCase();
    if (hash === '#edit') {
        switchProfileTab('edit');
    } else if (hash === '#password') {
        switchProfileTab('password');
    }
}

document.addEventListener('DOMContentLoaded', switchProfileTabFromHash);
window.addEventListener('hashchange', switchProfileTabFromHash);

function toggleProfileApplicantFields() {
    const applicantTypeSelect = document.getElementById('editApplicantType');
    if (!applicantTypeSelect) {
        return;
    }

    const schoolLabel = document.getElementById('editSchoolLabel');
    const courseLabel = document.getElementById('editCourseLabel');
    const admissionStatusLabel = document.getElementById('editAdmissionStatusLabel');
    const incomingFields = document.querySelectorAll('.edit-incoming-field');
    const currentFields = document.querySelectorAll('.edit-current-student-field');
    const shsSchool = document.getElementById('editShsSchool');
    const shsStrand = document.getElementById('editShsStrand');
    const shsGraduationYear = document.getElementById('editShsGraduationYear');
    const targetCollege = document.getElementById('editTargetCollege');
    const yearLevel = document.getElementById('editYearLevel');
    const enrollmentStatus = document.getElementById('editEnrollmentStatus');
    const admissionStatus = document.getElementById('editAdmissionStatus');

    const applicantType = applicantTypeSelect.value;
    const isIncoming = applicantType === 'incoming_freshman';
    const isCurrentStudent = applicantType === 'current_college' || applicantType === 'transferee' || applicantType === 'continuing_student';

    if (schoolLabel) {
        schoolLabel.textContent = isIncoming ? 'Current / Last School *' : 'College / University *';
    }
    if (courseLabel) {
        courseLabel.textContent = isIncoming ? 'Planned Course *' : 'Course *';
    }
    if (admissionStatusLabel) {
        admissionStatusLabel.textContent = isIncoming ? 'Admission Status *' : 'Admission / Enrollment Status';
    }

    incomingFields.forEach((field) => {
        field.style.display = isIncoming ? '' : 'none';
    });

    currentFields.forEach((field) => {
        field.style.display = isCurrentStudent ? '' : 'none';
    });

    if (shsSchool) shsSchool.required = isIncoming;
    if (shsStrand) shsStrand.required = isIncoming;
    if (shsGraduationYear) shsGraduationYear.required = isIncoming;
    if (targetCollege) targetCollege.required = isIncoming;
    if (yearLevel) yearLevel.required = isCurrentStudent;
    if (enrollmentStatus) enrollmentStatus.required = isCurrentStudent;
    if (admissionStatus) {
        admissionStatus.required = isIncoming;
        if (isCurrentStudent && !admissionStatus.value) {
            admissionStatus.value = 'enrolled';
        }
    }
}

document.getElementById('editApplicantType')?.addEventListener('change', toggleProfileApplicantFields);
toggleProfileApplicantFields();

function isValidProfileName(value) {
    return /^[A-Za-z\s\-'.]+$/.test(value);
}

function isValidProfileMiddleInitial(value) {
    return value === '' || /^[A-Za-z]$/.test(value);
}

// Handle Edit Profile Form Submission
document.getElementById('editProfileForm')?.addEventListener('submit', function(e) {
    e.preventDefault();

    const firstNameInput = document.getElementById('editFirstName');
    const middleInitialInput = document.getElementById('editMiddleInitial');
    const lastNameInput = document.getElementById('editLastName');
    const firstName = firstNameInput ? firstNameInput.value.trim() : '';
    const middleInitial = middleInitialInput ? middleInitialInput.value.trim() : '';
    const lastName = lastNameInput ? lastNameInput.value.trim() : '';

    if (!firstName) {
        Swal.fire({
            icon: 'error',
            title: 'Missing First Name',
            text: 'Please enter your first name.'
        }).then(() => firstNameInput?.focus());
        return;
    }

    if (!isValidProfileName(firstName)) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid First Name',
            text: 'First name should contain only letters, spaces, hyphens, or apostrophes. Numbers are not allowed.'
        }).then(() => firstNameInput?.focus());
        return;
    }

    if (!lastName) {
        Swal.fire({
            icon: 'error',
            title: 'Missing Last Name',
            text: 'Please enter your last name.'
        }).then(() => lastNameInput?.focus());
        return;
    }

    if (!isValidProfileName(lastName)) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Last Name',
            text: 'Last name should contain only letters, spaces, hyphens, or apostrophes. Numbers are not allowed.'
        }).then(() => lastNameInput?.focus());
        return;
    }

    if (!isValidProfileMiddleInitial(middleInitial)) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Middle Initial',
            text: 'Middle initial must be a single letter.'
        }).then(() => middleInitialInput?.focus());
        return;
    }
    
    const formData = new FormData(this);
    
    // Add action to identify this as profile update
    formData.append('action', 'update_profile');
    
    fetch('../Controller/user_profile_controller.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const noChanges = Boolean(data.no_changes);
            Swal.fire({
                icon: noChanges ? 'info' : 'success',
                title: noChanges ? 'No Changes' : 'Success!',
                text: data.message,
                timer: 2000
            }).then(() => {
                if (!noChanges) {
                    location.reload();
                }
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'An error occurred. Please try again.'
        });
    });
});

document.getElementById('changePasswordForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const currentPassword = document.getElementById('currentPassword').value;
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    const passwordContext = {
        username: this.dataset.username || '',
        email: this.dataset.email || '',
        name: this.dataset.name || ''
    };
    
    // Validate passwords
    if (!currentPassword) {
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'Current password is required.'
        });
        return;
    }
    
    if (newPassword !== confirmPassword) {
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'New passwords do not match.'
        });
        return;
    }
    
    const passwordValidation = window.PasswordPolicy
        ? window.PasswordPolicy.validate(newPassword, passwordContext)
        : {
            valid: newPassword.length >= 10
                && /[A-Z]/.test(newPassword)
                && /[a-z]/.test(newPassword)
                && /\d/.test(newPassword)
                && /[^A-Za-z0-9]/.test(newPassword),
            errors: ['Use at least 10 characters with uppercase, lowercase, number, and symbol.']
        };

    if (!passwordValidation.valid) {
        Swal.fire({
            icon: 'error',
            title: 'Weak Password',
            text: (passwordValidation.errors && passwordValidation.errors[0]) || 'Use at least 10 characters with uppercase, lowercase, number, and symbol.'
        });
        return;
    }
    
    // Use FormData from the form - this will automatically include the hidden action field
    const formData = new FormData(this);
    
    // Debug: Log what we're sending
    console.log('Sending password change request');
    for (let pair of formData.entries()) {
        console.log(pair[0] + ': ' + (pair[0].includes('password') ? '***' : pair[1]));
    }
    
    // Disable submit button and show loading state
    const submitButton = this.querySelector('button[type="submit"]');
    const originalButtonText = submitButton.innerHTML;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitButton.disabled = true;
    
    fetch('../Controller/user_profile_controller.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('Response:', data);
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 2000
            }).then(() => {
                // Clear form fields
                document.getElementById('currentPassword').value = '';
                document.getElementById('newPassword').value = '';
                document.getElementById('confirmPassword').value = '';
                // Switch back to view profile tab
                switchProfileTab('view');
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: data.message
            });
            // Re-enable submit button
            submitButton.innerHTML = originalButtonText;
            submitButton.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'An error occurred. Please try again.'
        });
        // Re-enable submit button
        submitButton.innerHTML = originalButtonText;
        submitButton.disabled = false;
    });
});
