<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scanner Debug - Scholarship Finder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/upload.css')); ?>">
</head>
<body>
<?php
include 'layout/header.php';

$isLoggedIn = isset($_SESSION['user_id']);
$lastRawOcrValue = isset($_SESSION['last_ocr_raw_gwa']) && is_numeric($_SESSION['last_ocr_raw_gwa']) ? (float) $_SESSION['last_ocr_raw_gwa'] : null;
$lastExtractedGwa = isset($_SESSION['last_ocr_final_gwa']) && is_numeric($_SESSION['last_ocr_final_gwa']) ? (float) $_SESSION['last_ocr_final_gwa'] : null;
$lastOcrProvider = isset($_SESSION['last_ocr_provider']) ? (string) $_SESSION['last_ocr_provider'] : '';
$lastOcrMethod = isset($_SESSION['last_ocr_method']) ? (string) $_SESSION['last_ocr_method'] : '';
$lastOcrBasis = isset($_SESSION['last_ocr_basis']) ? (string) $_SESSION['last_ocr_basis'] : '';
$lastOcrTextPreview = isset($_SESSION['last_ocr_text_preview']) ? (string) $_SESSION['last_ocr_text_preview'] : '';
$lastOcrCandidates = isset($_SESSION['last_ocr_candidates']) && is_array($_SESSION['last_ocr_candidates']) ? $_SESSION['last_ocr_candidates'] : [];
$lastOcrSemesterValues = isset($_SESSION['last_ocr_semester_values']) && is_array($_SESSION['last_ocr_semester_values']) ? $_SESSION['last_ocr_semester_values'] : [];

$hasScannerDebug = $lastOcrTextPreview !== '' || $lastOcrBasis !== '' || $lastExtractedGwa !== null || $lastRawOcrValue !== null;
$scannerMethodLabels = [
    'label_match' => 'Explicit label match (GWA/GPA/General Average)',
    'semester_average_percent' => 'Semester average using percentage values',
    'semester_average_gwa_scale' => 'Semester average using GWA-scale values',
    'semester_average_mixed' => 'Semester average (mixed values)',
    'fallback_decimal_candidate' => 'Fallback decimal candidate',
    'fallback_percent_average' => 'Fallback percentage average',
    'fallback_first_candidate' => 'Fallback first filtered candidate',
    'label_integer_fallback' => 'Labeled integer fallback',
    'service_error' => 'OCR service/configuration error',
    'none' => 'No valid GWA candidate found'
];
$scannerMethodLabel = $scannerMethodLabels[$lastOcrMethod] ?? ($lastOcrMethod !== '' ? $lastOcrMethod : 'Not available');
$scannerProviderLabel = 'Not available';
if ($lastOcrProvider === 'ocr_space') {
    $scannerProviderLabel = 'OCR.space API';
} elseif ($lastOcrProvider === 'remote_api') {
    $scannerProviderLabel = 'Remote OCR API';
} elseif ($lastOcrProvider === 'local_tesseract') {
    $scannerProviderLabel = 'Local Tesseract';
}
?>

