<?php
require_once __DIR__ . '/time_config.php';

$host = 'localhost';
$dbname = 'db_scholarship';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    $timezoneStmt = $pdo->prepare('SET time_zone = ?');
    $timezoneStmt->execute([APP_DB_TIMEZONE_OFFSET]);
    
    // Also create $conn for compatibility
    $conn = $pdo;
} catch(PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    die('Database connection failed. Please check your configuration and try again.');
}
?>
