<?php
header('Content-Type: application/json');

$paths = [
    'D:/TesseractOCR/tesseract.exe',
    'D:/Tesseract-OCR/tesseract.exe',
    'D:/Program Files/Tesseract-OCR/tesseract.exe',
    'C:/Program Files/Tesseract-OCR/tesseract.exe',
    '../../TesseractOCR/tesseract.exe'
];

$results = [];
foreach ($paths as $path) {
    $exists = file_exists($path);
    $results[$path] = [
        'exists' => $exists,
        'readable' => $exists ? is_readable($path) : false
    ];
    
    if ($exists) {
        // Try to run version command
        exec('"' . $path . '" --version 2>&1', $output, $returnCode);
        $results[$path]['version_test'] = [
            'return_code' => $returnCode,
            'output' => implode("\n", array_slice($output, 0, 3))
        ];
    }
}

echo json_encode([
    'current_directory' => __DIR__,
    'paths_tested' => $results,
    'php_version' => phpversion(),
    'is_windows' => strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
], JSON_PRETTY_PRINT);
?>