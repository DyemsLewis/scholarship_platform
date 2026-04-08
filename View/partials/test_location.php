<?php
require_once __DIR__ . '/../../app/Config/session_bootstrap.php';
echo "<h2>Session Location Test</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>JavaScript Test</h3>";
echo "<script>
    const userLat = " . json_encode($_SESSION['user_latitude'] ?? null) . ";
    const userLng = " . json_encode($_SESSION['user_longitude'] ?? null) . ";
    
    console.log('From PHP to JS:', {lat: userLat, lng: userLng});
    
    if (userLat && userLng) {
        document.write('<p style=\"color: green\">✅ Location passed to JavaScript: ' + userLat + ', ' + userLng + '</p>');
    } else {
        document.write('<p style=\"color: red\">❌ No location passed to JavaScript</p>');
    }
</script>";

echo "<p><a href='scholarships.php'>Go to Scholarships</a></p>";
?>
