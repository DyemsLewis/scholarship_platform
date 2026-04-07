<?php
$logFile = __DIR__ . '/../../storage/logs/ocr_debug.log';
if (file_exists($logFile)) {
    echo file_get_contents($logFile);
} else {
    echo "No log file yet.";
}
?>
