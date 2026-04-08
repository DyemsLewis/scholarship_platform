<?php

return [
    /*
     * Modes:
     * - local: use the Tesseract binary on the same server
     * - ocr_space: send the file directly to OCR.space
     * - remote_api: send the file to a separate OCR endpoint
     * - auto: prefer local Tesseract, otherwise OCR.space, otherwise remote_api
     */
    'mode' => getenv('OCR_MODE') ?: 'ocr_space',

    /*
     * OCR.space hosted OCR API.
     * Official docs: https://ocr.space/OCRAPI
     */
    'ocr_space_api_key' => getenv('OCR_SPACE_API_KEY') ?: 'K86519215288957',
    'ocr_space_endpoint' => getenv('OCR_SPACE_ENDPOINT') ?: 'https://api.ocr.space/parse/image',
    'ocr_space_timeout' => (int) (getenv('OCR_SPACE_TIMEOUT') ?: 45),
    'ocr_space_language' => getenv('OCR_SPACE_LANGUAGE') ?: 'eng',
    'ocr_space_engine' => (int) (getenv('OCR_SPACE_ENGINE') ?: 2),
    'ocr_space_detect_orientation' => strtolower((string) (getenv('OCR_SPACE_DETECT_ORIENTATION') ?: 'true')) !== 'false',
    'ocr_space_scale' => strtolower((string) (getenv('OCR_SPACE_SCALE') ?: 'true')) !== 'false',
    'ocr_space_is_table' => strtolower((string) (getenv('OCR_SPACE_IS_TABLE') ?: 'false')) === 'true',
    'ocr_space_max_upload_bytes' => (int) (getenv('OCR_SPACE_MAX_UPLOAD_BYTES') ?: 1048576),

    /*
     * Self-hosted remote OCR endpoint for hosting providers such as Hostinger.
     * Example: https://your-ocr-server.com/Controller/ocr_api.php
     */
    'remote_url' => getenv('OCR_REMOTE_URL') ?: '',
    'remote_api_key' => getenv('OCR_REMOTE_API_KEY') ?: '',
    'remote_timeout' => (int) (getenv('OCR_REMOTE_TIMEOUT') ?: 45),

    /*
     * API key expected by Controller/ocr_api.php when that endpoint is exposed
     * on a server that can run local Tesseract.
     */
    'endpoint_api_key' => getenv('OCR_ENDPOINT_API_KEY') ?: '',

    /*
     * Local Tesseract candidate paths. The service will use the first working one.
     */
    'local_tesseract_candidates' => [
        __DIR__ . '/../TesseractOCR/tesseract.exe',
        __DIR__ . '/../TesseractOCR/tesseract',
        'D:/XAMPP/htdocs/Thesis/TesseractOCR/tesseract.exe',
        'D:/TesseractOCR/tesseract.exe',
        'D:/Program Files/Tesseract-OCR/tesseract.exe',
        'C:/Program Files/Tesseract-OCR/tesseract.exe',
        'tesseract'
    ]
];
