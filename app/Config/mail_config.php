<?php

if (!function_exists('mailConfigValue')) {
    function mailConfigValue($key, $default = '') {
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        return $default;
    }
}

$smtpUsername = trim((string) mailConfigValue('SCHOLARSHIP_SMTP_USERNAME', 'manuelsalditos@gmail.com'));
$smtpPassword = trim((string) mailConfigValue('SCHOLARSHIP_SMTP_PASSWORD', 'rmzrhetsedbevker'));
$fromEmail = trim((string) mailConfigValue('SCHOLARSHIP_SMTP_FROM_EMAIL', $smtpUsername));
$replyTo = trim((string) mailConfigValue('SCHOLARSHIP_SMTP_REPLY_TO', $fromEmail));

return [
    'host' => mailConfigValue('SCHOLARSHIP_SMTP_HOST', 'smtp.gmail.com'),
    'port' => (int) mailConfigValue('SCHOLARSHIP_SMTP_PORT', 587),
    'encryption' => mailConfigValue('SCHOLARSHIP_SMTP_ENCRYPTION', 'tls'),
    'username' => $smtpUsername,
    'password' => $smtpPassword,
    'from_email' => $fromEmail,
    'from_name' => mailConfigValue('SCHOLARSHIP_SMTP_FROM_NAME', 'Scholarship Finder'),
    'reply_to' => $replyTo,
    'timeout' => (int) mailConfigValue('SCHOLARSHIP_SMTP_TIMEOUT', 20),
    'verify_peer' => filter_var(mailConfigValue('SCHOLARSHIP_SMTP_VERIFY_PEER', 'true'), FILTER_VALIDATE_BOOLEAN),
    'log_file' => __DIR__ . '/../storage/logs/smtp_mail.log',
    'configured' => $smtpUsername !== '' && $smtpPassword !== '' && $fromEmail !== '',
];
?>
