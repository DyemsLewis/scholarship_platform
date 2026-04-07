(function (window) {
    const config = {
        minLength: 10
    };

    function validate(password, context) {
        const value = String(password || '');
        const errors = [];

        if (value.length < config.minLength) {
            errors.push(`Password must be at least ${config.minLength} characters long.`);
        }

        if (!/[A-Z]/.test(value)) {
            errors.push('Password must include at least one uppercase letter.');
        }

        if (!/[a-z]/.test(value)) {
            errors.push('Password must include at least one lowercase letter.');
        }

        if (!/\d/.test(value)) {
            errors.push('Password must include at least one number.');
        }

        if (!/[^A-Za-z0-9]/.test(value)) {
            errors.push('Password must include at least one special character.');
        }

        return {
            valid: errors.length === 0,
            errors: Array.from(new Set(errors))
        };
    }

    window.PasswordPolicy = {
        config,
        hint: `Use at least ${config.minLength} characters with uppercase, lowercase, number, and symbol.`,
        validate
    };
})(window);
