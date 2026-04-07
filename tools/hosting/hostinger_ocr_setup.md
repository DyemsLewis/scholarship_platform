# Hostinger OCR Setup

This setup is for:

- main website on Hostinger shared hosting
- no `TesseractOCR/` folder on Hostinger
- no VPS
- OCR done through a hosted external OCR API

## Recommended setup for your project

Use `OCR.space` directly from your Hostinger website.

Main website:

- `https://findscholarship.online`

No OCR subdomain is required for this setup.

## What was added

- `Config/ocr_config.php`
  - controls OCR mode and API credentials
- `Model/OcrService.php`
  - shared OCR service used by the upload flow
- `Controller/process_upload.php`
  - still handles the same TOR upload flow, but now calls `OcrService`
- `View/scanner_debug.php`
  - now shows whether the OCR result came from local Tesseract, OCR.space, or a remote OCR API

## Hostinger config

Edit `Config/ocr_config.php` before uploading:

```php
'mode' => 'ocr_space',
'ocr_space_api_key' => 'YOUR_OCR_SPACE_API_KEY',
'ocr_space_endpoint' => 'https://api.ocr.space/parse/image',
'ocr_space_language' => 'eng',
'ocr_space_engine' => 2,
'ocr_space_max_upload_bytes' => 1048576,
```

## Important note about file limits

The OCR.space official API page lists these hosted plan examples:

- Free plan: `1 MB` per file
- PRO plan: `5 MB` per file

If you are using the free OCR.space plan, keep:

```php
'ocr_space_max_upload_bytes' => 1048576,
```

If you upgrade to OCR.space PRO, you can increase it, for example:

```php
'ocr_space_max_upload_bytes' => 5242880,
```

## How the flow works now

1. Student uploads TOR in `View/upload.php`
2. `Controller/process_upload.php` saves the TOR document
3. `Model/OcrService.php` sends the same file to OCR.space
4. OCR text is analyzed for GWA
5. GWA is saved to `student_data.gwa` when detected

## What to upload to Hostinger

Upload your project files to `public_html`, but you do not need:

- `TesseractOCR/`

The website can work without it when `mode` is `ocr_space`.

## Notes

- If OCR is unavailable, the TOR file is still saved in documents.
- The upload process is still the same page and controller flow.
- If you later get a VPS, the project can still support self-hosted OCR using `remote_api` or `local`.
