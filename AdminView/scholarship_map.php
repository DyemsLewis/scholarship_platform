<?php
require_once __DIR__ . '/../app/Config/session_bootstrap.php';
require_once __DIR__ . '/../app/Config/db_config.php';
require_once __DIR__ . '/../app/Config/access_control.php';

requireRoles(['provider', 'admin', 'super_admin'], '../View/index.php', 'You do not have permission to view scholarship maps.');

$stmt = $pdo->query("
    SELECT
        s.*,
        sd.provider,
        sd.benefits,
        sd.deadline,
        sl.latitude,
        sl.longitude
    FROM scholarships s
    LEFT JOIN scholarship_data sd ON s.id = sd.scholarship_id
    LEFT JOIN scholarship_location sl ON s.id = sl.scholarship_id
    WHERE s.status = 'active'
      AND sl.latitude IS NOT NULL
      AND sl.longitude IS NOT NULL
    ORDER BY s.name
");
$scholarships = $stmt->fetchAll();

$sampleScholarships = [
    [
        'name' => 'CHED Merit Scholarship Program',
        'provider' => 'Commission on Higher Education',
        'lat' => 14.609054,
        'lng' => 121.022257,
        'eligibility' => 'GWA <= 1.75',
        'benefits' => 'Full Tuition + Monthly Stipend + Book Allowance',
        'deadline' => '2026-03-15'
    ],
    [
        'name' => 'DOST-SEI Undergraduate Scholarship',
        'provider' => 'Department of Science and Technology',
        'lat' => 14.5831,
        'lng' => 121.0510,
        'eligibility' => 'GWA <= 2.0, STEM Course',
        'benefits' => 'Tuition + Allowance + Book Allowance + Monthly Stipend',
        'deadline' => '2026-04-30'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scholarship Locations - Map View</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="../public/css/admin_style.css">

<style>
.navbar {
    position: relative;
    z-index: 1050;
}

.map-wrapper {
    margin:80px 20px 0 20px;
    height:calc(100vh - 200px);
    min-height:600px;
    position:relative;
    border-radius:8px;
    overflow:hidden;
    border:2px solid #e0e0e0;
    transition:all 0.3s ease;
    z-index: 1;
}

#map { height:100%; width:100%; }

.scholarship-popup { max-width:280px; }
.scholarship-popup h3 { margin:0 0 10px 0; color:#0066ff; font-size:16px; font-weight:600; }
.scholarship-popup p { margin:6px 0; font-size:13px; color:#555; line-height:1.4; }
.popup-badge { display:inline-block; padding:3px 8px; background:#0066ff; color:white; border-radius:4px; font-size:11px; margin:2px 2px 2px 0; font-weight:500; }

/* Leaflet zoom buttons */
.leaflet-control-zoom {
    border:none !important;
    border-radius:8px !important;
    margin-left:20px !important;
    margin-top:20px !important;
    z-index: 3; 
}
.leaflet-control-zoom a {
    background:white !important;
    color:#0066ff !important;
    border:2px solid #0066ff !important;
    width:40px !important;
    height:40px !important;
    line-height:40px !important;
    font-size:18px !important;
    font-weight:bold !important;
    transition:all 0.2s !important;
}
.leaflet-control-zoom a:hover { background:#0066ff !important; color:white !important; }
.leaflet-control-zoom a:first-child { border-radius:8px 8px 0 0 !important; border-bottom:1px solid #e0e0e0 !important; }
.leaflet-control-zoom a:last-child { border-radius:0 0 8px 8px !important; }

/* Legend inside map */
.map-legend {
    position: absolute;
    bottom: 20px;  /* distance from bottom of map */
    left: 20px;    /* distance from left of map */
    background: white;
    padding: 12px 18px;
    border-radius: 8px;
    border: 1px solid #ccc;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    font-size: 14px;
    z-index: 500; /* above map tiles but below navbar */
    max-width: 220px;
}

.map-legend h4 {
    margin:0 0 10px 0;
    font-size:15px;
    color:#333;
    font-weight:600;
}

.legend-item {
    display:flex;
    align-items:center;
    margin:5px 0;
    gap:10px;
}

.legend-dot {
    width:20px;
    height:20px;
    background:#0066ff;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    color:white;
    font-size:12px;
    font-weight:bold;
}
</style>
</head>
<body>
<?php include 'layouts/admin_header.php'; ?>

<div class="map-wrapper" id="mapWrapper">
    <div id="map"></div>

    <!-- Legend inside the map -->
    <div class="map-legend">
        <h4>Map Legend</h4>
        <div class="legend-item">
            <div class="legend-dot">S</div>
            <span>Active Scholarship</span>
        </div>
    </div>
</div>

<section class="admin-dashboard">
    <div class="container">
        <div class="page-header">
            <h1>Scholarship Locations Map</h1>
            <p>View scholarship programs by geographic location</p>
        </div>

        <div class="form-section">
            <div class="form-card">
                <h3>Scholarship Programs (<?php echo count($scholarships); ?>)</h3>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px,1fr)); gap:15px; margin-top:15px;">
                    <?php foreach($scholarships as $scholarship): ?>
                    <div class="scholarship-item" style="border:1px solid #ddd; padding:15px; border-radius:5px;">
                        <h4><?php echo htmlspecialchars($scholarship['name']); ?></h4>
                        <p><strong>Provider:</strong> <?php echo htmlspecialchars($scholarship['provider']); ?></p>
                        <p><strong>Eligibility:</strong> <?php echo (isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') ? ('GWA <= ' . number_format((float) $scholarship['min_gwa'], 2)) : 'See criteria'; ?></p>
                        <p><strong>Deadline:</strong> <?php echo !empty($scholarship['deadline']) ? date('M d, Y', strtotime((string) $scholarship['deadline'])) : 'No deadline set'; ?></p>
                        <span class="popup-badge">Active</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('map').setView([12.8797, 121.7740], 6);

const philippinesBounds = L.latLngBounds([4.5,116.0],[21.0,127.0]);
map.setMaxBounds(philippinesBounds);
map.on('drag',()=>map.panInsideBounds(philippinesBounds,{animate:false}));

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
    attribution:'(c) OpenStreetMap contributors'
}).addTo(map);

const dbScholarships = [
<?php foreach($scholarships as $scholarship): ?>
<?php if($scholarship['latitude'] && $scholarship['longitude']): ?>
,{
    name: "<?php echo addslashes($scholarship['name']); ?>",
    provider: "<?php echo addslashes($scholarship['provider']); ?>",
    lat: <?php echo $scholarship['latitude']; ?>,
    lng: <?php echo $scholarship['longitude']; ?>,
    eligibility: "<?php echo addslashes($scholarship['eligibility'] ?: ((isset($scholarship['min_gwa']) && $scholarship['min_gwa'] !== null && $scholarship['min_gwa'] !== '') ? ('GWA <= ' . number_format((float) $scholarship['min_gwa'], 2)) : 'See criteria')); ?>",
    benefits: "<?php echo addslashes($scholarship['benefits'] ?: 'Various benefits available'); ?>",
    deadline: "<?php echo $scholarship['deadline']; ?>"
},
<?php endif; ?>
<?php endforeach; ?>
];

const sampleScholarships = <?php echo json_encode($sampleScholarships); ?>;

const scholarshipLocations = [
    ...dbScholarships,
    ...sampleScholarships.filter(sample => !dbScholarships.some(db => db.name === sample.name))
];

scholarshipLocations.forEach((scholarship,index)=>{
    const customIcon = L.divIcon({ className:'custom-marker', html:`<div class="legend-dot">${index+1}</div>`, iconSize:[28,28], iconAnchor:[14,14], popupAnchor:[0,-14] });
    L.marker([scholarship.lat,scholarship.lng],{icon:customIcon}).addTo(map).bindPopup(`
        <div class="scholarship-popup">
            <h3>${scholarship.name}</h3>
            <p><strong>Provider:</strong> ${scholarship.provider}</p>
            <p><strong>Eligibility:</strong> ${scholarship.eligibility}</p>
            <p><strong>Benefits:</strong> ${scholarship.benefits}</p>
            <p><strong>Deadline:</strong> ${scholarship.deadline}</p>
            <span class="popup-badge">Active</span>
        </div>
    `,{maxWidth:300});
});

if(scholarshipLocations.length>0){
    const group = new L.featureGroup();
    scholarshipLocations.forEach(loc=>group.addLayer(L.marker([loc.lat,loc.lng])));
    map.fitBounds(group.getBounds().pad(0.1));
}
</script>

<?php include 'layouts/admin_footer.php'; ?>
</body>
</html>
