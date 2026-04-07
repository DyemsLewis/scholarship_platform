<?php
require_once __DIR__ . '/../Config/session_bootstrap.php';
require_once __DIR__ . '/../Config/db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Not authenticated'
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit();
}

function tableHasColumn(PDO $pdo, $tableName, $columnName) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName
    ]);
    return ((int) $stmt->fetchColumn()) > 0;
}

$userId = (int) $_SESSION['user_id'];
$latitudeRaw = trim((string) ($_POST['latitude'] ?? ''));
$longitudeRaw = trim((string) ($_POST['longitude'] ?? ''));
$locationName = trim((string) ($_POST['location_name'] ?? ''));

if ($latitudeRaw === '' || $longitudeRaw === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Latitude and longitude are required.'
    ]);
    exit();
}

if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid coordinate format.'
    ]);
    exit();
}

$latitude = (float) $latitudeRaw;
$longitude = (float) $longitudeRaw;

if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    echo json_encode([
        'success' => false,
        'message' => 'Coordinates are out of range.'
    ]);
    exit();
}

$locationHasNameColumn = tableHasColumn($pdo, 'student_location', 'location_name');

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM student_location WHERE student_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($locationHasNameColumn) {
            $stmt = $pdo->prepare('UPDATE student_location SET latitude = ?, longitude = ?, location_name = ? WHERE student_id = ?');
            $stmt->execute([$latitude, $longitude, $locationName, $userId]);
        } else {
            $stmt = $pdo->prepare('UPDATE student_location SET latitude = ?, longitude = ? WHERE student_id = ?');
            $stmt->execute([$latitude, $longitude, $userId]);
        }
    } else {
        if ($locationHasNameColumn) {
            $stmt = $pdo->prepare('INSERT INTO student_location (student_id, latitude, longitude, location_name) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, $latitude, $longitude, $locationName]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO student_location (student_id, latitude, longitude) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $latitude, $longitude]);
        }
    }

    $pdo->commit();

    $_SESSION['user_latitude'] = $latitude;
    $_SESSION['user_longitude'] = $longitude;
    $_SESSION['user_location_name'] = $locationName;

    echo json_encode([
        'success' => true,
        'message' => 'Location updated successfully.',
        'latitude' => $latitude,
        'longitude' => $longitude,
        'location_name' => $locationName
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Location controller error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save location.'
    ]);
}
?>
