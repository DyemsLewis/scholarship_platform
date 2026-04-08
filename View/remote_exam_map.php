<?php
require_once __DIR__ . '/../app/Config/init.php';
require_once __DIR__ . '/../app/Config/url_token.php';
require_once __DIR__ . '/../app/Models/Scholarship.php';

$scholarshipId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_GET['scholarship_id'] ?? 0);
if ($scholarshipId <= 0) {
    $_SESSION['error'] = 'Invalid scholarship ID.';
    header('Location: scholarships.php');
    exit();
}

$scholarshipModel = new Scholarship($pdo);
$scholarship = $scholarshipModel->getScholarshipById($scholarshipId);

if (!$scholarship) {
    $_SESSION['error'] = 'Scholarship not found or inactive.';
    header('Location: scholarships.php');
    exit();
}

$scholarshipDetailsUrl = buildEntityUrl('scholarship_details.php', 'scholarship', $scholarshipId, 'view', ['id' => $scholarshipId]);

$assessmentType = strtolower(trim((string) ($scholarship['assessment_requirement'] ?? 'none')));
$remoteExamLocations = is_array($scholarship['remote_exam_locations'] ?? null) ? $scholarship['remote_exam_locations'] : [];

$mappedSites = [];
$unmappedSites = [];
foreach ($remoteExamLocations as $site) {
    $siteName = trim((string) ($site['site_name'] ?? ''));
    $addressParts = [];
    if (!empty($site['address'])) $addressParts[] = (string) $site['address'];
    if (!empty($site['city'])) $addressParts[] = (string) $site['city'];
    if (!empty($site['province'])) $addressParts[] = (string) $site['province'];
    $addressLabel = implode(', ', array_filter($addressParts));

    $siteData = [
        'site_name' => $siteName !== '' ? $siteName : 'Remote Examination Site',
        'address' => $addressLabel,
        'latitude' => isset($site['latitude']) && $site['latitude'] !== null && $site['latitude'] !== '' ? (float) $site['latitude'] : null,
        'longitude' => isset($site['longitude']) && $site['longitude'] !== null && $site['longitude'] !== '' ? (float) $site['longitude'] : null,
    ];

    if ($siteData['latitude'] !== null && $siteData['longitude'] !== null) {
        $mappedSites[] = $siteData;
    } else {
        $unmappedSites[] = $siteData;
    }
}

