<?php
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'Asia/Manila');
}

if (!defined('APP_DB_TIMEZONE_OFFSET')) {
    define('APP_DB_TIMEZONE_OFFSET', getenv('APP_DB_TIMEZONE_OFFSET') ?: '+08:00');
}

if (!function_exists('applyAppTimezone')) {
    function applyAppTimezone(): void
    {
        date_default_timezone_set(APP_TIMEZONE);
    }
}

applyAppTimezone();
?>
