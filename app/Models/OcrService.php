<?php

class OcrService
{
    private const DEFAULT_CONFIG = [
        'mode' => 'auto',
        'ocr_space_api_key' => '',
        'ocr_space_endpoint' => 'https://api.ocr.space/parse/image',
        'ocr_space_timeout' => 45,
        'ocr_space_language' => 'eng',
        'ocr_space_engine' => 2,
        'ocr_space_detect_orientation' => true,
        'ocr_space_scale' => true,
        'ocr_space_is_table' => false,
        'ocr_space_max_upload_bytes' => 1048576,
        'remote_url' => '',
        'remote_api_key' => '',
        'remote_timeout' => 45,
        'endpoint_api_key' => '',
        'local_tesseract_candidates' => []
    ];

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png'
    ];

    private array $config;

    public function __construct(array $config = [])
    {
        $loadedConfig = self::loadConfig();
        $this->config = array_merge(self::DEFAULT_CONFIG, $loadedConfig, $config);
        $this->config['remote_timeout'] = max(10, (int) ($this->config['remote_timeout'] ?? 45));
        $this->config['ocr_space_timeout'] = max(10, (int) ($this->config['ocr_space_timeout'] ?? 45));
        $this->config['ocr_space_engine'] = max(1, min(3, (int) ($this->config['ocr_space_engine'] ?? 2)));
        $this->config['ocr_space_max_upload_bytes'] = max(0, (int) ($this->config['ocr_space_max_upload_bytes'] ?? 1048576));
    }

    public static function loadConfig(): array
    {
        $configPath = __DIR__ . '/../Config/ocr_config.php';
        if (!file_exists($configPath)) {
            return self::DEFAULT_CONFIG;
        }

        $config = require $configPath;
        return is_array($config) ? $config : self::DEFAULT_CONFIG;
    }

    public function getEffectiveMode(): string
    {
        $mode = strtolower(trim((string) ($this->config['mode'] ?? 'auto')));
        if ($mode === 'ocr_space') {
            return 'ocr_space';
        }
        if ($mode === 'remote_api') {
            return 'remote_api';
        }
        if ($mode === 'local') {
            return 'local';
        }

        $tesseractPath = $this->detectTesseractPath();
        if ($tesseractPath !== null) {
            return 'local';
        }

        if (trim((string) ($this->config['ocr_space_api_key'] ?? '')) !== '') {
            return 'ocr_space';
        }

        if (trim((string) ($this->config['remote_url'] ?? '')) !== '') {
            return 'remote_api';
        }

        return 'local';
    }

    public function getProviderLabel(): string
    {
        $mode = $this->getEffectiveMode();
        if ($mode === 'ocr_space') {
            return 'ocr_space';
        }
        if ($mode === 'remote_api') {
            return 'remote_api';
        }
        return 'local_tesseract';
    }

    public function processDocument(string $inputPath, string $mimeType, ?string $originalFilename = null): array
    {
        if (!file_exists($inputPath)) {
            return $this->errorResult('Scanner could not start because the uploaded file was not found.');
        }

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return $this->errorResult('Scanner only supports PDF, JPG, and PNG files.');
        }

        $mode = $this->getEffectiveMode();
        if ($mode === 'ocr_space') {
            return $this->processOcrSpaceDocument($inputPath, $mimeType, $originalFilename);
        }

        if ($mode === 'remote_api') {
            return $this->processRemoteDocument($inputPath, $mimeType, $originalFilename);
        }

        return $this->processLocalDocument($inputPath, $mimeType);
    }

    private function processLocalDocument(string $inputPath, string $mimeType): array
    {
        $tesseractPath = $this->detectTesseractPath();
        if ($tesseractPath === null) {
            return $this->errorResult('Local scanner service was not found on this server.');
        }

        $ocrInputPath = $inputPath;
        $temporaryFiles = [];

        if ($mimeType === 'application/pdf') {
            $convertedImage = $this->convertPdfFirstPageToImage($inputPath);
            if ($convertedImage === null) {
                return $this->errorResult('PDF scanning needs Imagick on the scan server. Use the hosted scanner service or the remote scanner service if Imagick is unavailable.');
            }
            $ocrInputPath = $convertedImage;
            $temporaryFiles[] = $convertedImage;
        } else {
            $preprocessedImage = $this->preprocessImageForOcr($inputPath);
            if ($preprocessedImage !== null) {
                $ocrInputPath = $preprocessedImage;
                $temporaryFiles[] = $preprocessedImage;
            }
        }

        try {
            $ocrText = $this->extractTextUsingTesseract($tesseractPath, $ocrInputPath);
            return $this->buildSuccessResult($ocrText, 'local_tesseract');
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_string($temporaryFile) && $temporaryFile !== '' && file_exists($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }
    }

    private function processOcrSpaceDocument(string $inputPath, string $mimeType, ?string $originalFilename = null): array
    {
        $apiKey = trim((string) ($this->config['ocr_space_api_key'] ?? ''));
        if ($apiKey === '') {
            return $this->errorResult('The hosted scanner service is enabled, but no API key is configured.', 'ocr_space');
        }

        if (!function_exists('curl_init') || !class_exists('CURLFile')) {
            return $this->errorResult('The hosted scanner service needs the PHP cURL extension.', 'ocr_space');
        }

        $maxUploadBytes = (int) ($this->config['ocr_space_max_upload_bytes'] ?? 0);
        if ($maxUploadBytes > 0 && filesize($inputPath) > $maxUploadBytes) {
            return $this->errorResult(
                'The current scanner service limit is ' . number_format($maxUploadBytes / 1048576, 2) .
                ' MB per file. The upload was still accepted and saved to documents, but automatic scanning will not finish until the file is compressed or the scanner upload limit is increased.',
                'ocr_space'
            );
        }

        $endpoint = trim((string) ($this->config['ocr_space_endpoint'] ?? 'https://api.ocr.space/parse/image'));
        $postFields = [
            'file' => new CURLFile($inputPath, $mimeType, $originalFilename ?: basename($inputPath)),
            'language' => trim((string) ($this->config['ocr_space_language'] ?? 'eng')) ?: 'eng',
            'isOverlayRequired' => 'false',
            'detectOrientation' => !empty($this->config['ocr_space_detect_orientation']) ? 'true' : 'false',
            'scale' => !empty($this->config['ocr_space_scale']) ? 'true' : 'false',
            'isTable' => !empty($this->config['ocr_space_is_table']) ? 'true' : 'false',
            'OCREngine' => (string) ((int) ($this->config['ocr_space_engine'] ?? 2))
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int) ($this->config['ocr_space_timeout'] ?? 45),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'apikey: ' . $apiKey
            ]
        ]);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($responseBody === false || $responseBody === '' || $curlError !== '') {
            $message = 'Scanner service request failed.';
            if ($curlError !== '') {
                $message .= ' ' . $curlError;
            }
            return $this->errorResult($message, 'ocr_space');
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return $this->errorResult('Scanner service returned an invalid response.', 'ocr_space');
        }

        if (!empty($decoded['IsErroredOnProcessing'])) {
            return $this->errorResult($this->extractOcrSpaceErrorMessage($decoded, $httpCode), 'ocr_space');
        }

        $parsedResults = isset($decoded['ParsedResults']) && is_array($decoded['ParsedResults'])
            ? $decoded['ParsedResults']
            : [];

        $textParts = [];
        foreach ($parsedResults as $parsedResult) {
            $parsedText = trim((string) ($parsedResult['ParsedText'] ?? ''));
            if ($parsedText !== '') {
                $textParts[] = $parsedText;
            }
        }

        $text = trim(implode("\n", $textParts));
        if ($text === '') {
            $text = trim((string) ($decoded['SearchablePDFURL'] ?? ''));
        }

        return $this->buildSuccessResult($text, 'ocr_space');
    }

    private function extractOcrSpaceErrorMessage(array $decoded, int $httpCode = 0): string
    {
        $messages = [];

        if (isset($decoded['ErrorMessage'])) {
            if (is_array($decoded['ErrorMessage'])) {
                foreach ($decoded['ErrorMessage'] as $errorMessage) {
                    $errorMessage = trim((string) $errorMessage);
                    if ($errorMessage !== '') {
                        $messages[] = $errorMessage;
                    }
                }
            } else {
                $errorMessage = trim((string) $decoded['ErrorMessage']);
                if ($errorMessage !== '') {
                    $messages[] = $errorMessage;
                }
            }
        }

        $details = trim((string) ($decoded['ErrorDetails'] ?? ''));
        if ($details !== '') {
            $messages[] = $details;
        }

        if (empty($messages) && isset($decoded['OCRExitCode'])) {
            $messages[] = 'Scanner service failed with exit code ' . (string) $decoded['OCRExitCode'] . '.';
        }

        $message = empty($messages)
            ? 'Scanner service could not process the document.'
            : implode(' ', array_unique($messages));

        if ($httpCode >= 400) {
            $message = 'Scanner service error (' . $httpCode . '): ' . $message;
        }

        return $message;
    }

    private function processRemoteDocument(string $inputPath, string $mimeType, ?string $originalFilename = null): array
    {
        $remoteUrl = trim((string) ($this->config['remote_url'] ?? ''));
        if ($remoteUrl === '') {
            return $this->errorResult('The remote scanner service is enabled, but it is not configured yet.', 'remote_api');
        }

        if (!function_exists('curl_init') || !class_exists('CURLFile')) {
            return $this->errorResult('The remote scanner service needs the PHP cURL extension.', 'remote_api');
        }

        $postFields = [
            'document' => new CURLFile($inputPath, $mimeType, $originalFilename ?: basename($inputPath)),
            'include_text' => '1'
        ];

        $headers = ['Accept: application/json'];
        $apiKey = trim((string) ($this->config['remote_api_key'] ?? ''));
        if ($apiKey !== '') {
            $headers[] = 'X-OCR-API-Key: ' . $apiKey;
        }

        $ch = curl_init($remoteUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int) ($this->config['remote_timeout'] ?? 45),
            CURLOPT_HTTPHEADER => $headers
        ]);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($responseBody === false || $responseBody === '' || $curlError !== '') {
            $message = 'Remote scanner service request failed.';
            if ($curlError !== '') {
                $message .= ' ' . $curlError;
            }
            return $this->errorResult($message, 'remote_api');
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return $this->errorResult('Remote scanner service returned an invalid response.', 'remote_api');
        }

        if (!($decoded['success'] ?? false)) {
            $remoteMessage = trim((string) ($decoded['message'] ?? 'Remote scanner service could not process the document.'));
            if ($httpCode >= 400) {
                $remoteMessage = 'Remote scanner service error (' . $httpCode . '): ' . $remoteMessage;
            }
            return $this->errorResult($remoteMessage, 'remote_api');
        }

        $text = trim((string) ($decoded['text'] ?? $decoded['ocr_text'] ?? ''));
        $analysis = is_array($decoded['analysis'] ?? null)
            ? $decoded['analysis']
            : self::analyzeGwaFromText($text);

        $rawValue = null;
        if (isset($decoded['raw_gwa']) && is_numeric($decoded['raw_gwa'])) {
            $rawValue = (float) $decoded['raw_gwa'];
        } elseif (isset($decoded['raw_value']) && is_numeric($decoded['raw_value'])) {
            $rawValue = (float) $decoded['raw_value'];
        } elseif (isset($analysis['raw_value']) && is_numeric($analysis['raw_value'])) {
            $rawValue = (float) $analysis['raw_value'];
        }

        $finalGwa = null;
        if (isset($decoded['final_gwa']) && is_numeric($decoded['final_gwa'])) {
            $finalGwa = (float) $decoded['final_gwa'];
        } else {
            $finalGwa = self::convertToPhilippineGwa($rawValue);
        }

        return [
            'success' => true,
            'provider' => 'remote_api',
            'text' => $text,
            'analysis' => $analysis,
            'raw_gwa' => $rawValue,
            'final_gwa' => $finalGwa,
            'scanner_message' => trim((string) ($decoded['scanner_message'] ?? '')) !== ''
                ? (string) $decoded['scanner_message']
                : self::buildScannerStatusMessage($finalGwa, $rawValue)
        ];
    }

    private function buildSuccessResult(string $ocrText, string $provider): array
    {
        $analysis = self::analyzeGwaFromText($ocrText);
        $rawGwa = isset($analysis['raw_value']) && is_numeric($analysis['raw_value'])
            ? (float) $analysis['raw_value']
            : null;
        $finalGwa = self::convertToPhilippineGwa($rawGwa);

        return [
            'success' => true,
            'provider' => $provider,
            'text' => $ocrText,
            'analysis' => $analysis,
            'raw_gwa' => $rawGwa,
            'final_gwa' => $finalGwa,
            'scanner_message' => self::buildScannerStatusMessage($finalGwa, $rawGwa)
        ];
    }

    private function errorResult(string $message, string $provider = ''): array
    {
        return [
            'success' => false,
            'provider' => $provider !== '' ? $provider : $this->getProviderLabel(),
            'text' => '',
            'analysis' => [
                'raw_value' => null,
                'method' => 'service_error',
                'basis' => $message,
                'semester_values' => [],
                'candidates' => []
            ],
            'raw_gwa' => null,
            'final_gwa' => null,
            'scanner_message' => $message,
            'message' => $message
        ];
    }

    private function detectTesseractPath(): ?string
    {
        $candidates = $this->config['local_tesseract_candidates'] ?? [];
        if (!is_array($candidates)) {
            $candidates = [];
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (strpos($candidate, DIRECTORY_SEPARATOR) !== false || strpos($candidate, ':') !== false) {
                if (!file_exists($candidate)) {
                    continue;
                }
            }

            $output = [];
            $returnCode = 1;
            $command = escapeshellarg($candidate) . ' --version 2>&1';
            @exec($command, $output, $returnCode);
            if ($returnCode === 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }

    private function preprocessImageForOcr(string $imagePath): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $imageInfo = @getimagesize($imagePath);
        if (!$imageInfo || empty($imageInfo[2])) {
            return null;
        }

        switch ((int) $imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $img = @imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $img = @imagecreatefrompng($imagePath);
                break;
            default:
                return null;
        }

        if (!$img) {
            return null;
        }

        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_CONTRAST, -30);
        imagefilter($img, IMG_FILTER_BRIGHTNESS, 10);
        imagefilter($img, IMG_FILTER_MEAN_REMOVAL);

        $preprocessedPath = $imagePath . '.preprocessed.jpg';
        $saved = @imagejpeg($img, $preprocessedPath, 90);
        imagedestroy($img);

        if (!$saved || !file_exists($preprocessedPath)) {
            return null;
        }

        return $preprocessedPath;
    }

    private function convertPdfFirstPageToImage(string $pdfPath): ?string
    {
        if (!class_exists('Imagick')) {
            return null;
        }

        try {
            $imagick = new Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($pdfPath . '[0]');
            $imagick->setImageFormat('png');
            $imagick->setImageCompressionQuality(95);
            $outputPath = $pdfPath . '.page1.png';
            $imagick->writeImage($outputPath);
            $imagick->clear();
            $imagick->destroy();

            return file_exists($outputPath) ? $outputPath : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function extractTextUsingTesseract(string $tesseractPath, string $inputPath): string
    {
        $tempDir = __DIR__ . '/../public/temp/ocr/';
        $this->ensureDirectory($tempDir);

        $psmModes = [6, 4, 11];
        $allText = '';

        foreach ($psmModes as $psm) {
            $outputBase = $tempDir . 'ocr_' . uniqid('', true) . '_psm' . $psm;
            $command = escapeshellarg($tesseractPath) . ' ' .
                escapeshellarg($inputPath) . ' ' .
                escapeshellarg($outputBase) .
                ' -l eng --oem 1 --psm ' . (int) $psm . ' 2>&1';

            $output = [];
            $returnCode = 1;
            @exec($command, $output, $returnCode);

            $txtFile = $outputBase . '.txt';
            if (file_exists($txtFile)) {
                $text = (string) file_get_contents($txtFile);
                if (trim($text) !== '') {
                    $allText .= "\n" . $text;
                }
                @unlink($txtFile);
            }
        }

        return trim($allText);
    }

    public static function buildScannerStatusMessage(?float $finalGwa, ?float $rawGwaValue): string
    {
        if ($finalGwa !== null) {
            $message = 'Scanner result: academic score detected (' . number_format($finalGwa, 2) . ').';
            if ($rawGwaValue !== null && abs($rawGwaValue - $finalGwa) > 0.0001) {
                $message = 'Scanner result: academic score detected. Raw value ' . number_format($rawGwaValue, 2) .
                    ' converted to PH academic score ' . number_format($finalGwa, 2) . '.';
            }
            return $message;
        }

        return 'Scanner result: academic score not detected.';
    }

    public static function analyzeGwaFromText(string $text): array
    {
        $result = [
            'raw_value' => null,
            'method' => 'none',
            'basis' => 'No valid GWA pattern found.',
            'semester_values' => [],
            'candidates' => []
        ];

        if (trim($text) === '') {
            $result['basis'] = 'OCR text was empty.';
            return $result;
        }

        $cleanText = preg_replace('/\s+/', ' ', $text);
        if (!is_string($cleanText)) {
            $cleanText = $text;
        }

        $isValidGradeLikeValue = static function (float $value): bool {
            return ($value >= 1.0 && $value <= 5.0) || ($value >= 60 && $value <= 100);
        };
        $formatValue = static function (float $value): string {
            return number_format($value, 2, '.', '');
        };

        $patterns = [
            ['label' => 'General Weighted Average', 'pattern' => '/GENERAL\s*WEIGHTED\s*AVERAGE\s*[:=\-]?\s*([0-9]+(?:\.[0-9]+)?)(?![A-Za-z])/i'],
            ['label' => 'General Average', 'pattern' => '/GENERAL\s*AVERAGE\s*[:=\-]?\s*([0-9]+(?:\.[0-9]+)?)(?![A-Za-z])/i'],
            ['label' => 'GWA', 'pattern' => '/\bGWA\b\s*[:=\-]?\s*([0-9]+(?:\.[0-9]+)?)(?![A-Za-z])/i'],
            ['label' => 'Overall Average', 'pattern' => '/OVERALL\s*AVERAGE\s*[:=\-]?\s*([0-9]+(?:\.[0-9]+)?)(?![A-Za-z])/i'],
            ['label' => 'Final Grade', 'pattern' => '/FINAL\s*GRADE\s*[:=\-]?\s*([0-9]+(?:\.[0-9]+)?)(?![A-Za-z])/i'],
            ['label' => 'GPA', 'pattern' => '/\bGPA\b\s*[:=\-]?\s*([0-9]+(?:\.[0-9]+)?)(?![A-Za-z])/i']
        ];

        foreach ($patterns as $patternInfo) {
            if (preg_match($patternInfo['pattern'], $cleanText, $matches)) {
                $value = (float) $matches[1];
                if ($isValidGradeLikeValue($value)) {
                    $result['raw_value'] = $value;
                    $result['method'] = 'label_match';
                    $result['basis'] = 'Matched "' . $patternInfo['label'] . '" with value ' . $formatValue($value) . '.';
                    return $result;
                }
            }
        }

        $semesterPatterns = [
            '/(?:1st|first)\s*(?:semester|sem)\D{0,20}([0-9]+(?:\.[0-9]+)?)/i',
            '/(?:2nd|second)\s*(?:semester|sem)\D{0,20}([0-9]+(?:\.[0-9]+)?)/i',
            '/(?:3rd|third)\s*(?:semester|sem)\D{0,20}([0-9]+(?:\.[0-9]+)?)/i',
            '/(?:4th|fourth)\s*(?:semester|sem)\D{0,20}([0-9]+(?:\.[0-9]+)?)/i',
            '/(?:semester|sem)\s*(?:1|2|3|4|i|ii|iii|iv)\D{0,20}([0-9]+(?:\.[0-9]+)?)/i',
            '/([0-9]+(?:\.[0-9]+)?)\D{0,20}(?:1st|2nd|3rd|4th|first|second|third|fourth)\s*(?:semester|sem)/i',
            '/([0-9]+(?:\.[0-9]+)?)\D{0,20}(?:semester|sem)\s*(?:1|2|3|4|i|ii|iii|iv)/i'
        ];

        $semesterValues = [];
        foreach ($semesterPatterns as $pattern) {
            if (preg_match_all($pattern, $cleanText, $matches) && !empty($matches[1])) {
                foreach ($matches[1] as $raw) {
                    $value = (float) $raw;
                    if (!$isValidGradeLikeValue($value)) {
                        continue;
                    }

                    $alreadyAdded = false;
                    foreach ($semesterValues as $existingValue) {
                        if (abs($existingValue - $value) < 0.00001) {
                            $alreadyAdded = true;
                            break;
                        }
                    }

                    if (!$alreadyAdded) {
                        $semesterValues[] = $value;
                    }
                }
            }
        }

        $result['semester_values'] = $semesterValues;
        if (count($semesterValues) >= 2) {
            $gwaScaleValues = array_values(array_filter($semesterValues, static function (float $v): bool {
                return $v >= 1.0 && $v <= 5.0;
            }));
            $percentScaleValues = array_values(array_filter($semesterValues, static function (float $v): bool {
                return $v >= 60 && $v <= 100;
            }));

            if (count($percentScaleValues) >= 2) {
                $average = array_sum($percentScaleValues) / count($percentScaleValues);
                $result['raw_value'] = $average;
                $result['method'] = 'semester_average_percent';
                $result['basis'] = 'Averaged semester percentages [' . implode(', ', array_map($formatValue, $percentScaleValues)) . '] = ' . $formatValue($average) . '.';
                return $result;
            }

            if (count($gwaScaleValues) >= 2) {
                $average = array_sum($gwaScaleValues) / count($gwaScaleValues);
                $result['raw_value'] = $average;
                $result['method'] = 'semester_average_gwa_scale';
                $result['basis'] = 'Averaged semester GWA-scale values [' . implode(', ', array_map($formatValue, $gwaScaleValues)) . '] = ' . $formatValue($average) . '.';
                return $result;
            }

            $average = array_sum($semesterValues) / count($semesterValues);
            $result['raw_value'] = $average;
            $result['method'] = 'semester_average_mixed';
            $result['basis'] = 'Averaged semester values [' . implode(', ', array_map($formatValue, $semesterValues)) . '] = ' . $formatValue($average) . '.';
            return $result;
        }

        preg_match_all('/(?<![A-Za-z])([0-9]+(?:\.[0-9]+)?)(?![A-Za-z])/', $cleanText, $matches);
        if (empty($matches[1])) {
            $result['basis'] = 'No numeric candidates found in OCR text.';
            return $result;
        }

        $candidates = [];
        $decimalCandidates = [];
        $percentCandidates = [];
        foreach ($matches[1] as $number) {
            $value = (float) $number;
            if (!$isValidGradeLikeValue($value)) {
                continue;
            }

            $isWholeNumber = abs($value - round($value)) < 0.00001;
            $isGwaScale = ($value >= 1.0 && $value <= 5.0);
            $isPercentScale = ($value >= 60 && $value <= 100);

            if ($isGwaScale && $isWholeNumber) {
                continue;
            }

            $candidates[] = $value;
            if (!$isWholeNumber) {
                $decimalCandidates[] = $value;
            }
            if ($isPercentScale) {
                $percentCandidates[] = $value;
            }
        }

        $result['candidates'] = $candidates;
        if (empty($candidates)) {
            if (preg_match('/\b(?:GWA|GPA|GENERAL\s*WEIGHTED\s*AVERAGE|GENERAL\s*AVERAGE|OVERALL\s*AVERAGE)\b\s*[:=\-]?\s*([1-5])\b/i', $cleanText, $labelMatch)) {
                $value = (float) $labelMatch[1];
                $result['raw_value'] = $value;
                $result['method'] = 'label_integer_fallback';
                $result['basis'] = 'Used labeled integer value ' . $formatValue($value) . ' from explicit GWA/GPA label.';
                return $result;
            }

            $result['basis'] = 'Numeric candidates were found but none passed grade filters.';
            return $result;
        }

        if (!empty($decimalCandidates)) {
            $value = (float) $decimalCandidates[0];
            $result['raw_value'] = $value;
            $result['method'] = 'fallback_decimal_candidate';
            $result['basis'] = 'Used first decimal candidate ' . $formatValue($value) . ' from filtered OCR numbers.';
            return $result;
        }

        if (count($percentCandidates) >= 2) {
            $average = array_sum($percentCandidates) / count($percentCandidates);
            $result['raw_value'] = $average;
            $result['method'] = 'fallback_percent_average';
            $result['basis'] = 'Averaged fallback percentage candidates [' . implode(', ', array_map($formatValue, $percentCandidates)) . '] = ' . $formatValue($average) . '.';
            return $result;
        }

        $value = (float) $candidates[0];
        $result['raw_value'] = $value;
        $result['method'] = 'fallback_first_candidate';
        $result['basis'] = 'Used first filtered candidate ' . $formatValue($value) . '.';
        return $result;
    }

    public static function convertPercentageToGwa(float $percentage): float
    {
        if ($percentage >= 98) {
            return 1.00;
        }
        if ($percentage >= 95) {
            return 1.25;
        }
        if ($percentage >= 92) {
            return 1.50;
        }
        if ($percentage >= 89) {
            return 1.75;
        }
        if ($percentage >= 86) {
            return 2.00;
        }
        if ($percentage >= 83) {
            return 2.25;
        }
        if ($percentage >= 80) {
            return 2.50;
        }
        if ($percentage >= 77) {
            return 2.75;
        }
        if ($percentage >= 75) {
            return 3.00;
        }

        return 5.00;
    }

    public static function convertToPhilippineGwa(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($value >= 1.0 && $value <= 5.0) {
            return round($value, 2);
        }

        if ($value >= 60 && $value <= 100) {
            return self::convertPercentageToGwa($value);
        }

        return null;
    }
}