$defaultScholarshipImage = resolvePublicUploadUrl(null, '../');
$scholarshipImage = resolvePublicUploadUrl($scholarship['image'] ?? null, '../');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remote Examination Map - <?php echo htmlspecialchars((string) $scholarship['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/style.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('public/css/remote-exam-map.css')); ?>">
</head>
<body>
<?php include 'layout/header.php'; ?>

<section class="dashboard remote-map-page user-page-shell">
    <div class="container">
        <div class="map-page-hero">
            <img src="<?php echo htmlspecialchars($scholarshipImage); ?>" alt="<?php echo htmlspecialchars((string) $scholarship['name']); ?>" onerror="this.src='<?php echo htmlspecialchars($defaultScholarshipImage); ?>'">
            <div>
                <h2><?php echo htmlspecialchars((string) $scholarship['name']); ?></h2>
                <p><?php echo htmlspecialchars((string) ($scholarship['provider'] ?? 'Provider not specified')); ?></p>
                <div class="map-page-actions">
                    <a href="<?php echo htmlspecialchars($scholarshipDetailsUrl); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Details
                    </a>
                    <?php if (!empty($scholarship['assessment_link'])): ?>
                    <a href="<?php echo htmlspecialchars((string) $scholarship['assessment_link']); ?>" class="btn btn-primary" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-up-right-from-square"></i> Open Assessment Link
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($assessmentType !== 'remote_examination'): ?>
            <div class="guest-warning">
                <i class="fas fa-circle-info"></i>
                <p>This scholarship is not configured for remote examination sites.</p>
            </div>
        <?php endif; ?>

        <div class="map-layout">
            <div class="map-card">
                <div class="map-card-header">
                    <h3><i class="fas fa-map-location-dot"></i> Remote Examination Site Map</h3>
                    <span class="map-summary-pill">
                        <i class="fas fa-location-dot"></i>
                        <?php echo count($mappedSites); ?> mapped site<?php echo count($mappedSites) === 1 ? '' : 's'; ?>
                    </span>
                </div>
                <?php if (!empty($mappedSites)): ?>
                    <div id="remoteExamMap"></div>
                <?php else: ?>
                    <div class="site-empty">
                        <i class="fas fa-map-marked-alt"></i>
                        <p>No remote examination site coordinates are available yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="site-list-card">
                <div class="site-list-header">
                    <h3><i class="fas fa-list"></i> Examination Sites</h3>
                    <span class="map-summary-pill">
                        <i class="fas fa-building"></i>
                        <?php echo count($remoteExamLocations); ?> total site<?php echo count($remoteExamLocations) === 1 ? '' : 's'; ?>
                    </span>
                </div>
                <div class="site-list-body">
                    <?php if (empty($remoteExamLocations)): ?>
                        <div class="site-empty">
                            <i class="fas fa-location-crosshairs"></i>
                            <p>No remote examination sites are listed for this scholarship yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($mappedSites as $index => $site): ?>
                            <div class="site-item">
                                <span class="site-badge"><i class="fas fa-map-pin"></i> Map Ready</span>
                                <h4><?php echo htmlspecialchars((string) $site['site_name']); ?></h4>
                                <p><?php echo htmlspecialchars((string) ($site['address'] !== '' ? $site['address'] : 'Address not specified')); ?></p>
                                <div class="site-item-actions">
                                    <button type="button" class="btn btn-outline" onclick="focusRemoteSite(<?php echo (int) $index; ?>)">
                                        <i class="fas fa-crosshairs"></i> Focus on Map
                                    </button>
                                    <a href="https://www.google.com/maps?q=<?php echo rawurlencode((string) $site['latitude'] . ',' . (string) $site['longitude']); ?>" class="btn btn-primary" target="_blank" rel="noopener noreferrer">
                                        <i class="fas fa-route"></i> Open in Maps
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($unmappedSites as $site): ?>
                            <div class="site-item">
                                <span class="site-badge warn"><i class="fas fa-triangle-exclamation"></i> No Pin Yet</span>
                                <h4><?php echo htmlspecialchars((string) $site['site_name']); ?></h4>
                                <p><?php echo htmlspecialchars((string) ($site['address'] !== '' ? $site['address'] : 'Address not specified')); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($mappedSites)): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const remoteExamSites = <?php echo json_encode($mappedSites, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const remoteExamMap = L.map('remoteExamMap');

function escapeRemoteExamPopup(value) {
    return String(value ?? '').replace(/[&<>"']/g, function(char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[char] || char;
    });
}

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(remoteExamMap);

const remoteExamMarkers = [];
const remoteExamBounds = [];

remoteExamSites.forEach((site, index) => {
    const marker = L.marker([site.latitude, site.longitude]).addTo(remoteExamMap);
    marker.bindPopup(
        `<div class="popup-title">${escapeRemoteExamPopup(site.site_name)}</div><div class="popup-copy">${escapeRemoteExamPopup(site.address || 'Address not specified')}</div>`
    );
    remoteExamMarkers.push(marker);
    remoteExamBounds.push([site.latitude, site.longitude]);
});

if (remoteExamBounds.length === 1) {
    remoteExamMap.setView(remoteExamBounds[0], 15);
    remoteExamMarkers[0].openPopup();
} else if (remoteExamBounds.length > 1) {
    remoteExamMap.fitBounds(remoteExamBounds, { padding: [36, 36] });
}

function refreshRemoteExamMap() {
    window.setTimeout(() => {
        remoteExamMap.invalidateSize();
    }, 120);
}

remoteExamMap.whenReady(refreshRemoteExamMap);
window.addEventListener('load', refreshRemoteExamMap);
window.addEventListener('resize', () => remoteExamMap.invalidateSize());

window.focusRemoteSite = function focusRemoteSite(index) {
    const marker = remoteExamMarkers[index];
    if (!marker) {
        return;
    }

    remoteExamMap.setView(marker.getLatLng(), Math.max(remoteExamMap.getZoom(), 16), {
        animate: true
    });
    marker.openPopup();
};
</script>
<?php endif; ?>

<?php include 'layout/footer.php'; ?>
</body>
</html>
