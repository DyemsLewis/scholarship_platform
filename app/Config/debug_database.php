<?php
session_start();
require_once 'db_config.php';

echo "<h1>Database Debug Information</h1>";

// Check if users table exists and get its structure
try {
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Users Table Structure:</h2>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    
    $hasLatitude = false;
    $hasLongitude = false;
    $hasLocationName = false;
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
        
        if ($column['Field'] == 'latitude') $hasLatitude = true;
        if ($column['Field'] == 'longitude') $hasLongitude = true;
        if ($column['Field'] == 'location_name') $hasLocationName = true;
    }
    echo "</table>";
    
    echo "<h3>Location Columns Status:</h3>";
    echo "<ul>";
    echo "<li>latitude column: " . ($hasLatitude ? '✅ Exists' : '❌ MISSING') . "</li>";
    echo "<li>longitude column: " . ($hasLongitude ? '✅ Exists' : '❌ MISSING') . "</li>";
    echo "<li>location_name column: " . ($hasLocationName ? '✅ Exists' : '❌ MISSING') . "</li>";
    echo "</ul>";
    
    // If columns are missing, show SQL to add them
    if (!$hasLatitude || !$hasLongitude || !$hasLocationName) {
        echo "<h3>SQL to add missing columns:</h3>";
        echo "<pre style='background: #f4f4f4; padding: 10px;'>";
        if (!$hasLatitude) echo "ALTER TABLE users ADD COLUMN latitude DECIMAL(10, 8) NULL;\n";
        if (!$hasLongitude) echo "ALTER TABLE users ADD COLUMN longitude DECIMAL(11, 8) NULL;\n";
        if (!$hasLocationName) echo "ALTER TABLE users ADD COLUMN location_name VARCHAR(255) NULL;\n";
        echo "</pre>";
    }
    
    // Show sample data
    echo "<h2>Sample User Data (first 5 users):</h2>";
    $stmt = $pdo->query("SELECT id, email, name, latitude, longitude, location_name FROM users LIMIT 5");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($users) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Email</th><th>Name</th><th>Latitude</th><th>Longitude</th><th>Location Name</th></tr>";
        
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . $user['id'] . "</td>";
            echo "<td>" . $user['email'] . "</td>";
            echo "<td>" . $user['name'] . "</td>";
            echo "<td>" . ($user['latitude'] ?? 'NULL') . "</td>";
            echo "<td>" . ($user['longitude'] ?? 'NULL') . "</td>";
            echo "<td>" . ($user['location_name'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No users found in database.</p>";
    }
    
    // Check if current user is logged in
    if (isset($_SESSION['user_id'])) {
        echo "<h2>Current Logged-in User Data:</h2>";
        $stmt = $pdo->prepare("SELECT id, email, name, latitude, longitude, location_name FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currentUser) {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>ID</th><th>Email</th><th>Name</th><th>Latitude</th><th>Longitude</th><th>Location Name</th></tr>";
            echo "<tr>";
            echo "<td>" . $currentUser['id'] . "</td>";
            echo "<td>" . $currentUser['email'] . "</td>";
            echo "<td>" . $currentUser['name'] . "</td>";
            echo "<td style='background: " . ($currentUser['latitude'] ? '#90EE90' : '#FFB6C1') . "'>" . ($currentUser['latitude'] ?? 'NULL') . "</td>";
            echo "<td style='background: " . ($currentUser['longitude'] ? '#90EE90' : '#FFB6C1') . "'>" . ($currentUser['longitude'] ?? 'NULL') . "</td>";
            echo "<td>" . ($currentUser['location_name'] ?? 'NULL') . "</td>";
            echo "</tr>";
            echo "</table>";
            
            echo "<h3>Session Data:</h3>";
            echo "<pre>";
            print_r($_SESSION);
            echo "</pre>";
        }
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>