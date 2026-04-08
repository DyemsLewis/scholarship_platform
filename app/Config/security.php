<?php

require_once __DIR__ . '/session_bootstrap.php';

if (!function_exists('securityClientIp')) {
    function securityClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!$candidate) {
                continue;
            }

            $parts = array_map('trim', explode(',', (string) $candidate));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }

        return 'unknown';
    }
}

if (!function_exists('securityStorageDirectory')) {
    function securityStorageDirectory(): string
    {
        $directory = __DIR__ . '/../storage/security';
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }
}

if (!function_exists('securityRateLimitPath')) {
    function securityRateLimitPath(string $bucket): string
    {
        return securityStorageDirectory() . '/' . hash('sha256', $bucket) . '.json';
    }
}

if (!function_exists('securityReadRateLimitState')) {
    function securityReadRateLimitState(string $bucket): array
    {
        $path = securityRateLimitPath($bucket);
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}

if (!function_exists('securityWriteRateLimitState')) {
    function securityWriteRateLimitState(string $bucket, array $timestamps): void
    {
        $path = securityRateLimitPath($bucket);
        file_put_contents($path, json_encode(array_values($timestamps), JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

if (!function_exists('securityBuildRateLimitBucket')) {
    function securityBuildRateLimitBucket(string $scope, string $identifier = ''): string
    {
        return strtolower(trim($scope)) . '|' . securityClientIp() . '|' . strtolower(trim($identifier));
    }
}

if (!function_exists('securityGetRateLimitStatus')) {
    function securityGetRateLimitStatus(string $bucket, int $maxAttempts, int $windowSeconds): array
    {
        $now = time();
        $windowStart = $now - max(1, $windowSeconds);
        $attempts = array_values(array_filter(
            securityReadRateLimitState($bucket),
            static fn($timestamp): bool => is_numeric($timestamp) && (int) $timestamp >= $windowStart
        ));

        securityWriteRateLimitState($bucket, $attempts);

        if (count($attempts) >= max(1, $maxAttempts)) {
            $oldestRelevantAttempt = (int) $attempts[0];
            $retryAfter = max(1, $windowSeconds - ($now - $oldestRelevantAttempt));

            return [
                'blocked' => true,
                'retry_after' => $retryAfter,
                'attempts' => count($attempts),
            ];
        }

        return [
            'blocked' => false,
            'retry_after' => 0,
            'attempts' => count($attempts),
        ];
    }
}

if (!function_exists('securityRegisterRateLimitAttempt')) {
    function securityRegisterRateLimitAttempt(string $bucket, int $windowSeconds): void
    {
        $now = time();
        $windowStart = $now - max(1, $windowSeconds);
        $attempts = array_values(array_filter(
            securityReadRateLimitState($bucket),
            static fn($timestamp): bool => is_numeric($timestamp) && (int) $timestamp >= $windowStart
        ));
        $attempts[] = $now;
        securityWriteRateLimitState($bucket, $attempts);
    }
}

if (!function_exists('securityClearRateLimit')) {
    function securityClearRateLimit(string $bucket): void
    {
        $path = securityRateLimitPath($bucket);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
