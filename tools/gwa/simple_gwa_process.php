<?php
// simple_gwa_process.php
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log file for debugging
$debugLog = __DIR__ . '/../../storage/logs/ocr_debug.log';
file_put_contents($debugLog, "=== " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

function debugLog($message) {
    global $debugLog;
    file_put_contents($debugLog, $message . "\n", FILE_APPEND);
}

debugLog("Script started");

// Philippine grading: 1.0 (highest) to 5.0 (lowest)
define('GWA_HIGHEST', 1.0);
define('GWA_LOWEST', 5.0);

// Tesseract path
define('TESSERACT_PATH', 'D:/TesseractOCR/tesseract.exe');

// Check file upload
if (!isset($_FILES['grade_file']) || $_FILES['grade_file']['error'] !== UPLOAD_ERR_OK) {
    debugLog("Upload error");
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['grade_file'];
debugLog("File received: " . $file['name']);
debugLog("File size: " . $file['size']);

$uploadDir = __DIR__ . '/../../public/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Save file
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$filename = 'gwa_' . time() . '_' . uniqid() . '.' . $extension;
$filepath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    debugLog("Failed to save file");
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit;
}

debugLog("File saved to: " . $filepath);

// Preprocess the image for better OCR
$preprocessedPath = preprocessImage($filepath);
debugLog("Preprocessed image: " . $preprocessedPath);

// Extract text from the preprocessed image
$extractedText = extractTextFromImage($preprocessedPath);
debugLog("Extracted text: " . $extractedText);

// Try multiple methods to find GWA
$extractedValue = findGWAInText($extractedText);
debugLog("Extracted GWA: " . ($extractedValue ?? 'null'));

$convertedGWA = convertToPhilippineGWA($extractedValue);
debugLog("Converted GWA: " . ($convertedGWA ?? 'null'));

// Clean up preprocessed image
if ($preprocessedPath != $filepath && file_exists($preprocessedPath)) {
    unlink($preprocessedPath);
}

$response = [
    'success' => true,
    'gwa' => $convertedGWA,
    'original_value' => $extractedValue,
    'file' => [
        'original_name' => $file['name'],
        'saved_as' => $filename
    ],
    'method' => 'ocr_extraction_with_preprocessing',
    'raw_text' => $extractedText
];

echo json_encode($response);

/**
 * Preprocess image for better OCR
 */
function preprocessImage($filepath) {
    debugLog("preprocessImage started");
    
    // Check if GD library is available
    if (!extension_loaded('gd')) {
        debugLog("GD library not found, skipping preprocessing");
        return $filepath;
    }
    
    // Get image info
    list($width, $height, $type) = getimagesize($filepath);
    debugLog("Original image: {$width}x{$height}, type: $type");
    
    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $img = imagecreatefromjpeg($filepath);
            break;
        case IMAGETYPE_PNG:
            $img = imagecreatefrompng($filepath);
            break;
        case IMAGETYPE_GIF:
            $img = imagecreatefromgif($filepath);
            break;
        default:
            debugLog("Unsupported image type");
            return $filepath;
    }
    
    if (!$img) {
        debugLog("Failed to create image resource");
        return $filepath;
    }
    
    // Convert to grayscale
    imagefilter($img, IMG_FILTER_GRAYSCALE);
    debugLog("Applied grayscale filter");
    
    // Increase contrast
    imagefilter($img, IMG_FILTER_CONTRAST, -30);
    debugLog("Applied contrast filter");
    
    // Brighten a bit
    imagefilter($img, IMG_FILTER_BRIGHTNESS, 10);
    debugLog("Applied brightness filter");
    
    // Sharpen the image
    imagefilter($img, IMG_FILTER_MEAN_REMOVAL);
    debugLog("Applied sharpening filter");
    
    // Resize if too large (Tesseract works better with 300dpi equivalent)
    $maxDimension = 2000;
    if ($width > $maxDimension || $height > $maxDimension) {
        $ratio = min($maxDimension / $width, $maxDimension / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
        
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($img);
        $img = $resized;
        debugLog("Resized to {$newWidth}x{$newHeight}");
    }
    
    // Save preprocessed image
    $preprocessedPath = $filepath . '_preprocessed.jpg';
    imagejpeg($img, $preprocessedPath, 90);
    imagedestroy($img);
    
    debugLog("Saved preprocessed image to: " . $preprocessedPath);
    return $preprocessedPath;
}

/**
 * Extract text from image using Tesseract OCR
 */
function extractTextFromImage($filepath) {
    debugLog("extractTextFromImage started");
    
    if (!file_exists(TESSERACT_PATH)) {
        debugLog("Tesseract not found");
        return "";
    }
    
    // Create temp directory
    $tempDir = __DIR__ . '/../../public/temp/';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    $outputFile = $tempDir . pathinfo($filepath, PATHINFO_FILENAME);
    
    // Try different PSM modes for better table recognition
    $psmModes = [6, 3, 4, 11, 12];
    $allText = "";
    
    foreach ($psmModes as $psm) {
        debugLog("Trying PSM mode: $psm");
        $command = '"' . TESSERACT_PATH . '" "' . $filepath . '" "' . $outputFile . '_' . $psm . '" -l eng --psm ' . $psm;
        exec($command . ' 2>&1', $output, $returnCode);
        
        $outputTxtFile = $outputFile . '_' . $psm . '.txt';
        if (file_exists($outputTxtFile)) {
            $text = file_get_contents($outputTxtFile);
            $allText .= "\n--- PSM $psm ---\n" . $text;
            unlink($outputTxtFile);
        }
    }
    
    if (!empty($allText)) {
        debugLog("Total extracted text length: " . strlen($allText));
        return $allText;
    }
    
    return "";
}

/**
 * Find GWA in text using multiple strategies
 */
function findGWAInText($text) {
    debugLog("findGWAInText started");
    
    // Clean up the text - remove excessive spaces and line breaks
    $text = preg_replace('/\s+/', ' ', $text);
    debugLog("Cleaned text: " . substr($text, 0, 200));
    
    // Strategy 1: Look for labeled averages
    $patterns = [
        '/GENERAL\s*AVERAGE[:\s]*([0-9]+\.?[0-9]*)/i',
        '/GENERAL\s*WEIGHTED\s*AVERAGE[:\s]*([0-9]+\.?[0-9]*)/i',
        '/GEN\s*AVG[:\s]*([0-9]+\.?[0-9]*)/i',
        '/OVERALL\s*AVERAGE[:\s]*([0-9]+\.?[0-9]*)/i',
        '/GWA[:\s]*([0-9]+\.?[0-9]*)/i',
        '/AVERAGE[:\s]*([0-9]+\.?[0-9]*)/i',
        '/FINAL\s*GRADE[:\s]*([0-9]+\.?[0-9]*)/i',
        '/GPA[:\s]*([0-9]+\.?[0-9]*)/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $num = floatval($matches[1]);
            debugLog("Found labeled number: $num from pattern: $pattern");
            if (($num >= 1.0 && $num <= 5.0) || ($num >= 60 && $num <= 100)) {
                return $num;
            }
        }
    }
    
    // Strategy 2: Look for numbers near grade-related words
    $gradeKeywords = ['grade', 'average', 'gwa', 'semester', 'term', 'final', 'general'];
    $words = explode(' ', $text);
    
    for ($i = 0; $i < count($words); $i++) {
        foreach ($gradeKeywords as $keyword) {
            if (stripos($words[$i], $keyword) !== false) {
                // Check next few words for numbers
                for ($j = 1; $j <= 3; $j++) {
                    if (isset($words[$i + $j]) && preg_match('/([0-9]+\.?[0-9]*)/', $words[$i + $j], $matches)) {
                        $num = floatval($matches[1]);
                        debugLog("Found number near keyword '$keyword': $num");
                        if (($num >= 1.0 && $num <= 5.0) || ($num >= 60 && $num <= 100)) {
                            return $num;
                        }
                    }
                }
            }
        }
    }
    
    // Strategy 3: Extract all numbers and find the most reasonable GWA
    preg_match_all('/([0-9]+\.?[0-9]*)/', $text, $matches);
    $numbers = $matches[1];
    
    if (count($numbers) > 0) {
        debugLog("All numbers found: " . implode(', ', $numbers));
        
        // Filter to valid grade ranges
        $validNumbers = array_filter($numbers, function($n) {
            $n = floatval($n);
            return ($n >= 1.0 && $n <= 5.0) || ($n >= 60 && $n <= 100);
        });
        
        if (count($validNumbers) > 0) {
            // Convert to float values
            $validNumbers = array_map('floatval', $validNumbers);
            
            // Get the most frequent number
            $freq = array_count_values($validNumbers);
            arsort($freq);
            $mostFrequent = key($freq);
            debugLog("Most frequent valid number: $mostFrequent");
            return $mostFrequent;
        }
    }
    
    debugLog("No GWA found");
    return null;
}

/**
 * Convert to Philippine GWA scale
 */
function convertToPhilippineGWA($value) {
    debugLog("convertToPhilippineGWA called with: " . ($value ?? 'null'));
    
    if ($value === null) return null;
    
    // If already in 1.0-5.0 range, return as-is
    if ($value >= 1.0 && $value <= 5.0) {
        debugLog("Already in GWA scale: $value");
        return round($value, 2);
    }
    
    // Convert percentage to GWA
    if ($value >= 60 && $value <= 100) {
        $converted = convertPercentageToGWA($value);
        debugLog("Converted $value to $converted");
        return $converted;
    }
    
    return $value;
}

/**
 * Convert percentage to Philippine GWA
 */
function convertPercentageToGWA($percentage) {
    if ($percentage >= 98) return 1.0;
    if ($percentage >= 95) return 1.25;
    if ($percentage >= 92) return 1.5;
    if ($percentage >= 89) return 1.75;
    if ($percentage >= 86) return 2.0;
    if ($percentage >= 83) return 2.25;
    if ($percentage >= 80) return 2.5;
    if ($percentage >= 77) return 2.75;
    if ($percentage >= 75) return 3.0;
    return 5.0;
}
?>
