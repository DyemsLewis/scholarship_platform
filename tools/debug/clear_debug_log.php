<?php
$logFile = __DIR__ . '/../../storage/logs/ocr_debug.log';
if (file_exists($logFile)) {
    unlink($logFile);
}
echo "Log cleared";
?>