<section class="dashboard">
    <div class="container">
        <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div>
                <h2 style="margin: 0; color: var(--primary); font-size: 1.7rem;">
                    <i class="fas fa-magnifying-glass-chart"></i> Scanner Check Page
                </h2>
                <p style="margin: 6px 0 0 0; color: var(--gray); font-size: 0.9rem;">
                    Temporary page for OCR inspection. You can remove this later.
                </p>
            </div>
            <a href="upload.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back To Upload
            </a>
        </div>

        <?php if (!$isLoggedIn): ?>
            <div class="upload-card">
                <div class="upload-card-header">
                    <h2><i class="fas fa-lock"></i> Login Required</h2>
                    <p>Please sign in to view scanner debug details.</p>
                </div>
                <div class="upload-card-body" style="text-align: center;">
                    <a href="login.php" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Login</a>
                </div>
            </div>
        <?php else: ?>
            <div class="upload-card" style="margin-bottom: 18px;">
                <div class="upload-card-header" style="background: linear-gradient(135deg, #0f766e, #0f4c5c);">
                    <h2><i class="fas fa-flask"></i> OCR Result Snapshot</h2>
                    <p>Based on your latest TOR upload only</p>
                </div>
                <div class="upload-card-body">
                    <?php if ($hasScannerDebug): ?>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px;">
                            <span style="background: #ecfeff; color: #155e75; border: 1px solid #99f6e4; padding: 6px 11px; border-radius: 999px; font-size: 0.78rem; font-weight: 600;">
                                <i class="fas fa-calculator"></i>
                                Final GWA: <?php echo $lastExtractedGwa !== null ? number_format((float) $lastExtractedGwa, 2) : 'Not detected'; ?>
                            </span>
                            <span style="background: #f0fdfa; color: #115e59; border: 1px solid #99f6e4; padding: 6px 11px; border-radius: 999px; font-size: 0.78rem; font-weight: 600;">
                                <i class="fas fa-microchip"></i>
                                Raw OCR value: <?php echo $lastRawOcrValue !== null ? number_format((float) $lastRawOcrValue, 2) : 'N/A'; ?>
                            </span>
                            <span style="background: #ecfeff; color: #134e4a; border: 1px solid #99f6e4; padding: 6px 11px; border-radius: 999px; font-size: 0.78rem; font-weight: 600;">
                                <i class="fas fa-diagram-project"></i>
                                Method: <?php echo htmlspecialchars($scannerMethodLabel); ?>
                            </span>
                            <span style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 6px 11px; border-radius: 999px; font-size: 0.78rem; font-weight: 600;">
                                <i class="fas fa-server"></i>
                                OCR Source: <?php echo htmlspecialchars($scannerProviderLabel); ?>
                            </span>
                        </div>

                        <div style="padding: 12px; border: 1px solid #ccfbf1; border-radius: 12px; background: #f0fdfa; margin-bottom: 12px;">
                            <div style="font-size: 0.8rem; font-weight: 700; color: #0f766e; margin-bottom: 6px;">Basis Used</div>
                            <div style="font-size: 0.85rem; color: #334155; line-height: 1.55;">
                                <?php echo htmlspecialchars($lastOcrBasis !== '' ? $lastOcrBasis : 'No basis details available.'); ?>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 12px;">
                            <div style="padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc;">
                                <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.05em;">Semester Values</div>
                                <div style="font-size: 0.86rem; color: #0f172a;">
                                    <?php
                                    if (!empty($lastOcrSemesterValues)) {
                                        echo htmlspecialchars(implode(', ', array_map(static function ($value) {
                                            return number_format((float) $value, 2);
                                        }, $lastOcrSemesterValues)));
                                    } else {
                                        echo 'None';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div style="padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc;">
                                <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.05em;">Filtered Candidates</div>
                                <div style="font-size: 0.86rem; color: #0f172a;">
                                    <?php
                                    if (!empty($lastOcrCandidates)) {
                                        echo htmlspecialchars(implode(', ', array_map(static function ($value) {
                                            return number_format((float) $value, 2);
                                        }, $lastOcrCandidates)));
                                    } else {
                                        echo 'None';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <details style="border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc;">
                            <summary style="cursor: pointer; padding: 12px 14px; font-weight: 600; color: #0f172a;">
                                View OCR Text Preview
                            </summary>
                            <div style="padding: 0 14px 14px 14px;">
                                <p style="margin: 0 0 8px 0; font-size: 0.75rem; color: #64748b;">Showing latest OCR output preview only.</p>
                                <pre style="margin: 0; white-space: pre-wrap; word-break: break-word; font-size: 0.78rem; line-height: 1.45; color: #0f172a; max-height: 320px; overflow: auto;"><?php echo htmlspecialchars($lastOcrTextPreview !== '' ? $lastOcrTextPreview : 'No OCR preview available yet.'); ?></pre>
                            </div>
                        </details>
                    <?php else: ?>
                        <p style="margin: 0; color: var(--gray); font-size: 0.9rem;">
                            No scanner debug data yet. Upload and process a TOR first in Upload page.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include 'layout/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?php echo htmlspecialchars(assetUrl('public/js/script.js')); ?>"></script>
</body>
</html>
