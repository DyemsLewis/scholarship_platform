class SweetAlertHelper {
    static showSuccess(title, text) {
        return Swal.fire({
            icon: 'success',
            title: title,
            text: text,
            confirmButtonColor: '#3085d6'
        });
    }

    static showError(title, text) {
        return Swal.fire({
            icon: 'error',
            title: title,
            text: text,
            confirmButtonColor: '#3085d6'
        });
    }

    static showWarning(title, text) {
        return Swal.fire({
            icon: 'warning',
            title: title,
            text: text,
            confirmButtonColor: '#3085d6'
        });
    }

    static showInfo(title, text) {
        return Swal.fire({
            icon: 'info',
            title: title,
            text: text,
            confirmButtonColor: '#3085d6'
        });
    }

    static showConfirm(title, text, confirmButtonText = 'Yes', cancelButtonText = 'Cancel') {
        return Swal.fire({
            title: title,
            text: text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: confirmButtonText,
            cancelButtonText: cancelButtonText
        });
    }

    static showMultipleErrors(title, errors) {
        let errorList = '';
        if (Array.isArray(errors)) {
            errorList = errors.join('<br>');
        } else {
            errorList = errors;
        }

        return Swal.fire({
            icon: 'error',
            title: title,
            html: errorList,
            confirmButtonColor: '#3085d6'
        });
    }

    static showSessionMessages() {
        const successMessage = document.querySelector('[data-swal-success]');
        if (successMessage) {
            const title = successMessage.getAttribute('data-swal-title') || 'Success!';
            this.showSuccess(title, successMessage.textContent);
            successMessage.remove();
        }

        const errorMessage = document.querySelector('[data-swal-error]');
        if (errorMessage) {
            const title = errorMessage.getAttribute('data-swal-title') || 'Error!';
            this.showError(title, errorMessage.textContent);
            errorMessage.remove();
        }

        const errorsMessage = document.querySelector('[data-swal-errors]');
        if (errorsMessage) {
            const title = errorsMessage.getAttribute('data-swal-title') || 'Registration Error';
            const errors = JSON.parse(errorsMessage.textContent);
            this.showMultipleErrors(title, errors);
            errorsMessage.remove();
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    SweetAlertHelper.showSessionMessages();
});

// Export for module usage (if using ES6 modules)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SweetAlertHelper;
}