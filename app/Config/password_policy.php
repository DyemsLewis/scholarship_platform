<?php

if (!defined('APP_PASSWORD_MIN_LENGTH')) {
    define('APP_PASSWORD_MIN_LENGTH', 10);
}

if (!function_exists('passwordPolicyMinLength')) {
    function passwordPolicyMinLength(): int
    {
        return APP_PASSWORD_MIN_LENGTH;
    }
}

if (!function_exists('passwordPolicyHint')) {
    function passwordPolicyHint(): string
    {
        return 'Use at least ' . passwordPolicyMinLength() . ' characters with uppercase, lowercase, number, and symbol.';
    }
}

if (!function_exists('passwordPolicyChecklist')) {
    function passwordPolicyChecklist(): array
    {
        return [
            'At least ' . passwordPolicyMinLength() . ' characters',
            'At least one uppercase letter',
            'At least one lowercase letter',
            'At least one number',
            'At least one special character',
        ];
    }
}

if (!function_exists('passwordPolicyNormalizeComparisonValue')) {
    function passwordPolicyNormalizeComparisonValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        return preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';
    }
}

if (!function_exists('passwordPolicyCommonWeakPasswords')) {
    function passwordPolicyCommonWeakPasswords(): array
    {
        return [
            '123123',
            '123456',
            '1234567',
            '12345678',
            '123456789',
            '1234567890',
            '111111',
            '000000',
            'abc123',
            'admin',
            'admin123',
            'letmein',
            'password',
            'password1',
            'password123',
            'qwerty',
            'qwerty123',
            'welcome',
            'welcome123',
        ];
    }
}

if (!function_exists('passwordPolicyExtractPersonalTerms')) {
    function passwordPolicyExtractPersonalTerms(array $context = []): array
    {
        $values = [];

        foreach (['username', 'firstname', 'lastname', 'name'] as $key) {
            $candidate = trim((string) ($context[$key] ?? ''));
            if ($candidate !== '') {
                $values[] = $candidate;
            }
        }

        $email = trim((string) ($context['email'] ?? ''));
        if ($email !== '') {
            $localPart = strtolower((string) strstr($email, '@', true));
            if ($localPart !== '') {
                $values[] = $localPart;
            }
        }

        $terms = [];
        foreach ($values as $value) {
            foreach (preg_split('/[^A-Za-z0-9]+/', strtolower($value)) ?: [] as $term) {
                $term = trim($term);
                if ($term !== '' && strlen($term) >= 4) {
                    $terms[$term] = true;
                }
            }
        }

        return array_keys($terms);
    }
}

if (!function_exists('validateStrongPassword')) {
    function validateStrongPassword(string $password, array $context = []): array
    {
        $errors = [];
        $minLength = passwordPolicyMinLength();

        if (strlen($password) < $minLength) {
            $errors[] = 'Password must be at least ' . $minLength . ' characters long.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must include at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must include at least one lowercase letter.';
        }

        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Password must include at least one number.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must include at least one special character.';
        }

        return [
            'valid' => empty($errors),
            'errors' => array_values(array_unique($errors)),
        ];
    }
}
